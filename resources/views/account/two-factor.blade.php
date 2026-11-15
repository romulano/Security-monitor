@extends('layouts.app')

@section('title', 'Verificação em duas etapas · Security Monitor')

@php
    $codeError = $errors->getBag('two_factor')->first('two_factor_code');
    $support = config('secmon.contact_email');
@endphp

@section('content')
    <div class="mx-auto max-w-xl space-y-6 py-6">
        <div>
            {{-- Enquanto a 2FA exigida (empresa ou admin) bloqueia, Minha conta só voltaria para cá. --}}
            @unless ($blocked)
                <a href="{{ route('account.show') }}" class="text-sm text-ink-400 hover:text-accent">← Minha conta</a>
            @endunless
            <h1 class="mt-2 text-lg font-semibold text-white">Verificação em duas etapas</h1>
            <p class="mt-1 text-sm text-ink-400">
                Com ela ligada, entrar pede a senha e um código de 6 dígitos que um app autenticador do seu celular
                gera a cada 30 segundos. Quem descobrir a sua senha não entra sem o seu celular.
            </p>
        </div>

        @if ($codes !== [])
            <section class="rounded-lg border border-accent/40 bg-accent/5 p-5">
                <h2 class="text-sm font-semibold text-white">Guarde os seus códigos de recuperação</h2>
                <p class="mt-1 text-sm text-ink-400">
                    Se perder o celular, entre com um destes códigos no lugar do código do app. Cada um vale uma vez só.
                    Guarde fora do celular (no gerenciador de senhas do computador ou num papel): eles não aparecem de novo.
                </p>
                <ol class="mt-4 grid grid-cols-2 gap-2 font-mono text-sm text-white">
                    @foreach ($codes as $code)
                        <li class="rounded-md border border-ink-800 bg-ink-900 px-3 py-1.5 text-center tracking-wider">{{ $code }}</li>
                    @endforeach
                </ol>
                <textarea id="recovery-codes" class="sr-only" readonly tabindex="-1" aria-hidden="true">{{ implode("\n", $codes) }}</textarea>
                <button type="button" data-copy="#recovery-codes"
                        class="mt-4 min-h-11 rounded-lg border border-ink-800 px-4 text-sm text-ink-200 hover:border-accent">
                    Copiar os códigos
                </button>
                @if ($next && $status['state'] === 'on')
                    <a href="{{ $next }}" class="mt-4 ml-2 inline-flex min-h-11 items-center rounded-lg bg-accent px-4 text-sm font-semibold text-ink-950 hover:brightness-110">Guardei os códigos: voltar ao admin</a>
                @endif
                <p class="mt-3 text-xs text-ink-400">
                    Perdeu o celular e os códigos? Só o suporte do Security Monitor consegue devolver o acesso: escreva para
                    <a href="mailto:{{ $support }}" class="text-accent hover:underline">{{ $support }}</a>.
                </p>
            </section>
        @endif

        <section class="rounded-lg border border-ink-800 bg-ink-900 p-5">
            @if ($status['state'] === 'off')
                <h2 class="text-sm font-semibold text-white">Desligada</h2>
                <p class="mt-1 text-sm text-ink-400">
                    Você vai precisar do celular com um app autenticador instalado (Google Authenticator, Microsoft
                    Authenticator, 1Password, Aegis ou outro). Leva um minuto:
                </p>
                <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm text-ink-400">
                    <li>ler um QR code com o app;</li>
                    <li>digitar o código que ele mostrar;</li>
                    <li>guardar os códigos de recuperação.</li>
                </ol>
            @elseif ($status['state'] === 'pending')
                <h2 class="text-sm font-semibold text-white">Falta confirmar</h2>
            @else
                <h2 class="text-sm font-semibold text-white">Ligada</h2>
                <p class="mt-1 text-sm text-ink-400">
                    Desde {{ $status['since']->timezone('America/Sao_Paulo')->format('d/m/Y') }}.
                    {{ $status['remaining'] }} de {{ \App\Services\Auth\TwoFactor::RECOVERY_CODES }} códigos de recuperação ainda valem.
                </p>
                @if ($status['remaining'] <= 3)
                    <p class="mt-3 rounded-lg border border-amber-800/60 bg-amber-950/30 px-3 py-2 text-sm text-amber-200">
                        {{ match ($status['remaining']) { 0 => 'Nenhum código de recuperação vale mais.', 1 => 'Resta só 1 código de recuperação.', default => 'Restam só '.$status['remaining'].' códigos de recuperação.' } }}
                        Gere códigos novos e guarde fora do celular.
                    </p>
                @endif
            @endif

            @if ($reauth['recent'])
                <p class="mt-3 rounded-lg border border-emerald-800/60 bg-emerald-950/30 px-3 py-2 text-xs text-emerald-200">
                    Você confirmou que é você {{ $reauth['method'] }}. Até as {{ $reauth['until']->timezone('America/Sao_Paulo')->format('H:i') }}, as mudanças aqui não pedem a senha.
                </p>
            @endif

            @if ($status['state'] === 'pending' && $status['enrollment'] === null)
                {{-- O QR só aparece com a confirmação recente desta sessão: outra sessão da conta não vê o segredo. --}}
                <p class="mt-1 text-sm text-ink-400">
                    Por segurança, o QR só aparece logo depois de você confirmar que é você. Peça um QR novo e leia de novo
                    com o app: o anterior deixa de valer, e o Security Monitor que você talvez já tenha cadastrado no app precisa ser apagado de lá.
                </p>
                <form method="POST" action="{{ route('account.two-factor.start') }}" class="mt-4 space-y-3">
                    @csrf
                    @include('account.partials.two-factor-password')
                    <button type="submit" class="min-h-11 rounded-lg bg-accent px-4 text-sm font-semibold text-ink-950 hover:brightness-110">
                        Mostrar um QR novo
                    </button>
                </form>
                <form method="POST" action="{{ route('account.two-factor.cancel') }}" class="mt-5 border-t border-ink-800 pt-4">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="min-h-11 text-sm text-ink-400 hover:text-red-400">Cancelar a configuração</button>
                </form>
            @elseif ($status['state'] === 'pending')
                <h3 class="mt-5 text-sm font-semibold text-white">1. Cadastre o Security Monitor no app autenticador</h3>
                <a href="{{ $status['enrollment']['url'] }}"
                   class="mt-3 flex min-h-11 w-full items-center justify-center rounded-lg bg-accent px-4 text-sm font-semibold text-ink-950 hover:brightness-110 sm:hidden">
                    Abrir no app autenticador
                </a>
                <div class="mt-4 flex flex-col gap-4 sm:flex-row sm:items-start">
                    <div class="w-fit shrink-0 rounded-lg bg-white p-2 [&>svg]:h-44 [&>svg]:w-44" role="img" aria-label="QR para cadastrar o Security Monitor no app autenticador">
                        {!! $status['enrollment']['svg'] !!}
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm text-ink-400">Leia o QR com o app. Não dá? No app, escolha digitar uma chave e use esta, com o nome Security Monitor:</p>
                        <p data-secret class="mt-2 rounded-lg border border-ink-800 bg-ink-850 px-3 py-2 font-mono text-sm tracking-wider break-words text-white">{{ $status['enrollment']['secret'] }}</p>
                        <textarea id="two-factor-secret" class="sr-only" readonly tabindex="-1" aria-hidden="true">{{ str_replace(' ', '', $status['enrollment']['secret']) }}</textarea>
                        <button type="button" data-copy="#two-factor-secret"
                                class="mt-2 min-h-11 rounded-lg border border-ink-800 px-4 text-sm text-ink-200 hover:border-accent">Copiar a chave</button>
                    </div>
                </div>

                <h3 class="mt-6 text-sm font-semibold text-white">2. Digite o código que o app mostra</h3>
                <form method="POST" action="{{ route('account.two-factor.confirm') }}" class="mt-3 space-y-3">
                    @csrf
                    <div>
                        <label for="two-factor-code" class="mb-1 block text-xs tracking-widest text-ink-700 uppercase">Código do app</label>
                        <input id="two-factor-code" name="two_factor_code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required
                               @if ($codeError) aria-invalid="true" aria-describedby="two-factor-code-error" autofocus @endif
                               class="min-h-11 w-36 rounded-lg border {{ $codeError ? 'border-red-500' : 'border-ink-800' }} bg-ink-850 px-3 font-mono text-sm tracking-widest focus:border-accent">
                        @if ($codeError)
                            <p id="two-factor-code-error" class="mt-2 text-xs text-red-400">{{ $codeError }}</p>
                        @endif
                    </div>
                    @include('account.partials.two-factor-password')
                    <button type="submit" class="min-h-11 rounded-lg bg-accent px-4 text-sm font-semibold text-ink-950 hover:brightness-110">
                        Confirmar e ligar
                    </button>
                </form>

                <form method="POST" action="{{ route('account.two-factor.cancel') }}" class="mt-5 border-t border-ink-800 pt-4">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="min-h-11 text-sm text-ink-400 hover:text-red-400">Cancelar a configuração</button>
                </form>
            @elseif ($status['state'] === 'off')
                <form method="POST" action="{{ route('account.two-factor.start') }}" class="mt-4 space-y-3">
                    @csrf
                    @include('account.partials.two-factor-password')
                    <button type="submit" class="min-h-11 rounded-lg bg-accent px-4 text-sm font-semibold text-ink-950 hover:brightness-110">
                        Ligar a verificação em duas etapas
                    </button>
                </form>
            @else
                {{-- Uma senha para as duas ações: cada botão vai para a sua rota (formaction) e tem a sua pergunta. --}}
                <form method="POST" action="{{ route('account.two-factor.codes') }}" class="mt-4 space-y-3">
                    @csrf
                    @include('account.partials.two-factor-password')
                    <div class="flex flex-wrap gap-3">
                        <button type="submit" data-confirm="Gerar códigos de recuperação novos? Os que você guardou deixam de valer."
                                class="min-h-11 rounded-lg border border-ink-800 px-4 text-sm text-ink-200 hover:border-accent">
                            Gerar códigos novos
                        </button>
                        @unless ($companyRequires)
                            <button type="submit" formaction="{{ route('account.two-factor.disable') }}" name="_method" value="DELETE"
                                    data-confirm="Desligar a verificação em duas etapas? Quem descobrir a sua senha volta a conseguir entrar, e os códigos de recuperação deixam de valer."
                                    class="min-h-11 rounded-lg border border-red-900/60 px-4 text-sm text-red-300 hover:border-red-500">
                                Desligar
                            </button>
                        @endunless
                    </div>
                    @if ($companyRequires)
                        <p class="text-xs text-ink-400">A sua empresa exige a verificação em duas etapas, então ela não pode ser desligada. Para trocar de celular, peça a quem administra a conta da empresa para redefinir a sua.</p>
                    @endif
                </form>
            @endif
        </section>

        @include('account.partials.reauth', ['twoFactorBox' => false, 'intro' => 'Não sabe a senha? Confirme que é você de outro jeito e volte a esta página nos 10 minutos seguintes:'])
    </div>
@endsection
