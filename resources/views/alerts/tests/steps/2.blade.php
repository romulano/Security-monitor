{{--
    Passo 2 (Autorizar-2): confirmação de responsabilidade. Instalação self-hosted, de um dono só: não existe
    prova técnica de posse (sem registro TXT no DNS). Em vez disso, a pessoa confirma explicitamente, numa
    caixa de diálogo (nunca um alert() do navegador), que o domínio é dela antes de seguir — ver
    PentestWizard::saveResponsibility() e PentestDomain::responsibilityConfirmed().
--}}
@php
    $name = 'inline-block max-w-full font-mono [overflow-wrap:anywhere]';
    $confirmed = $domain->responsibilityConfirmed();
@endphp

<div>
    <h2 class="text-lg font-semibold text-white">O domínio é seu?</h2>
    <p class="text-sm text-ink-400">
        Esta instalação do Security Monitor é de uso próprio, de um dono só: não existe prova técnica de posse por
        registro TXT no DNS. A responsabilidade por testar só domínios seus é sua.
    </p>
</div>

<section class="space-y-3 rounded-xl border border-amber-800 bg-amber-950/30 px-5 py-4">
    <p class="font-semibold text-white">Aviso</p>
    <p class="text-sm text-amber-100">
        Você só pode fazer testes ativos em um domínio seu. Não teste domínios de outras pessoas ou empresas: um
        teste ativo manda requisições reais ao site e, em domínio de terceiros, pode ser considerado um ataque.
    </p>
    @if ($confirmed)
        <p class="flex items-center gap-1.5 text-sm text-emerald-300">
            <span class="size-1.5 rounded-full bg-emerald-400"></span>
            Você confirmou, em {{ \Illuminate\Support\Carbon::parse($domain->draft['responsibility_ack']['at'])->timezone('America/Sao_Paulo')->format('d/m \à\s H:i') }}, que
            <span class="{{ $name }}">{{ $domain->host }}</span> é seu.
        </p>
    @endif
</section>

<form id="step-form" method="POST" action="{{ route('alerts.tests.authorize.update', ['passo' => 2, 'dominio' => $domain->host]) }}"
      data-confirm="Aviso: você só pode fazer testes em um domínio seu. Não teste em outro domínio. Ao clicar em OK, você confirma que {{ $domain->host }} é seu."
      data-confirm-ok="Confirmar">
    @csrf
    @method('PUT')
    <input type="hidden" name="responsavel" value="1">
    @error('responsavel')<p class="text-sm text-red-300">{{ $message }}</p>@enderror
</form>
