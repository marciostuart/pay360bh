# Roadmap da Central de Cobranças

## Estornos — planejado

- Receber e registrar eventos de estorno do Asaas, inclusive estorno integral e parcial e estados intermediários.
- Atualizar o estado financeiro e o histórico no PostgreSQL sem confundir estorno com cancelamento.
- Notificar o serviço de destino com autenticação, deduplicação e recuperação de falhas.
- Conciliar estornos perdidos e testar eventos repetidos, fora de ordem e notificações interrompidas em homologação antes da operação.
- Exibir informações relevantes no Pay e definir a política de valores parciais antes de oferecer a operação pelo painel ou API.

Atualmente, estornos feitos diretamente no Asaas não são sincronizados de forma confiável com o Pay ou com os serviços.

## Próximos recursos

- Cobranças por boleto, após o fluxo Pix estar consolidado.
- Avaliar endpoint público de consulta segura de cobranças por serviço e referência.
