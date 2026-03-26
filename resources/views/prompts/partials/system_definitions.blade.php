{{-- System Definitions — injected into every prompt --}}
System time (UTC now): {{ $now_utc }}
Tenant timezone: {{ $tenant_timezone }}
Datasource timezone: {{ $datasource_timezone }}

Start of week: {{ strtolower($start_of_week) }}   {{-- e.g., monday --}}
Week definition: {{ strtolower($week_definition) }} {{-- 'iso' (Mon–Sun) or 'us' (Sun–Sat) --}}
Notes:
- All date ranges are end-exclusive [start, end).
- Compute user-intent ranges in the tenant timezone, then convert to the datasource timezone for final SQL timestamps.
- Output format MUST be ISO datetime: Y-m-d H:i:s.
