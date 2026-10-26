-- walle: bancos dos testes automáticos (Pest). Roda só na primeira vez que o volume do MySQL é criado.
-- `walle\_testes%` cobre walle_testes, walle_testes_help (imitação do Help Desk) e walle_testes_tNN (sessões paralelas).
CREATE DATABASE IF NOT EXISTS walle_testes CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
GRANT ALL PRIVILEGES ON `walle\_testes%`.* TO 'walle'@'%';
FLUSH PRIVILEGES;
