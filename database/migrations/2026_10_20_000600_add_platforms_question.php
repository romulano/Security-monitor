<?php

use Database\Seeders\InterviewQuestionSeeder;
use Database\Seeders\TechCatalogSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WordPress só aparecia em "Quais frameworks?", para quem marcava PHP e dizia
 * usar framework: quem não desenvolve, mas tem site em WordPress, não
 * conseguia marcar. Entra a pergunta "Site, loja e plataformas" (passo 4, sem
 * filtro por linguagem) com os CMS e os criadores de sites e apps (Lovable).
 * WordPress vira CMS, e os frameworks ficam só com frameworks.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            (new TechCatalogSeeder)->run();

            DB::table('tech_catalog')->where('slug', 'wordpress')->where('category', 'framework')->update(['category' => 'cms']);

            // Só se o admin não mexeu nas categorias da pergunta.
            DB::table('interview_questions')->where('key', 'frameworks')->where('catalog_category', 'framework,cms')
                ->update(['catalog_category' => 'framework']);

            if (! DB::table('interview_questions')->where('key', 'platforms')->exists()) {
                // Abre a vaga logo antes de "Ferramentas da empresa".
                $at = DB::table('interview_questions')->where('key', 'tools')->value('position');

                if ($at !== null) {
                    DB::table('interview_questions')->where('position', '>=', $at)->increment('position');
                }

                (new InterviewQuestionSeeder)->run();

                if ($at !== null) {
                    DB::table('interview_questions')->where('key', 'platforms')->update(['position' => $at]);
                }
            }

            // CMS marcado em "frameworks" passa para a pergunta nova.
            $cms = DB::table('tech_catalog')->whereIn('category', ['cms', 'builder'])->pluck('slug')->all();

            foreach (DB::table('alert_profile_answers')->where('question_key', 'frameworks')->get() as $answer) {
                $values = (array) json_decode($answer->value, true);
                $moved = array_values(array_intersect($values, $cms));

                if ($moved === []) {
                    continue;
                }

                DB::table('alert_profile_answers')->where('profile_id', $answer->profile_id)->where('question_key', 'frameworks')
                    ->update(['value' => json_encode(array_values(array_diff($values, $cms))), 'updated_at' => now()]);

                $current = DB::table('alert_profile_answers')->where('profile_id', $answer->profile_id)->where('question_key', 'platforms')->value('value');
                DB::table('alert_profile_answers')->updateOrInsert(
                    ['profile_id' => $answer->profile_id, 'question_key' => 'platforms'],
                    ['value' => json_encode(array_values(array_unique(array_merge((array) json_decode((string) $current, true), $moved)))), 'updated_at' => now(), 'created_at' => now()],
                );
            }
        });
    }

    public function down(): void
    {
        $at = DB::table('interview_questions')->where('key', 'platforms')->value('position');
        DB::table('interview_questions')->where('key', 'platforms')->delete();

        if ($at !== null) {
            DB::table('interview_questions')->where('position', '>', $at)->decrement('position');
        }

        DB::table('interview_questions')->where('key', 'frameworks')->where('catalog_category', 'framework')->update(['catalog_category' => 'framework,cms']);
        DB::table('tech_catalog')->where('slug', 'wordpress')->update(['category' => 'framework']);
        DB::table('tech_catalog')->where('slug', 'lovable')->delete();
    }
};
