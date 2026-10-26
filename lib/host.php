<?php
// Extensão visual opcional do site hospedeiro. Só inclui um caminho fixo do servidor.
declare(strict_types=1);

$trackHostUi = dirname(__DIR__, 2) . '/utm-ui.php';
if (is_file($trackHostUi)) {
    require_once $trackHostUi;
}
unset($trackHostUi);
