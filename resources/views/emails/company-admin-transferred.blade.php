@extends('emails.layout', ['title' => 'Administração da empresa'])

@section('body')
    <p style="margin:0 0 12px;">Olá, {{ $name }}.</p>
    @if ($isNew)
        <p style="margin:0 0 12px;">{{ $formerAdmin }} passou para você a administração da conta de <strong>{{ $company }}</strong> no Security Monitor. Agora você convida pessoas, muda papéis e cuida das configurações da empresa, em Contas da empresa.</p>
    @else
        <p style="margin:0 0 12px;">Você passou a administração da conta de <strong>{{ $company }}</strong> no Security Monitor para {{ $newAdmin }}. A sua conta continua na empresa como membro.</p>
    @endif
    @if ($isNew)
        <p style="margin:0;font-size:13px;color:#6b7280;">Não esperava por isso? Fale com {{ $formerAdmin }} ou escreva para contato@example.com.</p>
    @else
        <p style="margin:0;font-size:13px;color:#6b7280;">Não foi você? Escreva para contato@example.com.</p>
    @endif
@endsection
