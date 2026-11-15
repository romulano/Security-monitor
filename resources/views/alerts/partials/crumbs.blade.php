{{--
    Trilha das páginas que se abrem pela Integração (Notícias, Radar de domínios, GitHub, os testes de segurança e as
    entrevistas e detalhes deles). Espera $here; opcional $parent = [rótulo, url] para um nível no meio ("Integração /
    GitHub / Achado") ou $parents = [[rótulo, url], …] para mais de um ("Integração / Testes de segurança / domínio /
    Termo aceito").
--}}
<p class="mb-1 text-xs text-ink-400">
    <a href="{{ route('alerts.integrations') }}" class="hover:text-ink-200">Integração</a> /
    @foreach ($parents ?? (isset($parent) ? [$parent] : []) as [$label, $url])
        <a href="{{ $url }}" class="hover:text-ink-200">{{ $label }}</a> /
    @endforeach
    {{ $here }}
</p>
