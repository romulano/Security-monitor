<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Os posts que a conta do Security Monitor publicou à mão antes da etapa "Post no
 * TabNews" existir: com a matéria de cada um registrada, a etapa não avalia
 * de novo a mesma matéria. A matéria que a fusão noturna juntou a outra é
 * seguida até a nova (case_merges); a que sumiu fica sem vínculo. Rodar de
 * novo não duplica nada.
 */
return new class extends Migration
{
    /** uuid da matéria => [título do post, endereço, quando saiu (UTC)] */
    private const POSTS = [
        'afc8e200-c647-4936-9a1f-b833be718969' => ['GitLab CVE-2026-97229 já é explorada, e com o Ruby do sistema atualizar só o GitLab não resolve', 'gitlab-cve-2026-97229-ja-e-explorada-e-com-o-ruby-do-sistema-atualizar-so-o-gitlab-nao-resolve', '2026-11-02 16:18:57'],
        'e508b1b8-aa46-425b-9242-d8699e0b3293' => ['Agentes de IA estão instalando servidores MCP arquivados, e o do Puppeteer tem RCE que não vai ser corrigido', 'agentes-de-ia-estao-instalando-servidores-mcp-arquivados-e-o-do-puppeteer-tem-rce-que-nao-vai-ser-corrigido', '2026-11-03 12:22:08'],
        '5a953920-a28c-4cf5-bcb7-284c83afe196' => ['LLMJacking: acesso roubado a LLMs gerou conta de até US$ 1 milhão, diz o Google; o que conferir na sua nuvem', 'llmjacking-acesso-roubado-a-llms-gerou-conta-de-ate-us-1-milhao-diz-o-google-o-que-conferir-na-sua-nuvem', '2026-11-03 13:12:46'],
        '7d0c68f2-471c-411c-8ef6-7fde424b6b0f' => ['CVE-2026-98432 no TCP do kernel Linux: o Ubuntu ainda não liberou a correção, e desligar o MPTCP não resolve', 'cve-2026-98432-no-tcp-do-kernel-linux-o-ubuntu-ainda-nao-liberou-a-correcao-e-desligar-o-mptcp-nao-resolve', '2026-11-03 13:48:30'],
        '151ab667-fb03-4aa4-9a84-cbfed35b50e7' => ['Node-RED sem adminAuth está sendo explorado com um único POST em /flows (CVE-2026-58115)', 'node-red-sem-adminauth-esta-sendo-explorado-com-um-unico-post-em-flows-cve-2026-58115', '2026-11-03 16:51:26'],
        '517a3630-7875-449c-b9c0-305d6662d068' => ['antd-img-crop: um force push trocou o main por um commit forjado que instala um stealer no npm run build', 'antd-img-crop-um-force-push-trocou-o-main-por-um-commit-forjado-que-instala-um-stealer-no-npm-run-build', '2026-11-03 17:42:10'],
    ];

    public function up(): void
    {
        foreach (self::POSTS as $uuid => [$title, $slug, $at]) {
            $url = 'https://www.tabnews.com.br/secmon/'.$slug;

            if (DB::table('tabnews_posts')->where('url', $url)->exists()) {
                continue;
            }

            $caseId = DB::table('cases')->where('uuid', $uuid)->value('id')
                ?? DB::table('case_merges')->where('uuid', $uuid)->value('case_id');

            // A matéria nova da fusão pode já estar registrada por outro post: o vínculo fica com o primeiro.
            if ($caseId && DB::table('tabnews_posts')->where('case_id', $caseId)->exists()) {
                $caseId = null;
            }

            DB::table('tabnews_posts')->insert([
                'case_id' => $caseId,
                'decision' => 'posted',
                'reason' => 'Publicado à mão, antes da etapa automática.',
                'title' => $title,
                'url' => $url,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('tabnews_posts')->whereIn('url', array_map(fn ($p) => 'https://www.tabnews.com.br/secmon/'.$p[1], self::POSTS))->delete();
    }
};
