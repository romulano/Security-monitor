{{-- O tipo de visitante de uma linha de Admin → Acessos, pela precedência: nosso → ferramenta de varredura → atacante (mandou
     carga de ataque no período) → robô declarado → provável robô → pessoa (sem selo). E a conta, se estava logada. --}}
@php($badge = 'ml-1 rounded px-1 text-[10px] [word-break:normal] [overflow-wrap:anywhere]')
@if ($row->ours)<span class="{{ $badge }} bg-sky-950/60 text-sky-300">nosso</span>
@elseif ($row->robot_kind === \App\Support\RobotCatalog::SCANNER)@include('admin._robot', ['owner' => $row->robot_owner, 'kind' => $row->robot_kind])
@elseif ($row->attacker)<span class="{{ $badge }} bg-red-950/50 text-red-300" title="mandou carga de ataque (SQL, script, subida de pasta...) no período">atacante</span>
@elseif ($row->robot_kind)@include('admin._robot', ['owner' => $row->robot_owner, 'kind' => $row->robot_kind])
@elseif ($row->likely_bot)<span class="{{ $badge }} bg-amber-950/60 text-amber-300" title="nunca ficou {{ \App\Services\Admin\AccessReport::HUMAN_MIN_MS / 1000 }} s numa página no período, ou recebeu {{ \App\Services\Admin\AccessReport::NOT_FOUND }} respostas 404 ou mais">provável robô</span>@endif
@if ($row->email)<span class="block text-[11px] text-emerald-300">{{ $row->email }}</span>@endif
