<?php

use Illuminate\Support\Facades\Schedule;

// Horários no fuso de Brasília (app.schedule_timezone): dailyAt('03:00') é 03:00 de Brasília.

// Os bots de crawler rodam de hora em hora. Cada fonte ainda respeita o seu
// próprio `interval_minutes`, então dá para afrouxar ou apertar por fonte no
// admin sem mexer no agendamento.
Schedule::command('secmon:crawl --trigger=schedule')
    ->hourly()
    ->withoutOverlapping(55)
    ->appendOutputTo(storage_path('logs/crawler.log'));

// Reprocessa artigos que ficaram pendentes (provedor de IA fora do ar, etc.).
Schedule::command('secmon:enrich-pending')
    ->hourlyAt(30)
    ->withoutOverlapping(25);

// Alertas: o resumo de cada canal sai no horário e no fuso dele, então o
// agendamento só verifica quem está na hora; os segurados (silêncio, limite
// por hora) são soltos quando o prazo vence.
Schedule::command('secmon:alerts-digest')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

Schedule::command('secmon:alerts-flush')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

// A posse de domínio (a mesma para todos os serviços da conta): o TXT de quem espera a prova é
// conferido a cada 10 minutos (por 72 h), e cada posse comprovada, uma vez por dia (some por 7 dias,
// cai). A descoberta passiva se repete quando a próxima execução vence.
Schedule::command('secmon:alerts-verify-domains')
    ->everyTenMinutes()
    ->withoutOverlapping(10);

Schedule::command('secmon:alerts-discover --due')
    ->dailyAt('03:00')
    ->withoutOverlapping(60);

// Monitoria de segurança dos sites do admin: a cada 10 minutos enfileira só os sites cuja hora vencida
// (cada um tem o seu intervalo, 6 h por padrão). A fila `security` tem worker próprio no supervisor.
Schedule::command('secmon:security-scan --due')
    ->everyTenMinutes()
    ->withoutOverlapping(10);

// Varredura de chaves: pede a semanal de cada domínio comprovado ativado (a vez de cada um vem da
// última varredura, então elas se espalham pela semana) e retoma a que o deploy ou um worker morto
// interrompeu, do último ponto gravado: por isso de 5 em 5 minutos.
Schedule::command('secmon:keys-scan --due')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

// Exploits públicos: o índice do Exploit-DB muda poucas vezes por semana, e o
// download só acontece quando o ETag mudou. Os casos antigos que ganharam
// exploit são revistos e reindexados na mesma execução.
Schedule::command('secmon:exploits-sync')
    ->dailyAt('05:10')
    ->withoutOverlapping(60)
    ->appendOutputTo(storage_path('logs/exploits.log'));

// Fusão de matérias: duas vezes ao dia (00:00 e 12:00 de Brasília), as
// matérias novas que contam a mesma história de outra (a coleta só junta por
// CVE e título parecido) vão para a IA, que escreve uma matéria nova com todas
// as fontes. As antigas são apagadas e o
// link delas redireciona para a nova. O comando só enfileira (fila merge, que
// o worker atende depois da análise das notícias).
Schedule::command('secmon:cases-merge')
    ->cron('0 0,12 * * *')
    ->withoutOverlapping(60)
    ->appendOutputTo(storage_path('logs/merge.log'));

// Radar de domínios: todo Radar ativo tem a varredura completa todo dia às
// 01:00 (config secmon.lookalikes.scan_at); o comando roda a cada 15 minutos
// porque também pega quem acabou de comprovar o domínio ou foi retomado. A
// completa já relê o DNS dos achados, então não há releitura separada. O
// navegador sobe sozinho pelo systemd quando há pedido. A leitura das
// respostas também roda aqui, caso o docker/browser/run.sh não tenha
// conseguido ler ao terminar.
Schedule::command('secmon:lookalikes-scan --due')
    ->everyFifteenMinutes()
    ->withoutOverlapping(60);

Schedule::command('secmon:lookalikes-ingest')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

// Monitoramento do GitHub: uma varredura por dia por cliente (vence 24 h depois
// da anterior; a primeira sai ao ativar). O comando só enfileira (fila github);
// cada achado novo passa pela classificação e, se for achado no nível do
// cliente, pelo parecer de envio (fila judge).
Schedule::command('secmon:github-scan --due')
    ->everyFifteenMinutes()
    ->withoutOverlapping(30);

// E-mails: o log do servidor de envio diz o que o servidor do destinatário fez com
// cada mensagem (entregue, adiado, devolvido). Admin → E-mails mostra; minuto a minuto.
Schedule::command('secmon:mail-status')
    ->everyMinute()
    ->withoutOverlapping(5);

// Poda diária do que cresce sem parar: sessões vencidas, execuções do crawler,
// jobs que falharam, entregas e prints velhos do Radar (prazos em secmon.retention.*).
// Tem o seu horário: a poda dos prints dependia de uma janela de 15 minutos dentro de
// outro comando e, se o agendador estivesse fora nela, o dia era pulado sem recuperação.
Schedule::command('secmon:prune')
    ->dailyAt('03:40')
    ->withoutOverlapping(60)
    ->appendOutputTo(storage_path('logs/prune.log'));

// Testes de segurança: o vigia das execuções, a cada minuto. Redespacha o passo que um deploy
// matou, finaliza a execução parada, confere o portão da que roda, retoma a pausa vencida, começa
// a agendada cuja hora chegou e dá o próximo passo. Em segundo plano, para não atrasar os outros
// comandos do minuto. Com PENTEST_ALLOWED=false, só para e finaliza (e não há execução em produção).
Schedule::command('secmon:pentest-tick')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();

// Testes de segurança: as respostas do executor, a cada minuto (a ingestão idempotente, o pedido que ficou sem publicar e
// o que venceu sem resposta). Sem a stack instalada, não faz nada.
Schedule::command('secmon:pentest-ingest')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();

// Testes de segurança: a retenção da pasta do executor (as respostas, 7 dias; os registros de atividade, 180).
// O runner nunca apaga nada disso. Sem a stack instalada, não faz nada.
Schedule::command('secmon:pentest-limpar')
    ->dailyAt('04:40')
    ->withoutOverlapping(30);
