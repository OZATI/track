<?php
// Banco SQLite em arquivo unico, na pasta de dados (fora da pasta publica).

function track_db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $dir = track_pasta_dados();
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $pdo = new PDO('sqlite:' . $dir . DIRECTORY_SEPARATOR . 'track.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    track_migrar($pdo);
    return $pdo;
}

function track_migrar(PDO $pdo): void
{
    $versao = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($versao < 1) {
        track_migrar_v1($pdo);
    }
    if ($versao < 2) {
        // Vendas tambem pela API da Kiwify: de onde chegaram, order bump e referencia curta
        track_migrar_para($pdo, 2, <<<'SQL'
            ALTER TABLE vendas ADD COLUMN referencia TEXT;
            ALTER TABLE vendas ADD COLUMN tipo TEXT;
            ALTER TABLE vendas ADD COLUMN pedido_pai TEXT;
            ALTER TABLE vendas ADD COLUMN fonte TEXT;
            ALTER TABLE vendas ADD COLUMN aprovada_em TEXT;
            UPDATE vendas SET fonte = 'webhook' WHERE fonte IS NULL;
            CREATE INDEX IF NOT EXISTS idx_vendas_recebida ON vendas (recebida_em);
            CREATE TABLE IF NOT EXISTS ajustes (
                chave TEXT PRIMARY KEY,
                valor TEXT
            );
            PRAGMA user_version = 2;
            SQL);
    }
    if ($versao < 3) {
        // Gestor de anuncios: gasto da Meta por anuncio e por dia, campanhas/conjuntos/anuncios
        // com status e orcamento, e o valor liquido de cada venda (o que cai na conta).
        // Apagar a ultima busca completa faz a proxima reler as vendas e trazer o liquido.
        track_migrar_para($pdo, 3, <<<'SQL'
            ALTER TABLE vendas ADD COLUMN valor_liquido INTEGER;
            CREATE TABLE IF NOT EXISTS meta_objetos (
                id               TEXT PRIMARY KEY,
                nivel            TEXT NOT NULL,
                nome             TEXT,
                status           TEXT,
                status_efetivo   TEXT,
                campanha_id      TEXT,
                conjunto_id      TEXT,
                orcamento_diario INTEGER,
                orcamento_total  INTEGER,
                atualizado_em    TEXT
            );
            CREATE TABLE IF NOT EXISTS meta_gasto (
                dia         TEXT NOT NULL,
                anuncio_id  TEXT NOT NULL,
                conjunto_id TEXT,
                campanha_id TEXT,
                gasto       INTEGER NOT NULL DEFAULT 0,
                impressoes  INTEGER NOT NULL DEFAULT 0,
                cliques     INTEGER NOT NULL DEFAULT 0,
                checkouts   INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (dia, anuncio_id)
            );
            DELETE FROM ajustes WHERE chave = 'kiwify_sync_completa_em';
            PRAGMA user_version = 3;
            SQL);
    }
    if ($versao < 4) {
        // Resumo: visualizacoes da pagina (funil da Meta) e gasto por hora (grafico acumulado).
        // Apagar a ultima busca completa da Meta faz a proxima trazer os 89 dias com isso.
        track_migrar_para($pdo, 4, <<<'SQL'
            ALTER TABLE meta_gasto ADD COLUMN visualizacoes INTEGER NOT NULL DEFAULT 0;
            CREATE TABLE IF NOT EXISTS meta_gasto_hora (
                dia   TEXT NOT NULL,
                hora  INTEGER NOT NULL,
                gasto INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (dia, hora)
            );
            DELETE FROM ajustes WHERE chave = 'meta_sync_completa_em';
            PRAGMA user_version = 4;
            SQL);
    }
    if ($versao < 5) {
        // Perfil do Instagram (aba Organico): numeros da conta por dia e cada post ou reel.
        // Nada de seguidores um a um: so contagens.
        track_migrar_para($pdo, 5, <<<'SQL'
            CREATE TABLE IF NOT EXISTS ig_dia (
                dia              TEXT PRIMARY KEY,
                seguidores       INTEGER,
                alcance          INTEGER,
                visualizacoes    INTEGER,
                contas_engajadas INTEGER,
                interacoes       INTEGER,
                toques_links     INTEGER,
                seguiram         INTEGER,
                deixaram         INTEGER,
                buscado_em       TEXT
            );
            CREATE TABLE IF NOT EXISTS ig_media (
                id                TEXT PRIMARY KEY,
                tipo              TEXT,
                produto           TEXT,
                legenda           TEXT,
                link              TEXT,
                publicado_em      TEXT,
                curtidas          INTEGER,
                comentarios       INTEGER,
                alcance           INTEGER,
                visualizacoes     INTEGER,
                salvos            INTEGER,
                compartilhamentos INTEGER,
                interacoes        INTEGER,
                visitas_perfil    INTEGER,
                seguiram          INTEGER,
                tempo_medio_ms    INTEGER,
                atualizado_em     TEXT,
                insights_em       TEXT
            );
            CREATE INDEX IF NOT EXISTS ig_media_publicado ON ig_media (publicado_em);
            PRAGMA user_version = 5;
            SQL);
    }
    if ($versao < 6) {
        // Gestor de anuncios: cada vez que alguem liga ou pausa algo na Meta pelo painel
        track_migrar_para($pdo, 6, <<<'SQL'
            CREATE TABLE IF NOT EXISTS meta_alteracoes (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                em        TEXT NOT NULL,
                usuario   TEXT NOT NULL,
                nivel     TEXT NOT NULL,
                objeto_id TEXT NOT NULL,
                nome      TEXT,
                de        TEXT,
                para      TEXT NOT NULL,
                ok        INTEGER NOT NULL,
                erro      TEXT
            );
            PRAGMA user_version = 6;
            SQL);
    }
}

// Aplica uma versao do banco em transacao, conferindo de novo a versao la dentro: duas
// requisicoes ao mesmo tempo nao tentam criar a mesma coluna.
function track_migrar_para(PDO $pdo, int $alvo, string $sql): void
{
    $pdo->exec('BEGIN IMMEDIATE');
    if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() >= $alvo) {
        $pdo->exec('COMMIT');
        return;
    }
    $pdo->exec($sql);
    $pdo->exec('COMMIT');
}

// Valores pequenos do painel que mudam sozinhos (ex.: ultima busca na API)
function ajuste(string $chave): ?string
{
    $st = track_db()->prepare('SELECT valor FROM ajustes WHERE chave = ?');
    $st->execute([$chave]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

function definir_ajuste(string $chave, ?string $valor): void
{
    track_db()->prepare('INSERT INTO ajustes (chave, valor) VALUES (?, ?) ON CONFLICT (chave) DO UPDATE SET valor = excluded.valor')
        ->execute([$chave, $valor]);
}

function track_migrar_v1(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS visitantes (
            id          TEXT PRIMARY KEY,
            criado_em   TEXT NOT NULL,
            visto_em    TEXT NOT NULL,
            dispositivo TEXT,
            sistema     TEXT,
            navegador   TEXT,
            ip          TEXT
        );
        CREATE TABLE IF NOT EXISTS eventos (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            visitante    TEXT NOT NULL,
            em           TEXT NOT NULL,
            dominio      TEXT NOT NULL,
            pagina       TEXT NOT NULL,
            nome         TEXT NOT NULL,
            detalhe      TEXT,
            utm_source   TEXT,
            utm_medium   TEXT,
            utm_campaign TEXT,
            utm_content  TEXT,
            utm_term     TEXT,
            referrer     TEXT,
            ip           TEXT,
            dispositivo  TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_eventos_visitante ON eventos (visitante, em);
        CREATE INDEX IF NOT EXISTS idx_eventos_pagina ON eventos (dominio, pagina, em);
        CREATE TABLE IF NOT EXISTS vendas (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            pedido        TEXT NOT NULL UNIQUE,
            evento        TEXT,
            status        TEXT,
            produto       TEXT,
            valor         INTEGER,
            pagamento     TEXT,
            recebida_em   TEXT NOT NULL,
            atualizada_em TEXT NOT NULL,
            visitante     TEXT,
            sck           TEXT,
            src           TEXT,
            utm_source    TEXT,
            utm_medium    TEXT,
            utm_campaign  TEXT,
            utm_content   TEXT,
            utm_term      TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_vendas_visitante ON vendas (visitante);
        CREATE TABLE IF NOT EXISTS limites (
            chave TEXT NOT NULL,
            em    INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_limites ON limites (chave, em);
        PRAGMA user_version = 1;
        SQL);
}
