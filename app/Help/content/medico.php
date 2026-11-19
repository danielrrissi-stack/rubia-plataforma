<?php
declare(strict_types=1);

/** Manual do perfil medico. */
$equipe = (require __DIR__ . '/_equipe.php')('medica', 'medico');

return [
    $equipe['entrar'],
];
