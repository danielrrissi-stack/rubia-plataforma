<?php
declare(strict_types=1);

namespace App\Certificates;

/** Os quatro tipos de atestado/declaracao (redacao fixa em CertificateText). */
final class CertificateType
{
    public const TITLES = [
        'comparecimento' => 'Declaração de comparecimento',
        'acompanhante' => 'Declaração de acompanhante',
        'afastamento' => 'Atestado médico',
        'aptidao' => 'Atestado de aptidão para atividade física',
    ];

    /** Rotulo curto para botoes e listas. */
    public const LABELS = [
        'comparecimento' => 'Comparecimento',
        'acompanhante' => 'Acompanhante',
        'afastamento' => 'Afastamento',
        'aptidao' => 'Aptidão para atividade física',
    ];

    /** Tipos com horario do atendimento. */
    public const WITH_PERIOD = ['comparecimento', 'acompanhante'];

    /** Tipos que aceitam CID. */
    public const WITH_CID = ['comparecimento', 'acompanhante', 'afastamento'];

    public static function valid(string $type): bool
    {
        return isset(self::TITLES[$type]);
    }
}
