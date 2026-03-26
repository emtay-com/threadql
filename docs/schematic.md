# ThreadQL Query Job Flow

How a user's Slack message becomes a SQL result — and every job dispatched along the way.

---

## The Big Picture

```
Slack Message (@mention or DM)
     |
     v
SlackEventsController               (HTTP — synchronous)
     |
     |--- creates Thread + Query records (DB transaction)
     |--- sends immediate "thinking..." reply to Slack
     |
     v
QueryJobDispatcher                   (dispatches to queue)
     |
     +--[new question]---------> UserQueryInvokerJob
     |                                |
     +--[reply in thread]------> UserFollowUpQueryJob
     |                                |
     +--[always]--------------> QueryCrashWatchdogJob (300s delay)
                                      |
                            (both follow the same path below)
                                      |
                                      v
                            QueryExecutionService.execute()
                                      |
                                      v
                            LlmFallbackExecutor         (calls LLM via Prism)
                                      |
                            LLM decides which tools to call
                                      |
             +------------+-----------+-----------+------------------+
             |            |           |           |                  |
        run_sql_query  fetch_     request_    export_csv     run_query_for_
             |         table_     definition      |          csv_export
             |         ddls          |            |               |
             |            |          |            |               |
         aggregate?  NotifyTool  Request      (see CSV        NotifyTool
          /    \     ExecutingJob Definition   export           ExecutingJob
        yes     no               Job          breakdown          |
         |       |                            below)       ExportCsvAnd
     (returns  PaginateQueryJob                            DeliverJob (async)
      scalar    + QueryAnchorManager                       + SendCsvExportLinkJob
      to LLM)     (posts table directly
                   via SlackMessenger)
                + SendNoResultsMessageJob
                                      |
                                      v
                            (after LLM finishes all tool calls)
                                      |
                            SlackMessageDispatcher
                            (formats LLM text response)
                                      |
                              +-------+-------+
                              |               |
                        SendSlackBlocks  SendSlackAttachments
                        (text sections)  (table attachments)
                                      |
                                      v
                            SendFeedbackSurveyJob  (5s delay, only on success)

Lifecycle management (runs across entire job):
  QueryLifecycleMiddleware .... Sets/clears cache keys for active query tracking
  QueryCrashWatchdogJob ....... Fires after 300s delay to detect crashed queries
```

---

## Step-by-Step Walkthrough

### 1. Slack sends a webhook

A user @mentions the bot or sends it a DM. Slack fires a POST to:

```
POST /{tenant:uuid}/slack/events
```

**Controller:** `SlackEventsController::handle()`

**What happens (synchronous, in the HTTP request):**

1. Validates the Slack signature (middleware)
2. Filters out bot messages, unsupported event types
3. Inside a DB transaction:
   - Finds or creates a **Thread** record (keyed by Slack `thread_ts`)
   - Finds or creates a **SlackUser** record
   - Creates a **Query** record (with deduplication — skips if duplicate `event_id`)
4. Detects if this is a **follow-up** (thread already has previous queries)
5. Sends an immediate "thinking..." reply to Slack (outside the transaction)
6. Dispatches the appropriate job via `QueryJobDispatcher`
7. Returns `200 OK` to Slack

**Key files:**
- `app/Http/Controllers/Slack/SlackEventsController.php`
- `app/Slack/Events/QueryJobDispatcher.php`
- `app/Slack/Events/ThreadManager.php`
- `app/Slack/Events/QueryCreator.php`
- `app/Slack/Events/FollowUpDetector.php`

---

### 2. Job dispatched to queue

`QueryJobDispatcher` picks one of two jobs based on whether this is a new question or a follow-up:

| Scenario | Job Dispatched | Queue |
|---|---|---|
| First message in thread | `UserQueryInvokerJob` | `long_queue` |
| Reply in existing thread | `UserFollowUpQueryJob` | `long_queue` |
| Always (alongside query job) | `QueryCrashWatchdogJob` | `default` (300s delay) |

Both query jobs run `afterCommit` (waits for the DB transaction to complete before the worker picks them up).

---

### 3. Query job runs on the worker

**Job:** `UserQueryInvokerJob` or `UserFollowUpQueryJob`

Both jobs follow the same structure:

0. **Lifecycle middleware** — `QueryLifecycleMiddleware` sets cache keys marking the query as active (used by the crash watchdog and in-flight detection)
1. **Acquire a cache lock** (prevents duplicate processing if Slack retries the event)
2. **Load entities** — Thread, Query, Tenant, Datasource via `QueryEntityLoader`
3. **Guard rails** (dispatch error notification jobs if something is missing):
   - No datasource? → dispatches `SendNoDatasourceNotificationJob`, then throws
   - No LLM provider? → dispatches `SendNoLlmProviderNotificationJob`, then throws
4. **Generate the prompt** via the command bus:
   - `GenerateInitialPromptCommand` (new query) — includes schema context, definitions, date info
   - `GenerateFollowUpPromptCommand` (follow-up) — also includes conversation history (ledger)
5. **Call the LLM** via `QueryExecutionService.execute()` → `LlmFallbackExecutor`
6. During LLM execution, the **LLM calls MCP tools** (see section 4 below)
7. **Send results to Slack** via `SlackMessageDispatcher`
8. **Dispatch feedback survey** — `SendFeedbackSurveyJob` with a 5-second delay (only on success)
9. **Release the lock**
10. **Lifecycle middleware cleanup** — cache keys cleared (in `finally` block, runs even on failure). If an `UnrecoverableJobException` is thrown, query status is set to ERROR before propagating.

**Key files:**
- `app/Jobs/UserQueryInvokerJob.php`
- `app/Jobs/UserFollowUpQueryJob.php`
- `app/Jobs/Middleware/QueryLifecycleMiddleware.php`
- `app/Jobs/QueryCrashWatchdogJob.php`
- `app/Services/Query/QueryEntityLoader.php`
- `app/Services/Query/QueryExecutionService.php`
- `app/Services/Llm/LlmFallbackExecutor.php`

---

### 4. LLM tool calls (MCP tools)

While the LLM is processing the user's question, it can invoke any of these 5 MCP tools. Each tool runs synchronously within the LLM conversation loop (Prism handles the tool-call cycle), but tools dispatch their own async jobs for Slack notifications, pagination, and exports.

#### Tool: `run_sql_query`
**Purpose:** Execute a parameterized SELECT query against the tenant's database.

This is the most common tool — it's how the LLM answers data questions. The tool has two fundamentally different paths depending on whether the query is an **aggregate** (e.g. `SELECT COUNT(*)`) or a **tabular** query (e.g. `SELECT * FROM users`).

`AggregateDetector` inspects the SQL to decide which path to take.

```
run_sql_query
     |
     +---> NotifyToolExecutingJob .............. Posts "Running query..." to Slack
     |
     +---> AggregateDetector: is this an aggregate query?
     |
     +---> [YES — aggregate, e.g. SELECT COUNT(*), SELECT AVG(price)]
     |        |
     |        +---> Executes query directly
     |        +---> Returns scalar result (label + value) to the LLM
     |        |     e.g. { label: "count", value: 42 }
     |        |
     |        |     ** No Slack jobs dispatched — no table, no pagination **
     |        |     The LLM receives the number and crafts a natural-language
     |        |     response like "There are 42 active users." which is later
     |        |     sent to Slack via SlackMessageDispatcher (step 5).
     |        |
     |        +---> [fallback] If the "aggregate" returns multiple rows/columns,
     |              it falls through to the tabular path below.
     |
     +---> [NO — tabular query]
              |
              +---> Probes query with LIMIT 1 to check if rows exist
              |
              +---> [rows > 0]
              |        PaginateQueryJob .......... Builds table UI + pagination buttons
              |             |
              |             +---> QueryAnchorManager ... Posts table + pagination directly
              |                   via SlackMessenger     (no SendSlackBlocks/Attachments jobs)
              |
              +---> [rows = 0]
                       SendNoResultsMessageJob ... Posts "No results found" (2s delay)
```

**Why the split?** Aggregate results are small enough to return inline to the LLM, which lets it weave the answer into a sentence. Tabular results can be thousands of rows — those go straight to Slack as formatted tables via `PaginateQueryJob`, and the LLM just gets a "results posted in thread" acknowledgment. The other key reason is that non-aggregates might contain sensitive or personal data which the LLM shouldn't see — those results go directly to Slack without passing through the LLM again.

**File:** `app/Mcp/RunSqlQueryTool.php`

---

#### Tool: `fetch_table_ddls`
**Purpose:** Fetch CREATE TABLE statements for non-priority tables the LLM needs to inspect.

The LLM uses this when it needs schema details for tables not included in the initial prompt context.

```
fetch_table_ddls
     |
     +---> NotifyToolExecutingJob .............. Posts "Fetching schemas..." to Slack
     |
     (returns DDL data directly to the LLM — no further jobs)
```

**File:** `app/Mcp/FetchTableDdlsTool.php`

---

#### Tool: `request_definition`
**Purpose:** Ask the user to define a business term the LLM doesn't understand.

```
request_definition
     |
     +---> RequestDefinitionJob ................ Posts a "What does X mean?" message to Slack
     |
     (returns "pending" to the LLM — definition will be available in future queries)
```

**File:** `app/Mcp/RequestDefinitionTool.php`

---

#### Tool: `export_csv`
**Purpose:** Export results from a *previous* `run_sql_query` call to CSV and deliver via Slack.

Has three tiers based on row count:

```
export_csv
     |
     +---> [rows <= 1,000 (sync limit, configurable via max_rows_inline_csv)]
     |        Exports synchronously, uploads CSV to Slack thread
     |
     +---> [1,000 < rows <= 2,000,000 (async limit)]
     |        ExportCsvAndDeliverJob ........... Exports in background
     |          +---> SendCsvExportLinkJob ..... Posts download link to Slack
     |        + Slack notification: "Large CSV export queued..."
     |
     +---> [rows > 2,000,000]
              Denied — tells LLM the export is too large
```

**File:** `app/Mcp/ExportCsvTool.php`

---

#### Tool: `run_query_for_csv_export`
**Purpose:** Compose a new SELECT query *and* export its results to CSV in one step.

Same three-tier row-count logic as `export_csv`:

```
run_query_for_csv_export
     |
     +---> NotifyToolExecutingJob .............. Posts "Running query..." to Slack
     |
     +---> [rows <= 1,000 (sync limit, configurable via max_rows_inline_csv)]
     |        Exports synchronously, uploads CSV to Slack thread
     |
     +---> [1,000 < rows <= 2,000,000 (async limit)]
     |        ExportCsvAndDeliverJob ........... Exports in background
     |          +---> SendCsvExportLinkJob ..... Posts download link to Slack
     |        + Slack notification: "Large CSV export queued..."
     |
     +---> [rows > 2,000,000]
              Denied — tells LLM the export is too large
```

**File:** `app/Mcp/RunQueryForCsvExportTool.php`

---

### 5. Results dispatched to Slack

After the LLM finishes (all tool calls complete), results reach Slack through two paths:

#### Path A: LLM text response (explanation, commentary)

`SlackMessageDispatcher.dispatchFromAssistantText()` formats the LLM's text into Slack Block Kit chunks and dispatches:

- **`SendSlackBlocks`** — for text sections (markdown, context blocks)
- **`SendSlackAttachments`** — for inline table blocks (rendered as attachments)

These are separate jobs so Slack's rate limits are respected.

#### Path B: SQL result tables (from RunSqlQueryTool)

`PaginateQueryJob` builds the table UI with pagination buttons and posts them directly to Slack via `QueryAnchorManager` → `SlackMessenger` (synchronous calls within the job, not dispatched as separate jobs).

Both paths can fire during the same query — the LLM text explains what the data means, while the tool results show the actual table.

---

### 6. Feedback survey

If the query completed successfully, `SendFeedbackSurveyJob` fires after a 5-second delay. It sends a thumbs-up/thumbs-down prompt in the Slack thread. The delay ensures it appears _after_ all result messages.

---

## Complete Job Dispatch Chain

Every job that can be dispatched during a single query, in order:

```
[Dispatched by QueryJobDispatcher]
  UserQueryInvokerJob  OR  UserFollowUpQueryJob  [long_queue]
  + QueryCrashWatchdogJob ........................ [default, 300s delay]
    |
    |-- QueryLifecycleMiddleware ................. Sets cache keys (active tracking)
    |
    |-- (on missing datasource) --> SendNoDatasourceNotificationJob
    |-- (on missing LLM)        --> SendNoLlmProviderNotificationJob
    |
    |-- LLM calls MCP tools (any combination, any order):
    |
    |     [run_sql_query]
    |     |-- NotifyToolExecutingJob ............. "Running query..."
    |     |-- [aggregate path — e.g. COUNT/SUM/AVG]
    |     |     Returns scalar to LLM (no Slack jobs).
    |     |     LLM crafts a sentence, sent via SlackMessageDispatcher later.
    |     |-- [tabular path — multi-row results]
    |     |     |-- PaginateQueryJob ............. Builds table + pagination
    |     |     |     +---> QueryAnchorManager ... Posts directly via SlackMessenger
    |     |     |-- SendNoResultsMessageJob ...... "No results" (if 0 rows)
    |
    |     [fetch_table_ddls]
    |     |-- NotifyToolExecutingJob ............. "Fetching schemas..."
    |     (no further jobs — returns data to LLM)
    |
    |     [request_definition]
    |     |-- RequestDefinitionJob ............... "What does X mean?"
    |
    |     [export_csv]
    |     |-- ExportCsvAndDeliverJob ............. (async, >1K rows)
    |     |     +-- SendCsvExportLinkJob ......... Posts download link
    |
    |     [run_query_for_csv_export]
    |     |-- NotifyToolExecutingJob ............. "Running query..."
    |     |-- ExportCsvAndDeliverJob ............. (async, >1K rows)
    |     |     +-- SendCsvExportLinkJob ......... Posts download link
    |
    |-- (after LLM completes):
    |     |-- SendSlackBlocks .................... LLM commentary text
    |     |-- SendSlackAttachments ............... Any inline tables in LLM text
    |
    |-- QueryLifecycleMiddleware (finally) ....... Clears cache keys
    |-- SendFeedbackSurveyJob .................... Thumbs up/down (5s delay)
```

---

## Error Paths

| Error | What Happens | Retries? |
|---|---|---|
| No datasource configured | `SendNoDatasourceNotificationJob` dispatched, job throws | No (1 try) |
| No LLM provider configured | `SendNoLlmProviderNotificationJob` dispatched, job throws | No (1 try) |
| All LLM providers fail | Error status saved, error message sent to Slack | No — handled gracefully |
| Slack API failure on results | Job throws, retried by queue | Yes (3 tries) |
| Concurrent duplicate event | Cache lock prevents execution, job silently exits | N/A |
| CSV export too large (>2M rows) | Denied — LLM told export is too large | N/A |
| Job crash (OOM, worker killed) | `QueryCrashWatchdogJob` detects missing cache key + non-terminal status, marks ERROR | N/A — watchdog auto-detects |
| Unrecoverable exception | `QueryLifecycleMiddleware` marks query ERROR, prevents retry | No — fails immediately |

---

## Key Design Decisions

**Why jobs dispatching jobs?**
- Slack has strict rate limits (~1 msg/sec per channel). Separate jobs let the queue worker space out messages naturally.
- LLM tool calls happen mid-stream. The tool dispatches notification/result jobs while the LLM continues thinking.
- Decoupling means each job is small, testable, and independently retryable.

**Why two separate query jobs instead of one?**
- Initial queries need schema context + definitions in the prompt.
- Follow-ups need the full conversation ledger (previous queries + tool calls).
- Different prompt generation commands, same execution path via `QueryExecutionService`.

**Why `afterCommit`?**
- The Query record must exist in the database before the worker picks up the job. Without `afterCommit`, a race condition could cause the job to run before the transaction commits.

**Why cache locks?**
- Slack retries webhook delivery if it doesn't get a 200 within 3 seconds. This can cause duplicate job dispatches. The lock ensures only one worker processes a given query.

**Why the three-tier CSV export?**
- Small exports (≤1K rows, configurable): fast enough to do synchronously within the tool call.
- Medium exports (1K–2M rows): dispatched to `ExportCsvAndDeliverJob` so the LLM isn't blocked waiting. Once complete, `SendCsvExportLinkJob` posts the download link to Slack.
- Huge exports (>2M rows): denied entirely to protect system resources.

**Why `QueryLifecycleMiddleware` + `QueryCrashWatchdogJob`?**
- The middleware sets cache keys when a query job starts and clears them when it finishes (in a `finally` block). This lets external consumers know whether a query is actively being processed.
- The watchdog job is dispatched with a 300-second delay alongside every query job. When it fires, it checks the cache key:
  - **Key present** → job is still running, re-dispatch the watchdog for another check.
  - **Key absent + non-terminal status** → job crashed (OOM, worker kill, etc.), mark query as ERROR and log details. Also searches `failed_jobs` table for the exception.
  - **Terminal status** → query completed normally, nothing to do.
- Together they provide crash detection for scenarios where a job silently disappears without throwing an exception (e.g., worker OOM-killed). The middleware handles clean failures; the watchdog handles dirty ones.
