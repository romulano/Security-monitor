{{--
    Passo 6 (Autorizar-6): o termo ativo-v1 com os números da regra e as quatro declarações. A página manda a
    impressão digital do texto que mostrou: se ele mudar até a pessoa marcar, ela lê de novo (B6).
--}}
@php
    use App\Services\Pentest\PentestTerms;

    $box = 'space-y-3 rounded-xl border border-ink-800 bg-ink-900 p-5';
@endphp

<div>
    {{-- O selo do parecer ao lado do título, tracejado em âmbar, como no canvas; sem ele, nenhuma linha vazia. --}}
    <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
        <h2 class="text-lg font-semibold text-white">Leia e aceite o termo de teste ativo</h2>
        @if ($preview && $legalPending)
            <span class="rounded-md border border-dashed border-[#973c00] bg-amber-950/40 px-2 py-0.5 text-xs text-[#fee685]">Texto final · aguarda parecer jurídico</span>
        @endif
    </div>
    <p class="text-sm text-ink-400">Termo {{ PentestTerms::TERM }}. Os números abaixo são os que você escolheu nos passos 1 a 5.</p>
</div>

@if (! $preview)
    <section class="space-y-1 rounded-xl border border-amber-800 bg-amber-950/30 px-5 py-4 text-sm text-amber-100">
        <p>{{ $ruleError }}</p>
        <p>
            O termo sai com os números do passo 4.
            <a href="{{ route('alerts.tests.authorize', ['passo' => 4, 'dominio' => $domain->host]) }}" class="font-semibold underline">Volte ao passo 4</a> e grave a janela de novo.
        </p>
    </section>
@else
    @php
        $hash = $preview['rendered_sha256'];
        [$summary, $done, $never] = array_pad(array_slice($sections, 0, 3), 3, ['title' => '', 'body' => '']);
        $chosen = (array) old('declaracoes', $declared);
    @endphp

    <p class="rounded-lg border border-amber-800 bg-amber-950/30 px-3.5 py-2.5 text-sm text-amber-100">
        O aceite do Radar ou de Notícias não vale aqui: ele autoriza só leitura passiva. Este termo é separado.
    </p>

    <section class="space-y-2 rounded-xl border border-accent/40 bg-ink-900 p-5">
        <h3 class="text-[11px] tracking-widest text-accent uppercase">{{ $summary['title'] }}</h3>
        <div class="prose-brief text-sm leading-6">{{ PentestTerms::html($summary['body']) }}</div>
    </section>

    <div class="grid gap-4 md:grid-cols-2">
        @foreach ([$done, $never] as $part)
            <section class="{{ $box }}">
                <h3 class="text-[15px] font-semibold text-white">{{ $part['title'] }}</h3>
                <div class="prose-brief text-sm leading-6">{{ PentestTerms::html($part['body']) }}</div>
            </section>
        @endforeach
    </div>

    <section class="space-y-2">
        <h3 class="text-[11px] tracking-widest text-ink-400 uppercase">O termo completo: abra cada seção para ler</h3>
        @foreach (array_slice($sections, 3) as $section)
            <details class="group rounded-lg border border-ink-800 bg-ink-900">
                <summary class="flex min-h-11 cursor-pointer items-center justify-between gap-3 px-4 text-sm font-semibold text-white">
                    {{ $section['title'] }}
                    <svg aria-hidden="true" class="size-4 shrink-0 text-ink-400 transition-transform group-open:rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                </summary>
                <div class="prose-brief border-t border-ink-800 px-4 py-3 text-sm leading-6">{{ PentestTerms::html($section['body']) }}</div>
            </details>
        @endforeach
    </section>

    <form id="step-form" method="POST" action="{{ route('alerts.tests.authorize.update', ['passo' => 6, 'dominio' => $domain->host]) }}" class="{{ $box }}">
        @csrf
        @method('PUT')
        <input type="hidden" name="impressao" value="{{ $hash }}">
        <fieldset class="space-y-2.5">
            <legend class="mb-2 text-[15px] font-semibold text-white">Declarações (marque cada uma)</legend>
            @foreach ($preview['declarations'] as $id => $text)
                <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-ink-700 bg-ink-850 p-4 has-checked:border-accent has-checked:bg-accent/5">
                    <input type="checkbox" name="declaracoes[]" value="{{ $id }}" @checked(in_array($id, $chosen, true)) class="mt-0.5 size-5 shrink-0 accent-accent">
                    <span class="text-sm text-white">{{ $text }}</span>
                </label>
            @endforeach
        </fieldset>
        @foreach (['termo', 'declaracoes', 'declaracoes.*', 'impressao'] as $key)
            @error($key)<p class="text-sm text-red-300">{{ $message }}</p>@enderror
        @endforeach
        <p class="text-xs text-ink-400">
            Termo {{ PentestTerms::TERM }} ·
            {{ $publishedAt ? 'publicado em '.\Illuminate\Support\Carbon::parse($publishedAt)->format('d/m/Y') : 'ainda não publicado em /termos' }} ·
            impressão digital <span class="font-mono" title="sha256 {{ $hash }}">sha256 {{ substr($hash, 0, 4) }}…{{ substr($hash, -2) }}</span>.
            O aceite grava a impressão digital deste texto e a da regra (escopo, janela e intensidade). Todas as categorias e níveis que o teste pode usar estão neste texto.
        </p>
    </form>
@endif
