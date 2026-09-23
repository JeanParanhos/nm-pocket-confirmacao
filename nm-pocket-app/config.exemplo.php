<?php
/**
 * Modelo do arquivo de senha do painel de confirmações.
 * No servidor ele fica FORA do site: /home/arlindo/data/nm-pocket/confirmacao-config.php
 *
 * Para gerar o hash de uma senha nova:
 *   php -r 'echo password_hash("SUA-SENHA", PASSWORD_DEFAULT), "\n";'
 */

const NMC_SENHA_HASH = '$2y$10$troque-pelo-hash-gerado';
