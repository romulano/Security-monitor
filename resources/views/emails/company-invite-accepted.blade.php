@extends('emails.layout', ['title' => 'Convite aceito'])

@section('body')
    <p style="margin:0 0 12px;">Olá, {{ $name }}.</p>
    <p style="margin:0 0 12px;">{{ $member }} ({{ $memberEmail }}) aceitou o convite e entrou na conta de <strong>{{ $company }}</strong> no Security Monitor.</p>
    <p style="margin:0;font-size:13px;color:#6b7280;">Não reconhece essa pessoa? Remova a conta em Contas da empresa, no Security Monitor.</p>
@endsection
