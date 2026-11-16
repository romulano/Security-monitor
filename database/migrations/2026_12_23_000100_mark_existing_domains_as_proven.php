<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A posse por TXT saiu: todo domínio nasce comprovado. Os cadastrados antes ficaram com `verified_at`
 * nulo e sumiriam das telas que listam só os comprovados (Chaves, o passo 1 do Radar e dos testes).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('owned_domains')->whereNull('verified_at')->update([
            'verified_at' => DB::raw('coalesce(created_at, now())'),
            'unseen_since' => null,
            'lost_at' => null,
            'waiting_since' => null,
        ]);

        DB::table('monitored_domains')->whereNull('verified_at')->update(['verified_at' => DB::raw('coalesce(created_at, now())')]);

        DB::table('lookalike_watches')->where('status', 'waiting')->update(['status' => 'active', 'next_scan_at' => now()]);

        DB::table('notification_channels')->where('type', 'webhook')->where('status', 'pending')->update(['status' => 'active']);
    }

    public function down(): void {}
};
