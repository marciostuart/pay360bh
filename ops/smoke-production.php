<?php
declare(strict_types=1);
require '/var/www/pay/bootstrap/app.php';

$pdo = db();
$identity = $pdo->query('SELECT current_database() AS db, current_user AS usuario')->fetch();
if ($identity['db'] !== 'central_cobrancas_prod' || $identity['usuario'] !== 'pay360bh_prod_login') {
    throw new RuntimeException('A conexão não usa a base e a conta esperadas de produção.');
}
foreach (['Clientes MDI', 'Projetos', 'Cobrancas', 'Eventos Asaas', 'Entregas'] as $table) {
    $count = $pdo->query('SELECT count(*) FROM "' . $table . '"')->fetchColumn();
    if ((int)$count !== 0) throw new RuntimeException('A base inicial contém registros em ' . $table);
}
if (central_n8n_token() === '') throw new RuntimeException('Token interno de produção indisponível.');
echo "Banco, conta, cinco tabelas vazias e arquivo de token: OK\n";
