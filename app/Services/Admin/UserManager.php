<?php

namespace App\Services\Admin;

use App\Exceptions\ActionRefused;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/** Admin → Usuários: quem tem conta e a senha nova de cada uma. */
class UserManager
{
    /** Os administradores primeiro, depois quem entrou antes. */
    public function list(): LengthAwarePaginator
    {
        return User::query()
            ->with('companyMembership.company')
            ->orderByDesc('is_admin')
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(40, pageName: 'usuarios');
    }

    /** Quantas contas pediram a liberação dos testes de segurança e esperam (PentestAccess). */
    public function pentestRequests(): int
    {
        return User::query()->whereNotNull('pentest_access_requested_at')->whereNull('pentest_access_granted_at')->where('is_admin', false)->count();
    }

    /**
     * Gera uma senha nova (a antiga deixa de valer, e as sessões abertas da conta caem)
     * e devolve o texto dela: aparece na tela uma vez e nunca mais. O admin troca a
     * própria senha e a de qualquer usuário comum; a de outro admin é dele.
     */
    public function resetPassword(User $target, User $admin, string $currentSession): string
    {
        $self = $target->is($admin);

        if ($target->is_admin && ! $self) {
            throw new ActionRefused('A senha de outro administrador só ele mesmo troca.');
        }

        $password = Str::password(16, symbols: false);

        // O remember_token novo invalida o "continuar conectado" que ficou em outros aparelhos.
        $target->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
        $target->endSessions(except: $self ? $currentSession : null);

        return $password;
    }
}
