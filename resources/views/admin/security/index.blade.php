@extends('layouts.admin')

@section('title', 'Segurança dos sites')

@section('content')
    @php
        $input = 'w-full rounded-lg border border-ink-800 bg-ink-850 px-3 py-2 text-sm focus:border-accent';
        $label = 'mb-1 block text-xs tracking-widest text-ink-700 uppercase';
        $when = fn ($date) => $date?->timezone('America/Sao_Paulo')->format('d/m H:i') ?? '—';
    @endphp

    <div class="mb-6">
        <h1 class="text-sm font-semibold text-white">Segurança dos sites</h1>
        <p class="max-w-3xl text-xs leading-relaxed text-ink-700">
            Vigia os sites que você cadastrar: certificado e validade, HTTPS e cabeçalhos de segurança, cookies, e-mail do domínio
            (SPF e DMARC), vencimento do domínio e, nos sites com posse comprovada, arquivos que não deveriam ser públicos
            (<code class="font-mono">/.env</code>, <code class="font-mono">/.git</code>). É <strong class="font-medium text-ink-400">só leitura</strong>:
            GET, DNS e um handshake TLS, nunca em endereço interno, e o Security Monitor não guarda o conteúdo das páginas.
            Uma varredura por site a cada {{ intdiv(config('secmon.security.interval_minutes'), 60) }} h.
        </p>
    </div>

    @if ($card['stale'])
        <div class="mb-5 rounded-lg border border-red-800/60 bg-red-950/40 px-4 py-3 text-sm text-red-300">
            Sem varredura há mais de {{ $staleHours }} h em algum site ligado. O agendador ou a fila <code class="font-mono">security</code>
            pode ter parado: confira <code class="font-mono">docker exec secmon-supervisor supervisorctl -c /etc/supervisor/secmon.conf status</code>.
        </div>
    @endif

    <div class="mb-6 overflow-hidden rounded-lg border border-ink-800 bg-ink-900">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-ink-800 text-[11px] tracking-widest text-ink-700 uppercase">
                <tr>
                    <th class="px-4 py-2 font-medium">Site</th>
                    <th class="px-4 py-2 font-medium">Estado</th>
                    <th class="px-4 py-2 font-medium">Nota</th>
                    <th class="hidden px-4 py-2 font-medium sm:table-cell">Problemas</th>
                    <th class="hidden px-4 py-2 font-medium md:table-cell">Última</th>
                    <th class="hidden px-4 py-2 font-medium md:table-cell">Posse</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-ink-800">
                @forelse ($sites as $site)
                    <tr class="{{ $site->enabled ? '' : 'opacity-60' }}">
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.security.show', $site) }}" class="font-medium text-white hover:text-accent">{{ $site->host }}</a>
                            @unless ($site->enabled)
                                <span class="ml-1 text-[11px] text-ink-700">pausado</span>
                            @endunless
                        </td>
                        <td class="px-4 py-3">@include('admin.security._state', ['status' => $site->last_status])</td>
                        <td class="px-4 py-3 font-mono text-ink-200">{{ $site->last_status && $site->last_status !== 'erro' ? $site->last_score.'/100' : '—' }}</td>
                        <td class="hidden px-4 py-3 sm:table-cell">
                            @if ($site->open_critical)
                                <span class="text-red-300">{{ $site->open_critical }} crítico(s)</span>
                                <span class="text-ink-700">· {{ $site->open_findings }} no total</span>
                            @elseif ($site->open_findings)
                                <span class="text-amber-300">{{ $site->open_findings }}</span>
                            @else
                                <span class="text-ink-700">nenhum</span>
                            @endif
                        </td>
                        <td class="hidden px-4 py-3 text-xs text-ink-400 md:table-cell">{{ $when($site->last_check_at) }}</td>
                        <td class="hidden px-4 py-3 text-xs md:table-cell">
                            @if ($site->ownership)
                                <span class="text-emerald-300">comprovada</span>
                            @else
                                <span class="text-amber-300">pendente</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-6 text-sm text-ink-400">Nenhum site ainda. Cadastre o primeiro abaixo.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <details class="rounded-lg border border-ink-800 bg-ink-900 p-4" @if ($errors->any() || $sites->isEmpty()) open @endif>
        <summary class="cursor-pointer text-sm font-medium text-ink-200">Cadastrar um site ({{ $sites->count() }}/{{ $max }})</summary>

        <form method="POST" action="{{ route('admin.security.store') }}" class="mt-4 grid max-w-2xl gap-3">
            @csrf
            <label class="block">
                <span class="{{ $label }}">Site</span>
                <input name="host" required maxlength="253" placeholder="exemplo.com.br" value="{{ old('host') }}" class="{{ $input }}">
                <span class="mt-1 block text-[11px] text-ink-700">Só o nome do site (com <code class="font-mono">www.</code> se for ele que você quer vigiar). IP, porta e endereço interno são recusados.</span>
            </label>
            <label class="flex items-start gap-2 text-sm text-ink-400">
                <input type="checkbox" name="consent" value="1" required @checked(old('consent')) class="mt-1">
                <span>Sou o responsável por este site e autorizo o Security Monitor a verificá-lo (certificado, cabeçalhos, DNS e, com posse comprovada, arquivos expostos).</span>
            </label>
            <div>
                <button class="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-ink-950 hover:brightness-110">Cadastrar</button>
            </div>
        </form>
    </details>
@endsection
