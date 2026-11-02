# Preparação da Central de Cobranças para produção

Estado em 2026-11-02: stack e fluxos de produção implantados; ainda falta validar uma cobrança real de ponta a ponta antes do uso comercial.

## Acesso administrativo à VPS

- Nesta estação Windows, a chave privada utilizada pelo SSH está em `%USERPROFILE%\.ssh\vps_nova_187_41_108_26`; sua chave pública fica no arquivo de mesmo nome com extensão `.pub`.
- O host é `187.41.108.26`, usuário `root`, porta `22`. Usar a chave somente para a VPS autorizada e conferir a identidade do host. O conteúdo da chave privada não deve ser incluído no repositório ou na conversa.

## Arquivos preparados

- `compose.portainer.production.yml`: stack independente para Portainer/Traefik, com variáveis e arquivo de senha próprios.
- `Dockerfile.production`: imagem baseada na aplicação homologada, com nomes de tabelas e textos próprios de produção.
- `ops/prepare-production.php`: transforma a cópia da aplicação durante a construção da imagem. A construção falha se restarem referências de homologação nos arquivos processados.
- `ops/provision-production-db.sh`: procedimento executado no VPS para criar o banco novo a partir da **estrutura** das cinco tabelas de negócio. Não copia linhas, credenciais de serviços, a tabela de usuários legada nem outros dados do NocoDB.
- `n8n/producao/`: sete definições sem segredos para criação de Pix, consulta, ações administrativas, conciliação, recebimento Asaas e reprocessamento de eventos e entregas. As cópias de produção foram vinculadas às credenciais corretas e publicadas; falta o teste financeiro real de ponta a ponta.

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
- A stack Portainer `pay360bh-prod` usa a imagem `pay360bh-production:8f8c5f0`. O domínio `pay.360bh.com.br` atende com certificado HTTPS válido; `/health`, login e acesso autenticado ao painel foram verificados.
- A criação inicial de um rascunho de produção no n8n associou automaticamente credenciais de outros serviços. O rascunho permaneceu inativo e foi arquivado; as cópias finais devem ter as credenciais de produção atribuídas e conferidas explicitamente antes da publicação.
- O validador apontou avisos de credencial genérica HTTP nas definições preliminares. Elas devem reutilizar as credenciais corretas já criadas, por ID, e não criar credenciais automáticas de tipo genérico.

## Pendências para implantação

1. Fazer uma cobrança real controlada e confirmar estado, evento e entrega ao serviço ponta a ponta.
2. Validar baixa, cancelamento e recuperação de falhas no ambiente efetivamente utilizado antes da operação comercial.
3. Configurar backup recorrente externo e planejar a atualização do PostgreSQL compartilhado, que apresenta aviso de compatibilidade do n8n.
4. Manter as credenciais independentes e nunca registrar seus valores no repositório.

## Implantação e validação de produção

- Os sete fluxos separados de produção foram publicados no n8n, com credenciais próprias. O webhook Asaas `Pay 360BH Producao` foi criado e ativado em `https://hook.360bh.com.br/webhook/asaas-avisos-producao-v1`; o webhook preexistente `Automação 360` foi preservado.
- Eventos de cobranças que não pertencem à Central são marcados como ignorados. Entregas foram testadas com receptor e registros sintéticos: token incorreto manteve a entrega pendente, token correto permitiu envio e reexecução não duplicou a entrega. Os dados de teste foram removidos e as cinco tabelas de negócio estavam vazias após a limpeza.
- Backup inicial: `/opt/360bh/backups/central_cobrancas_prod_inicial.dump`, SHA-256 `f1795f4604dc4f6d9c27c1f560cb7875332fdf1cbbbc22b082de882d96feb99d`. Uma restauração em banco temporário terminou sem erro; o banco temporário foi removido. Configurar também uma rotina recorrente de backup externo e testar restaurações periodicamente.
- A senha administrativa inicial foi gerada no VPS em arquivo restrito `/opt/360bh/secrets/pay360bh_prod_admin_password`. Entregar por canal privado e remover o arquivo após confirmar o acesso. A chave SSH permanece no caminho documentado acima, nunca no repositório.
- Falta cadastrar um serviço, gerar e pagar uma cobrança real controlada, conferir a notificação recebida e entregue, validar baixa e cancelamento e revisar os processos de recuperação antes do uso comercial. A versão PostgreSQL 14 usada pela infraestrutura compartilhada apresenta aviso de compatibilidade do n8n; planejar atualização com backup e avaliação dos demais serviços.

O banco de homologação, o banco original do NocoDB e o Pay legado continuam preservados; a tabela legada de usuários não integra a produção.
