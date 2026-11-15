@extends('emails.layout', ['title' => 'Limite da empresa'])

@section('body')
    <p style="margin:0 0 12px;">Olá, {{ $name }}.</p>
    @if ($level >= 100)
        <p style="margin:0 0 12px;">A conta de <strong>{{ $company }}</strong> no Security Monitor chegou ao limite de <strong>{{ $max }} {{ $what }}</strong>{{ $note }}. {{ $action }}</p>
    @else
        <p style="margin:0 0 12px;">A conta de <strong>{{ $company }}</strong> no Security Monitor já usa <strong>{{ $used }} de {{ $max }} {{ $what }}</strong>{{ $note }}. Quando chegar a {{ $max }}, não dá para cadastrar mais até remover algum.</p>
    @endif
    <p style="margin:0 0 20px;">{{ $more }}</p>
    <p style="margin:0 0 20px;">
        <a href="{{ $url }}" style="display:inline-block;background:#f5b301;color:#0e1117;text-decoration:none;font-weight:600;padding:10px 18px;border-radius:8px;">{{ $button }}</a>
    </p>
    <p style="margin:0;font-size:13px;color:#6b7280;">{{ $why }}</p>
@endsection
