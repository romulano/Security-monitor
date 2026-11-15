{{--
    As telas da empresa: a atual sem link, marcada com aria-current. Contas, Setores e Atividade são de quem administra;
    Domínios, de toda conta da empresa (cada uma vê os do que vê).
--}}
@php
    $admin = auth()->user()?->companyRole() === \App\Enums\CompanyRole::Admin;
    $screens = $admin
        ? ['company.accounts' => 'Contas', 'company.sectors' => 'Setores', 'company.domains' => 'Domínios', 'company.activity' => 'Atividade']
        : ['company.domains' => 'Domínios'];
@endphp
{{-- Um item só (quem não administra): sem navegação, a tela já tem o título. --}}
@if (count($screens) > 1)
<nav aria-label="Empresa" class="flex flex-wrap gap-4 text-sm">
    @foreach ($screens as $route => $label)
        @if (request()->routeIs($route))
            <span aria-current="page" class="font-semibold text-white">{{ $label }}</span>
        @else
            <a href="{{ route($route) }}" class="text-accent hover:underline">{{ $label }}</a>
        @endif
    @endforeach
</nav>
@endif
