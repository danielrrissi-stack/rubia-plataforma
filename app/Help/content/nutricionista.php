<?php
declare(strict_types=1);

/** Manual do perfil nutricionista. */
$equipe = (require __DIR__ . '/_equipe.php')('nutricionista', 'nutricionista');

return [
    $equipe['entrar'],
];
