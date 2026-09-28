<?php
require __DIR__ . '/lib/util.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && csrf_valido()) {
    $_SESSION = [];
    session_destroy();
}
header('Location: entrar.php');
