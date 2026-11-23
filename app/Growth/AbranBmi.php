<?php
declare(strict_types=1);

namespace App\Growth;

/**
 * Faixa "Normal" do IMC da calculadora infantil da ABRAN (6 a 15 anos completos), so para comparacao.
 * Fonte: https://abran.org.br/calculadoras/imc-infantil, tabelas "Interpretacao do IMC", lidas em 23/11/2026.
 * O minimo fica perto da mediana da OMS (escore Z ~0): NAO usar para classificar.
 */
final class AbranBmi
{
    /** Idade completa => [minimo, maximo]. Meninos de 8 anos: a pagina traz "15,6 e 16,7", menor que aos 7 anos (a conferir), por isso fora. */
    private const NORMAL = [
        'M' => [6 => [14.5, 16.6], 7 => [15.0, 17.3], 9 => [16.1, 18.8], 10 => [16.7, 19.6], 11 => [17.2, 20.3],
            12 => [17.8, 21.1], 13 => [18.5, 21.9], 14 => [19.2, 22.7], 15 => [19.9, 23.6]],
        'F' => [6 => [14.3, 16.1], 7 => [14.9, 17.1], 8 => [15.6, 18.1], 9 => [16.3, 19.1], 10 => [17.0, 20.1], 11 => [17.6, 21.1],
            12 => [18.3, 22.1], 13 => [18.9, 23.0], 14 => [19.3, 23.8], 15 => [19.6, 24.2]],
    ];

    /** @return ?array{0:float,1:float} IMC minimo e maximo; null fora da tabela */
    public static function normal(string $sex, int $years): ?array
    {
        return self::NORMAL[$sex][$years] ?? null;
    }
}
