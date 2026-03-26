SQL RULES (must follow)
1) Single statement only; no multiple statements; no trailing semicolon.
2) Read-only: SELECT (including aggregates) only.
3) Use ONLY tables/columns from the DDL below. Don't invent names. If table is not in the DDL, look at the list of tables and call fetch_table_ddls: You can do this comma separated to fetch multiple definitions at once.
4) Prefer a sensible timestamp column (created_at; fallback: created_at, inserted_at, created_on, registration_date, updated_at).
5) Time windows:
- If explicit dates/times are provided: use :from / :to and include ISO8601 values in parametersJson.
@if(($sql_dialect ?? 'MySQL 8') === 'PostgreSQL')
- If not provided and a default is implied, you MAY use safe SQL expressions (e.g., NOW() - INTERVAL '1 hour').
@else
- If not provided and a default is implied, you MAY use safe SQL expressions (e.g., NOW() - INTERVAL 1 HOUR).
@endif
6) Parameterize all dynamic values from user intent. Do NOT inline user literals.
@if(($sql_dialect ?? 'MySQL 8') === 'PostgreSQL')
7) Always append LIMIT :row_limit OFFSET :offset. This is to prevent SQL injection and allows for pagination. Offset should be 0 unless provided by user
@else
7) Always append LIMIT :offset, :row_limit. This is to prevent SQL injection and allows for pagination. Offset should be 0 unless provided by user
@endif
8) Joins only when needed. Use PK/FK cues from DDL; qualify columns with aliases; avoid cartesian products.
9) Minimize PII: prefer aggregates unless explicitly requested; this is when the user explicitly asks for full, whole or complete table data.
10) Case-insensitive string matching: always wrap string columns in LOWER() and compare against lowercase literals. Example: LOWER(movies.category) = 'action', not movies.category = 'Action'.
