<?php
declare(strict_types=1);

namespace App\Certificates;

/** Atestado invalido (ou anulacao recusada); `errors` traz mensagens em pt-BR. */
final class InvalidCertificateException extends \RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
