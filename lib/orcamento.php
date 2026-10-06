<?php
// Orcamento pelo painel (pedido do Allan de 06/10/2026): mudar o orcamento diario de uma
// campanha ou de um conjunto na Meta e programar aumentos e reducoes, todo dia num horario ou
// uma vez numa data e hora. Precisa do token com ads_management (o mesmo de ligar e pausar).
//
// Travas: so objetos que o painel ja conhece da conta e que tem orcamento diario; nunca acima do
// teto (Configuracoes, padrao R$ 300,00 por dia); a tela confirma antes e avisa quando a mudanca
// passa de 20% (a Meta pode reiniciar o aprendizado). Cada tentativa, do painel ou da
// programacao, fica em meta_alteracoes com o resultado, e a programacao avisa no celular.
// Quem executa as programacoes e a tarefa agendada (cron.php, a cada 5 minutos).

require_once __DIR__ . '/vendas.php';
require_once __DIR__ . '/gestor_editar.php';
require_once __DIR__ . '/financeiro.php';

const ORC_TETO_PADRAO = 30000;   // R$ 300,00 por dia
const ORC_MINIMO = 100;          // R$ 1,00 (a Meta tem o minimo dela e recusa abaixo)
const ORC_JANELA_MIN = 30;       // programacao diaria atrasada (tarefa parada) roda ate 30 min depois
const ORC_SEMANA = ['1' => 'seg', '2' => 'ter', '3' => 'qua', '4' => 'qui', '5' => 'sex', '6' => 'sáb', '7' => 'dom'];

function orc_teto(): int
{
    $t = (int)(ajuste('orcamento_teto') ?? 0);
    return $t > 0 ? $t : ORC_TETO_PADRAO;
}

// Campanha ou conjunto conhecido com orcamento diario, ou null
function orc_objeto(string $id): ?array
{
    $st = track_db()->prepare("SELECT * FROM meta_objetos WHERE id = ? AND nivel IN ('campaign', 'adset')");
    $st->execute([$id]);
    $o = $st->fetch(PDO::FETCH_ASSOC);
    return $o && (int)$o['orcamento_diario'] > 0 ? $o : null;
}

// Onde da para mudar o orcamento de uma campanha: nela (orcamento da campanha) ou nos conjuntos
// dela (cada conjunto com o seu)
function orc_alvos(string $campanha): array
{
    $alvos = [];
    if ($o = orc_objeto($campanha)) {
        $alvos[] = $o;
    }
    $st = track_db()->prepare("SELECT * FROM meta_objetos WHERE nivel = 'adset' AND campanha_id = ? AND orcamento_diario > 0 ORDER BY nome");
    $st->execute([$campanha]);
    return array_merge($alvos, $st->fetchAll(PDO::FETCH_ASSOC));
}

// Muda o orcamento diario na Meta. $quem: o usuario, ou "programação (usuario)". [ok, mensagem]
function orc_mudar(string $id, int $novo, string $quem): array
{
    $obj = orc_objeto($id);
    if (!$obj) {
        return [false, 'Não encontrei essa campanha ou conjunto com orçamento diário na conta. Atualize o gestor e tente de novo.'];
    }
    $k = meta_api_chave();
    if (!$k || !gestor_pode_editar()) {
        return [false, 'O token da API Meta só lê. Para mudar o orçamento pelo painel, gere um token com ads_management.'];
    }
    $nome = (string)$obj['nome'];
    $atual = (int)$obj['orcamento_diario'];
    if ($novo < ORC_MINIMO || $novo > orc_teto()) {
        return [false, 'O orçamento de ' . GESTOR_NIVEL_META[$obj['nivel']] . ' "' . $nome . '" precisa ficar entre ' . reais(ORC_MINIMO) . ' e o teto de ' . reais(orc_teto()) . ' por dia (o teto muda em Configurações).'];
    }
    if ($novo === $atual) {
        return [true, 'O orçamento de "' . $nome . '" já é ' . reais($novo) . ' por dia.'];
    }
    [$status, $c] = meta_api_post('/' . $id, ['daily_budget' => (string)$novo], $k['token']);
    $ok = $status === 200 && !empty($c['success']);
    $erro = $ok ? null : (meta_api_mensagem($c) ?: ($status === 0 ? 'sem conexão com a Meta' : 'a Meta respondeu HTTP ' . $status));
    $db = track_db();
    $db->prepare('INSERT INTO meta_alteracoes (em, usuario, nivel, objeto_id, nome, de, para, ok, erro) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([agora_utc(), $quem, $obj['nivel'], $id, $nome, 'orc:' . $atual, 'orc:' . $novo, $ok ? 1 : 0, $erro]);
    if (!$ok) {
        return [false, 'A Meta não deixou mudar o orçamento de "' . $nome . '": ' . $erro . '.'];
    }
    // Reflete na hora (a proxima busca na Meta confirma) e no orcamento do dia da analise diaria
    $db->prepare('UPDATE meta_objetos SET orcamento_diario = ? WHERE id = ?')->execute([$novo, $id]);
    $db->prepare('INSERT OR REPLACE INTO meta_orcamento_dia (dia, objeto_id, orcamento_diario, orcamento_total) VALUES (?, ?, ?, NULL)')
        ->execute([(new DateTime('today', fuso()))->format('Y-m-d'), $id, $novo]);
    return [true, 'Orçamento de "' . $nome . '" mudou de ' . reais($atual) . ' para ' . reais($novo) . ' por dia na Meta.'];
}

// Texto de uma mudanca de orcamento no historico ("orc:3000" -> "R$ 30,00")
function orc_rotulo(?string $v): ?string
{
    return is_string($v) && strpos($v, 'orc:') === 0 ? reais((int)substr($v, 4)) : null;
}

// Programacoes de uma campanha (dela e dos conjuntos dela), ou de todas
function orc_programacoes(?string $campanha = null): array
{
    $sql = 'SELECT p.*, o.nome, o.nivel, o.orcamento_diario FROM meta_programacoes p LEFT JOIN meta_objetos o ON o.id = p.objeto_id';
    $par = [];
    if ($campanha !== null) {
        $sql .= ' WHERE p.objeto_id = ? OR o.campanha_id = ?';
        $par = [$campanha, $campanha];
    }
    return consulta_db($sql . ' ORDER BY p.ativo DESC, p.tipo, p.hora, p.quando', $par);
}

function consulta_db(string $sql, array $par): array
{
    $st = track_db()->prepare($sql);
    $st->execute($par);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// Cria uma programacao. $d: objeto, tipo (diaria|unica), hora "HH:MM", dias ["1".."7"],
// data "AAAA-MM-DD" (unica), valor em reais (texto). [ok, mensagem]
function orc_programar(array $d, string $usuario): array
{
    $obj = orc_objeto((string)($d['objeto'] ?? ''));
    $valor = fin_centavos((string)($d['valor'] ?? ''));
    $hora = (string)($d['hora'] ?? '');
    $tipo = in_array($d['tipo'] ?? '', ['diaria', 'unica'], true) ? $d['tipo'] : '';
    if (!$obj) {
        return [false, 'Escolha uma campanha ou conjunto com orçamento diário.'];
    }
    if (!gestor_pode_editar()) {
        return [false, 'O token da API Meta só lê. Para programar o orçamento, gere um token com ads_management.'];
    }
    if ($tipo === '' || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hora)) {
        return [false, 'Confira a programação: quando repete e o horário (ex.: 08:00).'];
    }
    if ($valor === null || $valor < ORC_MINIMO || $valor > orc_teto()) {
        return [false, 'O valor precisa ficar entre ' . reais(ORC_MINIMO) . ' e o teto de ' . reais(orc_teto()) . ' por dia (o teto muda em Configurações).'];
    }
    $dias = null;
    $quando = null;
    if ($tipo === 'diaria') {
        $dias = implode('', array_intersect(array_keys(ORC_SEMANA), array_map('strval', (array)($d['dias'] ?? []))));
        if ($dias === '') {
            return [false, 'Marque pelo menos um dia da semana.'];
        }
    } else {
        $data = (string)($d['data'] ?? '');
        if (!fin_data_valida($data)) {
            return [false, 'Escolha a data da mudança.'];
        }
        $local = new DateTime($data . ' ' . $hora, fuso());
        if ($local->getTimestamp() < time() - 60) {
            return [false, 'Essa data e hora já passou.'];
        }
        $quando = (clone $local)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
    track_db()->prepare('INSERT INTO meta_programacoes (objeto_id, tipo, hora, dias, quando, valor, criado_por, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$obj['id'], $tipo, $hora, $dias, $quando, $valor, $usuario, agora_utc()]);
    return [true, 'Programado: ' . orc_descrever(['tipo' => $tipo, 'hora' => $hora, 'dias' => $dias, 'quando' => $quando, 'valor' => $valor]) . ' em "' . $obj['nome'] . '".'];
}

// "todo dia (seg a sex) às 08:00, R$ 50,00" ou "em 10/10 às 22:00, R$ 30,00"
function orc_descrever(array $p): string
{
    if ($p['tipo'] === 'unica') {
        return 'em ' . data_local($p['quando'], 'd/m/Y') . ' às ' . data_local($p['quando'], 'H:i') . ', ' . reais((int)$p['valor']);
    }
    $dias = (string)$p['dias'];
    $quais = $dias === '1234567' ? 'todo dia' : ($dias === '12345' ? 'de segunda a sexta' : ($dias === '67' ? 'sábado e domingo'
        : implode(', ', array_map(fn($c) => ORC_SEMANA[$c], str_split($dias)))));
    return $quais . ' às ' . $p['hora'] . ', ' . reais((int)$p['valor']);
}

function orc_apagar(int $id): bool
{
    $st = track_db()->prepare('DELETE FROM meta_programacoes WHERE id = ?');
    $st->execute([$id]);
    return $st->rowCount() > 0;
}

// Tarefa agendada: executa as programacoes vencidas. Diaria: no dia da semana marcado, a
// partir do horario e ate 30 minutos depois (tarefa parada mais tempo que isso nao muda o
// orcamento fora de hora), uma vez por dia. Unica: na data e hora, ate 2 horas depois.
// Cada execucao avisa no celular. Devolve quantas rodaram.
function orc_executar_vencidas(?DateTime $agora = null): int
{
    $db = track_db();
    $tz = fuso();
    $agora = $agora ? (clone $agora)->setTimezone($tz) : new DateTime('now', $tz);
    $hoje = $agora->format('Y-m-d');
    $rodaram = 0;
    foreach (consulta_db('SELECT p.*, o.nome FROM meta_programacoes p LEFT JOIN meta_objetos o ON o.id = p.objeto_id WHERE p.ativo = 1', []) as $p) {
        if ($p['tipo'] === 'diaria') {
            $inicio = new DateTime($hoje . ' ' . $p['hora'], $tz);
            $vence = strpos((string)$p['dias'], $agora->format('N')) !== false && $agora >= $inicio
                && $agora->getTimestamp() - $inicio->getTimestamp() <= ORC_JANELA_MIN * 60
                && (!$p['executada_em'] || data_local($p['executada_em'], 'Y-m-d') !== $hoje);
        } else {
            $quando = (new DateTime($p['quando'], new DateTimeZone('UTC')))->getTimestamp();
            $vence = !$p['executada_em'] && $agora->getTimestamp() >= $quando;
            if ($vence && $agora->getTimestamp() - $quando > 7200) {
                $db->prepare("UPDATE meta_programacoes SET ativo = 0, executada_em = ?, resultado = ? WHERE id = ?")
                    ->execute([agora_utc(), 'Não rodou: a tarefa agendada estava parada no horário.', $p['id']]);
                continue;
            }
        }
        if (!$vence) {
            continue;
        }
        // Reserva a execucao antes de chamar a Meta: duas tarefas ao mesmo tempo nao mudam duas vezes
        $st = $db->prepare('UPDATE meta_programacoes SET executada_em = ? WHERE id = ? AND (executada_em IS ? OR executada_em = ?)');
        $st->execute([agora_utc(), $p['id'], $p['executada_em'], $p['executada_em']]);
        if ($st->rowCount() !== 1) {
            continue;
        }
        [$ok, $msg] = orc_mudar((string)$p['objeto_id'], (int)$p['valor'], 'programação (' . $p['criado_por'] . ')');
        $db->prepare('UPDATE meta_programacoes SET resultado = ?' . ($p['tipo'] === 'unica' ? ', ativo = 0' : '') . ' WHERE id = ?')->execute([($ok ? 'Feito: ' : 'Recusado: ') . $msg, $p['id']]);
        $rodaram++;
        if (function_exists('push_para_usuarios')) {
            push_para_usuarios(fn(array $pref) => ['titulo' => $ok ? 'Orçamento programado aplicado' : 'Programação de orçamento falhou',
                'corpo' => $msg, 'url' => './?aba=gestor', 'tag' => 'orcamento-' . $p['id']]);
        }
    }
    return $rodaram;
}

// Bloco "Orcamento" da analise diaria da campanha: mudar agora, programar, as programacoes e o
// historico das mudancas da campanha e dos conjuntos dela
function orc_bloco(string $campanha, string $volta): string
{
    $pode = gestor_pode_editar();
    $alvos = orc_alvos($campanha);
    $teto = orc_teto();
    $csrf = '<input type="hidden" name="csrf" value="' . e(token_csrf()) . '"><input type="hidden" name="volta" value="' . e($volta . '#orcamento') . '">';
    $html = '<section class="bloco orcamento" id="orcamento">' . titulo('Orçamento', 'Muda o orçamento diário na Meta agora ou numa programação (todo dia num horário, ou uma vez numa data e hora). Teto de ' . reais($teto) . ' por dia, que muda em Configurações. Subir ou descer mais de 20% de uma vez pode reiniciar o aprendizado da Meta: a tela avisa antes. Cada mudança fica no histórico e a programação avisa no celular.');
    if (!$alvos) {
        return $html . '<p class="suave">Esta campanha não tem orçamento diário no painel (orçamento total, ou nada buscado ainda). Atualize o gestor ou mude no Gerenciador de Anúncios da Meta.</p></section>';
    }
    if (!$pode) {
        $html .= '<p class="aviso-meta">O token da API Meta só lê: para mudar e programar o orçamento por aqui, gere um token com <b>ads_management</b> na aba <a href="meta-api.php">API Meta</a>.</p>';
    }
    $opcoes = '';
    foreach ($alvos as $o) {
        $opcoes .= '<option value="' . e($o['id']) . '" data-atual="' . (int)$o['orcamento_diario'] . '">' . e(($o['nivel'] === 'campaign' ? 'Campanha' : 'Conjunto') . ': ' . $o['nome'] . ' (' . reais((int)$o['orcamento_diario']) . ')') . '</option>';
    }
    $dis = $pode ? '' : ' disabled';
    $html .= '<div class="orc-grade"><form method="post" action="meta-orcamento.php" class="orc-form" data-orcamento>' . $csrf . '<input type="hidden" name="acao" value="mudar">'
        . '<h3>Mudar agora</h3><label>Onde<select name="objeto"' . $dis . '>' . $opcoes . '</select></label>'
        . '<label><span>' . com_info('Novo orçamento diário (R$)', 'Vale na hora, na Meta. Até ' . reais($teto) . ' por dia.') . '</span><input name="valor" inputmode="decimal" required placeholder="' . e(number_format((int)$alvos[0]['orcamento_diario'] / 100, 2, ',', '.')) . '"' . $dis . '></label>'
        . '<button type="submit"' . $dis . '>Mudar na Meta</button></form>';
    $hoje = (new DateTime('today', fuso()))->format('Y-m-d');
    $html .= '<form method="post" action="meta-orcamento.php" class="orc-form" data-programar>' . $csrf . '<input type="hidden" name="acao" value="programar">'
        . '<h3>Programar</h3><label>Onde<select name="objeto"' . $dis . '>' . $opcoes . '</select></label>'
        . '<div class="orc-linha"><label class="orc-op"><input type="radio" name="tipo" value="diaria" checked' . $dis . '> Repete</label><label class="orc-op"><input type="radio" name="tipo" value="unica"' . $dis . '> Uma vez</label></div>'
        . '<div class="orc-dias" data-so="diaria">';
    foreach (ORC_SEMANA as $n => $rot) {
        $html .= '<label class="orc-op"><input type="checkbox" name="dias[]" value="' . $n . '" checked' . $dis . '> ' . e($rot) . '</label>';
    }
    $html .= '</div><div class="orc-linha"><label data-so="unica">Data<input type="date" name="data" min="' . $hoje . '" value="' . $hoje . '"' . $dis . '></label>'
        . '<label>Horário<input type="time" name="hora" required value="08:00"' . $dis . '></label>'
        . '<label><span>' . com_info('Orçamento (R$)', 'O orçamento diário que a campanha passa a ter nesse horário. Até ' . reais($teto) . ' por dia.') . '</span><input name="valor" inputmode="decimal" required placeholder="0,00"' . $dis . '></label></div>'
        . '<button type="submit"' . $dis . '>Programar</button></form></div>';

    // Programacoes desta campanha
    $progs = orc_programacoes($campanha);
    $html .= '<h3>Programações</h3>';
    if (!$progs) {
        $html .= '<p class="suave">Nenhuma programação. Ex.: subir às 10h e voltar às 13h, nos horários em que a campanha mais vende (veja a leitura da campanha).</p>';
    } else {
        $html .= '<div class="tabela"><table><tr><th>' . com_info('Quando', 'Repete: nos dias marcados, no horário. Uma vez: na data e hora. A tarefa agendada roda a cada 5 minutos, então pode levar alguns minutos.') . '</th><th>Onde</th><th>' . com_info('Última vez', 'Quando rodou pela última vez e o que a Meta respondeu.') . '</th><th>Quem criou</th><th></th></tr>';
        foreach ($progs as $p) {
            $html .= '<tr' . ($p['ativo'] ? '' : ' class="suave"') . '><td>' . e(orc_descrever($p)) . ($p['ativo'] ? '' : ' <span class="selo neutro">encerrada</span>') . '</td><td class="quebra">' . e((string)$p['nome']) . '</td>'
                . '<td class="quebra">' . ($p['executada_em'] ? e(data_local($p['executada_em'], 'd/m H:i')) . ' <span class="suave">' . e((string)$p['resultado']) . '</span>' : '<span class="suave">ainda não rodou</span>') . '</td>'
                . '<td>' . e((string)$p['criado_por']) . '</td><td><form method="post" action="meta-orcamento.php" class="form-linha" data-confirma="Apagar esta programação?">' . $csrf
                . '<input type="hidden" name="acao" value="apagar"><input type="hidden" name="id" value="' . (int)$p['id'] . '"><button type="submit" class="discreto">Apagar</button></form></td></tr>';
        }
        $html .= '</table></div>';
    }

    // Historico das mudancas de orcamento desta campanha e dos conjuntos dela
    $ids = array_column($alvos, 'id');
    $marcas = implode(', ', array_fill(0, count($ids), '?'));
    $hist = consulta_db("SELECT * FROM meta_alteracoes WHERE objeto_id IN ($marcas) AND para LIKE 'orc:%' ORDER BY id DESC LIMIT 10", $ids);
    if ($hist) {
        $html .= '<h3>Mudanças de orçamento</h3><div class="tabela"><table><tr><th>Quando</th><th>Quem</th><th>Onde</th><th>Mudança</th><th>Resultado</th></tr>';
        foreach ($hist as $h) {
            $html .= '<tr><td>' . e(data_local($h['em'], 'd/m H:i')) . '</td><td>' . e($h['usuario']) . '</td><td class="quebra">' . e((string)$h['nome']) . '</td>'
                . '<td>' . e(orc_rotulo($h['de']) . ' → ' . orc_rotulo($h['para'])) . '</td>'
                . '<td>' . ($h['ok'] ? '<span class="selo ok">Feito</span>' : '<span class="selo erro">Recusado</span> <span class="suave">' . e((string)$h['erro']) . '</span>') . '</td></tr>';
        }
        $html .= '</table></div>';
    }
    return $html . '</section>';
}
