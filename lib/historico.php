<?php
// Historico: as vendas da Kiwify e o gasto da Meta mais antigos que os 89 dias da busca normal.
// Cada API tem o seu passo e o seu estado (kiwify_historico_passo em lib/kiwify_sync.php,
// meta_historico_passo em lib/meta_sync.php); aqui eles rodam alternados ate acabar o tempo, para
// uma API nao segurar a outra. Quem chama: sincronizar.php (com o painel aberto, depois das buscas
// normais, de HISTORICO_INTERVALO em HISTORICO_INTERVALO) e o cron.php. Terminado, nao roda mais.

require_once __DIR__ . '/kiwify_sync.php';
require_once __DIR__ . '/meta_sync.php';

const HISTORICO_INTERVALO = 30; // segundos entre rodadas, com o painel aberto

function historico_pendente(): bool
{
    return kiwify_historico_pendente() || meta_historico_pendente();
}

// Pendente e a ultima rodada ja passou do intervalo (o painel.js pede a busca em segundo plano)
function historico_vencido(): bool
{
    return historico_pendente() && time() - (int)(ajuste('historico_tentativa') ?? 0) >= HISTORICO_INTERVALO;
}

// Passos alternados ate $segundos (o passo que estiver no meio termina). Para de pedir a uma API
// quando ela termina, erra ou esta ocupada. ['novas' => vendas novas, 'dias' => linhas de gasto]
function historico_rodar(int $segundos): array
{
    definir_ajuste('historico_tentativa', (string)time());
    $limite = microtime(true) + $segundos;
    $novas = $dias = 0;
    $passos = [];
    if (kiwify_historico_pendente()) {
        $passos[] = 'kiwify_historico_passo';
    }
    if (meta_historico_pendente()) {
        $passos[] = 'meta_historico_passo';
    }
    while ($passos && microtime(true) < $limite) {
        foreach ($passos as $i => $passo) {
            try {
                $r = $passo();
            } catch (Throwable $e) {
                $r = ['ok' => false];
            }
            $novas += (int)($r['novas'] ?? 0);
            $dias += (int)($r['dias'] ?? 0);
            if (empty($r['ok']) || !empty($r['fim'])) {
                unset($passos[$i]);
            }
            if (microtime(true) >= $limite) {
                break 2;
            }
        }
    }
    return ['novas' => $novas, 'dias' => $dias];
}
