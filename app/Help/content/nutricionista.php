<?php
declare(strict_types=1);

/** Manual da nutricionista: so os pacientes liberados e as partes de nutricao e acompanhamento. */
$e = (require __DIR__ . '/_equipe.php')('nutricionista', 'nutricionista');
$c = (require __DIR__ . '/_clinica.php')('nutricionista', 'nutricionista');
$m = (require __DIR__ . '/_modelos.php')('nutricionista', 'nutricionista');

return [
    $e['entrar'],
    $e['pacientes'],
    $e['ficha'],
    $c['crescimento'],
    $c['energia'],
    $c['plano'],
    ...$m,
    $c['metas'],
    $c['biblioteca'],
    $c['diario'],
    $c['linha-do-tempo'],
    $c['meta-peso'],
    $e['agenda'],
    $e['horarios'],
    $e['ditado'],
];
