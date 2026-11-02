<?php
declare(strict_types=1);

$root = $argv[1] ?? '/var/www/pay';
$replacements = [
    'Projetos SANDBOX' => 'Projetos',
    'Cobrancas SANDBOX' => 'Cobrancas',
    'Eventos Asaas SANDBOX' => 'Eventos Asaas',
    'Entregas SANDBOX' => 'Entregas',
    'AMBIENTE SANDBOX' => 'AMBIENTE DE PRODUÇÃO',
    'Asaas Sandbox' => 'Asaas',
    'Painel Sandbox' => 'Painel de produção',
    '<p>Esta versão ainda está em homologação. Não use cobranças comerciais nem altere o DNS do domínio antes de completar a validação ponta a ponta.</p>' => '',
];

foreach (['bootstrap/app.php', 'public_html/index.php'] as $file) {
    $path = $root . '/' . $file;
    $content = file_get_contents($path);
    if ($content === false) throw new RuntimeException('Arquivo de origem indisponível: ' . $file);
    $content = str_replace(array_keys($replacements), array_values($replacements), $content);
    if (preg_match('/sandbox|homologa[cç][aã]o/i', $content)) {
        throw new RuntimeException('Referência de homologação não tratada em ' . $file);
    }
    if (file_put_contents($path, $content) === false) throw new RuntimeException('Falha ao preparar ' . $file);
}
