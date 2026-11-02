<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap/app.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; style-src 'self'; img-src 'self' data:");
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
        $configuredHash = '';
        $encodedHash = (string)envv('ADMIN_PASSWORD_HASH_B64', '');
        if ($encodedHash !== '') {
            $decodedHash = base64_decode($encodedHash, true);
            if ($decodedHash !== false) $configuredHash = $decodedHash;
        } else {
            // Compatibilidade temporária com implantações que ainda usam o hash direto.
            $configuredHash = (string)envv('ADMIN_PASSWORD_HASH', '');
        }
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
    if ($path !== '/login' && $path !== '/' && !preg_match('#^/clientes/(novo|[0-9]+/editar)$#', $path) && !preg_match('#^/cobrancas/[0-9]+$#', $path)) { http_response_code(404); exit('Pagina nao encontrada.'); }
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    ?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Entrar | Pay 360BH</title><link rel="stylesheet" href="/assets/panel.css"></head><body class="login"><main class="login-card"><div class="brand">360BH <span>Central de Cobranças</span></div><h1>Acessar painel</h1><?php if ($flash): ?><div class="notice error"><?=esc($flash['message'])?></div><?php endif; ?><form method="post" action="/login"><input type="hidden" name="_csrf" value="<?=esc(csrf_token())?>"><label>E-mail<input type="email" name="email" autocomplete="username" required></label><label>Senha<input type="password" name="password" autocomplete="current-password" required></label><button class="button primary">Entrar</button></form></main></body></html><?php exit;
}

if ($path === '/' || $path === '/login') { header('Location: /painel', true, 303); exit; }
admin_required();
$pdo = db();
$toBool = static fn(mixed $value): bool => in_array($value, [true, 1, '1', 't', 'true'], true);
$customerRoute = $path === '/clientes/novo' ? 'new' : (preg_match('#^/clientes/([0-9]+)/editar$#', $path, $routeMatch) ? 'edit' : null);
$routeCustomerId = $customerRoute === 'edit' ? (int)$routeMatch[1] : null;
$chargeRoute = preg_match('#^/cobrancas/([0-9]+)$#', $path, $chargeMatch) ? (int)$chargeMatch[1] : null;
$chargeDetail = null;
if ($chargeRoute !== null) {
    $detailStmt = $pdo->prepare('SELECT c.*, p.nome AS projeto_nome, cl.nome AS cliente_nome, cl.email AS cliente_email FROM "Cobrancas SANDBOX" c LEFT JOIN "Projetos SANDBOX" p ON p.id = c.projeto_id LEFT JOIN "Clientes MDI" cl ON cl.id = c.cliente_local_id WHERE c.id = :id');
    $detailStmt->execute(['id' => $chargeRoute]);
    $chargeDetail = $detailStmt->fetch();
    if (!$chargeDetail) { http_response_code(404); exit('Cobranca nao encontrada.'); }
}
$editingCustomer = null;
if ($customerRoute === 'edit') {
    $customerStmt = $pdo->prepare('SELECT * FROM "Clientes MDI" WHERE id = :id');
    $customerStmt->execute(['id' => $routeCustomerId]);
    $editingCustomer = $customerStmt->fetch();
    if ($editingCustomer) $editingCustomer['ativo'] = $toBool($editingCustomer['ativo']);
    if (!$editingCustomer) { http_response_code(404); exit('Cliente não encontrado.'); }
}
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$customerForm = $_SESSION['customer_form'] ?? [];
unset($_SESSION['customer_form']);
$issuedKey = $_SESSION['issued_service_key'] ?? null;
unset($_SESSION['issued_service_key']);
$activeTab = $chargeDetail ? 'charge_detail' : (in_array($_GET['aba'] ?? '', ['resumo', 'servicos', 'clientes', 'cobrancas'], true) ? (string)$_GET['aba'] : 'resumo');

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
        if ($action === 'update_project_notification_url') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            $callback = trim((string)($_POST['url_notificacao'] ?? ''));
            if (!$id) throw new InvalidArgumentException('Serviço inválido.');
            if (strlen($callback) > 2000 || ($callback !== '' && (!filter_var($callback, FILTER_VALIDATE_URL) || !str_starts_with($callback, 'https://')))) {
                throw new InvalidArgumentException('Informe uma URL HTTPS válida de até 2000 caracteres, ou deixe o campo vazio para remover o destino.');
            }
            $stmt = $pdo->prepare('UPDATE "Projetos SANDBOX" SET url_notificacao = :callback WHERE id = :id RETURNING id');
            $stmt->execute(['callback' => $callback !== '' ? $callback : null, 'id' => $id]);
            if (!$stmt->fetchColumn()) throw new InvalidArgumentException('Serviço não encontrado.');
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'URL de notificação atualizada.'];
            header('Location: /painel?aba=servicos', true, 303); exit;
        }
        if ($action === 'rotate_project_key') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) throw new InvalidArgumentException('Servico invalido.');
            $plainKey = new_service_key();
            $stmt = $pdo->prepare('UPDATE "Projetos SANDBOX" SET api_key_hash = :hash WHERE id = :id AND ativo = TRUE RETURNING codigo');
            $stmt->execute(['hash' => hash('sha256', $plainKey), 'id' => $id]);
            $code = $stmt->fetchColumn();
            if (!$code) throw new InvalidArgumentException('Servico inexistente ou inativo. Ative-o antes de gerar uma nova chave.');
            $_SESSION['issued_service_key'] = ['code' => $code, 'key' => $plainKey];
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Chave substituida. A chave anterior foi revogada imediatamente; copie a nova agora.'];
            header('Location: /painel?aba=servicos', true, 303); exit;
        }
        if ($action === 'save_customer') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            if ($customerRoute === 'edit' && $id !== $routeCustomerId) throw new InvalidArgumentException('O cliente do formulario nao corresponde ao cadastro aberto. Recarregue a pagina e tente novamente.');
            if ($customerRoute !== 'edit' && isset($_POST['id'])) throw new InvalidArgumentException('Nao foi possivel identificar o cliente. Recarregue a pagina e tente novamente.');
            $name = trim((string)($_POST['nome'] ?? ''));
            $legalName = trim((string)($_POST['razao_social'] ?? ''));
            $cpf = preg_replace('/\D+/', '', (string)($_POST['cpf'] ?? ''));
            $cnpj = preg_replace('/\D+/', '', (string)($_POST['cnpj'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $phone = preg_replace('/\D+/', '', (string)($_POST['telefone'] ?? ''));
            $contact = trim((string)($_POST['contato'] ?? ''));
            $address = trim((string)($_POST['endereco'] ?? ''));
            $number = trim((string)($_POST['numero'] ?? ''));
            $complement = trim((string)($_POST['numero_complemento'] ?? ''));
            $district = trim((string)($_POST['bairro'] ?? ''));
            $city = trim((string)($_POST['municipio'] ?? ''));
            $state = strtoupper(trim((string)($_POST['estado'] ?? '')));
            $cep = preg_replace('/\D+/', '', (string)($_POST['cep'] ?? ''));
            $plan = trim((string)($_POST['plano'] ?? ''));
            $value = trim((string)($_POST['valor'] ?? ''));
            $dueDay = trim((string)($_POST['vencimento'] ?? ''));
            $nextDue = trim((string)($_POST['proximo_vencimento'] ?? ''));
            $lastNotice = trim((string)($_POST['ultimo_aviso'] ?? ''));
            if ($cpf !== '' && $cnpj !== '') throw new InvalidArgumentException('Informe CPF ou CNPJ, nao os dois.');
            $document = $cpf !== '' ? $cpf : $cnpj;
            if ($name === '' || $document === '' || !valid_br_document($document)) throw new InvalidArgumentException('Nome e um CPF/CNPJ valido sao obrigatorios.');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('O e-mail informado nao e valido.');
            if (($cpf !== '' && strlen($cpf) !== 11) || ($cnpj !== '' && strlen($cnpj) !== 14)) throw new InvalidArgumentException('Confira a quantidade de digitos do CPF ou CNPJ.');
            if ($state !== '' && !preg_match('/^[A-Z]{2}$/', $state)) throw new InvalidArgumentException('Informe a UF com duas letras.');
            if ($number !== '' && (!ctype_digit($number) || strlen($number) > 12)) throw new InvalidArgumentException('Numero do endereco deve ser inteiro.');
            if ($cep !== '' && strlen($cep) !== 8) throw new InvalidArgumentException('O CEP deve ter 8 digitos.');
            if ($value !== '' && !preg_match('/^\d{1,10}(?:[,.]\d{1,2})?$/', $value)) throw new InvalidArgumentException('Valor invalido. Use, por exemplo, 49,90.');
            if ($dueDay !== '' && (!ctype_digit($dueDay) || (int)$dueDay < 1 || (int)$dueDay > 31)) throw new InvalidArgumentException('O vencimento deve ser um dia entre 1 e 31.');
            if ($nextDue !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $nextDue) || !checkdate((int)substr($nextDue, 5, 2), (int)substr($nextDue, 8, 2), (int)substr($nextDue, 0, 4)))) throw new InvalidArgumentException('Confira a data do proximo vencimento.');
            if ($lastNotice !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $lastNotice)) throw new InvalidArgumentException('Confira a data do ultimo aviso.');
            $params = [
                'nome' => $name, 'razao' => $legalName ?: null, 'cpf' => $cpf ?: null, 'cnpj' => $cnpj ?: null,
                'endereco' => trim($address) ?: null, 'numero' => $number === '' ? null : (int)$number,
                'complemento' => $complement ?: null, 'bairro' => $district ?: null, 'municipio' => $city ?: null,
                'estado' => $state ?: null, 'cep' => $cep === '' ? null : (int)$cep, 'contato' => $contact ?: null,
                'email' => $email ?: null, 'telefone' => $phone ?: null, 'plano' => $plan ?: null,
                'valor' => $value === '' ? null : str_replace(',', '.', $value),
                'vencimento' => $dueDay === '' ? null : (int)$dueDay,
                'proximo_vencimento' => $nextDue ?: null,
                'ultimo_aviso' => $lastNotice === '' ? null : str_replace('T', ' ', $lastNotice) . ':00',
                'ativo' => isset($_POST['ativo']),
            ];
            if ($id) {
                $params['id'] = $id;
                $stmt = $pdo->prepare('UPDATE "Clientes MDI" SET nome=:nome, razao_social=:razao, cpf=:cpf, cnpj=:cnpj, endereco=:endereco, numero=:numero, numero_complemento=:complemento, bairro=:bairro, municipio=:municipio, estado=:estado, cep=:cep, contato=:contato, email=:email, telefone=:telefone, plano=:plano, valor=:valor, vencimento=:vencimento, proximo_vencimento=:proximo_vencimento, ultimo_aviso=:ultimo_aviso, ativo=:ativo, updated_at=NOW() WHERE id=:id');
                $stmt->execute($params);
                $message = 'Cadastro do cliente atualizado.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO "Clientes MDI" (nome, razao_social, cpf, cnpj, endereco, numero, numero_complemento, bairro, municipio, estado, cep, contato, email, telefone, plano, valor, vencimento, proximo_vencimento, ultimo_aviso, ativo) VALUES (:nome, :razao, :cpf, :cnpj, :endereco, :numero, :complemento, :bairro, :municipio, :estado, :cep, :contato, :email, :telefone, :plano, :valor, :vencimento, :proximo_vencimento, :ultimo_aviso, :ativo)');
                $stmt->execute($params);
                $message = 'Cliente cadastrado.';
            }
            $_SESSION['flash'] = ['type' => 'success', 'message' => $message];
            header('Location: /painel?aba=clientes', true, 303); exit;
        }
        if ($action === 'deactivate_customer') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) throw new InvalidArgumentException('Cliente invalido.');
            $pdo->prepare('UPDATE "Clientes MDI" SET ativo = FALSE, updated_at = NOW() WHERE id = :id')->execute(['id' => $id]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Cliente inativado. O historico financeiro foi preservado.'];
            header('Location: /painel?aba=clientes', true, 303); exit;
        }
        if ($action === 'create_charge') {
            $projectId = filter_var($_POST['projeto_id'] ?? null, FILTER_VALIDATE_INT);
            $customerId = filter_var($_POST['cliente_id'] ?? null, FILTER_VALIDATE_INT);
            $projectQuery = $pdo->prepare('SELECT id, codigo, nome FROM "Projetos SANDBOX" WHERE id = :id AND ativo = TRUE');
            $projectQuery->execute(['id' => $projectId]);
            $project = $projectQuery->fetch();
            $customerQuery = $pdo->prepare('SELECT id, nome, cpf, cnpj, email, telefone, asaas_id FROM "Clientes MDI" WHERE id = :id AND ativo = TRUE');
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
                $pix = find_pix_data($answer);
                $pdo->prepare('UPDATE "Cobrancas SANDBOX" SET cliente_local_id = :customer, projeto_id = :project, descricao = :description, invoice_url = COALESCE(:url, invoice_url), pix_payload = COALESCE(:payload, pix_payload), pix_encoded_image = COALESCE(:image, pix_encoded_image), origem = :origin WHERE id = :id')
                    ->execute(['customer' => $customer['id'], 'project' => $project['id'], 'description' => trim((string)($_POST['descricao'] ?? '')) ?: null, 'url' => $url, 'payload' => $pix['payload'], 'image' => $pix['image'], 'origin' => 'painel', 'id' => $chargeId]);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Cobrança criada no Asaas e salva na Central. Confira os dados abaixo.'];
                header('Location: /cobrancas/' . (int)$chargeId, true, 303); exit;
            }
            $_SESSION['flash'] = ['type' => 'warning', 'message' => 'O n8n respondeu, mas a cobrança ainda não apareceu no banco local. Não envie novamente; confira a lista e o histórico do n8n.'];
            header('Location: /painel?aba=cobrancas', true, 303); exit;
        }
        if ($action === 'receive_charge' || $action === 'cancel_charge') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) throw new InvalidArgumentException('Cobrança inválida.');
            $chargeStmt = $pdo->prepare('SELECT id, status, valor_centavos, cobranca_asaas_id, chave_pedido FROM "Cobrancas SANDBOX" WHERE id = :id');
            $chargeStmt->execute(['id' => $id]);
            $charge = $chargeStmt->fetch();
            if (!$charge || !preg_match('/^pay_[A-Za-z0-9]+$/', (string)$charge['cobranca_asaas_id'])) throw new InvalidArgumentException('A cobrança não tem um ID Asaas válido.');
            if (!in_array(strtoupper((string)$charge['status']), ['PENDING', 'PENDENTE', 'AWAITING_PAYMENT', 'OVERDUE', 'VENCIDA'], true)) throw new DomainException('Somente cobranças pendentes ou vencidas podem receber esta ação.');
            $payload = ['action' => $action === 'receive_charge' ? 'receive_cash' : 'cancel', 'charge_id' => (int)$charge['id'], 'asaas_payment_id' => (string)$charge['cobranca_asaas_id'], 'order_key' => (string)$charge['chave_pedido']];
            if ($action === 'receive_charge') {
                $date = (string)($_POST['payment_date'] ?? '');
                if ($date === '') $date = date('Y-m-d');
                $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new InvalidArgumentException('Informe uma data de recebimento válida.');
                if (($_POST['confirm_receive'] ?? '') !== '1') throw new InvalidArgumentException('Confirme o recebimento integral por outro meio.');
                $payload['payment_date'] = $date;
            } elseif (($_POST['confirm_cancel'] ?? '') !== '1') {
                throw new InvalidArgumentException('Confirme o cancelamento desta cobrança.');
            }
            notify_charge_admin_action($payload);
            $_SESSION['flash'] = ['type' => 'success', 'message' => $action === 'receive_charge' ? 'Recebimento externo registrado no Asaas. O webhook atualizará o histórico.' : 'Cobrança cancelada no Asaas. O webhook atualizará o histórico.'];
            header('Location: /cobrancas/' . (int)$id, true, 303); exit;
        }
        throw new InvalidArgumentException('Ação não reconhecida.');
    } catch (PDOException $e) {
        error_log('pay360bh admin database error: ' . $e->getMessage());
        $_SESSION['flash'] = ['type' => 'error', 'message' => str_contains($e->getMessage(), 'unique') || str_contains($e->getMessage(), '23505') ? 'Este código ou documento já está cadastrado.' : 'Não foi possível salvar os dados no banco.'];
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => $e->getMessage()];
    }
    if ($customerRoute) $_SESSION['customer_form'] = array_merge($_POST, ['_form_submitted' => true]);
    $errorReturn = $customerRoute === 'edit' ? '/clientes/' . $routeCustomerId . '/editar' : ($customerRoute === 'new' ? '/clientes/novo' : '/painel?aba=' . rawurlencode($activeTab));
    header('Location: ' . $errorReturn, true, 303); exit;
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

function find_pix_data(array $value): array
{
    $found = ['payload' => null, 'image' => null];
    $walk = static function (mixed $item) use (&$walk, &$found): void {
        if (!is_array($item)) return;
        foreach (['payload', 'pix_copia_cola'] as $key) if (isset($item[$key]) && is_string($item[$key]) && $item[$key] !== '') $found['payload'] = $item[$key];
        foreach (['encodedImage', 'qr_code_base64'] as $key) if (isset($item[$key]) && is_string($item[$key]) && $item[$key] !== '') $found['image'] = preg_replace('#^data:image/[^;]+;base64,#', '', $item[$key]);
        foreach ($item as $child) if (is_array($child)) $walk($child);
    };
    $walk($value);
    return $found;
}

$projects = $pdo->query('SELECT id, codigo, nome, url_notificacao, ativo FROM "Projetos SANDBOX" ORDER BY nome')->fetchAll();
foreach ($projects as &$project) $project['ativo'] = $toBool($project['ativo']);
unset($project);
$customers = $pdo->query('SELECT id, nome, cpf, cnpj, email, telefone, asaas_id, ativo FROM "Clientes MDI" ORDER BY id DESC')->fetchAll();
foreach ($customers as &$customer) $customer['ativo'] = $toBool($customer['ativo']);
unset($customer);
$activeCustomers = $pdo->query('SELECT id, nome, cpf, cnpj, email FROM "Clientes MDI" WHERE ativo = TRUE ORDER BY id DESC')->fetchAll();
$charges = $pdo->query('SELECT c.id, c.projeto, c.referencia, c.status, c.valor_centavos, c.vencimento, c.cobranca_asaas_id, c.invoice_url, c.cliente_local_id, p.nome AS projeto_nome, cl.nome AS cliente_nome FROM "Cobrancas SANDBOX" c LEFT JOIN "Projetos SANDBOX" p ON p.id = c.projeto_id LEFT JOIN "Clientes MDI" cl ON cl.id = c.cliente_local_id ORDER BY c.id DESC LIMIT 100')->fetchAll();
$counts = [
    'projects' => (int)$pdo->query('SELECT COUNT(*) FROM "Projetos SANDBOX"')->fetchColumn(),
    'customers' => (int)$pdo->query('SELECT COUNT(*) FROM "Clientes MDI" WHERE ativo = TRUE')->fetchColumn(),
    'charges' => (int)$pdo->query('SELECT COUNT(*) FROM "Cobrancas SANDBOX"')->fetchColumn(),
    'pending' => (int)$pdo->query("SELECT COUNT(*) FROM \"Cobrancas SANDBOX\" WHERE upper(status) IN ('PENDING','PENDENTE','AWAITING_PAYMENT')")->fetchColumn(),
];
$pageTitle = $chargeDetail ? 'Detalhes da cobrança' : ($customerRoute ? ($customerRoute === 'new' ? 'Novo cliente' : 'Editar cliente') : ['resumo'=>'Visao geral','servicos'=>'Servicos autorizados','clientes'=>'Clientes compartilhados','cobrancas'=>'Cobrancas'][$activeTab]);
$csrf = csrf_token();
$customerValue = static fn(string $key, string $default = ''): string => esc($customerForm[$key] ?? $editingCustomer[$key] ?? $default);
$lastNoticeValue = array_key_exists('ultimo_aviso', $customerForm) ? (string)$customerForm['ultimo_aviso'] : '';
if (!array_key_exists('ultimo_aviso', $customerForm) && !empty($editingCustomer['ultimo_aviso'])) $lastNoticeValue = date('Y-m-d\TH:i', strtotime((string)$editingCustomer['ultimo_aviso']));
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Central de Cobranças | 360BH</title><link rel="stylesheet" href="/assets/panel.css"></head><body><div class="shell"><aside class="sidebar"><div class="brand">360BH <span>Central de Cobranças</span></div><nav><?php foreach (['resumo'=>'Visão geral','servicos'=>'Serviços','clientes'=>'Clientes','cobrancas'=>'Cobranças'] as $key => $label): ?><a class="<?=$activeTab === $key ? 'active' : ''?>" href="/painel?aba=<?=$key?>"><?=esc($label)?></a><?php endforeach; ?></nav><form method="post" action="/logout" class="logout-form"><input type="hidden" name="_csrf" value="<?=esc($csrf)?>"><button class="logout">Sair</button></form></aside><main class="main"><header><div><p class="eyebrow">AMBIENTE SANDBOX</p><h1><?=esc($pageTitle)?></h1></div><span class="badge">Asaas via n8n</span></header>
<?php if ($flash): ?><div class="notice <?=esc($flash['type'])?>"><?=esc($flash['message'])?></div><?php endif; ?>
<?php if ($issuedKey): ?><section class="notice warning"><strong>Chave do serviço — copie agora.</strong><p>Ela só aparece nesta tela uma vez. Guarde-a num cofre de senhas.</p><code><?=esc($issuedKey['key'])?></code><p>Serviço: <?=esc($issuedKey['code'])?> · Envie a chave no cabeçalho <code>X-Service-Key</code>.</p></section><?php endif; ?>
<?php if ($customerRoute): ?>
<section class="panel"><h2><?= $customerRoute === 'new' ? 'Dados do cliente' : 'Atualizar dados do cliente' ?></h2><p>O cadastro e compartilhado por todos os servicos. CPF ou CNPJ deve ser unico. Os identificadores Asaas sao gerenciados pela integracao.</p>
<form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?=esc($csrf)?>"><input type="hidden" name="action" value="save_customer"><?php if ($customerRoute === 'edit'): ?><input type="hidden" name="id" value="<?=esc($editingCustomer['id'])?>"><?php endif; ?>
<label>Nome<input name="nome" required maxlength="160" value="<?=$customerValue('nome')?>"></label><label>Razao social<input name="razao_social" maxlength="255" value="<?=$customerValue('razao_social')?>"></label>
<label>CPF<input name="cpf" inputmode="numeric" maxlength="14" value="<?=$customerValue('cpf')?>"></label><label>CNPJ<input name="cnpj" inputmode="numeric" maxlength="18" value="<?=$customerValue('cnpj')?>"></label>
<label>E-mail<input name="email" type="email" maxlength="254" value="<?=$customerValue('email')?>"></label><label>Telefone<input name="telefone" inputmode="tel" maxlength="20" value="<?=$customerValue('telefone')?>"></label>
<label>Contato<input name="contato" maxlength="255" value="<?=$customerValue('contato')?>"></label><label>CEP<input name="cep" inputmode="numeric" maxlength="9" value="<?=esc($customerForm['cep'] ?? ($editingCustomer ? str_pad((string)$editingCustomer['cep'], 8, '0', STR_PAD_LEFT) : ''))?>"></label>
<label class="wide">Endereco<input name="endereco" maxlength="255" value="<?=$customerValue('endereco')?>"></label><label>Numero<input name="numero" inputmode="numeric" value="<?=$customerValue('numero')?>"></label><label>Complemento<input name="numero_complemento" maxlength="255" value="<?=$customerValue('numero_complemento')?>"></label>
<label>Bairro<input name="bairro" maxlength="255" value="<?=$customerValue('bairro')?>"></label><label>Municipio<input name="municipio" maxlength="255" value="<?=$customerValue('municipio')?>"></label><label>Estado (UF)<input name="estado" maxlength="2" value="<?=$customerValue('estado')?>"></label>
<label>Plano<input name="plano" maxlength="255" value="<?=$customerValue('plano')?>"></label><label>Valor do plano<input name="valor" inputmode="decimal" placeholder="49,90" value="<?=$customerValue('valor')?>"></label><label>Dia de vencimento<input name="vencimento" type="number" min="1" max="31" value="<?=$customerValue('vencimento')?>"></label>
<label>Proximo vencimento<input name="proximo_vencimento" type="date" value="<?=$customerValue('proximo_vencimento')?>"></label><label>Ultimo aviso<input name="ultimo_aviso" type="datetime-local" value="<?=esc($lastNoticeValue)?>"></label>
<?php if ($customerRoute === 'edit'): ?><label class="check-field"><input type="checkbox" name="ativo" value="1" <?=((!empty($customerForm['_form_submitted']) ? isset($customerForm['ativo']) : (bool)$editingCustomer['ativo'])) ? 'checked' : ''?>> Cadastro ativo (clientes inativos nao aparecem ao gerar cobrancas)</label><?php else: ?><input type="hidden" name="ativo" value="1"><?php endif; ?>
<div class="wide"><h3>Integracao Asaas (somente leitura)</h3><p>Estes campos sao atualizados automaticamente pelo fluxo de cobrancas.</p></div><label>ID do cliente Asaas<input readonly value="<?=$customerValue('asaas_id')?>"></label><label>Link de pagamento<input readonly value="<?=$customerValue('link_pagamento')?>"></label><label class="wide">ID da cobranca Asaas<input readonly value="<?=$customerValue('id_cobranca_asaas')?>"></label><label class="wide">Anexo NocoDB (somente leitura)<input readonly value="<?=$customerValue('arquivo')?>"></label>
<div class="wide form-actions"><button class="button primary">Salvar cliente</button><a class="button" href="/painel?aba=clientes">Cancelar</a></div></form></section>
<?php elseif ($activeTab === 'resumo'): ?><section class="cards"><article><span>Serviços</span><strong><?=$counts['projects']?></strong></article><article><span>Clientes</span><strong><?=$counts['customers']?></strong></article><article><span>Cobranças</span><strong><?=$counts['charges']?></strong></article><article><span>Aguardando pagamento</span><strong><?=$counts['pending']?></strong></article></section><section class="panel"><h2>Como funciona</h2><p>Cadastre serviços e clientes aqui. Uma cobrança manual ou enviada por API segue para o n8n, que conversa com o Asaas Sandbox. O estado financeiro volta pelos webhooks e fica registrado no PostgreSQL.</p><p>Esta versão ainda está em homologação. Não use cobranças comerciais nem altere o DNS do domínio antes de completar a validação ponta a ponta.</p></section>
<?php elseif ($activeTab === 'servicos'): ?><section class="panel"><details class="service-create"><summary class="button primary">Cadastrar servico</summary><div class="service-create-body"><h2>Novo servico</h2><p>Cada servico recebe uma chave secreta exclusiva. Para chamar a API de cobrancas, envie-a no cabecalho <code>X-Service-Key</code>. A chave completa aparece uma unica vez; somente seu hash e salvo. Configure tambem o destino HTTPS das notificacoes de pagamento.</p><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?=esc($csrf)?>"><input type="hidden" name="action" value="create_project"><label>Codigo unico<input name="codigo" required maxlength="40" placeholder="ex.: hotspot-rural"></label><label>Nome do servico<input name="nome" required maxlength="160" placeholder="Hotspot Rural"></label><label class="wide">URL de destino para notificacoes (opcional)<input name="url_notificacao" type="url" maxlength="2000" placeholder="https://servico.exemplo/webhook/pagamento"></label><div class="wide"><button class="button primary">Salvar servico e gerar chave</button></div></form></div></details></section><section class="panel"><h2>Servicos cadastrados</h2><div class="table-wrap"><table><thead><tr><th>Codigo</th><th>Nome</th><th>Destino de notificacoes</th><th>Estado</th><th>Acoes</th></tr></thead><tbody><?php foreach ($projects as $p): ?><tr><td><code><?=esc($p['codigo'])?></code></td><td><?=esc($p['nome'])?></td><td><code><?=esc($p['url_notificacao'] ?: '-')?></code></td><td><span class="pill <?=$p['ativo'] ? 'good' : 'off'?>"><?=$p['ativo'] ? 'Ativo' : 'Inativo'?></span></td><td class="row-actions"><details class="service-url-edit"><summary class="button small">Trocar URL</summary><form method="post" class="service-url-form"><input type="hidden" name="_csrf" value="<?=esc($csrf)?>"><input type="hidden" name="action" value="update_project_notification_url"><input type="hidden" name="id" value="<?=esc($p['id'])?>"><label>URL HTTPS de notificacao (vazio remove)<input type="url" name="url_notificacao" maxlength="2000" value="<?=esc($p['url_notificacao'] ?? '')?>" placeholder="https://servico.exemplo/webhook/pagamento"></label><button class="button small primary">Salvar URL</button></form></details><form method="post"><input type="hidden" name="_csrf" value="<?=esc($csrf)?>"><input type="hidden" name="action" value="toggle_project"><input type="hidden" name="id" value="<?=esc($p['id'])?>"><button class="button small"><?=$p['ativo'] ? 'Desativar' : 'Ativar'?></button></form><?php if ($p['ativo']): ?><form method="post"><input type="hidden" name="_csrf" value="<?=esc($csrf)?>"><input type="hidden" name="action" value="rotate_project_key"><input type="hidden" name="id" value="<?=esc($p['id'])?>"><button class="button small">Trocar chave</button></form><?php endif; ?></td></tr><?php endforeach; ?><?php if (!$projects): ?><tr><td colspan="5">Nenhum servico cadastrado.</td></tr><?php endif; ?></tbody></table></div></section>
<?php elseif ($activeTab === 'clientes'): ?><section class="panel list-heading"><div><h2>Clientes</h2><p>Excluir desativa o cadastro e preserva as cobrancas e o historico financeiro.</p></div><a class="button primary" href="/clientes/novo">Adicionar cliente</a></section><section class="panel"><div class="table-wrap"><table><thead><tr><th>Nome / razao social</th><th>Documento</th><th>E-mail</th><th>Asaas</th><th>Situacao</th><th>Acoes</th></tr></thead><tbody><?php foreach ($customers as $c): ?><tr><td><?=esc($c['nome'] ?: '-')?></td><td><?=esc($c['cpf'] ?: $c['cnpj'] ?: '-')?></td><td><?=esc($c['email'] ?: '-')?></td><td><?=esc($c['asaas_id'] ?: 'Ainda nao vinculado')?></td><td><span class="pill <?=$c['ativo'] ? 'good' : 'off'?>"><?=$c['ativo'] ? 'Ativo' : 'Inativo'?></span></td><td class="row-actions"><a class="button small" href="/clientes/<?=esc($c['id'])?>/editar">Editar</a><?php if ($c['ativo']): ?><form method="post"><input type="hidden" name="_csrf" value="<?=esc($csrf)?>"><input type="hidden" name="action" value="deactivate_customer"><input type="hidden" name="id" value="<?=esc($c['id'])?>"><button class="button small danger">Excluir</button></form><?php endif; ?></td></tr><?php endforeach; ?><?php if (!$customers): ?><tr><td colspan="6">Nenhum cliente cadastrado.</td></tr><?php endif; ?></tbody></table></div></section>
<?php elseif ($chargeDetail): ?>
<?php $canAct = in_array(strtoupper((string)$chargeDetail['status']), ['PENDING', 'PENDENTE', 'AWAITING_PAYMENT', 'OVERDUE', 'VENCIDA'], true) && preg_match('/^pay_[A-Za-z0-9]+$/', (string)$chargeDetail['cobranca_asaas_id']); ?>
<section class="panel"><div class="list-heading"><div><h2><?=esc($chargeDetail['cliente_nome'] ?: 'Cliente')?> — <?=esc($chargeDetail['referencia'] ?: 'Cobrança')?></h2><p><?=esc($chargeDetail['projeto_nome'] ?: $chargeDetail['projeto'])?> — <?=esc($chargeDetail['cliente_email'] ?: '')?></p></div><a class="button" href="/painel?aba=cobrancas">Voltar às cobranças</a></div>
<div class="detail-grid"><article><span>Valor</span><strong><?=esc(is_numeric($chargeDetail['valor_centavos']) ? money((int)$chargeDetail['valor_centavos']) : $chargeDetail['valor_centavos'])?></strong></article><article><span>Status</span><strong><?=esc($chargeDetail['status'])?></strong></article><article><span>Vencimento</span><strong><?=esc($chargeDetail['vencimento'] ?: '—')?></strong></article><article><span>ID Asaas</span><strong><code><?=esc($chargeDetail['cobranca_asaas_id'] ?: '—')?></code></strong></article></div>
<?php if (!empty($chargeDetail['pix_encoded_image'])): ?><div class="pix-detail"><h3>Pix copia e cola</h3><img class="pix-qr" alt="QR Code Pix da cobrança" src="data:image/png;base64,<?=esc($chargeDetail['pix_encoded_image'])?>"><label>Pix copia e cola<textarea readonly rows="4"><?=esc($chargeDetail['pix_payload'] ?? '')?></textarea></label></div><?php endif; ?>
<?php if (!empty($chargeDetail['invoice_url'])): ?><p><a class="button primary" href="<?=esc($chargeDetail['invoice_url'])?>" target="_blank" rel="noopener">Abrir cobrança no Asaas</a></p><?php endif; ?>
<?php if ($canAct): ?><div class="charge-actions"><form method="post" class="action-card"><input type="hidden" name="_csrf" value="<?=esc($csrf)?>"><input type="hidden" name="action" value="receive_charge"><input type="hidden" name="id" value="<?=esc($chargeDetail['id'])?>"><h3>Dar baixa por recebimento externo</h3><p>Registra no Asaas que o valor integral foi recebido por outro meio.</p><p class="notice warning">Atenção: se houver negativacão em andamento, o Asaas pode cobrar tarifa de ativação ao registrar o recebimento.</p><label>Data do recebimento<input type="date" name="payment_date" required value="<?=date('Y-m-d')?>"></label><label class="check-field"><input type="checkbox" name="confirm_receive" value="1" required> Confirmo o recebimento integral</label><button class="button primary">Registrar recebimento</button></form><form method="post" class="action-card"><input type="hidden" name="_csrf" value="<?=esc($csrf)?>"><input type="hidden" name="action" value="cancel_charge"><input type="hidden" name="id" value="<?=esc($chargeDetail['id'])?>"><h3>Cancelar cobrança</h3><p>O cancelamento remove a cobrança pendente no Asaas; não realiza estorno.</p><label class="check-field"><input type="checkbox" name="confirm_cancel" value="1" required> Confirmo o cancelamento desta cobrança</label><button class="button danger">Cancelar cobrança</button></form></div><?php endif; ?></section>
<?php else: ?><section class="panel"><h2>Gerar cobrança avulsa</h2><p>O painel envia a solicitação ao n8n. Uma nova referência deve ser usada para cada cobrança.</p><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?=esc($csrf)?>"><input type="hidden" name="action" value="create_charge"><label>Serviço<select name="projeto_id" required><option value="">Selecione</option><?php foreach ($projects as $p) if ($p['ativo']): ?><option value="<?=esc($p['id'])?>"><?=esc($p['nome'])?> (<?=esc($p['codigo'])?>)</option><?php endif; ?></select></label><label>Cliente<select name="cliente_id" required><option value="">Selecione</option><?php foreach ($activeCustomers as $c): ?><option value="<?=esc($c['id'])?>"><?=esc($c['nome'] ?: $c['cpf'] ?: $c['cnpj'])?> — <?=esc($c['email'])?></option><?php endforeach; ?></select></label><label>Referência única<input name="referencia" required maxlength="100" placeholder="pedido-2026-001"></label><label>Valor (R$)<input name="valor" required inputmode="decimal" placeholder="10,00"></label><label>Vencimento<input name="vencimento" type="date" required value="<?=date('Y-m-d')?>"></label><label>Descrição (opcional)<input name="descricao" maxlength="200"></label><div class="wide"><button class="button primary" <?=(!$projects || !$activeCustomers) ? 'disabled' : ''?>>Enviar ao n8n e gerar Pix</button></div></form></section><section class="panel"><h2>Últimas cobranças</h2><div class="table-wrap"><table><thead><tr><th>Serviço</th><th>Cliente</th><th>Referência</th><th>Valor</th><th>Vencimento</th><th>Status</th><th>Pagamento</th><th>Ações</th></tr></thead><tbody><?php foreach ($charges as $c): ?><tr><td><?=esc($c['projeto_nome'] ?: $c['projeto'])?></td><td><?=esc($c['cliente_nome'] ?: '—')?></td><td><?=esc($c['referencia'])?></td><td><?=esc(is_numeric($c['valor_centavos']) ? money((int)$c['valor_centavos']) : $c['valor_centavos'])?></td><td><?=esc($c['vencimento'])?></td><td><?=esc($c['status'])?></td><td><?php if ($c['invoice_url']): ?><a href="<?=esc($c['invoice_url'])?>" target="_blank" rel="noopener">Abrir</a><?php else: ?><?=esc($c['cobranca_asaas_id'] ?: '—')?><?php endif; ?></td><td class="row-actions"><a class="button small" href="/cobrancas/<?=esc($c['id'])?>">Visualizar</a><?php if (in_array(strtoupper((string)$c['status']), ['PENDING','PENDENTE','AWAITING_PAYMENT','OVERDUE','VENCIDA'], true) && preg_match('/^pay_[A-Za-z0-9]+$/', (string)$c['cobranca_asaas_id'])): ?><form method="post"><input type="hidden" name="_csrf" value="<?=esc($csrf)?>"><input type="hidden" name="action" value="receive_charge"><input type="hidden" name="id" value="<?=esc($c['id'])?>"><input type="hidden" name="payment_date" value="<?=date('Y-m-d')?>"><button class="button small" name="confirm_receive" value="1" onclick="return confirm('Confirmar recebimento integral por outro meio?')">Dar baixa</button></form><form method="post"><input type="hidden" name="_csrf" value="<?=esc($csrf)?>"><input type="hidden" name="action" value="cancel_charge"><input type="hidden" name="id" value="<?=esc($c['id'])?>"><button class="button small danger" name="confirm_cancel" value="1" onclick="return confirm('Cancelar esta cobrança pendente no Asaas?')">Cancelar</button></form><?php endif; ?></td></tr><?php endforeach; ?><?php if (!$charges): ?><tr><td colspan="8">Nenhuma cobrança cadastrada.</td></tr><?php endif; ?></tbody></table></div></section><?php endif; ?>
<footer>Pay 360BH · Painel Sandbox · <?=date('Y')?></footer></main></div></body></html>
