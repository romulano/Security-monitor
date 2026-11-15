{{--
    A faixa do modo leitura da área de testes: com a chave global desligada, dá para ler e preparar a
    autorização, mas não autorizar. Pausados (já estiveram ligados) ou ainda não ligados. Espera $switch
    (PentestSwitch::status()); ligados, não aparece nada. O "avisamos nos seus apps" voltou com o aviso
    direcionado (PentestReadyNotice, Blue Team rodada 3, item 3), mas só quando é verdade para esta conta: algum
    app recebe o aviso dos testes (conectado, sem pausa e com o tipo ligado na Central). Sem nenhum, a faixa diz
    onde escolher.
--}}
@php
    $warned = (bool) auth()->user()?->notificationChannels->contains(fn ($channel) => $channel->receives(\App\Models\NotificationChannel::TESTS));
@endphp
@if ($switch['state'] === 'paused')
    <p role="status" class="flex items-start gap-2.5 rounded-lg border border-amber-800 bg-amber-950/30 px-3.5 py-3 text-sm text-amber-100">
        <svg aria-hidden="true" class="mt-0.5 size-[18px] shrink-0 text-amber-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5v14"/><path d="M15 5v14"/></svg>
        <span class="text-pretty">
            <b class="text-white">Os testes estão pausados pelo Security Monitor para todas as contas{{ $switch['since'] ? ' desde '.$switch['since']->timezone('America/Sao_Paulo')->format('d/m \à\s H:i') : '' }}.</b>
            Nenhuma requisição sai para os sites. Dá para ler como funciona; a autorização fica para quando eles voltarem.
            {{-- Sem `since`, a chave está ligada e caiu uma pré-condição: a volta sozinha não avisa (I2 do Red Team). --}}
            @if ($switch['since'] && $warned)
                Se a sua já estiver pronta, avisamos nos seus apps.
            @elseif ($switch['since'])
                Para receber o aviso, escolha um app em <a href="{{ route('alerts.notifiers') }}" class="underline">Notificadores</a>.
            @endif
        </span>
    </p>
@elseif ($switch['state'] === 'not_live')
    <p role="status" class="flex items-start gap-2.5 rounded-lg border border-ink-700 bg-ink-850 px-3.5 py-3 text-sm text-ink-200">
        <svg aria-hidden="true" class="mt-0.5 size-[18px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
        <span class="text-pretty">
            <b class="text-white">Os testes de segurança ainda não foram ligados no Security Monitor.</b>
            Dá para ler como funcionam e preparar a autorização, que fica pronta. O “Autorizar” só funciona quando ligarmos{{ $warned ? ', e avisamos nos seus apps.' : '.' }}
            @unless ($warned)
                Para receber o aviso, escolha um app em <a href="{{ route('alerts.notifiers') }}" class="underline">Notificadores</a>.
            @endunless
        </span>
    </p>
@endif
