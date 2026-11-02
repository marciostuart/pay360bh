# Preparação da Central de Cobranças para produção

Estado em 2026-11-02: preparação em andamento. O banco de produção foi criado; a stack e os fluxos de produção ainda não foram implantados ou validados de ponta a ponta.

## Acesso administrativo à VPS

- Nesta estação Windows, a chave privada utilizada pelo SSH está em `%USERPROFILE%\.ssh\vps_nova_187_41_108_26`; sua chave pública fica no arquivo de mesmo nome com extensão `.pub`.
- O host é `187.41.108.26`, usuário `root`, porta `22`. Usar a chave somente para a VPS autorizada e conferir a identidade do host. O conteúdo da chave privada não deve ser incluído no repositório ou na conversa.

## Arquivos preparados

- `compose.portainer.production.yml`: stack independente para Portainer/Traefik, com variáveis e arquivo de senha próprios.
- `Dockerfile.production`: imagem baseada na aplicação homologada, com nomes de tabelas e textos próprios de produção.
- `ops/prepare-production.php`: transforma a cópia da aplicação durante a construção da imagem. A construção falha se restarem referências de homologação nos arquivos processados.
- `ops/provision-production-db.sh`: procedimento executado no VPS para criar o banco novo a partir da **estrutura** das cinco tabelas de negócio. Não copia linhas, credenciais de serviços, a tabela de usuários legada nem outros dados do NocoDB.
- `n8n/producao/`: sete definições sem segredos para criação de Pix, consulta, ações administrativas, conciliação, recebimento Asaas e reprocessamento de eventos e entregas. As sete cópias foram criadas no n8n, vinculadas às credenciais de produção e conferidas como inativas; exigem testes de integração antes da publicação.

O procedimento de banco cria `central_cobrancas_prod`, o schema `central_cobrancas` e os logins restritos `pay360bh_prod_login` e `central_cobrancas_prod_n8n`. As senhas são geradas no VPS e guardadas em arquivos protegidos em `/opt/360bh/secrets`; não devem ser colocadas no Git ou na conversa. O script verifica se banco, logins ou arquivos já existem e interrompe em caso de conflito. Se qualquer etapa falhar após iniciar alterações, inspecionar o estado antes de repetir.

## Constatações da revisão

- O acesso SSH à VPS foi confirmado com a chave descrita acima. A credencial PostgreSQL de homologação no n8n não possui `CREATEDB` nem superusuário.
- O procedimento de banco foi executado no VPS após prévia somente de estrutura. As cinco tabelas de produção foram criadas com zero linhas; logins e senhas próprias foram criados.
- As duas contas de banco foram autenticadas, o Pay não possui permissão para alterar o status financeiro, o n8n possui essa permissão e não existe tabela de usuários legada no schema de produção. Uma consulta de leitura pelo n8n confirmou a conexão ao banco correto e as contagens zeradas.
- As credenciais `Central PostgreSQL Produção`, `Central Entrada Produção` e `Asaas Webhook Produção` foram criadas no projeto pessoal do n8n; os segredos foram gerados e mantidos em arquivos protegidos na VPS. O importador da versão instalada exige IDs explícitos; a primeira tentativa sem IDs foi revertida pelo próprio n8n, e a segunda importou as três credenciais.
- Ao executar a ferramenta administrativa do n8n, a instalação avisou que seu banco usa PostgreSQL 14, fora das versões atualmente suportadas pela ferramenta. Isso afeta a infraestrutura compartilhada e requer avaliação e planejamento de atualização antes da liberação comercial; nenhuma atualização foi feita nesta etapa.
- A credencial n8n `Asaas Produção — Central` foi criada pelo operador e testada por consulta de leitura à situação cadastral do Asaas de produção: situação geral aprovada. Nenhuma cobrança real foi criada nesse teste.
- As cinco tabelas de negócio existentes têm suas chaves primárias e as cobranças referenciam somente as tabelas locais de clientes e projetos; não foram encontrados gatilhos de usuário nessas tabelas. A inspeção foi somente de metadados.
- Os fluxos originais de homologação estão limitados ao projeto `teste` e ao receptor de teste. Nas cópias de produção, os destinos são lidos do cadastro do serviço; o token de notificação é individual e enviado no cabeçalho `X-Notificacao-Token`. O token é apresentado uma vez pelo Pay ao criar ou trocar a chave de serviço; a troca invalida ambos. Serviços sem URL ou desativados não recebem notificações. A entrega ponta a ponta ainda precisa ser validada.
- A derivação do token individual foi conferida entre PHP e PostgreSQL. Para proteger esse token, as cópias de recebimento e reenvio não persistem dados das execuções no n8n; o painel de webhooks continua usando os registros operacionais do PostgreSQL.
- A imagem `pay360bh-production:0b9d4a0` foi construída na VPS e a conexão da aplicação ao banco, às tabelas vazias e ao arquivo do token interno foi verificada em container temporário. A imagem deve ser reconstruída após a inclusão do token individual no painel.
- A criação inicial de um rascunho de produção no n8n associou automaticamente credenciais de outros serviços. O rascunho permaneceu inativo e foi arquivado; as cópias finais devem ter as credenciais de produção atribuídas e conferidas explicitamente antes da publicação.
- O validador apontou avisos de credencial genérica HTTP nas definições preliminares. Elas devem reutilizar as credenciais corretas já criadas, por ID, e não criar credenciais automáticas de tipo genérico.

## Pendências para implantação

1. Confirmar índices de unicidade e testar restauração de backup do banco de produção.
2. Manter as credenciais independentes de produção no n8n. Nunca registrar seus valores no repositório.
3. Testar as sete cópias inativas e confirmar as credenciais de produção antes da publicação.
4. Validar a entrega aos destinos cadastrados por serviço, inclusive autenticação, repetição e recuperação. As cópias usam URL e token individuais, sem destino fixo de teste.
5. Construir uma imagem de produção com `Dockerfile.production`, preencher as variáveis do Compose e testar a stack independente. Criar uma senha administrativa própria e forte para produção.
6. Configurar o webhook de produção no Asaas, verificar backup e restauração e realizar a troca do domínio com plano de retorno. Fazer cobrança real controlada e conferir evento, estado e entrega de ponta a ponta.

O banco de homologação, o banco original do NocoDB e o Pay legado continuam como fontes para investigação e recuperação; a tabela legada de usuários não integra a produção.
