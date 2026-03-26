## Definitions

@foreach($definitions as $definition)
{{ $definition['subject'] }} => {{ $definition['definition'] }}
@endforeach
