@extends('layouts.app')

@section('title', $finding->repo.' · GitHub · Security Monitor')

@section('subheader')
    @include('alerts.partials.nav')
@endsection

@php
    use App\Models\GithubFinding;
    use App\Services\Github\GithubMessages;

    $level = $finding->level();
    $held = $finding->heldByAi();
    $action = GithubMessages::action($finding);
    $date = fn ($d) => $d?->timezone('America/Sao_Paulo')->format('d/m/Y H:i');
    $button = 'inline-flex min-h-11 items-center rounded-lg px-4 text-sm';
@endphp

@section('content')
    <div class="space-y-5">
        <div>
            @include('alerts.partials.crumbs', ['parent' => ['GitHub', route('alerts.github', $monitor->urlParams())], 'here' => 'Achado'])
            <div class="flex flex-wrap items-center gap-2">
                @if ($level === 'none')
                    <span class="rounded border border-ink-800 px-2 py-0.5 text-[11px] font-medium tracking-wide text-ink-400 uppercase">não é achado</span>
                @else
                    <x-criticity :level="$level" />
                @endif
                <span class="rounded-full border border-ink-700 px-2.5 py-0.5 text-xs text-ink-200">{{ $finding->kindLabel() }}</span>
                <span class="rounded-full border px-2.5 py-0.5 text-xs {{ $held ? 'border-violet-700/70 bg-violet-950/60 text-violet-200' : 'border-ink-700 text-ink-400' }}">{{ $finding->statusLabel() }}</span>
                @if ($finding->gone_at)
                    <span class="rounded-full border border-emerald-800/70 bg-emerald-950/40 px-2.5 py-0.5 text-xs text-emerald-300">removido do GitHub em {{ $date($finding->gone_at) }}</span>
                @endif
            </div>
            <h1 class="mt-2 font-mono text-lg font-semibold break-all text-white">{{ $finding->location() }}</h1>
            <p class="text-sm text-ink-400">Achado em {{ $date($finding->first_seen_at) }} · visto pela última vez em {{ $date($finding->last_seen_at) }}
                @if ($finding->changed_at) · mudou em {{ $date($finding->changed_at) }} @endif</p>
        </div>

        @if ($action)
            <div class="flex gap-3 rounded-xl border border-red-900 bg-red-950/30 px-5 py-4 text-sm text-red-100">
                <svg class="mt-0.5 size-5 shrink-0 text-red-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>
                <p><b class="text-white">O que fazer agora.</b> {{ $action }}</p>
            </div>
        @endif

        <div class="grid gap-5 lg:grid-cols-[3fr_2fr]">
            <div class="min-w-0 space-y-5">
                <section class="rounded-xl border border-ink-800 bg-ink-900 p-5">
                    <h2 class="font-semibold text-white">O que foi achado</h2>
                    <dl class="mt-3 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                        <div><dt class="text-[11px] tracking-widest text-ink-400 uppercase">Repositório</dt>
                            <dd class="mt-0.5 font-mono break-all text-white">{{ $finding->repo }}</dd></div>
                        <div><dt class="text-[11px] tracking-widest text-ink-400 uppercase">De quem é</dt>
                            <dd class="mt-0.5 text-ink-200">{{ $monitor->owns($finding->repo) ? 'Sua organização' : 'Terceiro: '.$finding->repo_owner.($finding->repo_owner_type ? ' ('.($finding->repo_owner_type === 'Organization' ? 'organização' : 'usuário').')' : '') }}{{ $finding->repo_fork ? ' · fork' : '' }}</dd></div>
                        @if ($finding->path)
                            <div class="sm:col-span-2"><dt class="text-[11px] tracking-widest text-ink-400 uppercase">Arquivo</dt>
                                <dd class="mt-0.5 font-mono break-all text-white">{{ $finding->path }}</dd></div>
                        @endif
                        <div><dt class="text-[11px] tracking-widest text-ink-400 uppercase">Seus termos que apareceram</dt>
                            <dd class="mt-1 flex flex-wrap gap-1">@foreach ($finding->terms ?? [] as $term)<span class="rounded bg-ink-800 px-2 py-0.5 font-mono text-xs text-amber-200">{{ $term }}</span>@endforeach</dd></div>
                        @if ($finding->repo_pushed_at || $finding->repo_created_at)
                            <div><dt class="text-[11px] tracking-widest text-ink-400 uppercase">Repositório</dt>
                                <dd class="mt-0.5 text-ink-200">{{ $finding->repo_created_at ? 'criado em '.$finding->repo_created_at->format('d/m/Y') : '' }}{{ $finding->repo_pushed_at ? ' · último envio '.$finding->repo_pushed_at->format('d/m/Y') : '' }}{{ $finding->repo_stars ? ' · '.$finding->repo_stars.' estrelas' : '' }}</dd></div>
                        @endif
                        @if ($finding->repo_description)
                            <div class="sm:col-span-2"><dt class="text-[11px] tracking-widest text-ink-400 uppercase">Descrição do repositório</dt>
                                <dd class="mt-0.5 text-ink-200">{{ $finding->repo_description }}</dd></div>
                        @endif
                    </dl>
                    <a href="{{ $finding->html_url }}" target="_blank" rel="noopener noreferrer nofollow"
                       class="mt-4 {{ $button }} border border-ink-700 text-ink-200 hover:border-ink-400 hover:text-white">Abrir no GitHub ↗</a>
                </section>

                @if ($finding->secrets)
                    <section class="rounded-xl border border-ink-800 bg-ink-900 p-5">
                        <h2 class="font-semibold text-white">Valores no arquivo</h2>
                        <p class="text-sm text-ink-400">Mascarados: o Security Monitor nunca guarda o valor de uma chave. Para ver, abra no GitHub.</p>
                        <div class="mt-3 overflow-x-auto">
                            <table class="w-full min-w-[480px] text-left text-sm">
                                <thead class="text-[11px] tracking-widest text-ink-400 uppercase">
                                    <tr><th class="py-1.5 pr-3 font-normal">Nome</th><th class="py-1.5 pr-3 font-normal">Valor</th><th class="py-1.5 pr-3 font-normal">Tamanho</th><th class="py-1.5 font-normal">Parece</th></tr>
                                </thead>
                                <tbody>
                                    @foreach ($finding->secrets as $secret)
                                        <tr class="border-t border-ink-850">
                                            <td class="py-2 pr-3 font-mono text-white">{{ $secret['name'] }}</td>
                                            <td class="py-2 pr-3 font-mono text-ink-200">{{ $secret['masked'] }}</td>
                                            <td class="py-2 pr-3 text-ink-400">{{ $secret['length'] }} caracteres</td>
                                            <td class="py-2">
                                                @if ($secret['looks_real'])
                                                    <span class="rounded border border-red-800/70 bg-red-950/50 px-1.5 py-px text-xs text-red-300">real</span>
                                                @elseif ($secret['kind'] === 'reference')
                                                    <span class="rounded border border-ink-700 px-1.5 py-px text-xs text-ink-400">referência, sem valor</span>
                                                @elseif ($secret['kind'] === 'placeholder')
                                                    <span class="rounded border border-ink-700 px-1.5 py-px text-xs text-ink-400">exemplo</span>
                                                @else
                                                    <span class="rounded border border-amber-800/70 bg-amber-950/40 px-1.5 py-px text-xs text-amber-200">talvez real</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </section>
                @endif

                @if ($finding->fragment)
                    <section class="rounded-xl border border-ink-800 bg-ink-900 p-5">
                        <h2 class="font-semibold text-white">Trecho do arquivo</h2>
                        <pre class="mt-3 max-h-96 overflow-auto rounded-lg border border-ink-800 bg-ink-950 p-3 font-mono text-xs leading-5 whitespace-pre-wrap text-ink-200">{{ $finding->fragment }}</pre>
                    </section>
                @endif

                @if ($finding->signals)
                    <section class="rounded-xl border border-ink-800 bg-ink-900 p-5">
                        <h2 class="font-semibold text-white">O que a varredura viu</h2>
                        <ul class="mt-2 space-y-1 text-sm text-ink-200">
                            @foreach ($finding->signals as $signal)
                                <li>· {{ $signal['label'] }}</li>
                            @endforeach
                        </ul>
                        <p class="mt-2 text-xs text-ink-400">Nível pelas regras (sem a IA): {{ strtoupper($finding->auto_level) }}.</p>
                    </section>
                @endif
            </div>

            <div class="min-w-0 space-y-5">
                <section class="rounded-xl border {{ $held ? 'border-violet-800/70' : 'border-ink-800' }} bg-ink-900 p-5">
                    <h2 class="mb-3 font-semibold text-white">As duas análises da IA</h2>
                    @include('alerts.github.partials.stages')
                </section>

                <form method="POST" action="{{ route('alerts.github.feedback', $finding) }}" class="rounded-xl border border-ink-800 bg-ink-900 p-5">
                    @csrf
                    @include('alerts.partials.vote-fields', ['match' => $finding, 'id' => 'motivo', 'legend' => 'Este achado é',
                        'unaffectedHint' => 'Importa, mas já resolvemos: a chave foi trocada, o arquivo saiu ou não é nosso para corrigir.',
                        'placeholder' => 'Ex.: esse repositório é de um parceiro, a chave é de teste e não dá acesso a nada.',
                        'noteHint' => 'A IA lê o seu motivo para decidir os próximos avisos do GitHub. Não escreva o valor de chaves nem dados pessoais.'])
                    <button class="mt-4 min-h-11 w-full rounded-lg bg-accent px-4 text-sm font-semibold text-ink-950 hover:brightness-110">Salvar avaliação</button>
                </form>

                @if ($canManage && $receivers->isNotEmpty() && $finding->status !== 'queued' && $level !== 'none')
                    <form method="POST" action="{{ route('alerts.github.resend', $finding) }}" class="rounded-xl border border-ink-800 bg-ink-900 p-5">
                        @csrf
                        <p class="text-sm text-ink-400">
                            {{ $finding->status === 'sent' ? 'Mandar de novo' : 'Mandar mesmo assim' }} para o {{ $receivers->map->appName()->join(', o ', ' e o ') }}.
                        </p>
                        <button class="mt-3 min-h-11 w-full rounded-lg border border-ink-700 px-4 text-sm text-ink-200 hover:border-accent hover:text-white">
                            {{ $finding->status === 'sent' ? 'Reenviar' : 'Enviar' }}
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>
@endsection
