Olá.

{{ $inviter ?? 'Quem administra a conta' }} convidou você para a conta de {{ $company }} no Security Monitor. Para entrar, crie a sua conta pelo link abaixo:

{!! $url !!}

O convite vale por {{ $days }} dias e uma vez só. Aceitar pelo link também confirma este e-mail.

Não esperava este convite? Ignore: sem aceitar, nenhuma conta é criada.

--
Security Monitor · {{ config('app.url') }}
