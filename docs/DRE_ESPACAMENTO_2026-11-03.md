# Espaçamento do cabeçalho do DRE

O cabeçalho `.resumo-cab` é renderizado antes da `.painel-grade`, portanto o `gap` interno da grade não o separava dos cartões. Corrigido em `lib/layout.php` com margem superior na grade adjacente: 12 px no desktop e 10 px até 767 px, acompanhando o gap dos cartões. Aplica-se ao DRE e ao Fluxo de caixa, que compartilham esse cabeçalho. Não altera cálculos, posições salvas ou o editor.
