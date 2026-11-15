Olá, {{ $name }}.

As últimas {{ $failures }} entregas no {{ $webhook }} ({{ $host }}) falharam, e o Security Monitor parou de enviar para ele.

O último erro: {{ $error }}

Confira o seu servidor e envie um teste pela página do webhook. Se o teste der certo, o envio volta sozinho. Os alertas desse tempo continuam guardados no Security Monitor.

{!! $url !!}

--
Security Monitor · {{ config('app.url') }}
