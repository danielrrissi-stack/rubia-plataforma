<?php
declare(strict_types=1);

namespace App\Letterhead;

/** Tipos de papel timbrado (slug da URL => titulo impresso e texto do botao). */
final class PaperType
{
    public const TITLES = ['receita' => 'Receita', 'exames' => 'Pedido de Exames', 'anotacoes' => 'Anotações'];
}
