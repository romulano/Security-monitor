@extends('emails.layout', ['title' => 'O webhook parou de receber'])

@section('body')
    <p style="margin:0 0 12px;">Olá, {{ $name }}.</p>
    <p style="margin:0 0 12px;">As últimas {{ $failures }} entregas no <strong>{{ $webhook }}</strong> ({{ $host }}) falharam, e o Security Monitor parou de enviar para ele.</p>
    <p style="margin:0 0 12px;">O último erro: {{ $error }}</p>
    <p style="margin:0 0 20px;">Confira o seu servidor e envie um teste pela página do webhook. Se o teste der certo, o envio volta sozinho. Os alertas desse tempo continuam guardados no Security Monitor.</p>
    <p style="margin:0 0 20px;">
        <a href="{{ $url }}" style="display:inline-block;background:#f5b301;color:#0e1117;text-decoration:none;font-weight:600;padding:10px 18px;border-radius:8px;">Abrir o webhook</a>
    </p>
    <p style="margin:0;font-size:13px;color:#6b7280;word-break:break-all;">Se o botão não abrir, copie este endereço no navegador:<br>{{ $url }}</p>
@endsection
