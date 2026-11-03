# Checkout Orders: base local de 02/11/2026

Implementação em `lib/pagamentos.php`, `lib/pagamentos/mercadopago.php` e `lib/pagamentos/capi.php`. Não publicada nem homologada com credenciais reais. Mantém o restante do painel e os registros Kiwify inalterados.

- O site calcula itens/preço no servidor e autentica o comprador antes de preparar um pedido.
- SQLite guarda `csh_pedidos`, `csh_compradores` (contatos e cookies Meta separados) e `csh_capi` (outbox).
- Reserva atômica antes do POST `/v1/orders`; idempotência por ID local. Resposta incerta bloqueia nova cobrança. Não trocar de provedor em timeout/5xx: pode ter cobrado.
- Webhook verifica HMAC com ID em minúsculas no manifesto, consulta Orders e confere ID, referência e valor. Reembolso não volta a aprovado com resposta atrasada.
- Tokens de cartão/documento não são persistidos; somente whitelist da resposta do MP entra no banco.
- Purchase fica no outbox; cron envia com `event_id=mp:<order_id>`, email/telefone em hash. Falha reenvia o mesmo evento por até 24h. Em teste exige código Test Events. Graph API tem versão configurável.
- A entrega é responsabilidade do site consumidor; EngDesk usa RPC idempotente no Supabase, restrita a service_role.

Teste: `php tests/pagamentos.php`, sem rede, com transporte falso injetado por código. O endpoint do provedor em produção é fixo, nunca escolhido por parâmetro HTTP.

O restante do plano CSH continua pendente: Wiven/Asaas de reserva, conexão OAuth e tela completa de caixa. A tela inicial de configuração/pedidos da EngDesk fica no CMS. Não virar a oferta Kiwify enquanto a homologação e a reserva não estiverem concluídas. Configuração, migration, cron e roteiro em `engdesk/docs/CHECKOUT_IMPLEMENTACAO.md`.

Referências verificadas: [Orders/Pix](https://www.mercadopago.com.br/developers/en/docs/checkout-api-orders/payment-integration/websites/pix), [cartão](https://www.mercadopago.com.br/developers/pt/docs/checkout-api-orders/payment-integration/websites/cards), [webhooks](https://www.mercadopago.com.br/developers/pt/docs/checkout-api-orders/notifications).
