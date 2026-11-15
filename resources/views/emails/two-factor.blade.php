@extends('emails.layout', ['title' => 'Verificação em duas etapas'])

@section('body')
    <p style="margin:0 0 12px;">Olá, {{ $name }}.</p>
    <p style="margin:0 0 12px;">A verificação em duas etapas da sua conta {{ \App\Mail\TwoFactorChanged::EVENTS[$event][0] }} em {{ $when }}. {{ \App\Mail\TwoFactorChanged::EVENTS[$event][1] }}</p>
    @if ($event === 'reset')
        <p style="margin:0 0 20px;">Não pediu isso? Fale agora com quem administra a conta da sua empresa: alguém pode estar tentando entrar na sua conta. Se precisar, escreva para {{ config('secmon.contact_email') }}.</p>
    @else
        <p style="margin:0 0 20px;">Não foi você? Troque a sua senha agora e escreva para {{ config('secmon.contact_email') }}.</p>
    @endif
    <p style="margin:0 0 20px;">
        <a href="{{ $url }}" style="display:inline-block;background:#f5b301;color:#0e1117;text-decoration:none;font-weight:600;padding:10px 18px;border-radius:8px;">Abrir a minha conta</a>
    </p>
    <p style="margin:0;font-size:13px;color:#6b7280;word-break:break-all;">Se o botão não abrir, copie este endereço no navegador:<br>{{ $url }}</p>
@endsection
