<?php
declare(strict_types=1);

namespace App\Evolution;

final class InvalidEvolutionSettingsException extends \RuntimeException
{
    /** @param array<string,string> $errors campo => mensagem (modo|kg|pct|z) */
    public function __construct(public array $errors)
    {
        parent::__construct('Critério inválido.');
    }
}
