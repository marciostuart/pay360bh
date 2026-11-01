<?php
declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

function envv(string $name, ?string $default = null): ?string
{
    $value = getenv($name);
    return $value === false ? $default : $value;
}

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;

    $host = envv('DB_HOST', 'localhost');
    $port = envv('DB_PORT', '5432');
    $database = envv('DB_DATABASE');
    $schema = envv('DB_SCHEMA', 'public');
    if (!$database || !preg_match('/^[a-zA-Z0-9_]+$/', $schema)) {
        throw new RuntimeException('Configuração PostgreSQL inválida.');
    }

    $pdo = new PDO(
        "pgsql:host={$host};port={$port};dbname={$database};options='--search_path={$schema},public'",
        envv('DB_USERNAME'),
        envv('DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    return $pdo;
}

function esc(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_json(): array
{
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    session_name('pay360bh_session');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    session_set_cookie_params(['httponly' => true, 'secure' => $https, 'samesite' => 'Lax', 'path' => '/']);
    session_start();
}

function csrf_token(): string
{
    start_secure_session();
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function csrf_check(): void
{
    start_secure_session();
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['_csrf'] ?? ''))) {
        http_response_code(419);
        exit('Sessão expirada. Atualize a página e tente novamente.');
    }
}

function admin_required(): void
{
    start_secure_session();
    if (empty($_SESSION['admin_authenticated'])) {
        header('Location: /login', true, 303);
        exit;
    }
}

function service_required(): array
{
    $key = (string)($_SERVER['HTTP_X_SERVICE_KEY'] ?? '');
    if (strlen($key) < 32) json_out(['error' => 'Chave de serviço inválida.'], 401);
    $query = db()->prepare('SELECT id, codigo, nome FROM "Projetos SANDBOX" WHERE api_key_hash = :hash AND ativo = TRUE LIMIT 1');
    $query->execute(['hash' => hash('sha256', $key)]);
    $service = $query->fetch();
    if (!$service) json_out(['error' => 'Chave de serviço inválida ou inativa.'], 401);
    return $service;
}

function new_service_key(): string
{
    return rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');
}

function money(int $cents): string
{
    return 'R$ ' . number_format($cents / 100, 2, ',', '.');
}

function valid_br_document(string $document): bool
{
    if (preg_match('/^(\d)\1+$/', $document)) return false;
    if (strlen($document) === 11) {
        for ($position = 9; $position < 11; $position++) {
            $sum = 0;
            for ($index = 0; $index < $position; $index++) $sum += (int)$document[$index] * (($position + 1) - $index);
            $digit = ($sum * 10) % 11;
            if ($digit === 10) $digit = 0;
            if ($digit !== (int)$document[$position]) return false;
        }
        return true;
    }
    if (strlen($document) === 14) {
        foreach ([12, 13] as $position) {
            $weights = $position === 12 ? [5,4,3,2,9,8,7,6,5,4,3,2] : [6,5,4,3,2,9,8,7,6,5,4,3,2];
            $sum = 0;
            foreach ($weights as $index => $weight) $sum += (int)$document[$index] * $weight;
            $remainder = $sum % 11;
            $digit = $remainder < 2 ? 0 : 11 - $remainder;
            if ($digit !== (int)$document[$position]) return false;
        }
        return true;
    }
    return false;
}

function cents_from_brl(string $amount): int
{
    $amount = trim($amount);
    if (!preg_match('/^(\d{1,7})(?:[,.](\d{1,2}))?$/', $amount, $parts)) throw new InvalidArgumentException('Informe o valor em reais, por exemplo 10,00.');
    $cents = ((int)$parts[1] * 100) + (int)str_pad($parts[2] ?? '', 2, '0');
    if ($cents < 100 || $cents > 100000000) throw new InvalidArgumentException('O valor deve estar entre R$ 1,00 e R$ 1.000.000,00.');
    return $cents;
}

function notify_payment_workflow(array $payload): array
{
    $url = (string)envv('CENTRAL_N8N_PAYMENT_WEBHOOK_URL', '');
    $token = (string)envv('CENTRAL_N8N_TOKEN', '');
    if (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with($url, 'https://') || $token === '') {
        throw new RuntimeException('Webhook interno do n8n não configurado com HTTPS.');
    }
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-central-token: ' . $token],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $response = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if ($response === false) throw new RuntimeException('Não foi possível contatar o n8n: ' . $error);
    $decoded = json_decode($response, true);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('O n8n recusou a solicitação (HTTP ' . $status . ').');
    }
    return is_array($decoded) ? $decoded : ['resposta' => $response];
}

function create_charge(array $service, array $input): array
{
    $name = trim((string)($input['nome'] ?? $input['name'] ?? ''));
    $document = preg_replace('/\D+/', '', (string)($input['cpf_cnpj'] ?? $input['documento'] ?? ''));
    $email = trim((string)($input['email'] ?? ''));
    $phone = preg_replace('/\D+/', '', (string)($input['telefone'] ?? $input['phone'] ?? ''));
    $reference = trim((string)($input['referencia'] ?? ''));
    $amount = filter_var($input['valor_centavos'] ?? $input['amount_cents'] ?? null, FILTER_VALIDATE_INT);
    $due = (string)($input['vencimento'] ?? $input['due_date'] ?? '');

    if ($name === '' || strlen($name) > 160) throw new InvalidArgumentException('Informe o nome do cliente (até 160 caracteres).');
    if (!valid_br_document($document)) throw new InvalidArgumentException('CPF/CNPJ inválido.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('E-mail inválido.');
    if ($amount === false || $amount < 100 || $amount > 100000000) throw new InvalidArgumentException('Valor inválido. Informe o valor em centavos (mínimo R$ 1,00).');
    if ($reference === '' || strlen($reference) > 100) throw new InvalidArgumentException('Referência obrigatória (até 100 caracteres).');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $due);
    if (!$date || $date->format('Y-m-d') !== $due) throw new InvalidArgumentException('Vencimento deve usar o formato AAAA-MM-DD.');

    $pdo = db();
    $find = $pdo->prepare('SELECT id FROM "Cobrancas SANDBOX" WHERE chave_pedido = :key LIMIT 1');
    $orderKey = $service['codigo'] . ':' . $reference;
    $find->execute(['key' => $orderKey]);
    if ($existing = $find->fetchColumn()) throw new DomainException('Esta referência já foi usada neste serviço. Consulte a cobrança existente antes de tentar novamente.');

    $customerQuery = $pdo->prepare('SELECT id, asaas_id FROM "Clientes MDI" WHERE regexp_replace(COALESCE(NULLIF(btrim(cpf), \'\'), cnpj, \'\'), \'[^0-9]\', \'\', \'g\') = :document LIMIT 1');
    $customerQuery->execute(['document' => $document]);
    $customer = $customerQuery->fetch() ?: null;

    $payload = [
        'projeto' => $service['codigo'],
        'chave_pedido' => $orderKey,
        'nome' => $name,
        'cpf_cnpj' => $document,
        'email' => $email,
        'valor_centavos' => $amount,
        'referencia' => $reference,
        'vencimento' => $due,
        'cliente_local_id' => $customer['id'] ?? null,
        'cliente_asaas_id' => $customer['asaas_id'] ?? null,
        'descricao' => trim((string)($input['descricao'] ?? 'Cobrança ' . $reference)),
    ];
    if ($phone !== '') $payload['telefone'] = $phone;
    return notify_payment_workflow($payload);
}
