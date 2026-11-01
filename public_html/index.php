<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/app.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; style-src 'self'");
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($path === '/health') {
    try { db()->query('SELECT 1'); json_out(['ok' => true]); }
    catch (Throwable) { json_out(['ok' => false], 503); }
}

if ($path === '/api/v1/cobrancas' && $method === 'POST') {
    try {
        $service = service_required();
        $result = create_charge($service, request_json());
        json_out(['ok' => true, 'projeto' => $service['codigo'], 'resultado' => $result], 201);
    } catch (DomainException $e) { json_out(['error' => $e->getMessage()], 409); }
      catch (InvalidArgumentException $e) { json_out(['error' => $e->getMessage()], 422); }
      catch (Throwable $e) { error_log('pay360bh charge request failed: ' . $e->getMessage()); json_out(['error' => 'Não foi possível processar a cobrança. Verifique os dados e tente novamente.'], 502); }
}

start_secure_session();
if ($path === '/logout' && $method === 'POST') {
    csrf_check();
    $_SESSION = [];
    session_destroy();
    header('Location: /login', true, 303);
    exit;
}

if (empty($_SESSION['admin_authenticated'])) {
    if ($method === 'POST' && $path === '/login') {
        csrf_check();
        $email = (string)($_POST['email'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $configuredHash = (string)envv('ADMIN_PASSWORD_HASH', '');
        if (hash_equals((string)envv('ADMIN_EMAIL', ''), $email) && $configuredHash !== '' && password_verify($password, $configuredHash)) {
            session_regenerate_id(true);
            $_SESSION['admin_authenticated'] = true;
            header('Location: /painel', true, 303);
            exit;
        }
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'E-mail ou senha inválidos.'];
        header('Location: /login', true, 303);
        exit;
    }
    if ($path !== '/login' && $path !== '/') { http_response_code(404); exit('Página não encontrada.'); }
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    ?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Entrar | Pay 360BH</title><link rel="stylesheet" href="/assets/panel.css"></head><body class="login"><main class="login-card"><div class="brand">360BH <span>Central de Cobranças</span></div><h1>Acessar painel</h1><?php if ($flash): ?><div class="notice error"><?=esc($flash['message'])?></div><?php endif; ?><form method="post" action="/login"><input type="hidden" name="_csrf" value="<?=esc(csrf_token())?>"><label>E-mail<input type="email" name="email" autocomplete="username" required></label><label>Senha<input type="password" name="password" autocomplete="current-password" required></label><button class="button primary">Entrar</button></form></main></body></html><?php exit;
}

if ($path === '/' || $path === '/login') { header('Location: /painel', true, 303); exit; }
admin_required();
$pdo = db();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$issuedKey = $_SESSION['issued_service_key'] ?? null;
unset($_SESSION['issued_service_key']);
$activeTab = in_array($_GET['aba'] ?? '', ['resumo', 'servicos', 'clientes', 'cobrancas'], true) ? (string)$_GET['aba'] : 'resumo';

if ($method === 'POST') {
    csrf_check();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'create_project') {
            $code = strtolower(trim((string)($_POST['codigo'] ?? '')));
            $name = trim((string)($_POST['nome'] ?? ''));
            $callback = trim((string)($_POST['url_notificacao'] ?? ''));
            if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,39}$/', $code) || $name === '') throw new InvalidArgumentException('Informe um código simples (letras, números, hífen ou sublinhado) e o nome do serviço.');
            if ($callback !== '' && (!filter_var($callback, FILTER_VALIDATE_URL) || !str_starts_with($callback, 'https://'))) throw new InvalidArgumentException('A URL de notificação, se informada, deve usar HTTPS.');
            $plainKey = new_service_key();
            $stmt = $pdo->prepare('INSERT INTO "Projetos SANDBOX" (codigo, nome, url_notificacao, api_key_hash, ativo) VALUES (:code, :name, :callback, :hash, TRUE)');
            $stmt->execute(['code' => $code, 'name' => $name, 'callback' => $callback ?: null, 'hash' => hash('sha256', $plainKey)]);
            $_SESSION['issued_service_key'] = ['code' => $code, 'key' => $plainKey];
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Serviço cadastrado. Copie a chave abaixo agora; ela não será mostrada novamente.'];
            header('Location: /painel?aba=servicos', true, 303); exit;
        }
        if ($action === 'toggle_project') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) throw new InvalidArgumentException('Serviço inválido.');
            $pdo->prepare('UPDATE "Projetos SANDBOX" SET ativo = NOT ativo WHERE id = :id')->execute(['id' => $id]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Situação do serviço atualizada.'];
            header('Location: /painel?aba=servicos', true, 303); exit;
        }
        if ($action === 'save_customer') {
            $name = trim((string)($_POST['nome'] ?? ''));
            $document = preg_replace('/\D+/', '', (string)($_POST['documento'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $phone = trim((string)($_POST['telefone'] ?? ''));
            if ($name === '' || !valid_br_document($document) || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Preencha nome, CPF/CNPJ válido e e-mail válido.');
            $lookup = $pdo->prepare("SELECT id FROM \"Clientes MDI\" WHERE regexp_replace(COALESCE(NULLIF(btrim(cpf), ''), cnpj, ''), '[^0-9]', '', 'g') = :doc LIMIT 1");
            $lookup->execute(['doc' => $document]);
            $existingId = $lookup->fetchColumn();
            $columns = 'nome = :nome, razao_social = :razao, cpf = :cpf, cnpj = :cnpj, email = :email, telefone = :telefone, ativo = TRUE';
            $values = ['nome' => $name, 'razao' => $name, 'cpf' => strlen($document) === 11 ? $document : null, 'cnpj' => strlen($document) === 14 ? $document : null, 'email' => $email, 'telefone' => $phone ?: null];
            if ($existingId) {
                $values['id'] = (int)$existingId;
                $pdo->prepare('UPDATE "Clientes MDI" SET ' . $columns . ' WHERE id = :id')->execute($values);
                $message = 'Cadastro do cliente atualizado.';
            } else {
                $pdo->prepare('INSERT INTO "Clientes MDI" (nome, razao_social, cpf, cnpj, email, telefone, ativo) VALUES (:nome, :razao, :cpf, :cnpj, :email, :telefone, TRUE)')->execute($values);
                $message = 'Cliente cadastrado.';
            }
            $_SESSION['flash'] = ['type' => 'success', 'message' => $message];
            header('Location: /painel?aba=clientes', true, 303); exit;
        }
        if ($action === 'create_charge') {
            $projectId = filter_var($_POST['projeto_id'] ?? null, FILTER_VALIDATE_INT);
            $customerId = filter_var($_POST['cliente_id'] ?? null, FILTER_VALIDATE_INT);
            $projectQuery = $pdo->prepare('SELECT id, codigo, nome FROM "Projetos SANDBOX" WHERE id = :id AND ativo = TRUE');
            $projectQuery->execute(['id' => $projectId]);
            $project = $projectQuery->fetch();
            $customerQuery = $pdo->prepare('SELECT id, nome, cpf, cnpj, email, telefone, asaas_id FROM "Clientes MDI" WHERE id = :id');
            $customerQuery->execute(['id' => $customerId]);
            $customer = $customerQuery->fetch();
            if (!$project || !$customer) throw new InvalidArgumentException('Escolha um serviço ativo e um cliente cadastrado.');
            $amount = cents_from_brl((string)($_POST['valor'] ?? ''));
            $answer = create_charge($project, [
                'nome' => $customer['nome'], 'cpf_cnpj' => $customer['cpf'] ?: $customer['cnpj'],
                'email' => $customer['email'], 'telefone' => $customer['telefone'],
                'valor_centavos' => $amount, 'referencia' => trim((string)($_POST['referencia'] ?? '')),
                'vencimento' => (string)($_POST['vencimento'] ?? ''), 'descricao' => trim((string)($_POST['descricao'] ?? '')),
            ]);
            $key = $project['codigo'] . ':' . trim((string)$_POST['referencia']);
            $idQuery = $pdo->prepare('SELECT id FROM "Cobrancas SANDBOX" WHERE chave_pedido = :key LIMIT 1');
            $idQuery->execute(['key' => $key]);
            $chargeId = $idQuery->fetchColumn();
            if ($chargeId) {
                $url = find_invoice_url($answer);
                $pdo->prepare('UPDATE "Cobrancas SANDBOX" SET cliente_local_id = :customer, projeto_id = :project, descricao = :description, invoice_url = COALESCE(:url, invoice_url), origem = :origin WHERE id = :id')
                    ->execute(['customer' => $customer['id'], 'project' => $project['id'], 'description' => trim((string)($_POST['descricao'] ?? '')) ?: null, 'url' => $url, 'origin' => 'painel', 'id' => $chargeId]);
            }
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Solicitação enviada ao n8n. Resultado: ' . json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
            header('Location: /painel?aba=cobrancas', true, 303); exit;
        }
        throw new InvalidArgumentException('Ação não reconhecida.');
    } catch (PDOException $e) {
        error_log('pay360bh admin database error: ' . $e->getMessage());
        $_SESSION['flash'] = ['type' => 'error', 'message' => str_contains($e->getMessage(), 'unique') || str_contains($e->getMessage(), '23505') ? 'Este código ou documento já está cadastrado.' : 'Não foi possível salvar os dados no banco.'];
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => $e->getMessage()];
    }
    header('Location: /painel?aba=' . rawurlencode($activeTab), true, 303); exit;
}

function find_invoice_url(array $value): ?string
{
    $walk = static function (mixed $item) use (&$walk): ?string {
        if (!is_array($item)) return null;
        foreach (['invoiceUrl', 'invoice_url', 'link_pagamento'] as $key) {
            if (isset($item[$key]) && filter_var($item[$key], FILTER_VALIDATE_URL)) return $item[$key];
        }
        foreach ($item as $child) if (is_array($child) && ($found = $walk($child))) return $found;
        return null;
    };
    return $walk($value);
}

$projects = $pdo->query('SELECT id, codigo, nome, url_notificacao, ativo FROM "Projetos SANDBOX" ORDER BY nome')->fetchAll();
$customers = $pdo->query('SELECT id, nome, cpf, cnpj, email, telefone, asaas_id FROM "Clientes MDI" ORDER BY id DESC LIMIT 250')->fetchAll();
$charges = $pdo->query('SELECT c.id, c.projeto, c.referencia, c.status, c.valor_centavos, c.vencimento, c.cobranca_asaas_id, c.invoice_url, c.cliente_local_id, p.nome AS projeto_nome, cl.nome AS cliente_nome FROM "Cobrancas SANDBOX" c LEFT JOIN "Projetos SANDBOX" p ON p.id = c.projeto_id LEFT JOIN "Clientes MDI" cl ON cl.id = c.cliente_local_id ORDER BY c.id DESC LIMIT 100')->fetchAll();
$counts = [
    'projects' => (int)$pdo->query('SELECT COUNT(*) FROM "Projetos SANDBOX"')->fetchColumn(),
    'customers' => (int)$pdo->query('SELECT COUNT(*) FROM "Clientes MDI"')->fetchColumn(),
    'charges' => (int)$pdo->query('SELECT COUNT(*) FROM "Cobrancas SANDBOX"')->fetchColumn(),
    'pending' => (int)$pdo->query("SELECT COUNT(*) FROM \"Cobrancas SANDBOX\" WHERE upper(status) IN ('PENDING','PENDENTE','AWAITING_PAYMENT')")->fetchColumn(),
];
$csrf = csrf_token();
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Central de Cobranças | 360BH</title><link rel="stylesheet" href="/assets/panel.css"></head><body><div class="shell"><aside class="sidebar"><div class="brand">360BH <span>Central de Cobranças</span></div><nav><?php foreach (['resumo'=>'Visão geral','servicos'=>'Serviços','clientes'=>'Clientes','cobrancas'=>'Cobranças'] as $key => $label): ?><a class="<?=$activeTab === $key ? 'active' : ''?>" href="/painel?aba=<?=$key?>"><?=esc($label)?></a><?php endforeach; ?></nav><form method="post" action="/logout" class="logout-form"><input type="hidden" name="_csrf" value="<?=esc($csrf)?>"><button class="logout">Sair</button></form></aside><main class="main"><header><div><p class="eyebrow">AMBIENTE SANDBOX</p><h1><?=esc(['resumo'=>'Visão geral','servicos'=>'Serviços autorizados','clientes'=>'Clientes compartilhados','cobrancas'=>'Cobranças'][$activeTab])?></h1></div><span class="badge">Asaas via n8n</span></header>
<?php if ($flash): ?><div class="notice <?=esc($flash['type'])?>"><?=esc($flash['message'])?></div><?php endif; ?>
<?php if ($issuedKey): ?><section class="notice warning"><strong>Chave do serviço — copie agora.</strong><p>Ela só aparece nesta tela uma vez. Guarde-a num cofre de senhas.</p><code><?=esc($issuedKey['key'])?></code><p>Serviço: <?=esc($issuedKey['code'])?> · Envie a chave no cabeçalho <code>X-Service-Key</code>.</p></section><?php endif; ?>
<?php if ($activeTab === 'resumo'): ?><section class="cards"><article><span>Serviços</span><strong><?=$counts['projects']?></strong></article><article><span>Clientes</span><strong><?=$counts['customers']?></strong></article><article><span>Cobranças</span><strong><?=$counts['charges']?></strong></article><article><span>Aguardando pagamento</span><strong><?=$counts['pending']?></strong></article></section><section class="panel"><h2>Como funciona</h2><p>Cadastre serviços e clientes aqui. Uma cobrança manual ou enviada por API segue para o n8n, que conversa com o Asaas Sandbox. O estado financeiro volta pelos webhooks e fica registrado no PostgreSQL.</p><p>Esta versão ainda está em homologação. Não use cobranças comerciais nem altere o DNS do domínio antes de completar a validação ponta a ponta.</p></section>
<?php elseif ($activeTab === 'servicos'): ?><section class="panel"><h2>Cadastrar serviço</h2><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?=esc($csrf)?>"><input type="hidden" name="action" value="create_project"><label>Código único<input name="codigo" required maxlength="40" placeholder="ex.: hotspot-rural"></label><label>Nome do serviço<input name="nome" required maxlength="160" placeholder="Hotspot Rural"></label><label class="wide">URL para notificações do serviço (opcional)<input name="url_notificacao" type="url" placeholder="https://servico.exemplo/webhook/pagamento"></label><div class="wide"><button class="button primary">Cadastrar e gerar chave</button></div></form></section><section class="panel"><h2>Serviços cadastrados</h2><div class="table-wrap"><table><thead><tr><th>Código</th><th>Nome</th><th>Callback</th><th>Estado</th><th></th></tr></thead><tbody><?php foreach ($projects as $p): ?><tr><td><code><?=esc($p['codigo'])?></code></td><td><?=esc($p['nome'])?></td><td><?=esc($p['url_notificacao'] ?: '—')?></td><td><span class="pill <?=$p['ativo'] ? 'good' : 'off'?>"><?=$p['ativo'] ? 'Ativo' : 'Inativo'?></span></td><td><form method="post"><input type="hidden" name="_csrf" value="<?=esc($csrf)?>"><input type="hidden" name="action" value="toggle_project"><input type="hidden" name="id" value="<?=esc($p['id'])?>"><button class="button small"><?=$p['ativo'] ? 'Desativar' : 'Ativar'?></button></form></td></tr><?php endforeach; ?><?php if (!$projects): ?><tr><td colspan="5">Nenhum serviço cadastrado.</td></tr><?php endif; ?></tbody></table></div></section>
<?php elseif ($activeTab === 'clientes'): ?><section class="panel"><h2>Cadastrar ou atualizar cliente</h2><p>O CPF/CNPJ identifica o cadastro compartilhado entre os serviços.</p><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?=esc($csrf)?>"><input type="hidden" name="action" value="save_customer"><label>Nome / razão social<input name="nome" required maxlength="160"></label><label>CPF ou CNPJ<input name="documento" required inputmode="numeric" maxlength="18"></label><label>E-mail<input name="email" type="email" required></label><label>Telefone (opcional)<input name="telefone" inputmode="tel"></label><div class="wide"><button class="button primary">Salvar cliente</button></div></form></section><section class="panel"><h2>Clientes</h2><div class="table-wrap"><table><thead><tr><th>Nome</th><th>Documento</th><th>E-mail</th><th>Asaas</th></tr></thead><tbody><?php foreach ($customers as $c): ?><tr><td><?=esc($c['nome'] ?: '—')?></td><td><?=esc($c['cpf'] ?: $c['cnpj'] ?: '—')?></td><td><?=esc($c['email'] ?: '—')?></td><td><?=esc($c['asaas_id'] ?: 'Ainda não vinculado')?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php else: ?><section class="panel"><h2>Gerar cobrança avulsa</h2><p>O painel envia a solicitação ao n8n. Uma nova referência deve ser usada para cada cobrança.</p><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?=esc($csrf)?>"><input type="hidden" name="action" value="create_charge"><label>Serviço<select name="projeto_id" required><option value="">Selecione</option><?php foreach ($projects as $p) if ($p['ativo']): ?><option value="<?=esc($p['id'])?>"><?=esc($p['nome'])?> (<?=esc($p['codigo'])?>)</option><?php endif; ?></select></label><label>Cliente<select name="cliente_id" required><option value="">Selecione</option><?php foreach ($customers as $c): ?><option value="<?=esc($c['id'])?>"><?=esc($c['nome'] ?: $c['cpf'] ?: $c['cnpj'])?> — <?=esc($c['email'])?></option><?php endforeach; ?></select></label><label>Referência única<input name="referencia" required maxlength="100" placeholder="pedido-2026-001"></label><label>Valor (R$)<input name="valor" required inputmode="decimal" placeholder="10,00"></label><label>Vencimento<input name="vencimento" type="date" required value="<?=date('Y-m-d')?>"></label><label>Descrição (opcional)<input name="descricao" maxlength="200"></label><div class="wide"><button class="button primary" <?=(!$projects || !$customers) ? 'disabled' : ''?>>Enviar ao n8n e gerar Pix</button></div></form></section><section class="panel"><h2>Últimas cobranças</h2><div class="table-wrap"><table><thead><tr><th>Serviço</th><th>Cliente</th><th>Referência</th><th>Valor</th><th>Vencimento</th><th>Status</th><th>Pagamento</th></tr></thead><tbody><?php foreach ($charges as $c): ?><tr><td><?=esc($c['projeto_nome'] ?: $c['projeto'])?></td><td><?=esc($c['cliente_nome'] ?: '—')?></td><td><?=esc($c['referencia'])?></td><td><?=esc(is_numeric($c['valor_centavos']) ? money((int)$c['valor_centavos']) : $c['valor_centavos'])?></td><td><?=esc($c['vencimento'])?></td><td><?=esc($c['status'])?></td><td><?php if ($c['invoice_url']): ?><a href="<?=esc($c['invoice_url'])?>" target="_blank" rel="noopener">Abrir</a><?php else: ?><?=esc($c['cobranca_asaas_id'] ?: '—')?><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php endif; ?>
<footer>Pay 360BH · Painel Sandbox · <?=date('Y')?></footer></main></div></body></html>
