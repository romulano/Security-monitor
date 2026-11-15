{{-- Passo 5: o que o DNS público do domínio revela, para o cliente dizer o que entra no perfil.
     Fica dentro do formulário do passo, mas os campos são do #dns-sugestoes (atributo form). --}}
<section id="sugestoes-dns" class="space-y-4 rounded-xl border border-accent/40 bg-accent/5 p-5">
    <div>
        <h3 class="text-[15px] font-semibold text-white">Achamos no DNS público de <span class="font-mono">{{ $domain->domain }}</span></h3>
        <p class="mt-0.5 text-sm text-ink-400">
            Registros que qualquer pessoa consulta (MX, SPF e outros TXT, NS). Não acessamos o site. Quer adicionar ao perfil?
        </p>
    </div>

    <div class="space-y-4">
        <ul class="space-y-2">
            @foreach ($dnsSuggestions as $item)
                <li>
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-ink-700 bg-ink-850 p-3.5 has-checked:border-accent">
                        <input type="checkbox" form="dns-sugestoes" name="adicionar[]" value="{{ $item['slug'] }}" checked class="mt-0.5 size-5 shrink-0 accent-accent">
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold text-white">{{ $item['name'] }}</span>
                            <span class="block font-mono text-xs break-all text-ink-400">{{ $item['evidence'] }}</span>
                            <span class="block text-xs text-ink-400">entra em: {{ $item['target'] }}</span>
                        </span>
                    </label>
                </li>
            @endforeach
        </ul>

        <div class="flex flex-wrap gap-2">
            <button form="dns-sugestoes" name="acao" value="adicionar"
                    class="inline-flex min-h-11 items-center rounded-lg bg-accent px-4 text-sm font-semibold text-ink-950 hover:brightness-110">
                Adicionar marcadas
            </button>
            <button form="dns-sugestoes" name="acao" value="dispensar"
                    class="inline-flex min-h-11 items-center rounded-lg border border-ink-800 px-4 text-sm text-ink-200 hover:border-ink-700">
                Agora não
            </button>
        </div>
    </div>
</section>
