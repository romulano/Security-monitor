@extends('emails.layout', ['title' => 'Convite para o Security Monitor'])

@section('body')
    <p style="margin:0 0 12px;">Olá.</p>
    <p style="margin:0 0 20px;">{{ $inviter ?? 'Quem administra a conta' }} convidou você para a conta de <strong>{{ $company }}</strong> no Security Monitor. Para entrar, crie a sua conta pelo link:</p>
    <p style="margin:0 0 20px;">
        <a href="{{ $url }}" style="display:inline-block;background:#f5b301;color:#0e1117;text-decoration:none;font-weight:600;padding:10px 18px;border-radius:8px;">Aceitar o convite</a>
    </p>
    <p style="margin:0 0 12px;font-size:13px;color:#6b7280;">O convite vale por {{ $days }} dias e uma vez só. Aceitar pelo link também confirma este e-mail.</p>
    <p style="margin:0 0 12px;font-size:13px;color:#6b7280;word-break:break-all;">Se o botão não abrir, copie este endereço no navegador:<br>{{ $url }}</p>
    <p style="margin:0;font-size:13px;color:#6b7280;">Não esperava este convite? Ignore: sem aceitar, nenhuma conta é criada.</p>
@endsection
