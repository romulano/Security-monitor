@extends('layouts.admin')

@section('title', 'Usuários')

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-sm font-semibold text-white">Usuários</h1>
            <p class="text-xs text-ink-700">
                {{ $users->total() }} conta(s). O cadastro é aberto, com captcha e o e-mail confirmado por link.
            </p>
        </div>

    </div>

    @if (session('new_password'))
        @php $new = session('new_password'); @endphp
        <div class="mb-6 rounded-lg border border-accent/40 bg-accent/5 p-4">
            <p class="text-sm font-semibold text-white">
                {{ $new['self'] ? 'Nova senha da sua conta de administrador' : 'Nova senha de '.$new['name'] }}
            </p>
            <p class="mb-3 text-xs text-ink-400">
                Entra com o e-mail <span class="text-ink-200">{{ $new['email'] }}</span>.
                Copie agora: ela não aparece de novo. A senha anterior deixou de valer e
                {{ $new['self'] ? 'os outros aparelhos foram desconectados' : 'a pessoa foi desconectada' }}.
            </p>

            <div class="flex gap-2">
                <input id="new-password" type="text" readonly value="{{ $new['password'] }}"
                       class="min-w-0 flex-1 rounded-lg border border-ink-800 bg-ink-900 px-3 py-2 font-mono text-sm text-white">
                <button type="button" data-copy="#new-password"
                        class="shrink-0 rounded-lg border border-ink-800 px-3 py-2 text-sm text-ink-200 hover:border-accent">Copiar</button>
            </div>
        </div>
    @endif

    <p class="mb-3 text-xs text-ink-700">
        Remover apaga a conta e tudo o que está ligado a ela (alertas, Radar, GitHub e canais);
        a pessoa perde o acesso na hora. Com o cadastro aberto, ela pode criar uma conta nova (e confirmar o e-mail de novo).
        Contas de empresa não têm “Remover”: elas saem pela própria empresa (a admin dela desativa a conta), para a empresa não ficar sem admin.
    </p>

    <div class="overflow-x-auto rounded-lg border border-ink-800">
        <table class="w-full text-left text-sm">
            <thead class="bg-ink-900 text-[11px] tracking-widest text-ink-700 uppercase">
                <tr>
                    <th class="px-3 py-2 font-medium">Conta</th>
                    <th class="px-3 py-2 font-medium">Acesso</th>
                    <th class="px-3 py-2 font-medium">Criada</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-ink-800">
                @foreach ($users as $account)
                    @php $self = $account->is(auth()->user()); @endphp
                    <tr>
                        <td class="px-3 py-2">
                            <span class="text-ink-200">{{ $account->name }}</span>
                            @if ($self)
                                <span class="text-[11px] text-ink-700">(você)</span>
                            @endif
                            <span class="block text-xs text-ink-700">{{ $account->email }}</span>
                            @if (! $account->hasVerifiedEmail())
                                <span class="mt-0.5 block text-[11px] text-amber-300">e-mail não confirmado</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-xs">
                            @if ($account->is_admin)
                                <span class="rounded-full border border-accent/40 px-2 py-0.5 text-[11px] text-accent">admin</span>
                            @else
                                <span class="rounded-full border border-ink-700 px-2 py-0.5 text-[11px] text-ink-400">usuário</span>
                            @endif
                            @if ($account->hasGithub())
                                <a href="https://github.com/{{ $account->github_login }}" target="_blank" rel="noopener noreferrer"
                                   class="ml-1 text-ink-400 hover:text-accent">GitHub {{ '@'.$account->github_login }}</a>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-xs text-ink-400">
                            <span title="{{ $account->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}">{{ $account->created_at->diffForHumans() }}</span>
                        </td>
                        <td class="px-3 py-2">
                            <div class="flex justify-end gap-2">
                                @if (! $account->is_admin)

                                    <form method="POST" action="{{ route('admin.users.destroy', $account) }}"
                                          data-confirm="Remover {{ $account->name }} ({{ $account->email }})? A conta e tudo o que está ligado a ela são apagados e a pessoa perde o acesso na hora. Não dá para desfazer.">
                                        @csrf
                                        @method('DELETE')
                                        <button class="rounded-md border border-ink-800 px-2.5 py-1 text-xs text-ink-700 hover:text-red-400">Remover</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $users->withQueryString()->links() }}</div>
@endsection
