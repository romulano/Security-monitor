Olá, {{ $name }}.

{{ $admin }}, que administra a conta de {{ $company }} no Security Monitor, passou a exigir a verificação em duas etapas.

No próximo acesso, o Security Monitor vai pedir que você configure um app autenticador no celular (Google Authenticator, Microsoft Authenticator, 1Password, Aegis ou outro) antes de qualquer outra coisa. Leva um minuto.

{!! $url !!}

Não esperava este e-mail? Fale com quem administra a conta da sua empresa.

--
Security Monitor · {{ config('app.url') }}
