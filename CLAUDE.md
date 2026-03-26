# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

ThreadQL is a multi-tenant Laravel application that provides natural language to SQL query capabilities via Slack. It uses LLMs (via Prism PHP) to convert user questions into SQL queries, execute them against configured datasources, and return formatted results through Slack. The application also implements MCP (Model Context Protocol) to expose tools for external AI systems.

- **Documentation**: [threadql.com](https://threadql.com)
- **GitHub**: [github.com/emtay-com/threadql](https://github.com/emtay-com/threadql)

## Development Environment

### Docker Setup
The application runs in Docker with the following services:
- **threadql**: PHP 8.4-FPM application container (port 9000)
- **worker**: Background queue worker for async job processing
- **nginx**: Web server (port 80)
- **mysql**: MySQL 8.0 database (port 3306)
- **redis**: Redis cache and queue backend (port 6379)
- **ollama**: Local LLM service (port 11434)
- **ssh-tunnel**: SSH tunnel service for remote database access (port 8092)

### Starting the Application
```bash
# Start all services
make up

# Stop all services
make down

# Access application shell
make bash

# Run Laravel Tinker
make tinker
```

### Running Tests
```bash
# Run all tests
make test

# Or directly with run-tests.sh
./run-tests.sh

# Or via composer
composer test

# Tests run in the `threadql_test` database
# PHPUnit configuration is in phpunit.xml.dist
```

### Code Quality
```bash
# Run Easy Coding Standard to fix code style
make ecsfix

# Laravel Pint is also available
./vendor/bin/pint

# Static analysis
./vendor/bin/phpstan analyse
```

### Development Workflow
```bash
# Run concurrent dev servers (via composer)
composer dev
# Starts: artisan serve, queue:listen, pail (logs), and npm dev (vite)

# After code changes, reload services
make reload  # Runs config:cache + restarts worker container

# View logs
make log-tail        # Laravel logs
make recent-logs     # Last 250 lines
make worker-tail     # Worker logs
```

### Database Operations
```bash
# Run migrations
make migrate
php artisan migrate

# Queue operations
make queue           # Listen to queue
php artisan queue:listen --tries=1
```

## Core Architecture

### Command/Handler Pattern (CQRS-lite)
The application uses a command-based architecture for domain operations:

- **Commands** (`app/Command/`): Immutable data structures representing domain operations
  - Example: `ExecuteParameterizedSelectCommand`, `CreateDefinitionCommand`
  - Each command has a corresponding `*Response` class in `app/Command/Results/`

- **CommandHandlers** (`app/CommandHandlers/`): Execute commands and return responses
  - Implement `DomainCommandHandler` interface
  - Invokable classes: `__invoke(Command $command): Response`
  - Automatically discovered by `CommandHandlerServiceProvider`

- **CommandBus** (`app/Infrastructure/Command/`): Routes commands to handlers
  - `CommandHandlerLocator` discovers handlers by convention: `{CommandName}` → `{CommandName}Handler`
  - Handlers are preloaded at application boot

### Multi-Tenancy
Every request is scoped to a tenant via UUID:
- API routes: `/{tenant:uuid}/slack/...`
- **Tenant** model contains:
  - Slack credentials (bot token, signing secret, etc.) - encrypted
  - LLM provider configuration
  - Associated datasources, tables
- **Datasource** model: Connection details for target databases (also encrypted)
- All queries must be scoped to authenticated tenant
- Never trust tenant ID from request body; always use route binding

### Slack Integration
Three main endpoints for Slack interaction:

1. **Events API** (`/slack/events`): Handles app mentions, DMs
   - `SlackEventsController` creates Query and Thread records
   - Dispatches `UserQueryInvokerJob` to process with LLM
   - `FollowUpDetector` identifies replies in existing threads (with duplicate post prevention)

2. **Slash Commands** (`/slack/commands`): `/define`, `/list`, `/help`, etc.
   - `SlackSlashController` routes to command handlers via `SlackCommandFactory`
   - Commands: `DefineCommandCreator`, `ListCommandCreator`, `ShowHelpCommandCreator`, `SurveyToggleCommandCreator`, `DebugToggleCommandCreator`

3. **Interactive Components** (`/slack/interactive`): Button clicks, modals
   - `SlackInteractiveController` handles pagination, feedback, definition requests

All Slack endpoints validate signatures via `ValidateSlackSignature` middleware.
Slack user access controlled via `EnsureSlackUserApproved` middleware.

### Job Queue Architecture
Background jobs implement `ShouldQueue` with these patterns:
- **All jobs must be idempotent** (safe to retry)
- Define `$tries`, `$backoff`, and `$timeout` properties
- Use `FailOnUnrecoverableException` middleware to prevent retries on unrecoverable errors
- Use `QueryLifecycleMiddleware` on query jobs for crash detection

Key jobs:
- **UserQueryInvokerJob**: Processes initial user query with LLM
- **UserFollowUpQueryJob**: Handles follow-up queries in existing thread
- **QueryCrashWatchdogJob**: Monitors for stuck/crashed queries (dispatched with 300s delay alongside every query job)
- **PaginateQueryJob**: Handles result pagination with Slack anchor system
- **TableSchemaCrawlerJob**: Crawls database schemas for RAG
- **ExportCsvAndDeliverJob**: CSV export and delivery
- **GenerateSqlFromQueryJob**: SQL generation from natural language
- **NotifyToolExecutingJob**: In-flight tool execution notifications
- **SendSlackBlocks / SendSlackAttachments**: Async Slack message dispatch
- **SendFeedbackSurveyJob**: Sends feedback prompts after queries
- **SendNoResultsMessageJob**: Notifies when queries return empty results
- **SendNoDatasourceNotificationJob / SendNoLlmProviderNotificationJob**: Configuration error notifications
- **RequestDefinitionJob**: Creates definition requests

Queue configuration uses Redis in production, database driver in tests.

### Query Crash Detection & Lifecycle
The system uses a watchdog pattern to detect crashed queries:

- **QueryLifecycleMiddleware**: Wraps query jobs, sets cache keys (`query-active:{threadId}:{queryId}` and `thread-active-query:{threadId}`) with 1440s TTL. Clears keys in `finally` block. Catches `UnrecoverableJobException` and sets ERROR status.
- **QueryCrashWatchdogJob**: Dispatched alongside every query job with 300s delay. Checks cache keys to determine if query is still running, completed, or crashed. Re-dispatches itself if still running. Marks query ERROR if cache keys gone but status non-terminal.
- **QueryCacheKeyManager**: Injectable service for cache key operations.
- **QueryJobContract**: Interface implemented by query jobs, providing thread/query ID access.

### LLM Integration (Prism PHP)
- Uses **prism-php/prism** for multi-provider LLM support
- `PrismProviderMapper`: Maps tenant's LLM provider to Prism provider
- `LlmProviderResolver`: Resolves provider for a tenant
- `LlmFallbackExecutor`: Handles provider fallback logic
- `FallbackExceptionClassifier`: Classifies exceptions for retry decisions
- `ProviderOptionsResolver`: Resolves provider-specific options
- Supports: Anthropic, OpenAI, Gemini, Ollama, etc.
- Tool calling via MCP for SQL execution, schema introspection, CSV export

**Prompt Building**:
- `InitialPromptView` and `FollowupPromptView` in `app/Prompt/Views/`
- `BasePromptView` provides shared prompt structure
- Uses Blade views in `resources/views/prompts/` to construct prompts with:
  - Database schema context
  - Recent query history
  - User-defined definitions (business glossary)
  - Date/time context (tenant timezone)
  - SQL rules (case-insensitive string matching, etc.)
- `PromptLedgerBuilder`: Constructs conversation history from tool calls
- `ToolCallSummarizer` / `SqlCallSummarizer`: Summarize tool results for subsequent prompts

### MCP (Model Context Protocol)
MCP tools are defined in `app/Mcp/`:
- `ThreadqlServer`: MCP server definition
- `RunSqlQueryTool`: Executes SQL queries
- `FetchTableDdlsTool`: Fetches table DDLs for LLM context
- `ExportCsvTool`: Exports query results to CSV
- `RunQueryForCsvExportTool`: Runs queries specifically for CSV export
- `RequestDefinitionTool`: Requests definition creation

**Tool Results** (`app/Mcp/ToolResults/`):
- `RunSqlQueryPayload`, `FetchTableDdlsPayload`, `RequestDefinitionPayload`, `CsvResultDto`
- Tools are called by LLM during query processing
- Results are summarized and included in Slack responses

**Tool Messages** (`app/Support/Messages/`):
- Per-tool message classes: `RunSqlQueryMessages`, `ExportCsvMessages`, `FetchTableDdlsMessages`, etc.
- `InitialResponseMessages` / `FollowupResponseMessages`: Playful response messages

### Domain Models

**Core entities** (`app/Models/`):
- **Tenant**: Multi-tenant organization with Slack + LLM config
- **Datasource**: Database connection (MySQL, PostgreSQL — encrypted credentials)
- **Thread**: Conversation thread in Slack (has multiple queries)
- **Query**: Single user question with LLM response and tool calls
- **ToolCall**: Individual tool invocations during LLM processing
- **Definition**: Business term definitions for domain context
- **Table**: Database schema metadata for RAG
- **Feedback**: User feedback on query results
- **QueryAnchor**: Pinned/saved queries for reference
- **LlmProvider**: LLM provider configuration (encrypted API keys)
- **SlackUser**: Slack user records (with approval status)
- **SlackUserSetting**: Per-user settings (e.g., survey opt-out)
- **TenantSetting**: Per-tenant configuration
- **GeneralSetting**: Global application settings
- **MasterAdmin**: Master admin user
- **User**: Admin panel users

### Enums (`app/Enums/`)
- `QueryStatus`: pending, running, success, error
- `ToolNames`: run_sql_query, export_csv, run_query_for_csv_export, fetch_table_ddls, request_definition
- `MessageRole`: user, assistant, system
- `Queue`: Queue name constants
- `SettingEnum` / `TenantSettingEnum`: Setting key enums
- `SlackEventType` / `SlackSubcommand`: Slack event types
- `UserLevel`: User role levels
- `DatabaseDriver`: mysql, pgsql (in `app/Infrastructure/Database/`)

### Services Layer
- **Services** (`app/Services/`): Domain logic, stateless, inject repositories/clients
- **Export/** services: `CsvDataExporter`, `CsvFileGenerator`, `SlackCsvUploader`
- **Llm/** services: `PromptBuilder`, `FollowUpPromptBuilder`, `PrismProviderMapper`, `LlmProviderResolver`, `LlmFallbackExecutor`, `ProviderOptionsResolver`
- **Llm/Builders/**: `MessageCollectionBuilder`, `PromptDataContextBuilder`
- **Query/** services: `QueryExecutionService`, `QueryEntityLoader`, `QueryStatusCalculator`, `ToolCallPersister`
- **Sql/** services: `AggregateDetector`, `TotalCountEstimator` (supports CTEs, INTERSECT, EXCEPT)
- **Slack/** services: `SlackChannelRateLimiter`

### Infrastructure
- **DynamicDatabaseConnector**: Creates runtime database connections from Datasource models
- **DatabaseStrategyFactory**: Factory for database-specific strategies (MySQL, PostgreSQL)
  - Schema strategies: `MysqlSchemaStrategy`, `PostgresSchemaStrategy`
  - Query timeout strategies: `MysqlQueryTimeoutStrategy`, `PostgresQueryTimeoutStrategy`
- **DsnBuilder / DsnParser**: Connection string handling
- **SshTunnelClient**: SSH tunneling for remote database access
- **SlackClientFactory**: Creates tenant-scoped Slack API clients
- **SlackMessageDispatcher**: Queues Slack message dispatch jobs
- **SlackMessenger**: High-level Slack message sending with anchor management
- **SlackUserResolver / SlackUserSettingService**: Slack user management
- **SlackTableAttachmentBuilder**: Formats SQL result tables for Slack
- **PaginationControlsBuilder**: Builds pagination UI controls
- **JobParamAssigner**: Parameter injection for jobs via `Assignable` attribute

### Formatting and Presentation
- **SlackBlocks** (`app/Infrastructure/Slack/`): Builds Slack Block Kit UI
- **ResponseFormatter** (`app/Slack/Formatting/`): Formats SQL results for Slack
  - Converts result sets to markdown tables
  - Handles pagination controls
  - `TableScanner`: Detects and formats tables in LLM responses

### Middleware (`app/Http/Middleware/`)
- **ValidateSlackSignature**: Validates Slack request signatures
- **HandleSlackRetries**: Handles Slack retry headers
- **EnsureSlackUserApproved**: Checks Slack user approval status
- **EnsureTenantScope**: Ensures tenant context is set
- **EnsureMasterUser**: Restricts to master admin users
- **RestrictToInternalNetwork**: IP whitelisting for internal endpoints

## Admin Panel

The application includes a React-based admin panel for managing tenants, datasources, LLM providers, tables, definitions, Slack users, and settings.

### Admin Frontend Architecture

**Technology Stack**:
- **Framework**: React 19 with React Router DOM
- **State Management**: Zustand
- **Build Tool**: Vite with Laravel Vite Plugin
- **CSS**: Tailwind CSS 4.0
- **HTTP Client**: Axios with JWT authentication

**Directory Structure**:
```
resources/js/admin/
├── AdminApp.jsx                 # Root component with routing
├── routes.js                    # Route definitions
├── components/
│   ├── MainMenu.jsx            # Main navigation bar
│   ├── LoginScreen.jsx         # Authentication screen
│   ├── TenantSidebar.jsx       # Tenant selector + submenu
│   └── ToastProvider.jsx       # Global toast notification system
├── pages/
│   ├── tenants/                # Tenant CRUD pages
│   ├── data-sources/           # Datasource management
│   ├── tables/                 # Table priority/visibility
│   ├── definitions/            # Business glossary management
│   ├── llm-providers/          # LLM provider configuration
│   ├── slack-users/            # Slack user management
│   ├── tenant-settings/        # Per-tenant settings
│   └── SettingsPage.jsx        # Global settings + master user CRUD
├── stores/
│   └── tenantsStore.js         # Zustand store for tenant data
├── constants/                   # Application constants
├── generated/                   # Auto-generated files
├── services/                    # API client services
└── test/                        # Test utilities
```

**Frontend Routes** (all under `/panel`):
- `/panel/tenants` - Tenant list and management
- `/panel/tenants/:tenantId` - View tenant details
- `/panel/tenants/edit/add` - Create new tenant
- `/panel/tenants/edit/:tenantId` - Edit tenant
- `/panel/data-sources/:tenantId` - Datasource management
- `/panel/tables/:tenantId` - Table management
- `/panel/definitions/:tenantId` - Definition management
- `/panel/llm-providers` - LLM provider list
- `/panel/llm-providers/edit/add` - Add LLM provider
- `/panel/llm-providers/edit/:providerId` - Edit provider
- `/panel/slack-users/:tenantId` - Slack user management
- `/panel/tenant-settings/:tenantId` - Per-tenant settings
- `/panel/settings` - Global settings + master user CRUD

### Admin API Endpoints

All admin endpoints require JWT authentication. Controllers are in `app/Http/Controllers/Api/`.

**Authentication**:
| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/admin/token` | Generate JWT token (login) |
| POST | `/admin/token/refresh` | Refresh JWT token |
| POST | `/admin/token/logout` | Logout (invalidate token) |
| GET | `/admin/me` | Get authenticated user info |

**General Settings** (master-only):
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/admin/settings` | List global settings |
| PUT | `/admin/settings` | Update global settings |

**User Management** (master-only):
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/admin/users` | List admin users |
| POST | `/admin/users` | Create admin user |
| PUT | `/admin/users/{user}` | Update admin user |
| DELETE | `/admin/users/{user}` | Delete admin user |

**Tenant Management**:
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/admin/tenants` | List all tenants |
| POST | `/admin/tenants` | Create tenant (master-only) |
| PUT | `/admin/tenants/{tenant:id}` | Update tenant |
| GET | `/admin/tenants/{tenant:id}/manifest` | Get tenant config manifest |
| GET | `/admin/tenants/{tenant:id}/test-slack` | Test Slack connectivity |

**LLM Providers** (tenant-scoped):
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/admin/tenants/{tenant:id}/llm-providers` | List providers |
| POST | `/admin/tenants/{tenant:id}/llm-providers` | Create provider |
| PUT | `/admin/tenants/{tenant:id}/llm-providers/{llmProvider}` | Update provider |
| DELETE | `/admin/tenants/{tenant:id}/llm-providers/{llmProvider}` | Delete provider |
| GET | `/admin/tenants/{tenant:id}/llm-providers/{llmProvider}/ping` | Test provider connectivity |

**Datasources** (tenant-scoped):
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/admin/tenants/{tenant:id}/datasources` | List datasources |
| POST | `/admin/tenants/{tenant:id}/datasources` | Create datasource |
| PUT | `/admin/tenants/{tenant:id}/datasources/{datasource}` | Update datasource |
| POST | `/admin/tenants/{tenant:id}/datasources/test-connection` | Test connection |
| GET | `/admin/tenants/{tenant:id}/datasources/{datasource}/ping` | Ping datasource |
| POST | `/admin/tenants/{tenant:id}/datasources/{datasource}/scan` | Trigger schema scan |

**Tables** (tenant-scoped):
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/admin/tenants/{tenant:id}/tables` | List tables (with soft-deleted) |
| PUT | `/admin/tenants/{tenant:id}/tables/{table}` | Update table priority |
| PATCH | `/admin/tenants/{tenant:id}/tables/{table}` | Restore soft-deleted table |
| DELETE | `/admin/tenants/{tenant:id}/tables/{table}` | Soft delete table |

**Definitions** (tenant-scoped):
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/admin/tenants/{tenant:id}/definitions` | List definitions |
| POST | `/admin/tenants/{tenant:id}/definitions` | Create definition |
| PUT | `/admin/tenants/{tenant:id}/definitions/{definition}` | Update definition |
| DELETE | `/admin/tenants/{tenant:id}/definitions/{definition}` | Delete definition |

**Slack Users** (tenant-scoped):
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/admin/tenants/{tenant:id}/slack-users` | List Slack users |
| PUT | `/admin/tenants/{tenant:id}/slack-users/{slackUser}` | Update Slack user |
| DELETE | `/admin/tenants/{tenant:id}/slack-users/{slackUser}` | Soft delete Slack user |
| PATCH | `/admin/tenants/{tenant:id}/slack-users/{slackUser}` | Restore Slack user |

**Tenant Settings** (tenant-scoped):
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/admin/tenants/{tenant:id}/settings` | List tenant settings |
| PUT | `/admin/tenants/{tenant:id}/settings` | Update tenant settings |

### Admin Payload Classes

Located in `app/Http/Payloads/`:
- Response DTOs for consistent API responses (e.g., `TenantPayload`, `DataSourceCollectionPayload`)
- Standardized JSON structure for frontend consumption

## Coding Standards

### PHP Standards
- **Strict types**: Every PHP file must start with `<?php declare(strict_types=1);`
- **PSR-12** code style (enforced via Laravel Pint and ECS)
- Constructor property promotion, typed properties, readonly where applicable
- PHPDoc required for all public methods (params, return, throws)
- Prefer dependency injection over facades (except in controllers for brevity)
- No logic in controllers—delegate to Actions/Services/CommandHandlers

### Architecture Patterns
- **Controllers**: HTTP-only (validate, call handler/action, return resource)
- **FormRequest**: All input validation (`app/Http/Requests/`)
- **CommandHandlers**: Orchestrate single domain operations
- **Services**: Stateless domain/application logic
- **Jobs**: Background work with idempotency, retries, and backoff
- **Events/Listeners**: Cross-cutting async reactions only

### API Conventions
- Routes in `routes/api.php`
- Route names: `resource.action` (e.g., `slack.events`, `slack.commands`)
- JSON responses should have consistent structure
- Errors: 422 validation, 404 not found, 401/403 auth, 429 rate-limit

### Testing (PHPUnit)
- **Unit tests** in `tests/Unit/`: Services, handlers, repositories, formatters
- **Feature tests** in `tests/Feature/`: HTTP endpoints, job execution, Slack integration
- Use `RefreshDatabase` or `DatabaseTransactions` traits
- Test naming: descriptive method names like `it_creates_a_query_event`, `it_validates_input`
- **No Pest**: This codebase uses PHPUnit
- Mock external APIs (Slack, LLM) in tests
- Frontend tests use Vitest with React Testing Library

### Security
- Never hardcode secrets; use config with env defaults
- Input validation on all endpoints
- Authorize with Policies/Gates where applicable
- Log intent, not secrets; scrub PII
- Rate-limit public endpoints
- Parameterize SQL queries (use PDO bindings)
- Tenant-scoped encryption for sensitive data (Slack tokens, DB passwords, API keys)

### Migrations
- Name with intent: `2025_08_16_120000_create_queries_table.php`
- Zero-downtime patterns:
  - Add nullable columns, backfill, then make not-null
  - Don't drop columns in same release as code using them
- Models: Use `$fillable` or `$guarded`
- Use `$casts` for type casting (JSON, dates, etc.)

### Error Handling
- Throw domain exceptions in services
- Map to HTTP codes in exception handler
- Provide helpful error messages
- Don't expose stack traces in production
- Use `UnrecoverableJobException` to prevent job retries

### Logging
- Use structured logging with context
- Log at info: start/end of operations with correlation IDs
- Log at warning/error: exceptions with context (no secrets)
- Use channels: `stack`, `daily`, `stderr` (for containers)

### Git Practices
- Update `.env.example` when adding env keys
- Conventional commits:
  - `feat(api): add /queries endpoint`
  - `fix(queue): handle timeout in ExecuteQueryJob`
  - `chore(ci): add tests to pipeline`

## Domain Concepts

### Query Lifecycle
1. User mentions bot in Slack or sends DM
2. `SlackEventsController` validates event, `FollowUpDetector` checks for duplicates
3. `QueryCreator` creates Thread (if new) and Query record
4. `QueryJobDispatcher` dispatches `UserQueryInvokerJob` (or `UserFollowUpQueryJob` for replies) + `QueryCrashWatchdogJob` with 300s delay
5. `QueryLifecycleMiddleware` sets cache keys marking query as active
6. Job generates prompt via `GenerateInitialPromptCommand` / `GenerateFollowUpPromptCommand`
7. Prism/LLM called with tools (SQL execution, schema fetch, CSV export, etc.)
8. Tools called as needed; results stored in ToolCall records via `ToolCallPersister`
9. Final response formatted and sent to Slack via `SlackMessageDispatcher`
10. Query marked complete with status (success/error)
11. `QueryLifecycleMiddleware` clears cache keys in `finally` block
12. Optionally, feedback survey sent after query
13. If worker crashes, `QueryCrashWatchdogJob` detects missing cache keys + non-terminal status and marks query ERROR

### Follow-Up Queries
- User replies in Slack thread
- `FollowUpDetector` identifies thread and prevents duplicate posts while query is in-flight
- `UserFollowUpQueryJob` processes with full context (ledger)
- `PromptLedgerBuilder` reconstructs conversation history from previous tool calls
- `ToolCallSummarizer` / `SqlCallSummarizer` provide concise summaries of prior interactions

### Definitions (Business Glossary)
- Users can define business terms via `/define` slash command
- Definitions stored in `definitions` table (tenant-scoped)
- Included in LLM prompts to provide domain context
- Example: "ARR = Annual Recurring Revenue"
- Manageable via admin panel

### Schema Crawling
- `TableSchemaCrawlerJob` runs on-demand (triggered via admin panel datasource scan)
- Fetches table/column metadata from datasources
- Supports MySQL and PostgreSQL via `DatabaseStrategyFactory`
- Stores in `tables` table with priority ranking
- Used for including relevant schema in prompts (RAG-like)

### Pagination
- SQL results paginated (25 rows default, configurable)
- `PaginateQueryJob` handles result delivery with anchor system
- Slack messages include "Next Page" buttons via `PaginationControlsBuilder`
- `QueryAnchorManager` enables in-place message updates for navigation

### Feedback
- After successful queries, feedback survey sent (optional, configurable per user via `SlackUserSetting`)
- User rates query with thumbs up/down
- Feedback stored in `feedback` table with context

### CSV Export
- LLM can trigger CSV export via `ExportCsvTool` / `RunQueryForCsvExportTool`
- `CsvDataExporter` → `CsvFileGenerator` → `SlackCsvUploader` pipeline
- `ExportCsvAndDeliverJob` handles async export and delivery
- Limit: 1,000 rows for sync export

## Common Tasks

### Adding a New Slack Slash Command
1. Create command creator in `app/Slack/Commands/` implementing `CommandCreatorInterface`
2. Register in `SlackCommandFactory`
3. Create corresponding command in `app/Command/Slack/` and handler in `app/CommandHandlers/Slack/`
4. Add tests in `tests/Feature/Slack/`

### Adding a New MCP Tool
1. Create tool class in `app/Mcp/` (e.g., `MyTool`)
2. Define tool result DTO in `app/Mcp/ToolResults/`
3. Register tool in `ThreadqlServer`
4. Create message class in `app/Support/Messages/`
5. Update LLM prompt views to include tool description
6. Add tests in `tests/Feature/Mcp/` and `tests/Unit/Mcp/`

### Adding a New Background Job
1. Create job in `app/Jobs/` implementing `ShouldQueue`
2. Define `$tries`, `$backoff`, `$timeout`
3. Use `FailOnUnrecoverableException` middleware if needed
4. For query jobs: implement `QueryJobContract` and use `QueryLifecycleMiddleware`
5. Ensure idempotency (safe to retry)
6. Dispatch from controller/command handler
7. Add tests in `tests/Unit/Jobs/` and/or `tests/Feature/Jobs/`

### Adding a Database Migration
1. Create migration: `php artisan make:migration create_my_table`
2. Follow zero-downtime patterns (nullable first, then not-null)
3. Update model with fillable, casts, relationships
4. Run migration: `php artisan migrate`
5. Add tests to verify migration structure

### Debugging LLM Calls
```bash
# Debug a specific query
php artisan threadql:debug-llm {query_id}

# View chat/prompt for a query
php artisan threadql:chat-debug {query_id}

# Replay a query (re-run with same inputs)
php artisan threadql:replay-query {query_id}
```

### Extracting Schema
```bash
# Extract schema from a datasource
php artisan threadql:extract-schema {datasource_id}
```

## Configuration

Key config files in `config/`:
- **database.php**: Multi-connection setup for dynamic datasources
- **queue.php**: Redis queue configuration
- **logging.php**: Log channels and formatting
- **llm.php**: LLM provider settings (timeouts, defaults)
- **slack.php**: Slack API settings
- **slack-formatting.php**: Slack message formatting options
- **slack-settings.php**: Slack-specific settings
- **jwt.php**: JWT authentication configuration
- **mcp.php**: MCP server settings
- **pagination.php**: Pagination defaults
- **export.php**: CSV export settings
- **prompt.php**: Prompt template configuration
- **prism.php** / **relay.php**: Prism PHP provider configuration
- **tenant-settings.php**: Tenant setting defaults
- **default_settings.php**: Default global settings
- **threadql.php**: ThreadQL-specific configuration

Environment variables (see `.env.example`):
- `APP_ENV`, `APP_DEBUG`, `APP_KEY`
- `DB_*`: Primary database connection
- `REDIS_*`: Queue and cache
- `JWT_SECRET`: JWT authentication secret
- `MASTER_ADMIN_PASSWORD`: Master admin password

## CI/CD

GitHub Actions workflows in `.github/workflows/`:
- **php.yml**: PHP tests, linting, static analysis (PHPStan)
- **js.yml**: JavaScript/frontend tests (Vitest)
- **build-and-push.yml**: Docker build and push to registry
- **helm.yml**: Helm chart validation and deployment
- **helm-integration.yml**: Helm integration tests
- **release.yml**: Release workflow with versioned image tags

## External Dependencies
- **Slack API**: `jolicode/slack-php-api` v4.9
- **Prism PHP**: Multi-provider LLM client (`prism-php/prism`, `prism-php/relay`)
- **Laravel MCP**: `laravel/mcp` v0.5
- **JWT Auth**: `tymon/jwt-auth` v2.2
- **AWS S3**: `league/flysystem-aws-s3-v3` v3.32
- **Static Analysis**: `larastan/larastan`, `phpstan/phpstan`
- **Laravel Framework**: 12.x
- **PHP**: 8.4
- **MySQL**: 8.0
- **Redis**: 7 (Alpine)

## Useful Artisan Commands
```bash
# MCP server
php artisan mcp:serve --transport=http --port=8091

# Queue worker
php artisan queue:listen --tries=1

# View logs in real-time
php artisan pail --timeout=0

# Tinker REPL
php artisan tinker

# Clear caches
php artisan config:clear
php artisan cache:clear
php artisan clear-compiled

# Debug formatting
php artisan threadql:debug-slack-formatting {query_id}

# Generate app manifest
php artisan threadql:generate-manifest

# Schedule table scans
php artisan threadql:schedule-table-scans

# Generate provider options
php artisan threadql:generate-provider-options

# Test datasource connection
php artisan threadql:test-datasource-connection {datasource_id}

# Update Slack credentials
php artisan threadql:update-slack-credentials

# Anonymize tool calls (privacy)
php artisan threadql:anonymize-tool-calls
```

## Notes on Codebase Evolution
- Originally used "Actions" pattern, now uses Command/Handler pattern
- This is a Slack-first application; admin panel added for management
- LLM context is carefully managed to stay within token limits
- Tool calls are summarized rather than including full results in subsequent prompts
- The codebase uses a "ledger" concept to reconstruct conversation history efficiently
- PostgreSQL support added alongside original MySQL support
- SSH tunneling added for secure remote database access
- Query crash detection system added to handle worker failures gracefully
