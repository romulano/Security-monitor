@extends('emails.layout', ['title' => 'Verificação em duas etapas'])

@section('body')
    <p style="margin:0 0 12px;">Olá, {{ $name }}.</p>
    <p style="margin:0 0 12px;">{{ $admin }}, que administra a conta de <strong>{{ $company }}</strong> no Security Monitor, passou a exigir a verificação em duas etapas.</p>
    <p style="margin:0 0 20px;">No próximo acesso, o Security Monitor vai pedir que você configure um app autenticador no celular (Google Authenticator, Microsoft Authenticator, 1Password, Aegis ou outro) antes de qualquer outra coisa. Leva um minuto.</p>
    <p style="margin:0 0 20px;">
        <a href="{{ $url }}" style="display:inline-block;background:#f5b301;color:#0e1117;text-decoration:none;font-weight:600;padding:10px 18px;border-radius:8px;">Configurar agora</a>
    </p>
    <p style="margin:0 0 12px;font-size:13px;color:#6b7280;word-break:break-all;">Se o botão não abrir, copie este endereço no navegador:<br>{{ $url }}</p>
    <p style="margin:0;font-size:13px;color:#6b7280;">Não esperava este e-mail? Fale com quem administra a conta da sua empresa.</p>
@endsection
