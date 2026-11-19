<?php
declare(strict_types=1);

/** Manual do responsavel (portal da familia). */
$f = (require __DIR__ . '/_familia.php')('responsavel', 'responsavel', false);

return [
    $f['entrar'],
    $f['escolher'],
    $f['inicio'],
    $f['pesagem'],
    $f['crescimento'],
    $f['alimentacao'],
    $f['exames'],
    $f['tratamento'],
    $f['metas'],
    $f['diario'],
    $f['conta'],
];
