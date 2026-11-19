<?php
declare(strict_types=1);

/** Manual do paciente adulto (portal da familia, falando "você"). */
$f = (require __DIR__ . '/_familia.php')('paciente', 'paciente', true);

return [
    $f['entrar'],
    $f['inicio'],
    $f['pesagem'],
    $f['crescimento'],
    $f['alimentacao'],
    $f['exames'],
    $f['tratamento'],
    $f['metas'],
    $f['conta'],
];
