<?php
// Aparencia do painel: claro ou escuro a partir de uma cor base, escolhida por usuario (pedido
// do Allan: poder deixar o painel todo em #000000, o "pretao" dos paineis financeiros). O resto
// da paleta (cartoes, linhas, realces) sai da cor base no CSS do layout (color-mix), e o texto
// fica claro ou escuro pela luminosidade da base: qualquer cor escolhida continua legivel.
// Guardado em ajustes, "tema:<usuario>", so a cor (#RRGGBB). Gravado por tema.php.

const TEMA_PADRAO = '#F7F8FA';
// Prontos: [nome, cor base]. O escuro e o azul-grafite da UTMify; o pretao, preto puro.
const TEMA_PRONTOS = ['claro' => ['Claro', '#F7F8FA'], 'escuro' => ['Escuro', '#1B2232'], 'pretao' => ['Pretão', '#000000']];

// Cor no formato #RRGGBB (maiusculas) ou null
function tema_cor($hex): ?string
{
    if (!is_string($hex) || !preg_match('/^#?([0-9a-fA-F]{6}|[0-9a-fA-F]{3})$/', trim($hex), $m)) {
        return null;
    }
    $h = $m[1];
    if (strlen($h) === 3) {
        $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
    }
    return '#' . strtoupper($h);
}

// Luminosidade relativa (WCAG), de 0 (preto) a 1 (branco)
function tema_luminosidade(string $hex): float
{
    $c = array_map(fn($p) => hexdec($p) / 255, str_split(substr($hex, 1), 2));
    $lin = array_map(fn($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);
    return 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
}

// Base escura pede texto claro (o mesmo limite do painel.js, que mostra a previa)
function tema_escuro(string $hex): bool
{
    return tema_luminosidade($hex) < 0.4;
}

// Cor base de quem esta no painel (sem login, a padrao)
function tema_atual(): string
{
    $u = usuario_atual();
    return ($u !== null ? tema_cor(ajuste('tema:' . $u)) : null) ?? TEMA_PADRAO;
}

// Atributos do <html>: modo, cor base (so quando nao e a padrao) e color-scheme
function tema_atributos(): string
{
    $base = tema_atual();
    return ' data-tema="' . (tema_escuro($base) ? 'escuro' : 'claro') . '"'
        . ($base !== TEMA_PADRAO ? ' data-base style="--base:' . e($base) . '"' : '');
}

// Escolha da aparencia: os prontos (com a previa, como na UTMify) e qualquer outra cor.
// Escolher ja grava; a cor livre mostra a previa enquanto arrasta (painel.js).
function tema_form(string $volta): string
{
    $atual = tema_atual();
    $html = '<form method="post" action="tema.php" class="tema-form" data-tema-form><input type="hidden" name="csrf" value="' . e(token_csrf()) . '">'
        . '<input type="hidden" name="volta" value="' . e($volta) . '"><div class="tema-prontos">';
    foreach (TEMA_PRONTOS as $k => [$nome, $cor]) {
        $html .= '<button type="submit" name="pronto" value="' . $k . '" class="tema-pronto' . ($atual === $cor ? ' atual' : '') . '" aria-pressed="' . ($atual === $cor ? 'true' : 'false') . '">'
            . '<span class="tema-previa" style="--p:' . e($cor) . '" data-escuro="' . (tema_escuro($cor) ? '1' : '0') . '"><i></i><i></i><i></i></span>' . e($nome) . '</button>';
    }
    $livre = !in_array($atual, array_column(TEMA_PRONTOS, 1), true);
    return $html . '</div><div class="tema-livre"><span>' . com_info('Outra cor de fundo', 'Escolha qualquer cor: cartões, linhas e realces se ajustam a ela, e o texto fica claro ou escuro para continuar legível. Vale só para o seu usuário.') . '</span>'
        . '<span><input type="color" name="cor" value="' . e($livre ? strtolower($atual) : '#000000') . '" data-tema-cor aria-label="Cor de fundo do painel"><code data-tema-hex>' . e($livre ? $atual : '') . '</code></span></div>'
        . '<noscript><button type="submit">Usar esta cor</button></noscript></form>';
}
