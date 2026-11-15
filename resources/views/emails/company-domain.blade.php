@extends('emails.layout', ['title' => $kind === 'lost' ? 'Posse do domínio' : 'TXT do domínio'])

@section('body')
    <p style="margin:0 0 12px;">Olá, {{ $name }}.</p>
    @if ($kind === 'lost')
        <p style="margin:0 0 12px;">O Security Monitor deixou de encontrar o registro TXT de <strong>{{ $domain }}</strong>, da conta de <strong>{{ $company }}</strong>{{ $since ? ' desde '.$since->timezone('America/Sao_Paulo')->format('d/m/Y') : '' }}, e a comprovação de posse do domínio caiu.</p>
        <p style="margin:0 0 12px;">{{ $stopped === [] ? 'Nada do que a empresa monitora dependia dele.' : 'Pararam até a posse voltar: '.implode(', ', $stopped).'.' }}</p>
    @else
        <p style="margin:0 0 12px;">O Security Monitor não encontrou hoje o registro TXT de <strong>{{ $domain }}</strong>, da conta de <strong>{{ $company }}</strong> (ou o DNS dele não respondeu).</p>
        <p style="margin:0 0 12px;">Se o TXT não voltar até <strong>{{ $fallsAt?->timezone('America/Sao_Paulo')->format('d/m/Y') }}</strong>, a comprovação de posse cai, e o que depende dela para (Radar, descoberta e webhook).</p>
    @endif
    <p style="margin:0 0 6px;">Para manter a posse, o DNS de {{ $domain }} precisa ter este registro TXT:</p>
    <p style="margin:0 0 20px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px;background:#eef0f3;padding:8px 10px;border-radius:6px;word-break:break-all;">{{ $txt }}</p>
    <p style="margin:0 0 20px;">
        <a href="{{ $url }}" style="display:inline-block;background:#f5b301;color:#0e1117;text-decoration:none;font-weight:600;padding:10px 18px;border-radius:8px;">Ver os domínios da empresa</a>
    </p>
    <p style="margin:0;font-size:13px;color:#6b7280;">Você recebe este aviso porque configura este domínio na conta da empresa.</p>
@endsection
