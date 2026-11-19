<?php
declare(strict_types=1);

/** Manual do perfil secretaria. */
$equipe = (require __DIR__ . '/_equipe.php')('secretaria', 'secretaria');

return [
    $equipe['entrar'],
];
