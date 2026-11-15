<?php

namespace App\Services\Company;

use App\Models\Company;
use App\Models\CompanyAuditEntry;
use App\Models\CompanyInvite;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A auditoria da empresa (§4.9): cada serviço registra o que mudou; quem administra vê em "Atividade", com
 * filtros e CSV. Ninguém muda nem apaga uma linha; só a anonimização da conta apagada (anonymize).
 */
class CompanyAudit
{
    public const REMOVED = 'conta removida';

    /** As ações e como a tela as chama. */
    public const ACTIONS = [
        'company.created' => 'Empresa criada',
        'company.enabled' => 'Chave da empresa ligada',
        'company.disabled' => 'Chave da empresa desligada',
        'company.suspended' => 'Empresa suspensa',
        'company.resumed' => 'Empresa reativada',
        'company.limits' => 'Limites ajustados',
        'entry.sent' => 'Link de entrada enviado à admin',
        'entry.joined' => 'A admin entrou pela primeira vez',
        'invite.sent' => 'Convite enviado',
        'invite.resent' => 'Convite reenviado',
        'invite.cancelled' => 'Convite cancelado',
        'invite.accepted' => 'Convite aceito',
        'member.role' => 'Papel alterado',
        'admin.transferred' => 'Administração passada',
        'member.deactivated' => 'Conta removida',
        'member.reactivated' => 'Conta reativada',
        'member.purged' => 'Conta apagada (30 dias depois de removida)',
        'account.password' => 'Nova senha gerada pela Equipe do Security Monitor',
        'tests.granted' => 'Testes de segurança liberados',
        'tests.revoked' => 'Liberação dos testes de segurança retirada',
        'two_factor.required' => 'Verificação em duas etapas exigida de todas as contas',
        'two_factor.optional' => 'Verificação em duas etapas voltou a ser opcional',
        'two_factor.reset' => 'Verificação em duas etapas redefinida',
        'sector.created' => 'Setor criado',
        'sector.renamed' => 'Setor renomeado',
        'sector.member_added' => 'Conta posta num setor',
        'sector.member_removed' => 'Conta tirada de um setor',
        'sector.manager_set' => 'Responsável do setor marcado',
        'sector.manager_unset' => 'Responsável do setor desmarcado',
        'item.sector_changed' => 'Setor de um monitoramento trocado',
        'sector.deleted' => 'Setor apagado',
        'domain.added' => 'Domínio cadastrado',
        'domain.removed' => 'Domínio removido',
        'subdomain.added' => 'Subdomínio cadastrado',
        'subdomain.removed' => 'Subdomínio tirado',
        'subdomains.discovered' => 'Descoberta automática de subdomínios',
        'channel.added' => 'Canal de aviso conectado',
        'channel.removed' => 'Canal de aviso removido',
    ];

    /** Como a empresa vê quem fez: o nome da pessoa da empresa; o Security Monitor, sem o nome do funcionário. */
    public const ORIGINS = ['company' => 'empresa', 'secmon' => 'Security Monitor', 'system' => 'automático'];

    /** @param  array<string, scalar|null>  $data  nada de dado pessoal aqui (nome e e-mail vão no ator e no alvo) */
    public function record(Company|int $company, ?User $actor, string $action, ?User $target = null, ?string $targetLabel = null, array $data = []): void
    {
        CompanyAuditEntry::query()->forceCreate([
            'company_id' => $company instanceof Company ? $company->id : $company,
            'actor_kind' => match (true) {
                $actor === null => 'system',
                (bool) $actor->is_admin => 'secmon',
                default => 'company',
            },
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'actor_email' => $actor?->email,
            'action' => $action,
            'target_user_id' => $target?->id,
            'target_label' => $targetLabel ?? $target?->name,
            'data' => $data,
        ]);
    }

    /** O que o admin do Security Monitor faz numa conta em Admin → Usuários entra na auditoria da empresa dela, se ela for de empresa. */
    public function recordForAccount(User $target, User $admin, string $action): void
    {
        if (($membership = $target->companyMembership()->first()) !== null) {
            $this->record($membership->company_id, $admin, $action, $target);
        }
    }

    /** A conta foi apagada: nas linhas dela (como ator ou alvo, ou o e-mail convidado), nome e e-mail somem. */
    public function anonymize(User $user): void
    {
        // O e-mail de hoje e o de cada convite aceito: quem trocou de e-mail depois de entrar deixava o antigo nos
        // convites. Chame antes de apagar a conta: depois, o convite já não aponta para ela (K3 do Red Team).
        $emails = CompanyInvite::query()->where('accepted_user_id', $user->id)->pluck('email')->push($user->email)->unique()->values();

        DB::table('company_audit')->where('actor_id', $user->id)->update(['actor_name' => self::REMOVED, 'actor_email' => null]);
        DB::table('company_audit')
            ->where(function ($query) use ($user, $emails) {
                $query->where('target_user_id', $user->id);

                foreach ($emails as $email) {
                    $query->orWhereRaw('lower(target_label) = lower(?)', [$email]);
                }
            })
            ->update(['target_label' => self::REMOVED]);
    }

    /** A célula do CSV que a planilha leria como fórmula (=, +, -, @, tab, CR) vai como texto, com o apóstrofo (K1). */
    public static function cell(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    /** @param  array{from?: ?string, to?: ?string, actor?: ?int, action?: ?string}  $filters */
    public function list(Company $company, array $filters): LengthAwarePaginator
    {
        return $this->query($company, $filters)->paginate(50, pageName: 'pagina')->withQueryString();
    }

    /** Quem aparece na auditoria da empresa, para o filtro por pessoa: as pessoas da empresa e a Equipe do Security Monitor. */
    public function actors(Company $company): Collection
    {
        $people = CompanyAuditEntry::query()->where('company_id', $company->id)->where('actor_kind', 'company')->whereNotNull('actor_id')
            ->orderBy('actor_name')->pluck('actor_name', 'actor_id');
        $secmon = CompanyAuditEntry::query()->where('company_id', $company->id)->where('actor_kind', 'secmon')->exists();

        return $secmon ? $people->put('secmon', 'Equipe do Security Monitor') : $people;
    }

    /** Quem fez, como a empresa vê: o funcionário do Security Monitor aparece só como Equipe do Security Monitor. */
    public function who(CompanyAuditEntry $entry): string
    {
        return match ($entry->actor_kind) {
            'secmon' => 'Equipe do Security Monitor',
            'system' => 'Security Monitor (automático)',
            default => (string) $entry->actor_name,
        };
    }

    /** A frase da linha: o que aconteceu, com o alvo e os detalhes em texto. */
    public function describe(CompanyAuditEntry $entry): string
    {
        $target = $entry->target_label;
        $data = $entry->data ?? [];
        $label = self::ACTIONS[$entry->action] ?? $entry->action;

        return match ($entry->action) {
            'company.created' => 'Empresa criada'.($target ? ", com a admin {$target}" : ''),
            'entry.joined' => $label,
            'member.role' => "Papel alterado: {$target} (".($data['de'] ?? '?').' → '.($data['para'] ?? '?').')',
            'admin.transferred' => "Administração passada para {$target}",
            'sector.created' => 'Setor criado: '.($data['setor'] ?? '?'),
            'sector.renamed' => 'Setor renomeado: '.($data['de'] ?? '?').' → '.($data['para'] ?? '?'),
            'sector.member_added' => "{$target} entrou no setor ".($data['setor'] ?? '?'),
            'sector.member_removed' => "{$target} saiu do setor ".($data['setor'] ?? '?'),
            'sector.manager_set' => "{$target} passou a responder pelo setor ".($data['setor'] ?? '?'),
            'sector.manager_unset' => "{$target} deixou de responder pelo setor ".($data['setor'] ?? '?'),
            'sector.deleted' => 'Setor apagado: '.($data['setor'] ?? '?').'. '.(isset($data['contas']) ? Sectors::summary($data) : ''),
            'item.sector_changed' => ($data['tipo'] ?? 'Item')." {$target}: setor ".($data['de'] ?? '?').' → '.($data['para'] ?? '?'),
            'domain.added' => "Domínio cadastrado: {$target} (setor: ".($data['setor'] ?? '?').')',
            'domain.removed' => "Domínio removido: {$target} (setor: ".($data['setor'] ?? '?').'). '.(isset($data['alertas']) ? CompanyDomains::summary($data) : ''),
            'subdomains.discovered' => "Descoberta automática de subdomínios em {$target}: ".collect($data)->map(fn ($n, $what) => "{$n} {$what}")->implode(', '),
            'channel.added', 'channel.removed' => $label.": {$target} (".($data['onde'] ?? '?').')',
            'company.limits' => 'Limites ajustados: '.collect($data)->map(fn ($change, $name) => "{$name}: {$change}")->implode('; '),
            default => $label.($target ? ": {$target}" : '').(isset($data['papel']) ? " como {$data['papel']}" : '')
                .(isset($data['setores']) ? " (setores: {$data['setores']})" : ''),
        };
    }

    /**
     * O CSV com os mesmos filtros da tela, do mais novo ao mais antigo, para o Excel em português: BOM, ";" e a data
     * dd/mm/aaaa hh:mm. A célula que começa com = + - @ ganha um apóstrofo, para não virar fórmula.
     */
    public function export(Company $company, array $filters): StreamedResponse
    {
        $query = $this->query($company, $filters);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, ['quando', 'quem', 'e-mail', 'origem', 'tipo', 'o que aconteceu'], ';');

            foreach ($query->reorder()->lazyByIdDesc(500, 'id') as $row) {
                fputcsv($out, array_map(self::cell(...), [
                    $row->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
                    $this->who($row),
                    $row->actor_kind === 'company' ? $row->actor_email : null,
                    self::ORIGINS[$row->actor_kind] ?? $row->actor_kind,
                    self::ACTIONS[$row->action] ?? $row->action,
                    $this->describe($row),
                ]), ';');
            }

            fclose($out);
        }, 'atividade-'.now('America/Sao_Paulo')->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function query(Company $company, array $filters)
    {
        $zone = 'America/Sao_Paulo';

        return CompanyAuditEntry::query()->where('company_id', $company->id)
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', Carbon::parse($from, $zone)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', Carbon::parse($to, $zone)->endOfDay()))
            ->when($filters['actor'] ?? null, fn ($q, $actor) => $actor === 'secmon' ? $q->where('actor_kind', 'secmon') : $q->where('actor_kind', 'company')->where('actor_id', $actor))
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->orderByDesc('created_at')->orderByDesc('id');
    }
}
