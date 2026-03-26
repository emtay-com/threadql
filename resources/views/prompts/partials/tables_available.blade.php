## Other tables available (no DDL included) — call `fetch_table_ddls` to load

@foreach($tables as $t)
@if(is_array($t))
- {{ $t['name'] }}@if(isset($t['row_count']) && $t['row_count'] !== null) (~{{ number_format($t['row_count']) }} rows)@endif @if(isset($t['size_mb']) && $t['size_mb'] !== null) ({{ $t['size_mb'] }} MB)@endif

@else
- {{ $t }}
@endif
@endforeach
