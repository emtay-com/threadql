## Steps so far (context ledger)

@foreach($steps as $index => $step)
{{ $index + 1 }}. {!! $step !!}
@endforeach
