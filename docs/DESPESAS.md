# Despesas integradas ao financeiro

O cadastro em `financeiro.php?aba=despesas` usa `fin_dias()` para seguir o mesmo intervalo efetivo do DRE e do fluxo de caixa. As abas, os atalhos, a edição e o retorno do cadastro mantêm o período selecionado.

- Os cartões contam as ocorrências no período, incluindo despesas mensais e anuais; despesas futuras, encerradas ou pausadas não entram fora de sua incidência.
- Cada linha mostra o valor por ocorrência e o total no período. A participação usa esse total; o histórico termina no mês final da seleção.
- Na classificação gerencial existente, despesas únicas entram como custos variáveis e recorrentes entram como despesas fixas. O cadastro informa essa relação e oferece acesso direto ao DRE e ao fluxo.
- A tela avisa que os valores do fluxo são calculados pela data cadastrada, sem conciliação de pagamentos, e orienta a não duplicar custos já importados.
- A configuração fiscal permanece disponível depois do formulário. Sua regra de cálculo não mudou.

Pausar continua removendo as ocorrências de todos os períodos; isso é informado no formulário. Controle de contas a pagar, comprovantes e vínculo com projetos exigem modelagem própria e não são simulados nesta tela.

## Verificação

`php tests/despesas.php` usa um SQLite temporário exclusivo e confere despesas únicas/mensais/anuais, fevereiro bissexto, pausa, início futuro, encerramento, equivalência com DRE/fluxo, período dos links e estado vazio.

`node --check painel.js` verifica sintaxe. No navegador, conferir cadastro, troca de período e abas, mostrar/esconder a data final da recorrência, rolagem até o formulário e largura de 390 px.

A correção é feita no núcleo `OZATI/track`. Para chegar a uma instalação, deve seguir o fluxo de cópia versionada descrito em `ARQUITETURA.md` e a publicação do site consumidor.
