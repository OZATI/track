<?php
// Catalogo Kiwify e associacao entre campanhas Meta e ofertas principais.
require __DIR__ . '/lib/util.php';
require __DIR__ . '/lib/vendas.php';
require __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/financeiro.php';
require_once __DIR__ . '/lib/ofertas.php';

exigir_login();
$db = track_db();
$erros = [];
$aviso = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_valido()) {
        $erros[] = 'Sessão expirada. Recarregue a página.';
    } elseif (($_POST['acao'] ?? '') === 'salvar_oferta') {
        $idExistente = texto($_POST['id'] ?? '', 200);
        $nome = texto($_POST['nome'] ?? '', 200);
        $produtoId = texto($_POST['produto_id'] ?? '', 120) ?: null;
        $ofertaKiwiId = texto($_POST['oferta_kiwify_id'] ?? '', 120) ?: null;
        $preco = fin_centavos((string)($_POST['preco_base'] ?? ''));
        $tipo = in_array($_POST['tipo'] ?? '', ['principal', 'bump', 'upsell'], true) ? $_POST['tipo'] : '';
        if ($nome === '' || $preco === null || $preco < 1 || $tipo === '') {
            $erros[] = 'Informe nome, preço-base válido e tipo da oferta.';
        } elseif ($idExistente !== '' && !(int)valor($db, 'SELECT COUNT(*) FROM ofertas WHERE id = ?', [$idExistente])) {
            $erros[] = 'A oferta selecionada não existe mais.';
        } else {
            if ($idExistente !== '') {
                $db->prepare('UPDATE ofertas SET nome = ?, preco_base = ?, tipo = ?, ativo = ?, atualizado_em = ? WHERE id = ?')
                    ->execute([$nome, $preco, $tipo, ($_POST['ativo'] ?? '') === '1' ? 1 : 0, agora_utc(), $idExistente]);
            } else {
                $oid = oferta_registrar($db, $produtoId, $ofertaKiwiId, $nome, $preco);
                $db->prepare('UPDATE ofertas SET tipo = ?, ativo = ?, atualizado_em = ? WHERE id = ?')
                    ->execute([$tipo, ($_POST['ativo'] ?? '') === '1' ? 1 : 0, agora_utc(), $oid]);
            }
            $aviso = 'Oferta salva.';
        }
    } elseif (($_POST['acao'] ?? '') === 'associar') {
        $campanha = texto($_POST['campanha_id'] ?? '', 25);
        $oferta = texto($_POST['oferta_id'] ?? '', 200);
        $valida = preg_match('/^\d{3,25}$/', $campanha)
            && (int)valor($db, "SELECT COUNT(*) FROM meta_objetos WHERE id = ? AND nivel = 'campaign'", [$campanha]) === 1;
        if (!$valida) {
            $erros[] = 'Selecione uma campanha válida da Meta.';
        } elseif ($oferta === '') {
            $db->prepare('DELETE FROM campanha_ofertas WHERE campanha_id = ?')->execute([$campanha]);
            $aviso = 'Associação manual removida; o ticket será inferido pelas vendas.';
        } elseif (!(int)valor($db, 'SELECT COUNT(*) FROM ofertas WHERE id = ? AND ativo = 1', [$oferta])) {
            $erros[] = 'Selecione uma oferta ativa do catálogo.';
        } else {
            $db->prepare('INSERT INTO campanha_ofertas (campanha_id, oferta_id, atualizado_em) VALUES (?, ?, ?)
                ON CONFLICT(campanha_id) DO UPDATE SET oferta_id = excluded.oferta_id, atualizado_em = excluded.atualizado_em')
                ->execute([$campanha, $oferta, agora_utc()]);
            $aviso = 'Oferta associada à campanha. Vendas aprovadas continuam definindo o mix do período.';
        }
    }
}
$ofertas = consulta($db, 'SELECT * FROM ofertas ORDER BY ativo DESC, nome COLLATE NOCASE');
$edicaoId = texto($_GET['editar'] ?? '', 200);
$edicao = $edicaoId !== '' ? (consulta($db, 'SELECT * FROM ofertas WHERE id = ?', [$edicaoId])[0] ?? null) : null;
$campanhas = consulta($db, "SELECT id, nome FROM meta_objetos WHERE nivel = 'campaign' ORDER BY nome COLLATE NOCASE");
pagina_inicio('Catálogo de ofertas');
casca_inicio('integracoes');
topo_pagina();
abas_painel('gestor');
?>
<main class="cfg">
  <p><a href="./?aba=gestor">← Gestor de anúncios</a></p>
  <?php foreach ($erros as $erro): ?><p class="erro"><?= e($erro) ?></p><?php endforeach; ?>
  <?php if ($aviso): ?><p class="aviso-ok"><?= e($aviso) ?></p><?php endif; ?>
  <h1>Catálogo de ofertas</h1>
  <p class="suave">O preço-base vem da Kiwify sem juros. A associação é usada quando a campanha ainda não tem vendas; com vendas, o ticket acompanha o mix aprovado no período.</p>
  <section class="cartao">
    <h2>Adicionar ou editar uma oferta</h2>
    <form method="post" class="cfg-grade">
      <input type="hidden" name="csrf" value="<?= e(token_csrf()) ?>"><input type="hidden" name="acao" value="salvar_oferta"><input type="hidden" name="id" value="<?= e($edicao['id'] ?? '') ?>">
      <label class="cfg-campo"><span>Nome do produto/oferta</span><input name="nome" maxlength="200" value="<?= e($edicao['nome'] ?? '') ?>" required></label>
      <?php if ($edicao): ?><p class="suave">IDs Kiwify: <?= e($edicao['produto_id'] ?: '—') ?><?= $edicao['oferta_kiwify_id'] ? ' · ' . e($edicao['oferta_kiwify_id']) : '' ?></p><?php else: ?>
      <label class="cfg-campo"><span>ID do produto Kiwify (opcional)</span><input name="produto_id" maxlength="120"></label>
      <label class="cfg-campo"><span>ID da oferta Kiwify (opcional)</span><input name="oferta_kiwify_id" maxlength="120"></label><?php endif; ?>
      <label class="cfg-campo"><span>Preço-base (R$)</span><input name="preco_base" inputmode="decimal" placeholder="67,00" value="<?= $edicao ? e(number_format((int)$edicao['preco_base'] / 100, 2, ',', '.')) : '' ?>" required></label>
      <label class="cfg-campo"><span>Tipo</span><select name="tipo"><?php foreach (['principal' => 'Produto principal', 'bump' => 'Order bump', 'upsell' => 'Upsell'] as $v => $rotulo): ?><option value="<?= e($v) ?>"<?= ($edicao['tipo'] ?? 'principal') === $v ? ' selected' : '' ?>><?= e($rotulo) ?></option><?php endforeach; ?></select></label>
      <label class="cfg-campo"><span>Ativa</span><select name="ativo"><option value="1"<?= !isset($edicao['ativo']) || (int)$edicao['ativo'] ? ' selected' : '' ?>>Sim</option><option value="0"<?= isset($edicao['ativo']) && !(int)$edicao['ativo'] ? ' selected' : '' ?>>Não</option></select></label>
      <button type="submit"><?= $edicao ? 'Salvar alterações' : 'Salvar oferta' ?></button>
      <?php if ($edicao): ?><a href="ofertas.php">Cancelar edição</a><?php endif; ?>
    </form>
  </section>
  <section class="cartao">
    <h2>Associar oferta à campanha</h2>
    <form method="post" class="cfg-grade">
      <input type="hidden" name="csrf" value="<?= e(token_csrf()) ?>"><input type="hidden" name="acao" value="associar">
      <label class="cfg-campo"><span>Campanha</span><select name="campanha_id" required><option value="">Escolha</option><?php foreach ($campanhas as $c): ?><option value="<?= e($c['id']) ?>"><?= e($c['nome'] ?: $c['id']) ?> · <?= e($c['id']) ?></option><?php endforeach; ?></select></label>
      <label class="cfg-campo"><span>Oferta principal (vazio remove a associação)</span><select name="oferta_id"><option value="">Inferir pelas vendas</option><?php foreach ($ofertas as $o): if ((int)$o['ativo'] && $o['tipo'] === 'principal'): ?><option value="<?= e($o['id']) ?>"><?= e($o['nome']) ?> · <?= e(reais((int)$o['preco_base'])) ?></option><?php endif; endforeach; ?></select></label>
      <button type="submit">Associar</button>
    </form>
  </section>
  <section class="cartao">
    <h2>Ofertas conhecidas</h2>
    <div class="tabela"><table><thead><tr><th>Produto/oferta</th><th>Preço-base</th><th>Tipo</th><th>Estado</th><th>ID Kiwify</th></tr></thead><tbody>
    <?php foreach ($ofertas as $o): ?><tr><td><a href="ofertas.php?editar=<?= rawurlencode($o['id']) ?>"><?= e($o['nome']) ?></a></td><td><?= e(reais((int)$o['preco_base'])) ?></td><td><?= e(['principal' => 'Principal', 'bump' => 'Order bump', 'upsell' => 'Upsell'][$o['tipo']] ?? $o['tipo']) ?></td><td><?= (int)$o['ativo'] ? 'Ativa' : 'Inativa' ?></td><td class="suave"><?= e($o['produto_id'] ?: '—') ?><?= $o['oferta_kiwify_id'] ? ' · ' . e($o['oferta_kiwify_id']) : '' ?></td></tr><?php endforeach; ?>
    <?php if (!$ofertas): ?><tr><td colspan="5" class="suave">As ofertas aparecerão aqui quando chegarem pelo webhook ou pela sincronização, ou depois de adicioná-las acima.</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>
</main>
<?php casca_fim(); pagina_fim(); ?>
