<?php

namespace App\Services\Prompts;

use App\Services\Alerts\AlertReviewer;
use App\Services\Alerts\CustomAnswerExpander;
use App\Services\Enrichment\ArticleMediaReader;
use App\Services\Enrichment\ErrorExplainer;
use App\Services\Enrichment\HermesEnricher;
use App\Services\Exploits\ExploitExplainer;
use App\Services\Github\ExtraInterpreter;
use App\Services\Github\GithubJudge;
use App\Services\Github\GithubTriage;
use App\Services\Lookalike\LookalikeReviewer;
use App\Services\Merge\MergeEditor;
use App\Services\Pentest\Hypotheses\HypothesisPrioritizer;
use App\Services\Review\ReportReviewer;
use App\Services\Vulnerability\VulnerabilityExplainer;
use App\Support\TagCode;

/**
 * As etapas que mandam prompt ao hermes e os trechos editáveis de cada uma.
 * O texto padrão de cada trecho fica em resources/prompts/<trecho>.txt; a
 * parte fixa (formato da resposta, etiquetas do texto de terceiros) fica no
 * service, porque o código que lê a resposta depende dela.
 */
class PromptCatalog
{
    /** Código aleatório que cerca o texto de terceiros, novo a cada chamada. */
    public const NONCE = '{código}';

    /** Grupos da aba, na ordem em que aparecem. */
    public const GROUPS = [
        'coleta' => 'Coleta e análise',
        'materia' => 'Explicações da matéria',
        'fusao' => 'Fusão de matérias',
        'alertas' => 'Alertas',
        'radar' => 'Radar de domínios',
        'github' => 'Monitoramento do GitHub',
        'testes' => 'Testes de segurança',
    ];

    /**
     * Etapas, por grupo e na ordem em que acontecem. `off`: o que acontece
     * com a etapa desligada (ela é pulada e a IA não é chamada). `default_on`
     * falso: a etapa nasce desligada, até o admin ligar.
     */
    public const STAGES = [
        'analise' => [
            'group' => 'coleta',
            'label' => 'Análise de cada artigo',
            'when' => 'A cada artigo coletado (fila enrich), na reanálise de uma matéria e depois que o admin cola o texto de um artigo.',
            'what' => 'Lê o artigo, pesquisa na web o que o fornecedor publicou (boletim, GHSA, PR da correção, versões) e escreve título, resumo, criticidade e indicadores, em inglês e pt-BR. Quando o artigo cai numa matéria que já existe, decide se é a mesma história e enriquece o relatório dela.',
            'off' => 'Os artigos passam pela extração automática, sem IA. É a mesma chave do bot de análise em Configurações.',
            'parts' => ['enrichment.system', 'enrichment.existing_case'],
        ],
        'revisor' => [
            'group' => 'coleta',
            'label' => 'Revisor do relatório',
            'when' => 'Logo depois da análise, quando o artigo abre uma matéria ou muda o relatório de uma, inclusive no "Refazer com a IA", antes de o texto ir ao ar (no mesmo job da análise, na fila enrich).',
            'what' => 'Confere o rascunho contra o texto da fonte: fato inventado, números, versões, datas e CVEs (inclusive CVE de outro fornecedor ou antiga tratada como o assunto), criticidade, título, resumo, tradução pt-BR e campos obrigatórios. Aprova, ou pede à análise que refaça uma parte ou tudo, no máximo 2 vezes; depois do 2º ajuste o parecer é o final, e a matéria reprovada sai com a última versão e fica com "revisão pendente" no admin. O parecer e a refação vão para a mesma IA da análise (hermes, OpenRouter…); IA fora, driver sem IA (extração automática) ou sem tempo: a revisão é pulada e registrada. De 1 a 5 chamadas a mais por versão (até 3 pareceres e 2 refações).',
            'off' => 'O relatório vai ao ar como a análise escreveu, sem revisão (o fluxo de antes).',
            'default_on' => false,
            'parts' => ['cases.review', 'cases.review_redo'],
        ],
        'midia' => [
            'group' => 'coleta',
            'label' => 'Leitura de prints e imagens',
            'when' => 'Quando o admin cola os prints ou o HTML de um artigo que o crawler não conseguiu ler.',
            'what' => 'Transcreve o texto dos prints, junta com o texto colado e decide, imagem por imagem, se ela faz parte da matéria.',
            'off' => 'Os prints e as imagens do HTML são ignorados: vale só o texto colado.',
            'parts' => ['media.system', 'media.tasks'],
        ],
        'erros-explicar' => [
            'group' => 'coleta',
            'label' => 'Motivo dos erros de análise',
            'when' => 'Quando a análise de um artigo falha com um erro que o mapa fixo de motivos não conhece. Uma vez por erro: o mesmo erro (sem números e ids) em outro artigo usa a explicação guardada.',
            'what' => 'Explica ao admin, em uma ou duas frases, por que a análise falhou. Recebe só o erro técnico, o driver, a etapa e o tamanho do artigo; nunca o texto do artigo.',
            'off' => 'Os erros que o mapa fixo não conhece aparecem em Admin → Coletas com o texto técnico e "motivo ainda não explicado".',
            'parts' => ['errors.explain'],
        ],
        'vulnerabilidade' => [
            'group' => 'materia',
            'label' => 'Explicação da vulnerabilidade',
            'when' => 'Quando uma matéria de vulnerabilidade fica pronta: abriu, ganhou informação nova ou saiu da fusão de matérias. Espera 15 minutos para os artigos da mesma coleta entrarem juntos.',
            'what' => 'Lê a matéria e os artigos dela e explica a falha: o que é, como funciona, por que é vulnerável, onde está (quando é público), como foi descoberta e, quando há, a PoC: pública (com o link) ou existente mas privada. A matéria mostra em "Entenda a falha".',
            'off' => 'Matérias novas ficam sem "Entenda a falha"; as que já têm continuam com a explicação de antes.',
            'parts' => ['cases.vulnerability'],
        ],
        'exploits' => [
            'group' => 'materia',
            'label' => 'Explicação de exploit',
            'when' => 'Quando uma matéria ganha um exploit público do Exploit-DB.',
            'what' => 'Lê o arquivo do exploit e explica a um defensor o que ele faz, em inglês e pt-BR, sem ensinar a usar.',
            'off' => 'Exploits novos ficam sem explicação: a matéria mostra só os dados do Exploit-DB.',
            'parts' => ['exploits.explain'],
        ],
        'fusao-agrupar' => [
            'group' => 'fusao',
            'label' => 'Agrupar matérias',
            'when' => 'Duas vezes ao dia (00:00 e 12:00 de Brasília), para cada grupo de matérias parecidas.',
            'what' => 'Confirma quais matérias do grupo contam a mesma história e devem virar uma só.',
            'off' => 'Nenhuma matéria é juntada. As consolidadas ainda são atualizadas se "Enriquecer a consolidada" estiver ligada.',
            'parts' => ['merge.confirm'],
        ],
        'fusao-escrever' => [
            'group' => 'fusao',
            'label' => 'Escrever do zero',
            'when' => 'Na fusão de matérias, no primeiro agrupamento de uma história (nenhuma das matérias era consolidada).',
            'what' => 'Escreve a matéria consolidada a partir da análise de cada fonte, com relatos divergentes.',
            'off' => 'Grupos novos (sem matéria consolidada) não são juntados.',
            'parts' => ['merge.write'],
        ],
        'fusao-enriquecer' => [
            'group' => 'fusao',
            'label' => 'Enriquecer a consolidada',
            'when' => 'Na fusão de matérias, quando a matéria já é consolidada e recebeu fontes que o relatório ainda não leu, ou entra numa fusão em cadeia.',
            'what' => 'Atualiza o relatório consolidado com o material novo, sem refazê-lo: mantém tudo, corrige o que estava errado e registra as divergências.',
            'off' => 'A matéria consolidada não é atualizada pela fusão e não entra em fusão em cadeia.',
            'parts' => ['merge.enrich'],
        ],
        'juiz-alertas' => [
            'group' => 'alertas',
            'label' => 'Juiz de alertas',
            'when' => 'Antes de cada alerta em tempo real sair para um cliente (fila judge).',
            'what' => 'Dá a chance, de 0 a 100, de o cliente achar o alerta relevante, aprendendo com os votos anteriores dele.',
            'off' => 'O alerta sai sem o parecer da IA, só pelo casamento com o perfil. É a mesma chave do parecer da IA em Alertas.',
            'parts' => ['alerts.judge'],
        ],
        'respostas-escritas' => [
            'group' => 'alertas',
            'label' => 'Tradução das respostas escritas',
            'when' => 'Quando um cliente escreve uma resposta própria na entrevista de alertas (setor, região, tipo de ataque, tecnologia).',
            'what' => 'Traduz a resposta para os termos em inglês que aparecem nas notícias, para o alerta conseguir casar.',
            'off' => 'A resposta do cliente vale como termo de busca do jeito que foi escrita, sem tradução.',
            'parts' => ['custom.system', 'custom.sector', 'custom.region', 'custom.attack_type', 'custom.tech'],
        ],
        'juiz-radar' => [
            'group' => 'radar',
            'label' => 'Juiz do radar de domínios',
            'when' => 'Antes de avisar um cliente sobre um domínio parecido com o dele (fila lookalikes).',
            'what' => 'Olha as capturas de tela do site do cliente e do domínio suspeito e dá a chance, de 0 a 100, de ser golpe.',
            'off' => 'O aviso de domínio parecido sai sem o parecer da IA.',
            'parts' => ['lookalikes.judge'],
        ],
        'github-adicional' => [
            'group' => 'github',
            'label' => 'Leitura do texto adicional',
            'when' => 'Quando o cliente escreve ou muda o texto "Adicional" da entrevista do GitHub (só se houver texto), ou antes da varredura, se a leitura ficou pendente.',
            'what' => 'Tira do texto nomes de chaves, prefixos de token, repositórios privados, endereços internos e termos de busca, e resume em uma frase o que preocupa o cliente. Só vale o que está escrito no texto.',
            'off' => 'O texto fica guardado e não vira busca nem contexto.',
            'parts' => ['github.extra'],
        ],
        'github-classificador' => [
            'group' => 'github',
            'label' => 'Classificador de achados (etapa 1)',
            'when' => 'A cada achado novo (ou que mudou) da varredura diária do GitHub (fila judge).',
            'what' => 'Lê o achado com os segredos mascarados e dá o nível (Critical, High, Medium, Low ou não é achado), a certeza de 0 a 100 e o motivo. Só o que é achado, no nível escolhido pelo cliente, segue para a etapa 2.',
            'off' => 'Vale o nível pelas regras automáticas da varredura.',
            'parts' => ['github.triage'],
        ],
        'github-juiz' => [
            'group' => 'github',
            'label' => 'Parecer de envio (etapa 2)',
            'when' => 'Depois da etapa 1, só para o que é achado no nível do cliente (fila judge).',
            'what' => 'Dá a chance, de 0 a 100, de o cliente querer o aviso agora, aprendendo com as avaliações dele. Envia acima do corte do GitHub (Alertas › Configurações).',
            'off' => 'O achado sai sem o parecer, só pela classificação.',
            'parts' => ['github.judge'],
        ],
        'testes-prioridade' => [
            'group' => 'testes',
            'label' => 'Ordem das hipóteses',
            'when' => 'Na fase de hipóteses de cada teste de segurança, depois que as regras e o portão escolhem o que vai ao teste (fila pentest-ai). Até 4 chamadas por execução e 30 por dia.',
            'what' => 'Lê até 150 hipóteses planejadas, só com pseudônimos (alvo-1, h-1): o item do catálogo, as portas e as tecnologias com a versão. Dá a cada uma a prioridade, de 0 a 100, e uma frase do porquê, guardada para o relatório do cliente. Não cria, não tira e não muda teste.',
            'off' => 'Vale a ordem das regras: a severidade potencial, a força da pré-condição e o custo.',
            'parts' => ['pentest.prioritize'],
        ],
    ];

    /** Trechos editáveis. `vars`: o que o texto precisa conter, e o que entra no lugar. */
    public const PARTS = [
        'enrichment.system' => [
            'label' => 'Regras da análise',
            'where' => 'Mensagem de sistema. Vale também para os drivers Claude e OpenAI, que recebem o schema pela API.',
        ],
        'enrichment.existing_case' => [
            'label' => 'Quando o artigo cai numa matéria existente',
            'where' => 'Mensagem do usuário, depois do artigo e antes do título, do relatório e dos indicadores da matéria.',
        ],
        'media.system' => [
            'label' => 'Papel',
            'where' => 'Mensagem de sistema.',
        ],
        'media.tasks' => [
            'label' => 'Tarefas',
            'where' => 'Mensagem do usuário, depois do título, do endereço e do texto já disponível; as imagens vão em seguida.',
        ],
        'merge.confirm' => ['label' => 'Instruções', 'where' => 'Mensagem de sistema.'],
        'merge.write' => ['label' => 'Instruções', 'where' => 'Mensagem de sistema.'],
        'merge.enrich' => ['label' => 'Instruções', 'where' => 'Mensagem de sistema.'],
        'alerts.judge' => ['label' => 'Diretrizes', 'where' => 'Mensagem de sistema.'],
        'lookalikes.judge' => ['label' => 'Diretrizes', 'where' => 'Mensagem de sistema.'],
        'github.extra' => ['label' => 'Pedido e campos', 'where' => 'Mensagem de sistema. O texto do cliente vai na mensagem do usuário, entre etiquetas.'],
        'github.triage' => ['label' => 'Níveis e diretrizes', 'where' => 'Mensagem de sistema.'],
        'github.judge' => ['label' => 'Diretrizes', 'where' => 'Mensagem de sistema.'],
        'pentest.prioritize' => ['label' => 'Diretrizes', 'where' => 'Mensagem de sistema. Os alvos, as tecnologias e as hipóteses vão na mensagem do usuário, a versão de cada tecnologia entre etiquetas.'],
        'exploits.explain' => ['label' => 'Pedido e regras', 'where' => 'Mensagem de sistema.'],
        'errors.explain' => ['label' => 'Pedido e regras', 'where' => 'Mensagem de sistema. O erro técnico vai na mensagem do usuário, sem o texto do artigo.'],
        'cases.vulnerability' => ['label' => 'Pedido e regras', 'where' => 'Mensagem de sistema.'],
        'cases.review' => ['label' => 'Diretrizes do revisor', 'where' => 'Mensagem de sistema. A fonte (e, na atualização, o relatório publicado) e o rascunho vão na mensagem do usuário, entre etiquetas.'],
        'cases.review_redo' => ['label' => 'Pedido de ajuste à análise', 'where' => 'Mensagem do usuário da análise, depois do artigo, quando o revisor pede ajuste; seguem o escopo, os problemas e o rascunho anterior. A mensagem de sistema é a da análise.'],
        'custom.system' => ['label' => 'Papel', 'where' => 'Mensagem de sistema, igual para os quatro tipos.'],
        'custom.sector' => [
            'label' => 'Pedido: setor',
            'where' => 'Mensagem do usuário.',
            'vars' => ['label' => 'a resposta que o cliente escreveu'],
        ],
        'custom.region' => [
            'label' => 'Pedido: região',
            'where' => 'Mensagem do usuário.',
            'vars' => ['label' => 'a resposta que o cliente escreveu'],
        ],
        'custom.attack_type' => [
            'label' => 'Pedido: tipo de ataque',
            'where' => 'Mensagem do usuário.',
            'vars' => ['label' => 'a resposta que o cliente escreveu'],
        ],
        'custom.tech' => [
            'label' => 'Pedido: tecnologia',
            'where' => 'Mensagem do usuário.',
            'vars' => ['label' => 'a resposta que o cliente escreveu'],
        ],
    ];

    private static function tagged(string $reply): string
    {
        return $reply."\n".TagCode::rule();
    }

    /** O que vem logo depois do trecho e não se edita (o código aleatório aparece como CODE; nas hipóteses, como {código}). */
    public static function fixed(string $key): ?string
    {
        return match ($key) {
            'enrichment.system' => HermesEnricher::reply(),
            'media.system' => ArticleMediaReader::REPLY,
            'media.tasks' => ArticleMediaReader::SHAPE,
            // As etiquetas vão com o marcador CODE e a regra que o explica (TagCode): o prompt de sistema é igual em toda chamada.
            'merge.confirm' => self::tagged(MergeEditor::confirmReply(TagCode::CODE)),
            'merge.write' => self::tagged(MergeEditor::writeReply(TagCode::CODE)),
            'merge.enrich' => self::tagged(MergeEditor::enrichReply(TagCode::CODE)),
            'alerts.judge' => self::tagged(AlertReviewer::reply('{idioma do cliente}', TagCode::CODE)),
            'lookalikes.judge' => self::tagged(LookalikeReviewer::reply(TagCode::CODE)),
            'github.extra' => self::tagged(ExtraInterpreter::reply(TagCode::CODE)),
            'github.triage' => self::tagged(GithubTriage::reply(TagCode::CODE)),
            'github.judge' => self::tagged(GithubJudge::reply(TagCode::CODE)),
            'pentest.prioritize' => HypothesisPrioritizer::reply(self::NONCE),
            'exploits.explain' => self::tagged(ExploitExplainer::reply(TagCode::CODE)),
            'cases.vulnerability' => self::tagged(VulnerabilityExplainer::reply(TagCode::CODE)),
            'cases.review' => self::tagged(ReportReviewer::reply(TagCode::CODE)),
            'errors.explain' => self::tagged(ErrorExplainer::reply(TagCode::CODE)),
            'custom.system' => CustomAnswerExpander::REPLY,
            default => null,
        };
    }

    /** A etapa de um trecho. */
    public static function stageOf(string $key): string
    {
        foreach (self::STAGES as $slug => $stage) {
            if (in_array($key, $stage['parts'], true)) {
                return $slug;
            }
        }

        return array_key_first(self::STAGES);
    }
}
