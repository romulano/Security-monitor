@extends('emails.layout', ['title' => 'Teste de envio'])

@section('body')
    <p style="margin:0 0 12px;">Este é um e-mail de teste do Security Monitor, pedido em Admin → E-mails em {{ $requestedAt }}.</p>
    <p style="margin:0;">Se ele chegou na caixa de entrada (e não no spam), o servidor de envio, o SPF e o DKIM estão funcionando.</p>
@endsection
