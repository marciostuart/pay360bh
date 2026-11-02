<?php
declare(strict_types=1);
$baseUrl = rtrim((string) envv('APP_URL', 'https://pay.360bh.com.br'), '/');
$minimum = envv('APP_ENV', '') === 'production' ? 'R$ 5,00' : 'R$ 1,00';
$csrf = csrf_token();
?><!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex, nofollow, noarchive"><title>Manual de integração | Pay 360BH</title><link rel="stylesheet" href="/assets/panel.css"><link rel="stylesheet" href="/assets/documentacao.css"></head>
<body><div class="shell"><aside class="sidebar"><div class="brand">360BH <span>Central de Cobranças</span></div><nav><a href="/painel?aba=resumo">Visão geral</a><a href="/painel?aba=servicos">Serviços</a><a href="/painel?aba=clientes">Clientes</a><a href="/painel?aba=cobrancas">Cobranças</a><a href="/painel?aba=webhooks">Webhooks</a><a class="active" href="/documentacao">Manual de integração</a></nav><form method="post" action="/logout" class="logout-form"><input type="hidden" name="_csrf" value="<?=esc($csrf)?>"><button class="logout">Sair</button></form></aside>
<main class="main docs-main"><header><div><p class="eyebrow">CENTRAL DE COBRANÇAS</p><h1>Manual de integração</h1><p>Conecte seus serviços ao Pay 360BH e acompanhe pagamentos Pix.</p></div><span class="badge">API v1</span></header>
<div class="docs-layout"><aside class="docs-nav" aria-label="Navegação do manual"><label for="api-version">Versão da API</label><select id="api-version" aria-label="Versão da API"><option value="v1">API v1 · atual</option></select><p class="docs-nav-title">COMECE POR AQUI</p><a href="#visao-geral">Visão geral</a><a href="#primeiros-passos">Primeiros passos</a><a href="#autenticacao">Autenticação</a><p class="docs-nav-title">REFERÊNCIA DA API</p><a href="#criar-cobranca"><span class="method">POST</span> Criar cobrança</a><a href="#respostas">Respostas e erros</a><a href="#duplicidade">Referência e repetição</a><p class="docs-nav-title">EVENTOS</p><a href="#webhooks">Receber notificações</a><a href="#seguranca">Segurança e testes</a><a href="#limitacoes">Limitações atuais</a></aside>
<div class="docs-content">
<section id="visao-geral" class="doc-card"><div class="doc-kicker">INTEGRAÇÃO · V1</div><h2>Comece a integrar</h2><p>A Central cria cobranças Pix no Asaas e encaminha atualizações ao serviço cadastrado. O Pay é o endereço de entrada da API; o n8n e as credenciais do Asaas ficam internos.</p><div class="endpoint"><span>URL BASE</span><code><?=esc($baseUrl)?></code></div><p>Use este endereço para o ambiente em que o manual está aberto. Dados e credenciais dos ambientes são independentes.</p></section>
<section id="primeiros-passos" class="doc-card"><h2>Primeiros passos</h2><ol><li>No painel, acesse <a href="/painel?aba=servicos">Serviços</a> e cadastre um serviço com código e nome próprios.</li><li>Para receber avisos, informe a URL HTTPS do seu receptor. Ela pode ser configurada depois em <strong>Trocar URL</strong>.</li><li>Copie e guarde a chave do serviço e, em produção, o token de notificação exibidos após o cadastro. Eles aparecem somente nessa ocasião.</li><li>Implemente a chamada <a href="#criar-cobranca">Criar cobrança</a> no servidor do seu serviço e o <a href="#webhooks">receptor de notificações</a>.</li><li>Teste primeiro em homologação e valide uma cobrança real controlada antes do uso comercial.</li></ol><div class="doc-note">Trocar a chave invalida a chave anterior; em produção, atualize também o token de notificação no receptor.</div></section>
<section id="autenticacao" class="doc-card"><h2>Autenticação</h2><p>Envie a chave individual do serviço em toda chamada à API, no cabeçalho <code>X-Service-Key</code>. A chave identifica o serviço; não é necessário enviar seu código no corpo.</p><pre><code>X-Service-Key: SUA_CHAVE_DO_SERVICO
Content-Type: application/json</code></pre><p>Guarde a chave no servidor ou cofre de segredos. Não a inclua em aplicativos distribuídos, páginas web, repositórios ou capturas de tela.</p></section>
<section id="criar-cobranca" class="doc-card"><div class="doc-kicker">COBRANÇAS</div><h2>Criar cobrança Pix</h2><div class="endpoint"><span class="method">POST</span><code><?=esc($baseUrl)?>/api/v1/cobrancas</code></div><p>Envia os dados do cliente e do pedido. A Central procura o cliente pelo CPF/CNPJ e o vincula ao Asaas quando necessário.</p><div class="table-wrap"><table class="doc-table"><thead><tr><th>Campo</th><th>Tipo</th><th>Descrição</th></tr></thead><tbody><tr><td><code>nome</code></td><td>texto</td><td>Obrigatório. Nome do cliente, até 160 caracteres.</td></tr><tr><td><code>cpf_cnpj</code></td><td>texto</td><td>Obrigatório. CPF ou CNPJ válido, com ou sem pontuação.</td></tr><tr><td><code>email</code></td><td>texto</td><td>Obrigatório. E-mail válido.</td></tr><tr><td><code>telefone</code></td><td>texto</td><td>Opcional. Informe DDD e número válidos se enviar.</td></tr><tr><td><code>valor_centavos</code></td><td>inteiro</td><td>Obrigatório. Valor em centavos; mínimo neste ambiente: <?=esc($minimum)?>.</td></tr><tr><td><code>referencia</code></td><td>texto</td><td>Obrigatória e única por serviço. Use até 80 letras, números, hífen ou sublinhado.</td></tr><tr><td><code>vencimento</code></td><td>texto</td><td>Obrigatório. Data no formato AAAA-MM-DD.</td></tr><tr><td><code>descricao</code></td><td>texto</td><td>Opcional. O fluxo atual gera a descrição no Asaas a partir da referência.</td></tr></tbody></table></div>
<h3>Exemplo de requisição</h3><pre><code>curl --request POST '<?=esc($baseUrl)?>/api/v1/cobrancas' \
  --header 'X-Service-Key: SUA_CHAVE_DO_SERVICO' \
  --header 'Content-Type: application/json' \
  --data '{
    "nome": "Cliente de exemplo",
    "cpf_cnpj": "52998224725",
    "email": "cliente@example.com",
    "telefone": "31999999999",
    "valor_centavos": 1000,
    "referencia": "pedido-001",
    "vencimento": "2026-12-01"
  }'</code></pre><p>O exemplo usa dados fictícios. A referência deve ser nova para cada pedido e a data deve ser válida para a cobrança.</p></section>
<section id="respostas" class="doc-card"><h2>Respostas e erros</h2><p>Na criação, o Pay retorna HTTP <strong>201</strong>, com o resultado do processamento dentro de <code>resultado</code>. Uma resposta típica contém:</p><pre><code>{
  "ok": true,
  "projeto": "meu_servico",
  "resultado": {
    "payment_id": "pay_exemplo123",
    "referencia": "pedido-001",
    "status": "PENDING",
    "pix_copia_cola": "000201...",
    "qr_code_base64": "iVBORw0KGgo...",
    "expira_em": "2026-12-02T00:00:00Z"
  }
}</code></pre><p><code>pix_copia_cola</code> é o texto Pix; <code>qr_code_base64</code> contém a imagem em Base64. Não considere a cobrança paga apenas por receber o código.</p><div class="table-wrap"><table class="doc-table"><thead><tr><th>HTTP</th><th>O que significa</th></tr></thead><tbody><tr><td>401</td><td>Chave ausente, inválida ou serviço inativo.</td></tr><tr><td>409</td><td>Referência já utilizada pelo serviço.</td></tr><tr><td>422</td><td>Dados inválidos; confira o campo informado.</td></tr><tr><td>502</td><td>Falha ao processar a solicitação. Consulte a cobrança no painel antes de repetir.</td></tr></tbody></table></div></section>
<section id="duplicidade" class="doc-card"><h2>Referência e repetição</h2><p>A identidade do pedido combina o código do serviço e a referência. Na API pública do Pay, uma referência já registrada retorna <strong>409</strong>; ela não devolve automaticamente o Pix anterior.</p><div class="doc-note">Se houver timeout ou erro depois do envio, consulte o painel antes de criar outro pedido. Uma falha de comunicação pode ocorrer depois que o Asaas criou a cobrança.</div><p>Não existe, nesta versão, um endpoint público de consulta por referência. Use o painel para verificar o estado enquanto essa funcionalidade não estiver disponível.</p></section>
<section id="webhooks" class="doc-card"><div class="doc-kicker">NOTIFICAÇÕES</div><h2>Receber atualizações</h2><p>Cadastre uma URL HTTPS no serviço. A Central enviará um <strong>POST JSON</strong> para esse endereço quando houver uma atualização processada e um destino ativo.</p><pre><code>POST /seu-webhook HTTP/1.1
Content-Type: application/json
X-Notificacao-Token: SEU_TOKEN_DE_NOTIFICACAO

{
  "evento_id": "evt_exemplo123",
  "projeto": "meu_servico",
  "referencia": "pedido-001",
  "cobranca_id": "pay_exemplo123",
  "status": "RECEIVED"
}</code></pre><p>Compare o cabeçalho com o token do serviço de forma segura. Responda com HTTP 2xx depois de registrar o evento. A entrega pode ser repetida: use <code>evento_id</code> para evitar processar o mesmo evento duas vezes.</p><p><code>PENDING</code> indica aguardando pagamento; <code>RECEIVED</code> indica pagamento recebido. O evento traz o estado financeiro consultado no Asaas, não uma instrução para executar a cobrança novamente. Sem URL configurada ou com serviço inativo, nenhuma entrega é criada.</p><p>Consulte <a href="/painel?aba=webhooks">Webhooks</a> no painel para acompanhar eventos e situação das entregas. A recuperação tenta novamente entregas pendentes; seu receptor deve tolerar repetições.</p></section>
<section id="seguranca" class="doc-card"><h2>Segurança e testes</h2><ul><li>Use HTTPS no destino e valide <code>X-Notificacao-Token</code> antes de aceitar o corpo.</li><li>Separe credenciais e clientes de produção e homologação. Nunca envie a chave interna do n8n ou a chave da API Asaas ao seu serviço.</li><li>Confira serviço, referência, cobrança e valor esperado antes de liberar produtos ou acesso.</li><li>Teste chave incorreta, evento repetido, receptor indisponível, recuperação e cobrança paga.</li></ul></section>
<section id="limitacoes" class="doc-card"><h2>Limitações atuais</h2><ul><li>A versão atual atende cobrança Pix. Boleto está previsto para uma etapa futura.</li><li>Não há endpoint público para consulta de cobranças, cadastro de clientes, baixa ou cancelamento. Essas operações disponíveis no painel não devem ser presumidas como API de serviços.</li><li>Estornos feitos no Asaas ainda não são sincronizados de forma confiável com a Central ou notificados ao serviço.</li><li>O corpo do webhook não inclui valor; o serviço deve correlacionar o evento ao pedido que registrou.</li></ul></section>
<footer class="docs-footer">Pay 360BH · Documentação privada · API v1</footer>
</div></div></main></div></body></html>
