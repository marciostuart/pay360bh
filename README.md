# Pay 360BH — painel da Central de Cobranças

Aplicação PHP para administrar serviços, clientes compartilhados e cobranças. O PostgreSQL dedicado do VPS é a fonte de verdade; o n8n cria cobranças no Asaas Sandbox e processa as notificações financeiras. O navegador não recebe credenciais de banco, n8n ou Asaas.

## Estado de implantação

- Repositório: `https://github.com/marciostuart/pay360bh.git` (branch `main`).
- O ponto de partida foi copiado do Pay legado sem `.env`, chaves, credenciais ou arquivos de dados; o legado continua intacto para rollback.
- Foi feito backup verificável do banco atual do NocoDB em `/root/backups/central-cobrancas/`.
- Cinco tabelas de negócio Sandbox foram copiadas para `central_cobrancas_sandbox`; os registros originais permanecem no banco `nocodb`.
- A migração é aditiva e cria campos para chaves individuais dos serviços e para o vínculo local cliente/projeto/cobrança.
- A stack do Pay, o NocoDB e os fluxos n8n ainda precisam ser ligados à nova base e homologados juntos. O DNS oficial permanece inalterado.

## Arquitetura

```text
Serviços autorizados ── X-Service-Key ──┐
                                        ▼
Operador ── HTTPS/Traefik ── Pay 360BH ─── PostgreSQL privado
                                   │              ▲
                                   │ token        │ estado financeiro
                                   ▼              │
                                  n8n ── Asaas Sandbox

NocoDB ── fonte PostgreSQL externa para administração
```

O schema `pvfbh8yqws2olzw` mantém os nomes físicos conhecidos pelos fluxos: `Projetos SANDBOX`, `Clientes MDI`, `Cobrancas SANDBOX`, `Eventos Asaas SANDBOX` e `Entregas SANDBOX`. A tabela de usuários do NocoDB e a tabela Sandbox antiga não foram copiadas.

## O painel

- `/login`: autenticação administrativa com `ADMIN_EMAIL` e hash Argon2id/Bcrypt em `ADMIN_PASSWORD_HASH`.
- `/painel?aba=servicos`: cadastro de serviço e emissão de chave aleatória exibida somente uma vez.
- `/painel?aba=clientes`: cadastro compartilhado pelo CPF/CNPJ normalizado.
- `/painel?aba=cobrancas`: cobrança manual, enviada ao webhook do n8n, e consulta das últimas cobranças.
- `POST /api/v1/cobrancas`: criação autenticada por serviço usando `X-Service-Key`.
- `GET /health`: healthcheck sem dados internos.

O identificador idempotente de pedido é `codigo-do-servico:referencia`. Repetições são recusadas e a restrição única no PostgreSQL continua sendo a proteção final contra concorrência. Pay não altera o status financeiro: somente o fluxo n8n/Asaas deve fazê-lo. Cancelamentos e estornos não fazem parte do MVP.

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

Para gerar um hash de senha local sem colocar a senha na linha de comando, use `php -r '$p = readline("Senha nova: "); echo password_hash($p, PASSWORD_DEFAULT), PHP_EOL;'` e configure o hash no Portainer. Não publique `.env`, senhas, tokens ou chaves individuais no GitHub.

## Portainer e Traefik

Crie uma stack Git do repositório usando `compose.portainer.yml`. O serviço se conecta à rede Docker externa `360Network`, compartilhada com Traefik. O Compose não publica portas e não expõe PostgreSQL.

Configure as variáveis obrigatórias no Portainer: `PAY_HOST` (host de homologação com DNS apontando para a VPS), `ADMIN_EMAIL`, `ADMIN_PASSWORD_HASH`, `CENTRAL_N8N_PAYMENT_WEBHOOK_URL` e `CENTRAL_N8N_TOKEN`. O Compose monta `/opt/360bh/secrets/pay360bh_db_password` como arquivo somente leitura dentro do container; esse arquivo é provisionado no VPS pelo script `ops/provision-pay-db-login.sh`. O resolver TLS esperado é `letsencryptresolver`, e o entrypoint é `websecure`, conforme a configuração do Traefik no VPS.

Não use `pay.360bh.com.br` no teste inicial se o domínio ainda servir o legado. Só altere o DNS depois de validar login, banco, cadastro, cobrança Sandbox, webhook e recuperação. Durante homologação, manter o serviço antigo e seu MySQL facilita o rollback.

## PostgreSQL, papéis e acesso

`database/migrations/001_central_sandbox_postgres.sql` adiciona colunas e índices sem apagar dados. Os papéis `central_pay_data`, `central_n8n_data` e `central_nocodb_data` são grupos `NOLOGIN` com permissões separadas. O script `ops/provision-pay-db-login.sh` cria um usuário `LOGIN` restrito para o Pay e guarda a senha em arquivo protegido no VPS. Crie credenciais distintas para n8n e NocoDB; não reutilize a credencial da aplicação NocoDB interna.

Conecte o NocoDB como fonte externa ao banco dedicado, com edição de schema desativada. O n8n deve usar seu usuário próprio e os nós de banco que leem/escrevem na nova base. Só remova ou arquive a antiga fonte após a homologação completa e backup.

## Rollback e operação

- Se o Pay novo falhar, pare apenas a stack nova e mantenha o DNS no destino legado.
- Não apague o banco original `nocodb`, o Pay legado ou o MySQL legado durante a homologação.
- Para recuperar a cópia inicial, use o dump customizado `/root/backups/central-cobrancas/business-tables-*.dump`; valide o SHA-256 antes de restaurar.
- Antes da operação comercial, definir retenção de dados e validar os requisitos legais aplicáveis.
