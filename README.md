# Pay 360BH — painel da Central de Cobranças

Aplicação PHP para administrar serviços, clientes compartilhados e cobranças. O PostgreSQL dedicado do VPS é a fonte de verdade; o n8n cria cobranças no Asaas Sandbox e processa as notificações financeiras. O navegador não recebe credenciais de banco, n8n ou Asaas.

## Estado de implantação

- Repositório: `https://github.com/marciostuart/pay360bh.git` (branch `main`).
- O ponto de partida foi copiado do Pay legado sem `.env`, chaves, credenciais ou arquivos de dados; o legado continua intacto para rollback.
- Foi feito backup verificável dos bancos de origem e destino em `/root/backups/central-cobrancas/`.
- Os dados da Central foram consolidados no PostgreSQL dedicado `central_cobrancas_sandbox`; o banco original `nocodb` foi preservado para rollback.
- A migração é aditiva e cria campos para chaves individuais dos serviços e para o vínculo local cliente/projeto/cobrança.
- O Pay e os nós de dados do workflow Sandbox usam o PostgreSQL dedicado. O workflow não contém mais nós NocoDB; o DNS oficial e o banco original permanecem inalterados.

O registro de continuidade e o checklist de homologação estão em [`docs/ANDAMENTO-HOMOLOGACAO.md`](docs/ANDAMENTO-HOMOLOGACAO.md).

A preparação isolada da produção e suas pendências estão em [`docs/PREPARACAO-PRODUCAO.md`](docs/PREPARACAO-PRODUCAO.md).

## Arquitetura

```text
Serviços autorizados ── X-Service-Key ──┐
                                        ▼
Operador ── HTTPS/Traefik ── Pay 360BH ─── PostgreSQL privado
                                   │              ▲
                                   │ token        │ estado financeiro
                                   ▼              │
                                  n8n ── Asaas Sandbox

NocoDB legado ── preservado para rollback, fora do fluxo operacional da Central
```

O schema `pvfbh8yqws2olzw` mantém os nomes físicos usados pelo painel e pelo n8n: `Projetos SANDBOX`, `Clientes MDI`, `Cobrancas SANDBOX`, `Eventos Asaas SANDBOX` e `Entregas SANDBOX`. Os dois clientes da tabela antiga da Central foram reconciliados com `Clientes MDI`. Os hashes de senha da tabela legada `usuarios` foram arquivados em `public.usuarios_legado_nocodb`, sem acesso para Pay ou n8n. Tabelas internas e dados de outros projetos do NocoDB não foram migrados.

## O painel

- `/login`: autenticação administrativa com `ADMIN_EMAIL` e hash Argon2id/Bcrypt em `ADMIN_PASSWORD_HASH_B64`.
- `/painel?aba=servicos`: cadastro de serviço e emissão de chave aleatória exibida somente uma vez.
- `/painel?aba=clientes`: cadastro compartilhado pelo CPF/CNPJ normalizado.
- `/painel?aba=cobrancas`: cobrança manual, enviada ao webhook do n8n, e consulta das últimas cobranças.
- `POST /api/v1/cobrancas`: criação autenticada por serviço usando `X-Service-Key`.
- `GET /health`: healthcheck sem dados internos.

O identificador idempotente de pedido e `codigo-do-servico:referencia`. Repeticoes sao recusadas e a restricao unica no PostgreSQL protege contra concorrencia. Pay nao altera status financeiro diretamente: o fluxo n8n/Asaas confirma pagamentos. O Sandbox inclui cancelamento de cobranca pendente e registro de recebimento externo integral; estornos permanecem fora do escopo.

### Payload da API de serviços

```http
POST /api/v1/cobrancas
X-Service-Key: <chave emitida no painel>
Content-Type: application/json
```

```json
{
  "nome": "Cliente de exemplo",
  "cpf_cnpj": "52998224725",
  "email": "cliente@example.com",
  "telefone": "31999999999",
  "valor_centavos": 1000,
  "referencia": "pedido-001",
  "vencimento": "2026-12-01",
  "descricao": "Plano de internet"
}
```

O Pay acrescenta o código do serviço, chave do pedido e dados locais conhecidos e envia a requisição com `x-central-token` ao webhook configurado do n8n. Não envie tokens privados do Asaas ao Pay.

## Rodar localmente

Requisitos: PHP 8.3+, extensões `pdo_pgsql` e `curl`, PostgreSQL 14+.

1. Copie `.env.example` para `.env` fora da pasta pública e preencha valores apenas no seu ambiente local.
2. Aplique `database/migrations/001_central_sandbox_postgres.sql` somente no banco dedicado Sandbox, depois da cópia das tabelas de negócio.
3. Sirva `public_html` como document root do PHP/Apache.

Gere o hash PHP localmente, codifique-o em Base64 e configure `ADMIN_PASSWORD_HASH_B64` no Portainer. Essa codificação evita que os caracteres `$` do bcrypt sejam alterados pelo Compose. Não publique `.env`, senhas, tokens ou chaves individuais no GitHub.

## Portainer e Traefik

Crie uma stack Git do repositório usando `compose.portainer.yml`. O serviço se conecta à rede Docker externa `360Network`, compartilhada com Traefik. O Compose não publica portas e não expõe PostgreSQL.

Configure as variaveis obrigatorias no Portainer: `PAY_HOST`, `ADMIN_EMAIL`, `ADMIN_PASSWORD_HASH_B64`, `CENTRAL_N8N_PAYMENT_WEBHOOK_URL`, `CENTRAL_N8N_ADMIN_ACTION_WEBHOOK_URL` e `CENTRAL_N8N_TOKEN`. Use a URL administrativa Sandbox `https://hook.360bh.com.br/webhook/central-cobrancas-admin-sandbox-v1`. Reutilize o token interno ja configurado na credencial `x-central-token` do n8n; nunca coloque o segredo no Git. O Compose monta `/opt/360bh/secrets/pay360bh_db_password` como arquivo somente leitura dentro do container. O resolver Traefik e `letsencryptresolver` e o entrypoint e `websecure`.

Não use `pay.360bh.com.br` no teste inicial se o domínio ainda servir o legado. Só altere o DNS depois de validar login, banco, cadastro, cobrança Sandbox, webhook e recuperação. Durante homologação, manter o serviço antigo e seu MySQL facilita o rollback.

## PostgreSQL, papéis e acesso

`database/migrations/001_central_sandbox_postgres.sql` adiciona colunas e índices sem apagar dados. Os papéis `central_pay_data`, `central_n8n_data` e `central_nocodb_data` são grupos `NOLOGIN` com permissões separadas. O script `ops/provision-pay-db-login.sh` cria um usuário `LOGIN` restrito para o Pay e guarda a senha em arquivo protegido no VPS. O n8n usa a credencial `Central Cobranças Sandbox`, com acesso somente às tabelas operacionais; a conta não pode ler `usuarios_legado_nocodb`. O NocoDB não acessa a base dedicada da Central.

O workflow Sandbox usa a credencial PostgreSQL `Central Cobranças Sandbox` e os nós de dados consultam/gravam diretamente na base dedicada. A conta `central_cobrancas_sandbox` recebeu somente uso do schema e leitura/escrita operacional nas tabelas de trabalho, leitura de projetos e uso das sequences; não tem acesso ao arquivo de usuários legado. O NocoDB original continua preservado, mas não é dependência operacional da Central.

## Rollback e operação

- Se o Pay novo falhar, pare apenas a stack nova e mantenha o DNS no destino legado.
- Não apague o banco original `nocodb`, o Pay legado ou o MySQL legado durante a homologação.
- Para recuperar a cópia inicial, use o dump customizado `/root/backups/central-cobrancas/business-tables-*.dump`; valide o SHA-256 antes de restaurar.
- Antes da operação comercial, definir retenção de dados e validar os requisitos legais aplicáveis.


### Acoes administrativas de cobrancas (Sandbox)

A lista permite visualizar cada cobranca e, enquanto estiver pendente/vencida, solicitar ao Asaas o registro de recebimento integral por outro meio ou o cancelamento. O Pay valida a cobranca no PostgreSQL e chama o webhook administrativo autenticado do n8n; o n8n confere novamente o ID/status no PostgreSQL antes de chamar o Asaas. O Pay nao altera o status financeiro. O webhook Asaas continua responsavel por atualizar o estado oficial. O Asaas informa que, se houver negativacao em andamento, registrar recebimento externo pode gerar tarifa de ativacao do servico; confira isso antes de usar a baixa.

A tela `/cobrancas/{id}` exibe detalhes e dados Pix disponiveis na resposta do n8n. Configure a URL administrativa Sandbox no Portainer antes de usar as acoes. Nenhuma acao financeira foi executada ao publicar esta funcionalidade.
