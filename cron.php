<?php
// Tarefa agendada (cron) da hospedagem, a cada 5 minutos. Com o painel fechado, busca as vendas
// na Kiwify (o que dispara o aviso de venda nova), o gasto na Meta e o perfil do Instagram, e
// manda os relatorios nos horarios escolhidos em Configuracoes. So pela linha de comando:
//
//   php /caminho/do/painel/cron.php            (a cada 5 minutos)
//   php cron.php --hora=12                     (teste: forca o relatorio das 12h)

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/lib/util.php';
require_once __DIR__ . '/lib/kiwify_sync.php';
require_once __DIR__ . '/lib/meta_sync.php';
require_once __DIR__ . '/lib/instagram_sync.php';
require_once __DIR__ . '/lib/push.php';

if (!track_config()) {
    fwrite(STDERR, "Painel ainda nao instalado.\n");
    exit(1);
}
definir_ajuste('cron_ultimo', agora_utc());
$forcada = null;
foreach ($argv as $a) {
    if (preg_match('/^--hora=(\d{1,2})$/', $a, $m)) {
        $forcada = (int)$m[1];
    }
}
$feito = [];
foreach ([['kiwify', 'kiwify_sync_vencida', 'kiwify_sincronizar'], ['meta', 'meta_sync_vencida', 'meta_sincronizar'], ['instagram', 'ig_sync_vencida', 'ig_sincronizar']] as [$nome, $vencida, $buscar]) {
    try {
        if ($forcada === null && $vencida()) {
            $r = $buscar();
            $feito[] = $nome . ':' . (!empty($r['ok']) ? 'ok' : 'falhou');
        }
    } catch (Throwable $e) {
        $feito[] = $nome . ':erro';
    }
}
$enviados = relatorio_enviar_se_hora($forcada);
echo agora_utc(), ' ', implode(' ', $feito) ?: 'nada vencido', ' | relatorio: ', $enviados, "\n";
