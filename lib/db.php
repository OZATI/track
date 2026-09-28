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
    if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() >= 1) {
        return;
    }
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
