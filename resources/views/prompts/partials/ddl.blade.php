## Database Schema

Available tables: {{ implode(', ', array_column($ddls, 'table')) }}

@foreach($ddls as $ddl)
-- {{ $ddl['table'] }}@if(isset($ddl['row_count']) && $ddl['row_count'] !== null) (~{{ number_format($ddl['row_count']) }} rows)@endif @if(isset($ddl['size_mb']) && $ddl['size_mb'] !== null) ({{ $ddl['size_mb'] }} MB)@endif

{{ $ddl['ddl'] }}
@endforeach
