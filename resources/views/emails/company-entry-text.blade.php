Olá, {{ $name }}.

Você administra a conta de {{ $company }} no Security Monitor. Para entrar, escolha a sua senha abrindo o link abaixo:

{!! $url !!}

O link vale por {{ $days }} dias e uma vez só. Escolher a senha pelo link também confirma este e-mail.

Não esperava este e-mail? Ignore: sem a senha, ninguém entra na conta.

--
Security Monitor · {{ config('app.url') }}
