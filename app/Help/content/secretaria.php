<?php
declare(strict_types=1);

/** Manual da secretaria: cadastro, responsaveis e agenda. */
$e = (require __DIR__ . '/_equipe.php')('secretaria', 'secretaria');

return [
    $e['entrar'],
    $e['pacientes'],
    $e['ficha'],
    $e['cadastrar'],
    $e['responsaveis'],
    $e['agenda'],
    $e['horarios'],
    $e['ditado'],
];
