{{-- Date Particles Protocol --}}
Date ranges MUST be represented with two named parameters: *_start and *_end.
- Always use end-exclusive intervals: [start, end).
- SQL must reference these parameters (example):
  WHERE {!! $ts_column ?? '<timestamp_column>' !!} >= :today_start AND {!! $ts_column ?? '<timestamp_column>' !!} < :today_end
- For every placeholder you introduce (e.g., :today_start, :today_end), include exact MySQL timestamps in parameters.

Timezones:
- Interpret relative phrases (e.g., "today", "yesterday", "last week") in the TENANT timezone ({{ $tenant_timezone }}).
- Convert the resulting start/end to the DATASOURCE timezone ({{ $datasource_timezone }}) when emitting the final timestamps.
- Output timestamp strings in MySQL format: Y-m-d H:i:s

Examples (illustrative; you must compute the exact values based on "System time"):
1) today_start / today_end
2) yesterday_start / yesterday_end
3) last_week_start / last_week_end   {{-- week per "Start of week" + "Week definition" above --}}
4) last_week_wed_start / last_week_wed_end (24h window for last week's Wednesday)
5) this_month_start / this_month_end, last_month_start / last_month_end
6) arbitrary calendar days mentioned (e.g., "April 14") → that day’s 00:00:00 to next day 00:00:00 (tenant tz), then converted to datasource tz.

If the date phrase is ambiguous (e.g., "last quarter", "recently"), ask for a short clarification using the request_definition tool with subject "date range".

Validation rules:
- Always supply BOTH *_start and *_end for every range.
- NEVER inline user-provided literal dates directly into SQL; always parameterize.
- The application binds :row_limit; do NOT send a row_limit parameter.
