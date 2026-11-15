<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A posse de domínio passa a ser uma só para a conta (DomainOwnership): comprovada num
 * serviço, vale nos outros. Antes, cada um guardava a sua: Notícias e o Radar na linha do
 * domínio (`monitored_domains`, um token por linha), a stack de testes e o webhook numa cópia
 * dessa linha e o monitor de sites em `security_sites` (outro token, por site).
 *
 * - `users.domain_token`: o token da conta, o do TXT de todo domínio novo;
 * - `owned_domains`: um nome por conta, com a prova (o token achado e quando), a última
 *   reconferência e os tokens de antes (`tokens`), que continuam valendo.
 *
 * Os dados de hoje entram sem perder nada:
 * - cada nome de cada conta vira uma linha, com todos os tokens que as linhas dele tinham;
 * - o nome comprovado em qualquer serviço entra comprovado, com a data e o token da prova mais
 *   antiga. Fica de fora só a cópia da stack cuja última reconferência viu o TXT sumir, e a prova
 *   "servidor" do monitor (não é TXT: continua só dele);
 * - as provas de antes entram como `legacy`: o Radar dizia que dava para apagar o registro, então
 *   elas não caem sozinhas até o TXT aparecer de novo numa reconferência;
 * - o token da conta é o da prova mais antiga dela (o que já está no DNS); sem prova, o da linha
 *   mais nova. Assim, para quase todo cliente, nada muda no DNS.
 *
 * Só tabela e coluna novas, e nulas: o código anterior no ar não as vê. As colunas antigas ficam
 * (o código novo mantém o `verified_at` e o token das linhas de Notícias e do Radar em dia).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("set local lock_timeout = '5s'");

        Schema::table('users', function (Blueprint $table) {
            $table->string('domain_token', 64)->nullable()->unique();
        });

        Schema::create('owned_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('domain', 253);
            // O serviço que pediu o nome primeiro: news | radar | tests | monitor | webhook.
            $table->string('source', 12);
            // Os tokens de antes da unificação (o de cada linha de cada serviço), que continuam valendo.
            $table->jsonb('tokens')->default('[]');
            // A prova: o token achado no TXT do próprio nome e quando.
            $table->string('proof_token', 64)->nullable();
            $table->timestampTz('verified_at')->nullable();
            // Prova de antes da unificação: não cai sozinha até o TXT ser visto de novo.
            $table->boolean('legacy')->default(false);
            // A última conferência do TXT (present | missing | unknown) e desde quando ele não aparece.
            $table->timestampTz('checked_at')->nullable();
            $table->string('check_status', 8)->nullable();
            $table->timestampTz('unseen_since')->nullable();
            $table->timestampTz('lost_at')->nullable();
            // Desde quando o agendador procura o TXT (por 72 h): depois que a posse cai, por exemplo.
            $table->timestampTz('waiting_since')->nullable();
            $table->timestampsTz();

            // Também é o índice da FK user_id.
            $table->unique(['user_id', 'domain']);
            // A reconferência diária anda por aqui.
            $table->index(['verified_at', 'checked_at']);
        });

        $this->copyExistingProofs();
    }

    public function down(): void
    {
        Schema::dropIfExists('owned_domains');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['domain_token']);
            $table->dropColumn('domain_token');
        });
    }

    /** Cada nome de cada conta, com os tokens e a prova mais antiga de todas as linhas dele. */
    private function copyExistingProofs(): void
    {
        $copies = DB::table('pentest_domains')->get(['domain_id', 'ownership_status', 'ownership_checked_at'])->keyBy('domain_id');
        $webhooks = DB::table('notification_channels')->whereNotNull('domain_id')->pluck('domain_id')->flip();
        $groups = [];

        foreach (DB::table('monitored_domains')->whereNotNull('user_id')->orderBy('created_at')->orderBy('id')->get() as $row) {
            $copy = $copies->get($row->id);
            $groups[$row->user_id][$row->domain][] = [
                'source' => match (true) {
                    $copy !== null => 'tests',
                    $row->profile_id !== null => 'news',
                    $webhooks->has($row->id) => 'webhook',
                    default => 'radar',
                },
                'token' => $row->verify_token,
                // A cópia da stack que viu o TXT sumir não prova mais nada.
                'verified_at' => $copy?->ownership_status === 'missing' ? null : $row->verified_at,
                'checked_at' => max((string) $row->verify_checked_at, (string) $copy?->ownership_checked_at) ?: null,
                'created_at' => $row->created_at,
            ];
        }

        foreach (DB::table('security_sites')->orderBy('created_at')->orderBy('id')->get() as $site) {
            $groups[$site->user_id][$site->host][] = [
                'source' => 'monitor',
                'token' => $site->verify_token,
                // A prova "servidor" (o site aponta para o Security Monitor) não é TXT e fica só com o monitor.
                'verified_at' => $site->ownership === 'txt' ? ($site->ownership_checked_at ?? $site->created_at) : null,
                'checked_at' => $site->ownership_checked_at,
                'created_at' => $site->created_at,
            ];
        }

        foreach ($groups as $userId => $names) {
            $accountToken = null;
            $oldestProof = null;

            foreach ($names as $name => $rows) {
                $proven = collect($rows)->filter(fn ($r) => $r['verified_at'] !== null)->sortBy(fn ($r) => (string) $r['verified_at'])->first();
                $tokens = collect($rows)->pluck('token')->filter()->unique()->values()->all();

                DB::table('owned_domains')->insert([
                    'user_id' => $userId,
                    'domain' => $name,
                    'source' => ($proven ?? $rows[0])['source'],
                    'tokens' => json_encode($tokens),
                    'proof_token' => $proven['token'] ?? null,
                    'verified_at' => $proven['verified_at'] ?? null,
                    'legacy' => $proven !== null,
                    'checked_at' => collect($rows)->pluck('checked_at')->filter()->max(),
                    'created_at' => collect($rows)->pluck('created_at')->filter()->min() ?? now(),
                    'updated_at' => now(),
                ]);

                if ($proven && ($oldestProof === null || (string) $proven['verified_at'] < $oldestProof)) {
                    $oldestProof = (string) $proven['verified_at'];
                    $accountToken = $proven['token'];
                }
            }

            // Sem prova nenhuma, o token da linha mais nova (a que o cliente está vendo agora).
            $accountToken ??= collect($names)->flatten(1)->sortByDesc(fn ($r) => (string) $r['created_at'])->pluck('token')->filter()->first();
            $taken = $accountToken !== null && DB::table('users')->where('domain_token', $accountToken)->exists();

            if ($accountToken !== null && ! $taken) {
                DB::table('users')->where('id', $userId)->update(['domain_token' => $accountToken]);
            }
        }
    }
};
