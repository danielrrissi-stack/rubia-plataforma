<?php
declare(strict_types=1);

/** Manual do paciente adulto (portal da familia, falando "você"). */
$familia = (require __DIR__ . '/_familia.php')('paciente', 'paciente', true);

return [
    $familia['entrar'],
];
