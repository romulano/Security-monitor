-- CI (docker-compose.ci.yml): banco da suíte do Laravel, separado do "secmon"
-- que a stack de produção usa nos testes de ponta a ponta.
CREATE DATABASE secmon_test OWNER secmon;
