@extends('emails.layout', ['title' => 'Confirme o seu e-mail'])

@section('body')
    <p style="margin:0 0 12px;">Olá, {{ $name }}.</p>
    <p style="margin:0 0 20px;">Para usar os alertas do Security Monitor, confirme que este e-mail é seu:</p>
    <p style="margin:0 0 20px;">
        <a href="{{ $url }}" style="display:inline-block;background:#f5b301;color:#0e1117;text-decoration:none;font-weight:600;padding:10px 18px;border-radius:8px;">Confirmar e-mail</a>
    </p>
    <p style="margin:0 0 12px;font-size:13px;color:#6b7280;">O link vale por {{ $minutes }} minutos. Se ele vencer, entre no Security Monitor e peça outro na sua conta.</p>
    <p style="margin:0 0 12px;font-size:13px;color:#6b7280;word-break:break-all;">Se o botão não abrir, copie este endereço no navegador:<br>{{ $url }}</p>
    <p style="margin:0;font-size:13px;color:#6b7280;">Não criou uma conta no Security Monitor? Ignore este e-mail: sem a confirmação, a conta não usa nada.</p>
@endsection
