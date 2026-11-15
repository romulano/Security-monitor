@extends('layouts.admin')

@section('title', 'E-mails')

@section('content')
    @php
        $input = 'w-full rounded-lg border border-ink-800 bg-ink-850 px-3 py-2 text-sm focus:border-accent';
        $label = 'mb-1 block text-xs tracking-widest text-ink-700 uppercase';
        $when = fn ($date) => $date?->timezone('America/Sao_Paulo')->format('d/m H:i') ?? '—';
        $tone = fn (string $status) => match ($status) {
            'entregue' => 'text-emerald-300',
            'adiado' => 'text-amber-300',
            'devolvido', 'falhou' => 'text-red-300',
            default => 'text-ink-400',
        };
    @endphp

    <div class="mb-6">
        <h1 class="text-sm font-semibold text-white">E-mails</h1>
        <p class="max-w-3xl text-xs leading-relaxed text-ink-700">
            Todo e-mail do Security Monitor sai pelo servidor de envio do próprio stack (Postfix com DKIM, só alcançável pela rede interna) e fica
            registrado aqui: <strong class="font-medium text-ink-400">no servidor de envio</strong> quando ele aceitou a mensagem,
            <strong class="font-medium text-ink-400">entregue</strong> quando o servidor do destinatário aceitou,
            <strong class="font-medium text-ink-400">adiado</strong> enquanto ele tenta de novo (até 1 dia) e
            <strong class="font-medium text-ink-400">devolvido</strong> ou <strong class="font-medium text-ink-400">falhou</strong> com a resposta
            que explica o porquê. No máximo {{ config('secmon.mail.per_minute') }} envios por minuto; o resto espera na fila.
        </p>
    </div>

    <section class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ([
            'mailer' => 'Mailer',
            'server' => 'Servidor de envio',
            'dkim' => 'DKIM',
            'spf' => 'SPF',
            'dmarc' => 'DMARC',
            'reader' => 'Leitor do log de entrega',
        ] as $key => $name)
            @php($state = $health[$key])
            <div class="rounded-lg border p-4 {{ $state['ok'] ? 'border-emerald-900/60 bg-emerald-950/20' : 'border-red-900/60 bg-red-950/20' }}">
                <p class="mb-1 text-[11px] tracking-widest text-ink-700 uppercase">{{ $name }}</p>
                <p class="flex items-center gap-2 text-sm font-medium {{ $state['ok'] ? 'text-emerald-300' : 'text-red-300' }}">
                    <span class="size-2 rounded-full {{ $state['ok'] ? 'bg-emerald-400' : 'bg-red-400' }}"></span>
                    {{ $state['label'] }}
                </p>
                <p class="mt-1 font-mono text-[11px] break-words text-ink-400">{{ $state['detail'] }}</p>
            </div>
        @endforeach
    </section>

    @if ($health['dkim']['record'] && ! $health['dkim']['ok'])
        <div class="mb-6 rounded-lg border border-amber-900/60 bg-amber-950/20 p-4 text-sm">
            <p class="mb-2 text-amber-300">Registro DKIM para cadastrar na Cloudflare (DNS → Adicionar registro, tipo TXT):</p>
            <p class="mb-1 text-xs text-ink-400">Nome</p>
            <p class="mb-3 font-mono text-xs break-all text-ink-200">{{ $health['dkim']['record']['name'] }}</p>
            <p class="mb-1 text-xs text-ink-400">Conteúdo</p>
            <p class="font-mono text-xs break-all text-ink-200">{{ $health['dkim']['record']['value'] }}</p>
        </div>
    @endif

    <section class="mb-6 grid gap-3 sm:grid-cols-2">
        @foreach ($counts as $period => $byStatus)
            <div class="rounded-lg border border-ink-800 bg-ink-900 p-4">
                <p class="mb-2 text-[11px] tracking-widest text-ink-700 uppercase">Últimas {{ $period }}</p>
                <dl class="grid grid-cols-3 gap-2 text-xs">
                    @foreach ($byStatus as $status => $total)
                        <div>
                            <dt class="text-ink-700">{{ \App\Models\EmailMessage::STATUSES[$status] }}</dt>
                            <dd class="font-mono text-base {{ $total ? $tone($status) : 'text-ink-700' }}">{{ $total }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        @endforeach
    </section>

    <form method="POST" action="{{ route('admin.emails.test') }}" class="mb-8 flex max-w-xl flex-wrap items-end gap-3">
        @csrf
        <label class="block flex-1">
            <span class="{{ $label }}">Enviar um e-mail de teste para</span>
            <input name="to" type="email" required maxlength="255" value="{{ old('to', auth()->user()->email) }}" class="{{ $input }}">
        </label>
        <button class="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-ink-950 hover:brightness-110">Enviar teste</button>
    </form>

    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h2 class="text-xs font-semibold tracking-widest text-ink-700 uppercase">{{ $onlyErrors ? 'Com erro' : 'Últimos e-mails' }}</h2>
        <nav class="flex gap-1 text-sm">
            <a href="{{ route('admin.emails') }}" class="rounded-md px-2.5 py-1.5 {{ $onlyErrors ? 'text-ink-400 hover:text-ink-200' : 'bg-ink-850 text-white' }}">Todos</a>
            <a href="{{ route('admin.emails', ['erros' => 1]) }}" class="rounded-md px-2.5 py-1.5 {{ $onlyErrors ? 'bg-ink-850 text-white' : 'text-ink-400 hover:text-ink-200' }}">Só com erro</a>
        </nav>
    </div>

    <div class="overflow-x-auto rounded-lg border border-ink-800 bg-ink-900">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-ink-800 text-[11px] tracking-widest text-ink-700 uppercase">
                <tr>
                    <th class="hidden px-4 py-2 font-medium sm:table-cell">Quando</th>
                    <th class="px-4 py-2 font-medium">Para</th>
                    <th class="hidden px-4 py-2 font-medium md:table-cell">Assunto</th>
                    <th class="px-4 py-2 font-medium">Situação</th>
                    <th class="hidden px-4 py-2 font-medium lg:table-cell">Resposta</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-ink-800">
                @forelse ($messages as $message)
                    <tr>
                        <td class="hidden px-4 py-2 text-xs whitespace-nowrap text-ink-400 sm:table-cell">{{ $when($message->created_at) }}</td>
                        <td class="min-w-40 px-4 py-2 text-xs break-all text-ink-200">
                            {{ $message->to_address }}
                            <span class="block text-[11px] break-normal text-ink-700">{{ $message->kind }}<span class="sm:hidden"> · {{ $when($message->created_at) }}</span></span>
                        </td>
                        <td class="hidden px-4 py-2 text-xs text-ink-400 md:table-cell">{{ $message->subject }}</td>
                        <td class="px-4 py-2 text-xs {{ $tone($message->status) }}">
                            {{ $message->label() }}
                            @if ($message->attempts > 1)
                                <span class="block text-[11px] text-ink-700">{{ $message->attempts }} tentativas</span>
                            @endif
                            <span class="block text-[11px] text-ink-400 lg:hidden">{{ $message->last_response }}</span>
                        </td>
                        <td class="hidden px-4 py-2 font-mono text-[11px] break-words text-ink-400 lg:table-cell">{{ $message->last_response ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-6 text-sm text-ink-400">{{ $onlyErrors ? 'Nenhum e-mail com erro.' : 'Nenhum e-mail enviado ainda.' }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
