# Andamento da Central de Cobranças — 2026-11-02

Este documento registra o ponto de parada desta sessão para retomarmos sem repetir etapas. É um retrato do ambiente Sandbox; não significa que a Central esteja pronta para produção.

## Decisões e escopo vigentes

- O painel Pay é desenvolvido neste repositório e implantado no VPS por uma Stack Git do Portainer, atrás do Traefik.
- O subdomínio de homologação `pay-homolog.360bh.com.br` resolve publicamente para `187.41.108.26`. O domínio oficial `pay.360bh.com.br` continua fora da migração e o Pay legado permanece disponível para rollback.
- A fonte de verdade é o banco PostgreSQL dedicado `central_cobrancas_sandbox`; o Pay é a interface de administração e o n8n processa pagamentos/notificações Asaas. NocoDB não é dependência operacional.
- O gateway permanece em Asaas Sandbox. O painel permite cancelar cobrancas pendentes e registrar recebimento externo integral; estornos ficam fora do escopo.
- Não colocar senha, token, chave de API ou conteúdo de arquivo secreto no Git ou no chat.

## Feito e confirmado

### Banco e segurança

- Foi feito backup verificável do banco anterior do NocoDB em `/root/backups/central-cobrancas/`; a fonte original não foi apagada.
- As tabelas de negócio da Central foram consolidadas no banco dedicado. Os registros originais continuam no banco `nocodb`; dados internos do NocoDB e de outros projetos não foram migrados.
- Os dois registros da antiga tabela `Central de Cobrança SANDBOX` foram reconciliados com `Clientes MDI` por documento, preservando os cadastros existentes. A tabela `usuarios` foi arquivada como `public.usuarios_legado_nocodb`, sem permissão de leitura para Pay/n8n.
- A cobrança Sandbox `teste-paga-veiaca` foi migrada e vinculada ao cliente local e ao projeto `teste`; o cliente local agora contém o ID de cliente Asaas. O status financeiro foi preservado.
- A conta PostgreSQL `central_cobrancas_sandbox` recebeu acesso operacional restrito ao schema da Central; não tem acesso ao arquivo legado de usuários.
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
- Os 15 nós NocoDB do workflow Sandbox foram substituídos por consultas PostgreSQL com os mesmos nomes e conexões. A nova versão foi publicada em 2026-11-02; o workflow ativo continua no Sandbox.
- Backups pré-migração foram verificados por SHA-256 e formato `pg_restore`: `/root/backups/central-cobrancas/nocodb-before-central-migration-20261102T164013Z.dump` e `/root/backups/central-cobrancas/central-before-nocodb-sync-20261102T164013Z.dump`. O banco `nocodb` não foi alterado.

### Painel Pay e implantação

- O repositório é `https://github.com/marciostuart/pay360bh.git`, branch `main`. A última revisão local conferida é `2fcb700` (`Provision Pay database credential securely`).
- O painel contempla login administrativo, cadastro de serviços com chave individual exibida uma vez, cadastro compartilhado de clientes e emissão/consulta de cobranças manuais pelo n8n.
- `compose.portainer.yml` está implantado na Stack `pay360bh-homolog`, usando a rede externa `360Network` e Traefik/HTTPS. A aplicação está acessível em `pay-homolog.360bh.com.br`; login foi validado.
- O repositório `https://github.com/marciostuart/pay360bh` está público no momento e conectado à Stack Git do Portainer. O serviço está rodando; a porta do PostgreSQL não é publicada.

## Próximos passos — nesta ordem

- [x] `CENTRAL_N8N_PAYMENT_WEBHOOK_URL` e `CENTRAL_N8N_TOKEN` estão configurados no ambiente Sandbox; os valores secretos permanecem no Portainer.
- [x] A stack foi implantada no Portainer e está acessível via Traefik/HTTPS; o serviço não publica a porta do PostgreSQL.
- [ ] Validar a nova versão publicada do workflow no Sandbox com uma chave de pedido nova e confirmar o registro no Pay/PostgreSQL, o Pix no Asaas e o retorno ao serviço.
- [ ] Validar no Pay o cadastro/edição/desativação de clientes e serviços e a listagem de cobranças, inclusive o registro migrado `teste-paga-veiaca`.
- [ ] Confirmar que webhook Asaas, conciliação, entrega/notificação e recuperação pendente funcionam após a troca dos nós NocoDB por PostgreSQL.
- [ ] Validar no Sandbox, de ponta a ponta: serviço autorizado e isolamento de chave; cliente compartilhado; cobrança manual/API; Pix no Asaas; webhook autenticado; atualização do mesmo registro; notificações; reenvio após falha; evento duplicado; chave de pedido repetida; expiração e recuperação após indisponibilidade/reinício.
- [ ] Exportar os workflows n8n em JSON, revisar e remover qualquer dado sensível antes de guardá-los no repositório para recuperação por Git.
- [ ] Só depois da homologação aprovada planejar a troca do domínio oficial e a transição gradual; manter Pay legado, banco original e rollback disponíveis até lá.

## Como retomar

Retomar pela validação ponta a ponta da versão publicada do workflow e do painel com registros no PostgreSQL dedicado. O banco `nocodb` e os backups permanecem disponíveis para rollback durante a homologação.

## Atualização de interface e horários dos webhooks (2026-11-02)

- A tela de logs agora tem duas abas mutuamente exclusivas: eventos recebidos do Asaas e notificações enviadas aos serviços. Os filtros atualizam somente a lista da aba selecionada.
- Detalhes de cobranças que não estão pendentes ocultam QR Code e Pix copia e cola e mostram informações complementares.
- O workflow Sandbox grava explicitamente `created_at` ao registrar eventos Asaas e entregas aos serviços. A versão ativa foi publicada no n8n; o ajuste vale para novos registros, sem preencher horários históricos ausentes.
- Commit 751eb0f atualiza o Pay e o Compose aponta para pay360bh-homolog:paylogs-webhook-751eb0f. Construir essa imagem no Portainer e redeployar sem Pull images; a tag anterior continua disponível para rollback.

## Validacao autonoma da homologacao — 2026-11-02

### Verificacoes concluidas nesta rodada

- [x] Pay Sandbox responde em `/health` (HTTP 200) e a pagina de login responde (HTTP 200).
- [x] Sintaxe PHP aprovada em `bootstrap/app.php`, `public_html/index.php` e `public_html/webhooks-panel.php`; `git diff --check` sem erros.
- [x] A cobranca Sandbox de referencia `agora-foi` foi criada, confirmada no Asaas e atualizada para `RECEIVED` no mesmo registro PostgreSQL.
- [x] O evento Asaas foi registrado/processado e a entrega ao servico de teste foi concluida com sucesso.
- [x] Os fluxos separados de criacao Pix, recebimento Asaas, consulta de status, conciliacao, reprocessamento de eventos, reprocessamento de entregas, acoes administrativas e receptor de teste estao ativos.
- [x] O fluxo combinado legado `Central de Cobrancas - Sandbox - Criacao Pix (prototipo)` foi arquivado. Os agendamentos dos fluxos separados continuam executando com sucesso depois do arquivamento.
- [x] A amostra `.env.example` aponta para o endpoint Sandbox v2; nao contem credenciais reais.

### Limites e validacoes ainda pendentes

- [ ] Validacao visual no navegador autenticado do Pay: confirmar que o QR Pix e exibido numa cobranca pendente e escondido apos pagamento. A exibicao do horario de criacao ja foi confirmada pelo operador.
- [ ] Revisao funcional pelo operador das telas de cadastro/edicao de clientes e servicos e das acoes manuais de cobranca.
- [x] Repeticao controlada confirmada em 2026-11-02: duas chamadas identicas para a mesma chave `teste:idempotencia-20261102-175429` retornaram o mesmo ID Asaas; a segunda foi reconhecida como `pedido_existente`. A cobranca Sandbox permaneceu `PENDING`.
- [ ] Antes de producao: provisionar configuracao isolada e credenciais de producao do Asaas, registrar o webhook de producao, definir dominio/stack/segredos de producao e validar backup, restauracao e rollback.
- [ ] Fazer uma cobranca de baixo valor em producao somente apos aprovacao operacional, confirmar webhook e entrega ao servico, e observar reconciliacao e recuperacao.

O Docker CLI nao estava disponivel na maquina de trabalho nesta rodada; portanto nao foi feita validacao local de `docker compose config`. O endpoint do Pay e o fluxo em execucao foram verificados diretamente. Nenhuma alteracao foi feita no dominio oficial ou nas credenciais de producao.

## Fuso horário e data de criação das cobranças (2026-11-02)

- O Pay interpreta timestamps PostgreSQL sem fuso como UTC e converte a exibição para `America/Sao_Paulo` (Brasília, UTC-3). Datas sem horário permanecem como datas de calendário.
- As telas de detalhes de cobrança e logs de webhooks usam a mesma conversão local; timestamps com offset explícito mantêm seu instante correto.
- O detalhe da cobrança usa `PAYMENT_CREATED` como alternativa para recuperar a data de criação quando ela não estiver gravada diretamente na linha da cobrança.
- O workflow Sandbox `dvocpj2y628A80Ks` foi atualizado e publicado para gravar UTC em `CreatedAt` ao inserir novas cobranças. A versão ativa é `1c1b72f3-1e9b-431d-a782-9e3b82beaa0b`.
- A tag da imagem Pay foi atualizada para `pay360bh-homolog:brasil-timestamps-v1`. Construir essa tag no Portainer usando o repositório público e redeployar a stack sem selecionar “Pull images”. A tag anterior `paylogs-webhook-751eb0f` permanece disponível para rollback.
- Não preencher timestamps antigos ausentes com horários estimados.


## Atualizacao: acoes do painel (2026-11-02)

- Corrigido o roteamento da pagina `/cobrancas/{id}`; antes a selecao da aba geral podia interceptar o detalhe e deixar a area principal vazia.
- Apos criar cobranca, o Pay associa URL da fatura e dados Pix retornados pelo n8n/PostgreSQL, quando disponiveis, e redireciona para os detalhes. Se o webhook ainda nao gravou a linha, informa para nao reenviar cegamente.
- A tabela de cobrancas tem acao de visualizar e, para cobrancas pendentes/vencidas, acoes de baixa integral por recebimento externo e cancelamento com confirmacao explicita.
- Criado e publicado no n8n o workflow Sandbox `Central de Cobrancas - Acoes Administrativas Sandbox` (`1LdlHNdJ7xvUnZTH`), com webhook `POST https://hook.360bh.com.br/webhook/central-cobrancas-admin-sandbox-v1`, autenticacao `x-central-token`, autorizacao por ID/status/valor no PostgreSQL e chamadas ao Asaas. O fluxo financeiro atual e o workflow legado nao foram alterados.
- O recebimento externo usa o endpoint Asaas `receiveInCash` com o valor integral lido do PostgreSQL; cancelamento usa exclusao da cobranca no Asaas. Ambos dependem do webhook financeiro para refletir o estado no banco. Nao registrar baixa se o valor integral nao foi realmente recebido. Se houver negativacao em andamento, o Asaas informa que pode haver tarifa de ativacao do servico.
- Codigo atualizado localmente no Pay; deployment Portainer e validacao ponta a ponta ainda pendentes. Configure no Portainer `CENTRAL_N8N_ADMIN_ACTION_WEBHOOK_URL=https://hook.360bh.com.br/webhook/central-cobrancas-admin-sandbox-v1` antes do proximo redeploy. O token usa a variavel existente `CENTRAL_N8N_TOKEN`; nao incluir segredos no Git.
- Proximas validacoes: gerar uma cobranca Sandbox e confirmar detalhes sem tela branca; validar QR/copia-e-cola; numa cobranca de teste, confirmar recebimento integral externo e ver `PAYMENT_RECEIVED` no PostgreSQL; em outra cobranca pendente, cancelar e confirmar `PAYMENT_DELETED`; verificar repeticao e status terminal. Nenhuma chamada financeira foi feita ao publicar o fluxo.


- Para publicar a versao com acoes sem sobrescrever a imagem atual, o Compose aponta para a tag nova `pay360bh-homolog:admin-actions-v1`. Construir essa imagem pelo menu Images do Portainer usando o repositorio publico e depois atualizar a Stack sem Pull images. A tag antiga `clientes-servicos-v1` permanece disponivel para rollback.

## Ajustes em andamento — 2026-11-02

- Criada a aba Sandbox `Logs de webhooks` no Pay, com filtros por servico, referencia/IDs, estado de processamento/entrega e periodo; mostra eventos recebidos do Asaas e entregas registradas para os servicos.
- A tabela de cobrancas recebeu larguras de coluna estaveis, acoes em uma linha e alinhamento vertical central, seguindo o padrao aplicado na tabela de servicos.
- Limitacao registrada: o schema atual de `Entregas SANDBOX` guarda situacao e claim, mas nao guarda codigo HTTP nem resposta de erro do destino. A tela informa essa limitacao; capturar esses dados e uma melhoria posterior do fluxo de entrega.
- Proximo ajuste visual enfileirado: nos detalhes de cobrancas com status diferente de pendente, ocultar QR Code e Pix copia e cola e exibir datas e demais informacoes complementares disponiveis.
- Commit `a14c254` foi publicado em `main`. O Compose agora referencia `pay360bh-homolog:paylogs-webhook-751eb0f`; a imagem ainda precisa ser construida em Portainer > Images > Build image usando `https://github.com/marciostuart/pay360bh.git`, nome/tag `pay360bh-homolog:paylogs-webhook-751eb0f`, e entao a stack deve ser redeployada sem Pull images. Manter `service-row-aligned-v1` disponivel como rollback.
## Atualização de interface e horários dos webhooks (2026-11-02)

- A tela de logs agora tem duas abas mutuamente exclusivas: eventos recebidos do Asaas e notificações enviadas aos serviços. Os filtros atualizam somente a lista da aba selecionada.
- Detalhes de cobranças que não estão pendentes ocultam QR Code e Pix copia e cola e mostram informações complementares.
- O workflow Sandbox grava explicitamente created_at ao registrar eventos Asaas e entregas aos serviços. A versão ativa foi publicada no n8n; o ajuste vale para novos registros, sem preencher horários históricos ausentes.
- Commit 751eb0f atualiza o Pay e o Compose aponta para pay360bh-homolog:paylogs-webhook-751eb0f. Construir essa imagem no Portainer e redeployar sem Pull images; a tag anterior continua disponível para rollback.
