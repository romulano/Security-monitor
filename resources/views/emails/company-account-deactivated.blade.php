@extends('emails.layout', ['title' => 'Conta removida'])

@section('body')
    <p style="margin:0 0 12px;">Olá, {{ $name }}.</p>
    <p style="margin:0 0 12px;">Quem administra a conta de <strong>{{ $company }}</strong> no Security Monitor removeu a sua conta. Você não tem mais acesso.</p>
    {{-- TODO(E5a): quando a posse passar para a empresa (E3a, E5a), dizer "O que você fez na conta fica com a empresa."; até lá a poda apaga em cascata. --}}
    <p style="margin:0 0 12px;">A empresa pode reativar a sua conta em até {{ $days }} dias. Depois disso, a conta é apagada.</p>
    <p style="margin:0;font-size:13px;color:#6b7280;">Acha que foi engano? Fale com quem administra a conta da empresa.</p>
@endsection
