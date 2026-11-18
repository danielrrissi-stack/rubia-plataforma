<?php
declare(strict_types=1);

namespace App\Diary;

/** Pedido, registro ou comentario do diario invalido; `errors()` traz mensagens em pt-BR. */
final class InvalidDiaryException extends \RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(private array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
