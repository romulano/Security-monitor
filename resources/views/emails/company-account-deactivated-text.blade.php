Olá, {{ $name }}.

Quem administra a conta de {{ $company }} no Security Monitor removeu a sua conta. Você não tem mais acesso.

{{-- TODO(E5a): quando a posse passar para a empresa (E3a, E5a), dizer "O que você fez na conta fica com a empresa."; até lá a poda apaga em cascata. --}}
A empresa pode reativar a sua conta em até {{ $days }} dias. Depois disso, a conta é apagada.

Acha que foi engano? Fale com quem administra a conta da empresa.

--
Security Monitor · {{ config('app.url') }}
