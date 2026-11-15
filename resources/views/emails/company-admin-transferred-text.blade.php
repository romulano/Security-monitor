Olá, {{ $name }}.

@if ($isNew)
{{ $formerAdmin }} passou para você a administração da conta de {{ $company }} no Security Monitor. Agora você convida pessoas, muda papéis e cuida das configurações da empresa, em Contas da empresa.
@else
Você passou a administração da conta de {{ $company }} no Security Monitor para {{ $newAdmin }}. A sua conta continua na empresa como membro.
@endif

@if ($isNew)
Não esperava por isso? Fale com {{ $formerAdmin }} ou escreva para contato@example.com.
@else
Não foi você? Escreva para contato@example.com.
@endif

--
Security Monitor · {{ config('app.url') }}
