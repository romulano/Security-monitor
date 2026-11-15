{{-- Passo 1 (Autorizar-1): o domínio, entre os já comprovados na conta (em qualquer serviço), ou outro. O domínio de uma autorização não muda. --}}
@if ($domain)
    <h2 class="text-lg font-semibold text-white">Qual domínio testar?</h2>
    <section class="space-y-1 rounded-xl border border-ink-800 bg-ink-900 p-5">
        <p class="font-mono text-[15px] text-white">{{ $domain->host }}</p>
        <p class="text-sm text-ink-400">O domínio de uma autorização não muda. Para testar outro, comece uma autorização nova na <a href="{{ route('alerts.tests') }}" class="text-accent hover:underline">página dos testes</a>.</p>
    </section>
@elseif (! $canAdd)
    <h2 class="text-lg font-semibold text-white">Qual domínio testar?</h2>
    <p class="rounded-xl border border-amber-800 bg-amber-950/30 px-5 py-4 text-sm text-amber-100">
        Você chegou ao limite de {{ $max }} domínios nos testes de segurança. Exclua um na <a href="{{ route('alerts.tests') }}" class="underline">página dos testes</a> para inscrever outro.
    </p>
@else
    <div class="rounded-xl border border-accent/40 bg-accent/5 px-5 py-4">
        <p class="font-semibold text-white">Teste ativo, só com autorização.</p>
        <p class="mt-1 text-sm text-ink-200">
            O Security Monitor envia requisições ao seu site para encontrar falhas reais, dentro de uma regra que você aprova: o domínio, a janela, a intensidade
            e o que nunca fazemos. Antes de qualquer requisição você lê e aceita o termo, e a primeira execução passa por um analista do Security Monitor.
        </p>
    </div>

    <div>
        <h2 class="text-lg font-semibold text-white">Qual domínio testar?</h2>
        <p class="text-sm text-ink-400">O domínio e os subdomínios dele entram no teste, mas só os que forem seus: o passo 3 mostra a regra.</p>
    </div>

    @php
        $choice = old('origem', $proven->isNotEmpty() ? (string) $proven->first()->id : 'outro');
        $hint = 'Sem “www” nem “https://”. Um domínio interno ou um IP não servem. O passo 2 pede o registro TXT.';
    @endphp
    <form id="step-form" method="POST" action="{{ route('alerts.tests.domains.store') }}" class="space-y-4">
        @csrf
        @if ($proven->isNotEmpty())
            <fieldset class="space-y-2.5 rounded-xl border border-ink-800 bg-ink-900 p-5">
                <legend class="px-1.5 text-[11px] tracking-widest text-ink-400 uppercase">Já comprovados no Security Monitor</legend>
                @foreach ($proven as $option)
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-ink-700 bg-ink-850 p-4 has-checked:border-accent has-checked:bg-accent/5">
                        <input type="radio" name="origem" value="{{ $option->id }}" @checked($choice === (string) $option->id) class="mt-0.5 size-5 shrink-0 accent-accent">
                        <span class="flex flex-col gap-0.5">
                            <span class="font-mono text-[15px] text-white">{{ $option->domain }}</span>
                            <span class="text-sm text-ink-400">Posse comprovada em {{ $option->verified_at->timezone('America/Sao_Paulo')->format('d/m') }} {{ $option->origin }}. Usando este, o passo 2 já vem pronto.</span>
                        </span>
                    </label>
                @endforeach
                <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-ink-700 bg-ink-850 p-4 has-checked:border-accent has-checked:bg-accent/5">
                    <input id="origem-outro" type="radio" name="origem" value="outro" @checked($choice === 'outro') class="mt-0.5 size-5 shrink-0 accent-accent">
                    <span class="flex flex-1 flex-col gap-2">
                        <span class="text-[15px] text-white">Outro domínio</span>
                        <input type="text" name="outro" aria-label="Outro domínio" value="{{ old('outro') }}" placeholder="empresa.com.br" data-check-on-input="origem-outro"
                               autocomplete="off" spellcheck="false" maxlength="255"
                               class="min-h-11 rounded-lg border border-ink-700 bg-ink-950 px-3 font-mono text-sm text-white placeholder:text-ink-400 focus:border-accent">
                        <span class="text-xs text-ink-400">{{ $hint }}</span>
                    </span>
                </label>
            </fieldset>
        @else
            <section class="space-y-2 rounded-xl border border-ink-800 bg-ink-900 p-5">
                <input type="hidden" name="origem" value="outro">
                <label for="outro" class="text-[15px] font-semibold text-white">Domínio</label>
                <input id="outro" type="text" name="outro" value="{{ old('outro') }}" placeholder="empresa.com.br"
                       autocomplete="off" spellcheck="false" maxlength="255"
                       class="min-h-11 w-full rounded-lg border border-ink-700 bg-ink-850 px-3 font-mono text-sm text-white placeholder:text-ink-400 focus:border-accent">
                <p class="text-xs text-ink-400">{{ $hint }}</p>
            </section>
        @endif
        @error('origem')<p class="text-sm text-red-300">{{ $message }}</p>@enderror
        @error('outro')<p class="text-sm text-red-300">{{ $message }}</p>@enderror
    </form>

    <p class="text-xs text-ink-400">Você pode autorizar até {{ $max }} domínios. Um domínio bloqueado por denúncia não aparece aqui; fale com {{ config('secmon.contact_email') }}.</p>
@endif
