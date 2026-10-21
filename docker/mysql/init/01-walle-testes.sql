-- walle: banco dos testes automáticos (Pest). Roda só na primeira vez que o volume do MySQL é criado.
CREATE DATABASE IF NOT EXISTS walle_testes CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
GRANT ALL PRIVILEGES ON walle_testes.* TO 'walle'@'%';
FLUSH PRIVILEGES;
