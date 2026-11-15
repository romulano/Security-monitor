@extends('legal.layout')

@php
    $updated = '4 de novembro de 2026';
    $contact = config('secmon.contact_email');
    $sections = [
        'quem-somos' => 'Quem somos',
        'dados' => 'Quais dados tratamos',
        'finalidades' => 'Para que usamos',
        'ia' => 'Inteligência artificial',
        'compartilhamento' => 'Com quem compartilhamos',
        'transferencia' => 'Transferência internacional',
        'cookies' => 'Cookies',
        'retencao' => 'Por quanto tempo guardamos',
        'direitos' => 'Seus direitos',
        'seguranca' => 'Segurança',
        'pessoas-citadas' => 'Pessoas citadas nas notícias',
        'menores' => 'Menores de idade',
        'mudancas' => 'Mudanças nesta política',
    ];
@endphp

@section('title', 'Política de Privacidade · Security Monitor')
@section('description', 'Quais dados o Security Monitor trata, para quê, com quem compartilha e como exercer seus direitos pela LGPD.')
@section('heading', 'Política de Privacidade')

@section('legal')
    <p>
        Esta política explica quais dados pessoais o Security Monitor (<a href="{{ route('feed.index') }}">example.com</a>) trata, para quê,
        com quem compartilha e como você exerce os direitos garantidos pela Lei Geral de Proteção de Dados
        (Lei nº 13.709/2018, a LGPD).
    </p>

    <div class="mb-6 rounded-lg border border-ink-800 bg-ink-900 p-5">
        <p class="mb-2 text-[11px] tracking-widest text-accent uppercase">Em resumo</p>
        <ul class="mb-0">
            <li>Pedimos só o necessário para criar a conta e montar os alertas.</li>
            <li>Não vendemos dados, não exibimos anúncios e não usamos cookies de rastreamento; o código de aparelho da seção 2 serve só à segurança.</li>
            <li>O seu site só é consultado com a sua autorização, e de forma passiva. O DNS público do domínio, que qualquer pessoa
                consulta, é lido quando você o informa, para sugerir tecnologias.</li>
            <li>Senhas, webhooks, tokens e segredos ficam protegidos: senha só como hash; webhooks, tokens e o segredo dos seus
                webhooks, criptografados.</li>
            <li>Você pode pedir acesso, correção ou exclusão dos seus dados a qualquer momento.</li>
        </ul>
    </div>

    <h2 id="quem-somos">1. Quem somos</h2>
    <p>
        O Security Monitor é um serviço de inteligência de ameaças. Ele reúne notícias e avisos publicados por fontes públicas de segurança,
        resume e classifica esse material com inteligência artificial e, para quem tem conta, envia alertas personalizados.
    </p>
    <p>
        O Security Monitor é o controlador dos dados descritos aqui. O canal para qualquer assunto de privacidade, inclusive como canal do
        encarregado de dados, é <a href="mailto:{{ $contact }}">{{ $contact }}</a>.
    </p>

    <h2 id="dados">2. Quais dados tratamos</h2>
    <p><strong>Quem visita o site</strong> (com ou sem conta): endereço IP, o país de onde ele vem (informado pela Cloudflare),
        navegador, páginas acessadas, o endereço de onde veio (quando o navegador o informa), data, hora e quanto tempo cada página ficou aberta, nos registros técnicos do servidor e
        da Cloudflare. Das requisições que o site recusa ou que parecem tentativa de invasão, guardamos também o método e o
        código da resposta. O endereço é guardado sem senhas, códigos ou assinaturas. Sob ataque em grande volume, além de um
        limite por minuto guardamos só a contagem. Se você estiver logado, o registro fica ligado à sua conta. Também guardamos num cookie o idioma escolhido para os relatórios.</p>
    <p><strong>Código do aparelho (só para segurança):</strong> quando você navega sem estar logado, o seu navegador calcula, a
        partir de características técnicas do aparelho (placa de vídeo, tela, fuso horário, idiomas, número de núcleos, memória e
        fontes instaladas), um código que não revela essas características. Guardamos esse código com o IP, o país, o navegador e
        o dia. Ele serve só para reconhecer o mesmo aparelho quando ele troca de IP numa tentativa de invasão: não é ligado à sua
        conta, não é usado para marketing, anúncio ou estatística de audiência e não é compartilhado. Se o seu navegador enviar o
        sinal Global Privacy Control, ou estiver com o JavaScript desligado, o código não é calculado.</p>
    <p><strong>Conta:</strong> nome, e-mail, senha (guardada só como hash, nunca em texto), a data de criação e a data em
        que o e-mail foi confirmado. As contas criadas quando o cadastro era só por convite guardam também o código usado
        (só como hash). De cada sessão aberta guardamos o IP e o navegador.</p>
    <p><strong>E-mails que enviamos:</strong> o link de confirmação do e-mail da conta (e o de um e-mail novo, se você trocar)
        e o aviso de que um webhook seu parou depois de falhas seguidas saem pelo servidor de envio do próprio Security Monitor, sem empresa
        de e-mail no meio. De cada envio guardamos o destinatário,
        o assunto, a data e o resultado da entrega (a resposta do servidor de quem recebe, como "entregue" ou "endereço não
        existe"). O texto da mensagem não fica guardado depois do envio.</p>
    <p><strong>Alertas personalizados:</strong> as respostas da entrevista, que são o nome da empresa ou do produto, as marcas,
        os setores, as regiões, o sistema dos servidores, as tecnologias usadas e as versões delas (se você informar), os tipos
        de ataque que interessam, a criticidade mínima e o idioma. Também ficam guardados o que você escrever no campo “Outro”, os alertas gerados
        para você, as avaliações “relevante” ou “não é relevante” que você der, com o motivo, se você escrever um, e o
        registro de cada envio.</p>
    <p><strong>Domínio monitorado</strong>, só se você informar um: o domínio e as tecnologias que o DNS público dele sugere
        (MX, SPF e outros TXT, NS e o CNAME do www), com a sua decisão sobre cada uma. Se você autorizar a consulta ao site,
        também o registro TXT de verificação, a data, a hora, o IP e a versão do termo de aceite, e as tecnologias encontradas,
        com a evidência de cada uma. São informações sobre a infraestrutura do site, não sobre pessoas.</p>
    <p><strong>Radar de domínios</strong>, só se você responder a entrevista dele: o domínio, o nome da empresa e as marcas, com o
        registro TXT de verificação. Para cada domínio com a posse comprovada: os domínios parecidos com o seu que existem
        (dados públicos de DNS, de registro e de certificados), os prints das páginas desses domínios em computador, Android e
        iPhone, o texto delas, o parecer da IA e as suas respostas (“é golpe”, “é nosso”, “não é ameaça”), com o motivo, se
        você escrever um, e as denúncias que você marcar como enviadas. Com a autorização dos prints (o termo do Radar, no passo 3
        da entrevista dele, ou o termo v3 do domínio de um alerta), guardamos também os prints de até 3 páginas públicas do seu
        site, para comparar com os achados.</p>
    <p><strong>Monitoramento do GitHub</strong>, só se você responder a entrevista dele: as organizações e os repositórios
        informados, os <em>nomes</em> de chaves e variáveis, os prefixos de token, os endereços internos, os domínios de e-mail, a
        marca, o nível mínimo e o texto “Adicional”, com o que a IA entendeu dele. De cada achado (arquivo ou repositório público
        no GitHub), guardamos o endereço, o dono, um trecho do arquivo com os segredos <strong>mascarados</strong> (nunca o valor de
        uma chave), as duas análises da IA, as suas avaliações, com o motivo, e o registro de cada envio. Se você colar o valor de
        uma chave no lugar do nome, ele é descartado antes de ser gravado.</p>
    <p><strong>Slack, Discord, Telegram e webhook</strong>, só se você conectar:</p>
    <ul>
        <li>no Slack, o endereço do webhook ou o token do app (os dois criptografados) e os nomes do workspace e do canal;</li>
        <li>no Discord, os identificadores e nomes do servidor e do canal escolhidos; na mensagem direta, o seu ID e o seu nome
            de usuário no Discord; ou o endereço do webhook, se você colar um (criptografado);</li>
        <li>no Telegram, o identificador da conversa, o nome do grupo (quando é um grupo) e o nome de usuário de quem conectou
            (ou o nome, se a conta não tiver usuário);</li>
        <li>no webhook, o nome que você der a ele, a URL (criptografada), o domínio dela, o registro TXT de verificação desse
            domínio (como no Radar) e o segredo que autentica e assina as notificações (criptografado);</li>
        <li>em todos, as suas preferências (tempo real, horário silencioso, resumo diário, fuso horário) e o histórico de
            envios, com data, resultado e código de resposta; no webhook, também quanto tempo o seu servidor levou para responder.</li>
    </ul>
    <p>
        O bot do Security Monitor no Discord tem só três permissões: ver canais, enviar mensagens e inserir links. Ele não lê as
        mensagens do servidor, não acessa a lista de membros e não guarda nada sobre os usuários do servidor. Ao conectar,
        você autoriza com a sua conta do Discord; usamos essa autorização uma vez, para saber quem conectou e qual servidor
        escolheu, e a revogamos em seguida. Na mensagem direta, se você não estiver em nenhum servidor com o bot, oferecemos
        a entrada na comunidade do Security Monitor no Discord, porque o Discord só permite mensagem direta entre quem está num servidor em
        comum; só colocamos você lá se aceitar.
    </p>
    <p>
        No Telegram, você conecta por um link de uso único, que vale 30 minutos. O bot do Security Monitor não lê as conversas: nos grupos,
        o Telegram só entrega a ele os comandos com o nome dele, e o Security Monitor descarta tudo o que não for o comando de conexão.
        Na conversa privada, o bot responde a qualquer mensagem com a explicação de como conectar, sem guardar o que foi escrito.
    </p>
    <p>
        No webhook, o Security Monitor chama a URL que você cadastrar, por POST, com a notificação em JSON: o mesmo conteúdo das mensagens
        dos apps (a notícia ou o achado, por que chegou para você, o parecer da IA e os links de avaliação). Só chamamos endereços
        https de um domínio que a sua conta comprovou ser seu, nunca endereço de rede interna. Da resposta do seu servidor
        guardamos só o código e o tempo; o conteúdo dela é descartado. Códigos de confirmação nunca saem por webhook.
    </p>

    <h2 id="finalidades">3. Para que usamos</h2>
    <ul>
        <li><strong>Prestar o serviço que você pediu</strong>: criar a conta, confirmar o e-mail dela, montar o perfil e entregar
            os alertas no canal escolhido. Base legal: execução de contrato (art. 7º, V, da LGPD).</li>
        <li><strong>Consultar o seu domínio</strong> para identificar tecnologias. Base legal: consentimento (art. 7º, I),
            registrado com data, hora e IP e revogável a qualquer momento.</li>
        <li><strong>Sugerir tecnologias pelo DNS público</strong> do domínio que você informar, uma leitura de registros que
            qualquer pessoa consulta e que não acessa o site. Nada entra no perfil sem a sua confirmação. Base legal: execução de
            contrato (art. 7º, V).</li>
        <li><strong>Proteger o serviço</strong> contra robôs, abuso e tentativas de invasão, com captcha, limite de tentativas
            e registros de acesso, inclusive reconhecer o mesmo aparelho quando ele troca de IP. Bases legais: legítimo interesse (art. 7º, IX) e cumprimento de obrigação legal (art. 7º, II),
            já que o Marco Civil da Internet manda guardar registros de acesso.</li>
        <li><strong>Calibrar os alertas</strong> com as avaliações de relevância que você der: a IA as usa para decidir se um
            alerta sai na hora (seção 4). Base legal: legítimo interesse (art. 7º, IX).</li>
        <li><strong>Vigiar domínios que imitam o seu</strong> (Radar de domínios): consultar dados públicos de DNS, registro e
            certificados, abrir as páginas desses domínios num navegador isolado, que nunca faz requisição ao seu site, e avisar
            quando um deles virar golpe. Base legal: execução de contrato (art. 7º, V). Os prints do seu próprio site dependem do
            seu consentimento, no termo do Radar ou no v3 (art. 7º, I).</li>
        <li><strong>Procurar no GitHub público</strong> o que você informou na entrevista do GitHub, uma vez por dia, pela API
            oficial do GitHub, e avisar quando algo seu aparecer. Base legal: execução de contrato (art. 7º, V).</li>
        <li><strong>Medir a nossa divulgação</strong>: pelos registros de acesso, saber de quais sites vêm as visitas, por qual
            página elas entram e quais levam ao cadastro, para decidir onde divulgar. Base legal: legítimo interesse
            (art. 7º, IX).</li>
    </ul>
    <p>
        Não vendemos dados, não os usamos para direcionar publicidade e não montamos perfis para marketing.
    </p>

    <h2 id="ia">4. Inteligência artificial</h2>
    <p>
        As notícias públicas são resumidas e classificadas por um modelo de IA (Claude, da Anthropic). Quando uma notícia cita
        uma falha com exploit público no Exploit-DB, o mesmo modelo lê o arquivo publicado lá para explicar o exploit. Essas
        etapas não envolvem dados de clientes.
    </p>
    <p>
        Se você escrever uma resposta própria no campo “Outro” da entrevista (um setor que não está na lista, por exemplo),
        só esse texto curto vai para o mesmo modelo, sem nome, e-mail ou empresa, para ser traduzido em termos de busca.
        A tradução pode ser reaproveitada para outros clientes que escreverem a mesma coisa, sem identificar quem escreveu
        primeiro. Por isso, não coloque dados pessoais nesse campo.
    </p>
    <p>
        Antes de um alerta sair na hora, o mesmo modelo avalia se a notícia interessa a você. Para isso ele recebe a notícia,
        que é pública, as tecnologias (com as versões que você informar), os setores, as regiões e os tipos de ataque das suas
        respostas, e o título e o motivo dos alertas que você marcou como relevante ou não relevante, com o texto que você escreveu ao avaliar. Não recebe o
        nome da empresa, as marcas, o domínio, o seu nome nem o seu e-mail: tiramos tudo isso do texto antes, junto com
        links, endereços de e-mail e números longos. Texto que pareça uma instrução para a IA fica guardado, mas não vai para
        ela. Alertas que citam a sua empresa não passam por essa avaliação: são enviados sempre. O que a IA
        segura não é apagado e fica visível na aba Notícias.
    </p>
    <p>
        No Radar de domínios é diferente, porque julgar se um site imita o seu exige saber de quem é a marca: antes de um aviso
        sair, o mesmo modelo recebe o nome da empresa, as marcas e o domínio, os prints e o texto do domínio parecido, os prints
        do seu site (se os prints estiverem autorizados) e as suas respostas sobre outros domínios, com o motivo escrito. O motivo
        passa pelas mesmas limpezas (links, e-mails e números longos saem; texto com cara de instrução para a IA não vai). O
        que a IA segura não é apagado e fica visível no Radar.
    </p>
    <p>
        No monitoramento do GitHub também, porque saber se um arquivo é da sua empresa exige saber o que é seu: o mesmo modelo
        recebe a marca, o nome da empresa, as organizações, os repositórios, os nomes de chaves, os prefixos, os endereços
        internos e os domínios de e-mail que você informou, e o achado com os valores <strong>mascarados</strong> (o modelo nunca
        vê o segredo). São três etapas, cada uma com o seu prompt: a leitura do texto “Adicional” (só quando você escreve um), a
        classificação de cada achado por nível e, só para o que é achado no nível que você escolheu, o parecer de envio, que
        recebe também os achados que você avaliou, com o motivo. O texto “Adicional” e o motivo passam pelas mesmas limpezas, e
        texto com cara de instrução para a IA não vai para ela. Da leitura do texto “Adicional”, só é aproveitado o que está
        escrito nele.
    </p>

    <h2 id="compartilhamento">5. Com quem compartilhamos</h2>
    <p>Só com os fornecedores necessários para o Security Monitor funcionar, cada um limitado ao seu papel:</p>
    <ul>
        <li><strong>Cloudflare</strong>: rede de entrega, proteção contra robôs e captcha. Todo acesso ao site passa por ela.
            Também guarda a cópia externa dos backups do banco.</li>
        <li><strong>Provedor de hospedagem</strong> do servidor onde ficam a aplicação e o banco de dados.</li>
        <li><strong>Anthropic</strong>: o modelo de IA, nos casos descritos acima.</li>
        <li><strong>Slack, Discord e Telegram</strong>, só quando você conecta. Eles recebem as mensagens dos alertas que você
            configurou e as tratam conforme as políticas de cada um.</li>
        <li><strong>O endereço do seu webhook</strong>, só quando você cadastra um: o sistema que você escolheu, num domínio seu,
            recebe as notificações dos alertas que você configurou.</li>
        <li><strong>GitHub</strong>, só no monitoramento do GitHub: as buscas na API oficial levam os termos que você informou
            (nomes de chaves, de repositórios, endereços internos, marca), nunca valores de chave nem dados pessoais.</li>
        <li><strong>O seu provedor de e-mail</strong> (Gmail, Outlook e outros), que recebe as mensagens que enviamos a você.</li>
        <li><strong>Autoridades públicas</strong>, quando houver ordem judicial ou obrigação legal.</li>
    </ul>
    <p>O resultado da consulta ao seu domínio não é compartilhado com ninguém.</p>

    <h2 id="transferencia">6. Transferência internacional</h2>
    <p>
        Cloudflare, Anthropic, GitHub, Slack, Discord e Telegram processam dados fora do Brasil, principalmente nos Estados Unidos e na
        Europa. A transferência acontece para executar o serviço que você contratou ou pediu, como a entrega de alertas no seu
        Slack, Discord ou Telegram, conforme
        o art. 33, IX, da LGPD. Esses fornecedores adotam proteções contratuais para os dados. O webhook vai para o endereço que
        você cadastrar, onde ele estiver hospedado.
    </p>

    <h2 id="cookies">7. Cookies</h2>
    <ul>
        <li><code>security-monitor-session</code>: mantém você conectado. Essencial; expira depois de 4 horas sem uso.</li>
        <li><code>XSRF-TOKEN</code>: protege os formulários contra requisições falsificadas. Essencial.</li>
        <li><code>remember_web_…</code>: só existe se você marcar “lembrar de mim” no login.</li>
        <li><code>report_lang</code>: lembra o idioma escolhido para os relatórios.</li>
        <li>Cookies técnicos da Cloudflare, como <code>__cf_bm</code> e <code>cf_clearance</code>, usados para separar pessoas
            de robôs.</li>
    </ul>
    <p>Não usamos cookies de análise de audiência nem de anúncios, nossos ou de terceiros: a medição da divulgação usa só os
        registros de acesso (seção 2). Também não usamos técnicas parecidas para medir audiência: o código do aparelho (seção 2) é
        só para segurança.</p>

    <h2 id="retencao">8. Por quanto tempo guardamos</h2>
    <ul>
        <li><strong>Conta e alertas</strong>: enquanto a conta existir. Ao excluir um alerta, apagamos junto as respostas, o
            domínio, as tecnologias encontradas e o histórico dele.</li>
        <li><strong>Domínio</strong>: revogar a autorização apaga as tecnologias encontradas na consulta.</li>
        <li><strong>Prints do Radar de domínios</strong>: 90 dias. Ficam por mais tempo só os que marcaram uma mudança de nível
            ou foram para um aviso, porque servem de prova numa denúncia; saem quando o alerta é excluído. Os prints do seu site
            são trocados pelos novos todo mês.</li>
        <li><strong>Monitoramento do GitHub</strong>: enquanto ele existir. Excluir o monitoramento apaga as respostas da
            entrevista e todos os achados.</li>
        <li><strong>Slack, Discord, Telegram e webhook</strong>: desconectar (ou excluir o webhook) apaga as credenciais, os
            identificadores, a URL e o segredo do webhook, a prova de posse feita para ele e o histórico de envios daquele canal.</li>
        <li><strong>Sessões</strong>: expiram depois de 4 horas sem uso.</li>
        <li><strong>Registro dos e-mails enviados</strong> (destinatário, assunto, data e resultado): 90 dias.</li>
        <li><strong>Registros de acesso</strong> (IP, país, navegador, data, hora, endereço acessado, endereço de onde veio, tempo na página e, nas requisições recusadas, método e resposta) e tentativas recusadas: 6 meses, prazo do Marco Civil da Internet,
            salvo ordem judicial que exija mais tempo.</li>
        <li><strong>Código do aparelho</strong> (com o IP, o país, o navegador e o dia): 6 meses.</li>
        <li><strong>Backups do banco</strong>: no servidor e numa cópia externa, na Cloudflare, por até 35 dias. O que você
            apagar some dos backups nesse prazo.</li>
        <li><strong>Exclusão da conta</strong>: a pedido, pelo contato. Em até 15 dias apagamos tudo o que está ligado a ela,
            exceto o que a lei obrigar a guardar. Nos backups, esses dados somem em até 35 dias depois disso.</li>
    </ul>

    <h2 id="direitos">9. Seus direitos</h2>
    <p>Pela LGPD (art. 18), você pode pedir a qualquer momento:</p>
    <ul>
        <li>a confirmação de que tratamos dados seus e o acesso a eles;</li>
        <li>a correção de dados incompletos ou errados (nome e e-mail você mesmo muda em <em>Conta</em>);</li>
        <li>a anonimização, o bloqueio ou a eliminação de dados desnecessários ou tratados em desacordo com a lei;</li>
        <li>a portabilidade dos seus dados;</li>
        <li>a eliminação dos dados tratados com base no seu consentimento;</li>
        <li>a lista de com quem compartilhamos os seus dados;</li>
        <li>a revogação do consentimento.</li>
    </ul>
    <p>
        Para pedir, escreva para <a href="mailto:{{ $contact }}">{{ $contact }}</a> a partir do e-mail da sua conta.
        Respondemos em até 15 dias. Você também pode reclamar à Autoridade Nacional de Proteção de Dados
        (<a href="https://www.gov.br/anpd" rel="noopener" target="_blank">ANPD</a>).
    </p>

    <h2 id="seguranca">10. Segurança</h2>
    <ul>
        <li>Senhas guardadas só como hash; webhooks e tokens do Slack e do Discord, e a URL e o segredo dos seus webhooks,
            criptografados no banco.</li>
        <li>Cada notificação do webhook leva uma assinatura (HMAC-SHA256) feita com o segredo dele, para o seu servidor conferir
            que veio do Security Monitor e recusar a repetição de um envio antigo.</li>
        <li>HTTPS em todo o site, com TLS 1.2 ou superior e HSTS.</li>
        <li>Captcha e limite de tentativas no login e no cadastro; área administrativa com entrada separada.</li>
    </ul>
    <p>
        Se acontecer um incidente de segurança que possa trazer risco ou dano relevante, avisamos as pessoas afetadas e a ANPD,
        como manda o art. 48 da LGPD.
    </p>

    <h2 id="pessoas-citadas">11. Pessoas citadas nas notícias</h2>
    <p>
        O feed reúne notícias já publicadas por fontes públicas, e elas podem citar pessoas: pesquisadores, autores, vítimas ou
        suspeitos. Os exploits públicos mostram o autor registrado no Exploit-DB. Cada caso mantém o link para a fonte original. Se você foi citado e quer uma correção ou a remoção, escreva para
        o contato.
    </p>

    <h2 id="menores">12. Menores de idade</h2>
    <p>O Security Monitor é voltado a profissionais e empresas e não se destina a menores de 18 anos.</p>

    <h2 id="mudancas">13. Mudanças nesta política</h2>
    <p>
        Quando esta política mudar, a data no topo da página muda junto. Mudanças que afetem o uso de dados de quem tem conta
        serão avisadas com antecedência.
    </p>
@endsection
