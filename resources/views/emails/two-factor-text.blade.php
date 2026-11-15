Olá, {{ $name }}.

A verificação em duas etapas da sua conta {{ \App\Mail\TwoFactorChanged::EVENTS[$event][0] }} em {{ $when }}. {{ \App\Mail\TwoFactorChanged::EVENTS[$event][1] }}

@if ($event === 'reset')
Não pediu isso? Fale agora com quem administra a conta da sua empresa: alguém pode estar tentando entrar na sua conta. Se precisar, escreva para {{ config('secmon.contact_email') }}.
@else
Não foi você? Troque a sua senha agora e escreva para {{ config('secmon.contact_email') }}.
@endif

{!! $url !!}

--
Security Monitor · {{ config('app.url') }}
