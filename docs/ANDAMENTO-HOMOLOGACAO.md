# Andamento da Central de Cobranças — 2026-11-01

Este documento registra o ponto de parada desta sessão para retomarmos sem repetir etapas. É um retrato do ambiente Sandbox; não significa que a Central esteja pronta para produção.

## Decisões e escopo vigentes

- O painel Pay será desenvolvido neste repositório e implantado no VPS por uma Stack Git do Portainer, atrás do Traefik.
- O subdomínio de homologação `pay-homolog.360bh.com.br` resolve publicamente para `187.41.108.26`. O domínio oficial `pay.360bh.com.br` continua fora da migração e o Pay legado permanece disponível para rollback.
- A fonte de verdade planejada é o banco PostgreSQL dedicado `central_cobrancas_sandbox`; NocoDB será a interface de administração e o n8n será o processador de pagamentos/notificações Asaas.
- O gateway permanece em Asaas Sandbox. Cancelamentos e estornos estão fora do MVP atual.
- Não colocar senha, token, chave de API ou conteúdo de arquivo secreto no Git ou no chat.

## Feito e confirmado

### Banco e segurança

- Foi feito backup verificável do banco anterior do NocoDB em `/root/backups/central-cobrancas/`; a fonte original não foi apagada.
- As cinco tabelas de negócio Sandbox foram copiadas ao banco dedicado e comparadas com a origem. A conta interna do NocoDB e a tabela Sandbox antiga não foram copiadas.
- A migração PostgreSQL do Pay é aditiva. Há índices únicos no PostgreSQL para chave de pedido, identificador de evento e chave de entrega; a marca `UN` do NocoDB, sozinha, não garante unicidade.
- Os grupos de permissão de Pay, n8n e NocoDB são separados. O login restrito `pay360bh_login` foi provisionado sem permissão para alterar o status financeiro das cobranças.
- A senha do banco do Pay está guardada no VPS em `/opt/360bh/secrets/pay360bh_db_password`, com acesso restrito, e o Compose monta o arquivo somente leitura. Nenhuma senha foi adicionada ao repositório.

### Workflow n8n / Asaas Sandbox

- O fluxo Sandbox já criou cobranças Pix no Asaas, recebeu evento real `PAYMENT_RECEIVED` do Sandbox, atualizou o registro financeiro e marcou o evento como processado.
- A tabela de eventos registra a notificação antes do processamento, permitindo deduplicação e recuperação.
- Foram montados os percursos de conciliação de cobranças e eventos pendentes, com reserva por PostgreSQL antes do reprocessamento. O teste manual mais recente do percurso de recuperação executou com sucesso; os casos de falha e resposta perdida ainda precisam de uma validação ponta a ponta controlada.
- O percurso de entrega de notificações de teste foi executado com sucesso depois de corrigir o nome do cabeçalho HTTP. A reserva/claim evita que execuções concorrentes enviem a mesma entrega.
- A criação repetida de uma chave de pedido encontrou a restrição única PostgreSQL (`23505`), confirmando a proteção no banco. O tratamento amigável de repetição no fluxo e o retorno da cobrança já existente ainda precisam do teste final de ponta a ponta.
- O fluxo de produção anterior não foi alterado.

### Painel Pay e implantação

- O repositório é `https://github.com/marciostuart/pay360bh.git`, branch `main`. A última revisão local conferida é `2fcb700` (`Provision Pay database credential securely`).
- O painel contempla login administrativo, cadastro de serviços com chave individual exibida uma vez, cadastro compartilhado de clientes e emissão/consulta de cobranças manuais pelo n8n.
- `compose.portainer.yml` está configurado para construir a aplicação a partir do repositório, usar a rede externa `360Network` e publicar via Traefik/HTTPS. Não publica a porta do PostgreSQL.
- A sintaxe PHP e a configuração Compose tiveram validações anteriores. A Stack Pay ainda **não foi criada nem implantada**.
- Todos os repositórios GitHub foram tornados privados. Ainda não foi criado token GitHub nem credencial Git no Portainer. O procedimento combinado é usar um token fine-grained restrito ao repositório `pay360bh`, com `Contents: Read-only`, guardado como credencial Git no Portainer. Não enviar o token pelo chat.
- A tentativa de conexão MCP ao Portainer não foi configurada. Isso não bloqueia a criação manual da Stack.

## Próximos passos — nesta ordem

- [ ] Criar no GitHub um token fine-grained com acesso somente a `pay360bh` e conteúdo somente para leitura; guardá-lo em local seguro.
- [ ] No Portainer, criar/salvar uma credencial Git com esse usuário e token. Criar a Stack `pay360bh-homolog` a partir do repositório, branch `main`, arquivo `compose.portainer.yml`.
- [ ] Preencher as variáveis da Stack diretamente no Portainer: `PAY_HOST`, `ADMIN_EMAIL`, `ADMIN_PASSWORD_HASH_B64` (hash bcrypt codificado em Base64), `CENTRAL_N8N_PAYMENT_WEBHOOK_URL` e `CENTRAL_N8N_TOKEN`. Usar o host de homologação, nunca o domínio oficial nesta fase. Gerar o hash localmente; não usar a senha administrativa em texto puro.
- [ ] Para `CENTRAL_N8N_PAYMENT_WEBHOOK_URL`, usar o webhook de produção do workflow Sandbox: `https://automacao.360bh.com.br/webhook/central-cobrancas-sandbox-v1`. Configurar `CENTRAL_N8N_TOKEN` com o mesmo segredo esperado pela autenticação `x-central-token` do webhook; inserir o valor somente no Portainer.
- [ ] Conferir se o ambiente do Portainer é Docker Standalone ou Swarm antes de implantar, pois o Compose usa `build:`. Se for Swarm, preparar a imagem em registry compatível antes da Stack.
- [ ] Implantar e verificar container, logs, HTTPS/Traefik, `/health`, login e conexão ao banco; corrigir antes de conectar usuários.
- [ ] Criar credenciais PostgreSQL próprias para n8n e NocoDB e conectar ambos ao banco dedicado, mantendo o banco original intacto até concluir a homologação.
- [ ] Validar no Sandbox, de ponta a ponta: serviço autorizado e isolamento de chave; cliente compartilhado; cobrança manual/API; Pix no Asaas; webhook autenticado; atualização do mesmo registro; notificações; reenvio após falha; evento duplicado; chave de pedido repetida; expiração e recuperação após indisponibilidade/reinício.
- [ ] Exportar os workflows n8n em JSON, revisar e remover qualquer dado sensível antes de guardá-los no repositório para recuperação por Git.
- [ ] Só depois da homologação aprovada planejar a troca do domínio oficial e a transição gradual; manter Pay legado, banco original e rollback disponíveis até lá.

## Como retomar

Retomar pelo primeiro item pendente: criar o token fine-grained restrito ao repositório e cadastrar a credencial Git no Portainer. O token deve ser colado apenas no campo de credencial do Portainer, nunca nesta conversa.
