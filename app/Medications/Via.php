<?php
declare(strict_types=1);

namespace App\Medications;

/** Vias de administracao: valores do ENUM, rotulos de tela e cabecalhos da receita. */
final class Via
{
    public const ROUTES = [
        'oral', 'sublingual', 'subcutanea', 'intramuscular', 'topica', 'inalatoria',
        'nasal', 'oftalmica', 'otologica', 'retal', 'vaginal', 'outra',
    ];

    public const LABELS = [
        'oral' => 'Oral', 'sublingual' => 'Sublingual', 'subcutanea' => 'Subcutânea',
        'intramuscular' => 'Intramuscular', 'topica' => 'Tópica', 'inalatoria' => 'Inalatória',
        'nasal' => 'Nasal', 'oftalmica' => 'Oftálmica', 'otologica' => 'Otológica',
        'retal' => 'Retal', 'vaginal' => 'Vaginal', 'outra' => 'Outra',
    ];

    public const RX_HEADINGS = [
        'oral' => 'Uso oral', 'sublingual' => 'Uso sublingual', 'subcutanea' => 'Uso subcutâneo',
        'intramuscular' => 'Uso intramuscular', 'topica' => 'Uso tópico', 'inalatoria' => 'Uso inalatório',
        'nasal' => 'Uso nasal', 'oftalmica' => 'Uso oftálmico', 'otologica' => 'Uso otológico',
        'retal' => 'Uso retal', 'vaginal' => 'Uso vaginal', 'outra' => 'Outras vias',
    ];

    private function __construct()
    {
    }
}
