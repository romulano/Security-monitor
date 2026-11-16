@extends('legal.layout')

@php
    $updated = '4 de novembro de 2026';
    $contact = config('secmon.contact_email');
    $sections = [
        'servico' => 'O que é o Security Monitor',
        'conta' => 'Conta',
        'conteudo' => 'Conteúdo e inteligência artificial',
        'dominio' => 'Domínio monitorado',
        'notificadores' => 'Notificadores: Slack, Discord, Telegram e webhook',
        'uso' => 'Uso aceitável',
        'disponibilidade' => 'Disponibilidade e mudanças',
        'encerramento' => 'Suspensão e encerramento',
        'responsabilidade' => 'Responsabilidade',
        'propriedade' => 'Propriedade intelectual',
        'alteracoes' => 'Alterações destes termos',
        'lei' => 'Lei aplicável',
    ];
@endphp

@section('title', 'Termos de Uso · Security Monitor')
@section('description', 'Regras de uso do Security Monitor: conta, alertas, domínio monitorado, notificadores (Slack, Discord, Telegram e webhook) e responsabilidades.')
@section('heading', 'Termos de Uso')

@section('legal')
    <p>
        Estes termos valem para quem usa o Security Monitor em <a href="{{ route('feed.index') }}">example.com</a>, com ou sem conta, e para
        o app do Security Monitor no Slack, no Discord e no Telegram. Ao usar o serviço você concorda com eles e com a
        <a href="{{ route('privacy') }}">Política de Privacidade</a>.
    </p>

    <h2 id="servico">1. O que é o Security Monitor</h2>
    <p>
        O Security Monitor é um serviço de inteligência de ameaças. Ele reúne notícias de segurança publicadas por fontes públicas, resume e
        classifica esse material com inteligência artificial e o organiza em um feed, um painel e uma API pública de leitura.
        Quem tem conta também pode receber alertas personalizados no Slack, no Discord, no Telegram ou num webhook do próprio sistema.
    </p>

    <h2 id="conta">2. Conta</h2>
    <ul>
        <li>O cadastro é aberto: você cria a conta com nome, e-mail e senha (ou com o GitHub), passa pelo captcha e confirma o
            e-mail pelo link que enviamos antes de usar os alertas. Para ter conta você precisa ter 18 anos ou mais e, se usar o Security Monitor em nome de uma empresa, ter
            autorização dela.</li>
        <li>Informe dados verdadeiros e mantenha a senha em segredo. Você responde pelo que for feito com a sua conta.</li>
        <li>Não compartilhe o acesso. Se suspeitar de uso indevido, troque a senha e avise pelo contato.</li>
    </ul>

    <h2 id="conteudo">3. Conteúdo e inteligência artificial</h2>
    <ul>
        <li>As notícias vêm de fontes públicas e pertencem a quem as publicou. O Security Monitor mostra resumos, indicadores e o link para
            o original.</li>
        <li>Resumos, criticidade e indicadores são gerados por IA e podem ter erros ou omissões. Confira a fonte original antes
            de tomar qualquer decisão.</li>
        <li>Quando há exploit público para um CVE da notícia, a matéria mostra os dados do índice do Exploit-DB, o link e uma
            explicação escrita por IA. O código do exploit nunca é reproduzido no Security Monitor.</li>
        <li>O Security Monitor é uma ferramenta de apoio. Ele não substitui análise profissional, resposta a incidentes nem consultoria de
            segurança.</li>
        <li>Os alertas dependem do que você informou na entrevista. Um alerta importante pode não chegar, ou chegar um que não
            interessa. Por isso, não use o Security Monitor como o único meio de monitorar riscos críticos.</li>
    </ul>

    <h2 id="dominio">4. Domínio monitorado</h2>
    <ul>
        <li>Só cadastre domínios que você ou a sua empresa controlam. Esta é uma instalação própria, de um único dono: o
            domínio cadastrado já fica em uso na hora, sem verificação por registro TXT no DNS. A responsabilidade por
            cadastrar só domínios seus é sua.</li>
        <li>Ao informar o domínio, o Security Monitor lê o DNS público dele, que qualquer pessoa consulta, para sugerir tecnologias. Isso não
            acessa o site, e nada entra no perfil sem você confirmar.</li>
        <li>Com a sua autorização, o Security Monitor faz uma consulta passiva, conforme o termo de aceite mostrado na entrevista: lê o DNS
            público e até 3 páginas públicas do site, no máximo uma vez a cada 30 dias.</li>
        <li>O Security Monitor nunca faz scan de portas, testes de vulnerabilidade, login nem envio de formulários no seu site.</li>
        <li>Você pode revogar a autorização a qualquer momento. As tecnologias encontradas são apagadas junto.</li>
    </ul>

    <h2 id="notificadores">5. Notificadores: Slack, Discord, Telegram e webhook</h2>
    <ul>
        <li>Para conectar o Security Monitor, você precisa ter permissão para adicionar apps ao workspace do Slack ou ao servidor do Discord,
            ou para adicionar membros ao grupo do Telegram.</li>
        <li>No Discord, o bot do Security Monitor pede só três permissões: ver canais, enviar mensagens e inserir links. Ele não lê mensagens,
            não responde a comandos e só escreve no canal que você escolher ou na sua mensagem direta.</li>
        <li>A mensagem direta exige que você e o bot estejam em algum servidor em comum. Se não estiverem, oferecemos a entrada na
            comunidade do Security Monitor no Discord; se você sair dela, as mensagens diretas param.</li>
        <li>No Telegram, o bot do Security Monitor entra como membro comum na conversa privada ou no grupo que você escolher, pelo link de
            conexão. Ele não lê as conversas do grupo e só escreve os alertas, a mensagem de teste e a explicação de como conectar.</li>
        <li>No webhook, o Security Monitor chama a URL que você cadastrar, por POST, com a notificação em JSON. A URL precisa ser https e
            ficar num domínio seu (instalação de um dono só: sem verificação por registro TXT no DNS). O Security Monitor não segue
            redirecionamentos e nunca chama endereço de rede interna.</li>
        <li>O endereço e o segredo do webhook são responsabilidade sua: o segredo vai no cabeçalho Security-Monitor-Notification e assina cada
            notificação (Security-Monitor-Signature), e o Security Monitor recomenda que o seu servidor confira os dois. Depois de 5 entregas seguidas com
            falha, o envio para o webhook para e avisamos você por e-mail.</li>
        <li>Você pode desconectar quando quiser, pelo próprio Security Monitor, removendo o app do Slack ou o bot do servidor do Discord,
            tirando o bot do grupo do Telegram (na conversa privada, bloqueando o bot) ou excluindo o webhook.</li>
        <li>O uso do Slack, do Discord e do Telegram também segue os termos de cada um.</li>
    </ul>

    <h2 id="uso">6. Uso aceitável</h2>
    <p>Não é permitido:</p>
    <ul>
        <li>tentar acessar contas, áreas ou dados de outras pessoas, ou burlar a autenticação, o captcha ou os limites de uso;</li>
        <li>sobrecarregar o serviço, inclusive com coleta automatizada além do uso razoável da API pública;</li>
        <li>usar o Security Monitor para planejar ou executar ataques, ou cadastrar domínios de terceiros sem autorização;</li>
        <li>revender ou redistribuir o conteúdo em massa como se fosse seu.</li>
    </ul>
    <p>
        Pesquisa de segurança de boa-fé no próprio Security Monitor é bem-vinda. Se encontrar uma falha, conte para
        <a href="mailto:{{ $contact }}">{{ $contact }}</a> antes de divulgar e não acesse dados de outras pessoas.
    </p>

    <h2 id="disponibilidade">7. Disponibilidade e mudanças</h2>
    <p>
        O Security Monitor está em desenvolvimento ativo e é oferecido no estado em que se encontra. Ele pode ficar fora do ar, mudar ou ter
        recursos retirados, e não há garantia de disponibilidade contínua. Se algum plano pago for criado, o preço e as condições
        serão apresentados antes de qualquer cobrança.
    </p>

    <h2 id="encerramento">8. Suspensão e encerramento</h2>
    <p>
        Você pode parar de usar o Security Monitor a qualquer momento e pedir a exclusão da conta pelo contato. Podemos suspender ou encerrar
        contas que violem estes termos ou coloquem o serviço ou outras pessoas em risco, informando o motivo sempre que possível.
    </p>

    <h2 id="responsabilidade">9. Responsabilidade</h2>
    <p>
        Na medida permitida pela lei, o Security Monitor não responde por decisões tomadas com base no conteúdo exibido ou nos alertas, por
        erros em notícias de terceiros, por períodos fora do ar nem por falhas do Slack, do Discord, do Telegram, do servidor que recebe o seu webhook ou de outros serviços externos.
        Nada nestes termos limita os direitos que você tenha como consumidor.
    </p>

    <h2 id="propriedade">10. Propriedade intelectual</h2>
    <p>
        A marca Security Monitor, o mascote, o código e o design do serviço pertencem ao Security Monitor. O conteúdo das notícias pertence às fontes.
        Os dados que você fornece continuam seus, e você autoriza o Security Monitor a usá-los só para prestar o serviço.
    </p>

    <h2 id="alteracoes">11. Alterações destes termos</h2>
    <p>
        Quando estes termos mudarem, a data no topo da página muda junto. Mudanças relevantes serão avisadas com antecedência a
        quem tem conta. Continuar usando o Security Monitor depois disso significa concordar com a nova versão.
    </p>

    <h2 id="lei">12. Lei aplicável</h2>
    <p>
        Estes termos seguem a lei brasileira. Qualquer conflito será resolvido no foro do domicílio do usuário.
    </p>
@endsection
