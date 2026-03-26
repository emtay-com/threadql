CRITICAL ARGUMENT RULES (must follow)
0) parametersJson MUST be a string containing a JSON object. If you have no parameters, set "parametersJson": "{}".
1) Always include "query_id", "sql", and "parametersJson" for run_sql_query.
2) For every named placeholder in your SQL include a key/value inside parametersJson.
3) Always include LIMIT :offset, :row_limit in SQL. Add row_limit to parametersJson. Use 0 for offset. For queries that are aggregating to a number, use 1. for queries counting and grouping use 16. Otherwise use 25.
4) For request_definition, subject should be a short noun phrase (e.g., "paused member", "intranet orders"). No SQL, no JSON—just the phrase.
