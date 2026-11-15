Olá, {{ $name }}.

@if ($kind === 'lost')
O Security Monitor deixou de encontrar o registro TXT de {{ $domain }}, da conta de {{ $company }}{{ $since ? ' desde '.$since->timezone('America/Sao_Paulo')->format('d/m/Y') : '' }}, e a comprovação de posse do domínio caiu.

{{ $stopped === [] ? 'Nada do que a empresa monitora dependia dele.' : 'Pararam até a posse voltar: '.implode(', ', $stopped).'.' }}
@else
O Security Monitor não encontrou hoje o registro TXT de {{ $domain }}, da conta de {{ $company }} (ou o DNS dele não respondeu).

Se o TXT não voltar até {{ $fallsAt?->timezone('America/Sao_Paulo')->format('d/m/Y') }}, a comprovação de posse cai, e o que depende dela para (Radar, descoberta e webhook).
@endif

Para manter a posse, o DNS de {{ $domain }} precisa ter este registro TXT:
{{ $txt }}

Os domínios da empresa: {!! $url !!}

Você recebe este aviso porque configura este domínio na conta da empresa.

--
Security Monitor · {{ config('app.url') }}
