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
- Commit `a14c254` foi publicado em `main`. O Compose agora referencia `pay360bh-homolog:webhook-logs-a14c254`; a imagem ainda precisa ser construida em Portainer > Images > Build image usando `https://github.com/marciostuart/pay360bh.git`, nome/tag `pay360bh-homolog:webhook-logs-a14c254`, e entao a stack deve ser redeployada sem Pull images. Manter `service-row-aligned-v1` disponivel como rollback.
