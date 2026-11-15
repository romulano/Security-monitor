@extends('emails.layout', ['title' => 'Sua conta de empresa no Security Monitor'])

@section('body')
    <p style="margin:0 0 12px;">Olá, {{ $name }}.</p>
    <p style="margin:0 0 20px;">Você administra a conta de <strong>{{ $company }}</strong> no Security Monitor. Para entrar, escolha a sua senha:</p>
    <p style="margin:0 0 20px;">
        <a href="{{ $url }}" style="display:inline-block;background:#f5b301;color:#0e1117;text-decoration:none;font-weight:600;padding:10px 18px;border-radius:8px;">Escolher a senha</a>
    </p>
    <p style="margin:0 0 12px;font-size:13px;color:#6b7280;">O link vale por {{ $days }} dias e uma vez só. Escolher a senha pelo link também confirma este e-mail.</p>
    <p style="margin:0 0 12px;font-size:13px;color:#6b7280;word-break:break-all;">Se o botão não abrir, copie este endereço no navegador:<br>{{ $url }}</p>
    <p style="margin:0;font-size:13px;color:#6b7280;">Não esperava este e-mail? Ignore: sem a senha, ninguém entra na conta.</p>
@endsection
