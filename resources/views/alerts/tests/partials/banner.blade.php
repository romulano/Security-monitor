{{--
    A faixa do teste (Estados-gerais), em toda página com login: $banner vem do composer (PentestBanner),
    a situação mais urgente da conta. A consulta periódica troca o conteúdo; se ela falha, a faixa fica com o
    último estado, diz há quanto tempo está sem atualização e o Parar continua valendo (situação 9).
--}}
@if ($banner)
    <div data-pentest-banner="{{ route('alerts.tests.banner', array_filter(['pagina' => is_string(request()->route('execucao')) ? request()->route('execucao') : null])) }}"
         data-poll="{{ (int) config('secmon.pentest.banner_poll_seconds') }}" data-live="{{ $banner['kind'] === 'stopped' ? '0' : '1' }}" data-login="{{ route('alerts.tests') }}">
        @include('alerts.tests.partials.banner-bar', ['banner' => $banner])
    </div>
    {{-- O que o leitor de tela ouve da consulta da faixa, uma vez por mudança: fora do pedaço trocado. --}}
    <p data-banner-announce class="sr-only" role="status" aria-live="polite"></p>
@endif
