@extends('layouts.admin')

@section('title', 'Alertas · Catálogo')

@php
    use App\Models\TechCatalog;
    use App\Services\Admin\CatalogText;
    $input = 'w-full rounded-lg border border-ink-800 bg-ink-850 px-3 py-2 text-sm';
    $form = $editing ?? ($creating ? new TechCatalog(['active' => true, 'terms' => [], 'detect' => []]) : null);
@endphp

@section('content')
    @include('admin.alerts._nav', ['subtitle' => 'O catálogo traduz cada resposta em termos de busca. Mudar um termo recompila os perfis que usam a tecnologia.'])

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-sm font-semibold text-white">Catálogo de tecnologias <span class="font-normal text-ink-400">· {{ $techs->total() }}</span></h2>
        <form method="GET" class="flex flex-wrap gap-2">
            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Buscar" aria-label="Buscar tecnologia"
                   class="rounded-lg border border-ink-800 bg-ink-900 px-3 py-2 text-sm">
            <select name="categoria" aria-label="Categoria" class="rounded-lg border border-ink-800 bg-ink-900 px-3 py-2 text-sm [color-scheme:dark]">
                <option value="">Todas as categorias</option>
                @foreach (TechCatalog::CATEGORIES as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['categoria'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <label class="flex items-center gap-2 rounded-lg border border-ink-800 px-3 py-2 text-sm text-ink-200">
                <input type="checkbox" name="origem" value="discovery" @checked(($filters['origem'] ?? null) === 'discovery') class="accent-accent">
                Descoberta automática ({{ $autoCount }})
            </label>
            <button class="rounded-lg border border-ink-800 px-3 py-2 text-sm text-ink-200 hover:border-ink-700">Filtrar</button>
            <a href="{{ route('admin.alerts.catalog', ['nova' => 1]) }}" class="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-ink-950 hover:brightness-110">+ Nova tecnologia</a>
        </form>
    </div>

    <div class="grid items-start gap-4 {{ $form ? 'xl:grid-cols-[minmax(0,1fr)_24rem]' : '' }}">
        <div class="overflow-x-auto rounded-lg border border-ink-800">
            <table class="w-full min-w-[48rem] text-left text-sm">
                <thead class="bg-ink-900 text-[11px] tracking-widest text-ink-400 uppercase">
                    <tr>
                        <th class="px-3 py-2 font-medium">Tecnologia</th>
                        <th class="px-3 py-2 font-medium">Termos de busca</th>
                        <th class="px-3 py-2 text-right font-medium">Perfis</th>
                        <th class="px-3 py-2 text-right font-medium">Alertas 30 d</th>
                        <th class="px-3 py-2 text-right font-medium">Não relev.</th>
                        <th class="px-3 py-2 font-medium">Ativa</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-800">
                    @foreach ($techs as $tech)
                        @php
                            $u = $usage[$tech->name] ?? null;
                            $rate = $u && $u['alerts'] ? round($u['irrelevant'] / $u['alerts'] * 100) : null;
                        @endphp
                        <tr class="{{ $editing?->id === $tech->id ? 'bg-accent/5' : '' }} {{ $tech->active ? '' : 'opacity-60' }}">
                            <td class="px-3 py-2.5">
                                <a href="{{ route('admin.alerts.catalog', array_filter(['editar' => $tech->id] + $filters)) }}" class="block font-semibold text-white hover:text-accent">{{ $tech->name }}</a>
                                <span class="text-xs text-ink-400">{{ $tech->categoryLabel() }}{{ $tech->parent ? ' · '.$tech->parent->name : '' }}{{ $tech->strict ? ' · só produto afetado' : '' }}</span>
                                @if ($tech->isAutoDiscovered())
                                    <span class="mt-1 inline-block rounded-full border border-sky-800 px-2 py-px text-[11px] text-sky-300" title="Cadastrado sozinho pela descoberta em {{ $tech->discovered_on }}: revise nome e termos">descoberta automática · {{ $tech->discovered_on }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2.5 font-mono text-xs">
                                {{ implode(', ', $tech->terms ?? []) }}
                                @if ($tech->detect)
                                    <span class="block text-ink-400">detecção: {{ count($tech->detect) }} regra(s)</span>
                                @endif
                            </td>
                            <td class="px-3 py-2.5 text-right">{{ $tech->profiles_count }}</td>
                            <td class="px-3 py-2.5 text-right">{{ $u['alerts'] ?? 0 }}</td>
                            <td class="px-3 py-2.5 text-right {{ ($rate ?? 0) >= 20 ? 'text-orange-300' : '' }}">{{ $rate === null ? '—' : $rate.'%' }}</td>
                            <td class="px-3 py-2.5">
                                <form method="POST" action="{{ route('admin.alerts.catalog.toggle', $tech) }}">@csrf
                                    <button class="rounded-full border px-2 py-0.5 text-xs {{ $tech->active ? 'border-emerald-800 text-emerald-300' : 'border-ink-700 text-ink-400' }}">{{ $tech->active ? 'ativa' : 'inativa' }}</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="px-3 py-3">{{ $techs->links() }}</div>
        </div>

        @if ($form)
            <form method="POST" action="{{ $editing ? route('admin.alerts.catalog.update', $editing) : route('admin.alerts.catalog.store') }}"
                  class="space-y-4 rounded-lg border border-accent/40 bg-ink-900 p-4">
                @csrf
                @if ($editing) @method('PUT') @endif
                <div class="flex items-baseline justify-between">
                    <h3 class="text-sm font-semibold text-white">{{ $editing ? 'Editar · '.$editing->name : 'Nova tecnologia' }}</h3>
                    <a href="{{ route('admin.alerts.catalog', $filters) }}" class="text-xs text-ink-400 hover:text-ink-200">fechar</a>
                </div>

                <label class="block"><span class="mb-1 block text-xs text-ink-400">Nome</span>
                    <input name="name" value="{{ old('name', $form->name) }}" required class="{{ $input }}">
                    @error('name') <span class="text-xs text-red-400">{{ $message }}</span> @enderror
                </label>
                @unless ($editing)
                    <label class="block"><span class="mb-1 block text-xs text-ink-400">Slug (fixo depois de criado)</span>
                        <input name="slug" value="{{ old('slug') }}" required pattern="[a-z0-9-]+" class="{{ $input }} font-mono">
                        @error('slug') <span class="text-xs text-red-400">{{ $message }}</span> @enderror
                    </label>
                @endunless
                <div class="grid grid-cols-2 gap-3">
                    <label class="block"><span class="mb-1 block text-xs text-ink-400">Categoria</span>
                        <select name="category" class="{{ $input }} [color-scheme:dark]">
                            @foreach (TechCatalog::CATEGORIES as $value => $label)
                                <option value="{{ $value }}" @selected(old('category', $form->category) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block"><span class="mb-1 block text-xs text-ink-400">Pertence a</span>
                        <select name="parent_id" class="{{ $input }} [color-scheme:dark]">
                            <option value="">—</option>
                            @foreach ($parents as $parent)
                                <option value="{{ $parent->id }}" @selected((int) old('parent_id', $form->parent_id) === $parent->id)>{{ $parent->name }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
                <label class="block"><span class="mb-1 block text-xs text-ink-400">Termos de busca (um por linha ou vírgula; * no fim = prefixo)</span>
                    <textarea name="terms" rows="4" required class="{{ $input }} font-mono text-xs">{{ old('terms', implode("\n", $form->terms ?? [])) }}</textarea>
                    @error('terms') <span class="text-xs text-red-400">{{ $message }}</span> @enderror
                </label>
                <label class="flex items-start gap-2 text-sm">
                    <input type="hidden" name="strict" value="0">
                    <input type="checkbox" name="strict" value="1" @checked(old('strict', $form->strict)) class="mt-0.5 accent-accent">
                    <span><span class="text-ink-200">Só conta como produto afetado ou fornecedor</span>
                        <span class="block text-xs text-ink-400">Para tecnologias amplas (Windows, Python, GitHub): menção no texto não alerta.</span></span>
                </label>
                <label class="block"><span class="mb-1 block text-xs text-ink-400">Detecção pelo domínio (uma regra por linha)</span>
                    <textarea name="detect" rows="4" placeholder="header:server:re:nginx(?:/([\d.]+))?&#10;cookie:laravel_session&#10;html:/wp-content/&#10;mx:google.com"
                              class="{{ $input }} font-mono text-xs">{{ old('detect', CatalogText::detectToText($form->detect ?? [])) }}</textarea>
                    <span class="text-xs text-ink-400">Tipos: {{ implode(', ', \App\Services\Alerts\Discovery\StackDetector::TYPES) }}. Padrão é texto; com “re:”, regex (o 1º grupo vira a versão).</span>
                    @error('detect') <span class="block text-xs text-red-400">{{ $message }}</span> @enderror
                </label>
                <label class="flex items-center gap-2 text-sm text-ink-200">
                    <input type="hidden" name="active" value="0">
                    <input type="checkbox" name="active" value="1" @checked(old('active', $form->active)) class="accent-accent"> Ativa
                </label>

                @if ($editing?->isAutoDiscovered())
                    <p class="rounded-lg border border-sky-900 bg-sky-950/30 px-3 py-2 text-xs text-sky-200">
                        Cadastrado sozinho pela descoberta em <span class="font-mono">{{ $editing->discovered_on }}</span>. O nome e os termos vêm do sinal
                        (domínio, cabeçalho, meta generator); ajuste para como o produto aparece nas notícias.
                    </p>
                @endif
                @if ($editing)
                    <p class="border-t border-ink-800 pt-3 text-sm text-ink-200">
                        Estes termos alcançam <b class="text-white">{{ $reach }}</b> {{ $reach === 1 ? 'caso' : 'casos' }} nos últimos 90 dias.
                        <a href="{{ route('feed.index', ['q' => 'all:('.collect($editing->terms)->map(fn ($t) => '"'.rtrim($t, '*').'"')->implode(' OR ').')']) }}" target="_blank" class="text-accent hover:underline">Testar no feed ↗</a>
                    </p>
                @endif

                <button class="w-full rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-ink-950 hover:brightness-110">
                    {{ $editing ? 'Salvar e recompilar '.$editing->profiles()->count().' perfil(is)' : 'Criar tecnologia' }}
                </button>
            </form>
        @endif
    </div>
@endsection
