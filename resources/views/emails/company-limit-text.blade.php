Olá, {{ $name }}.

@if ($level >= 100)
A conta de {{ $company }} no Security Monitor chegou ao limite de {{ $max }} {{ $what }}{{ $note }}. {{ $action }}
@else
A conta de {{ $company }} no Security Monitor já usa {{ $used }} de {{ $max }} {{ $what }}{{ $note }}. Quando chegar a {{ $max }}, não dá para cadastrar mais até remover algum.
@endif

{{ $more }}

{{ $button }}: {!! $url !!}

{{ $why }}

--
Security Monitor · {{ config('app.url') }}
