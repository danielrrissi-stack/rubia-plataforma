<?php
declare(strict_types=1);

/** Manual do responsavel (portal da familia). */
$familia = (require __DIR__ . '/_familia.php')('responsavel', 'responsavel', false);

return [
    $familia['entrar'],
];
