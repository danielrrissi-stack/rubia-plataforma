<?php
declare(strict_types=1);

namespace App\Letterhead;

use App\Core\View;

/**
 * Folha A4 timbrada para preencher a mao. Nada e gravado.
 * O desenho (logo com o nome da clinica, ondas, frase) e fixo em art/fundo.svg; so os textos variam.
 */
final class LetterheadDoc
{
    public const SITE = 'www.rubiapediatra.com.br';
    public const APP = 'rubia.med.br';

    /**
     * @param array<string,mixed> $doctor linha de users (name, specialty, professional_register, rqe)
     * @param array<string,string> $head PrescriptionPrintController::headSettings()
     * @param ?array<string,mixed> $patient null = papel em branco
     */
    public static function html(string $type, array $doctor, array $head, ?array $patient, string $today): string
    {
        $creds = array_values(array_filter([
            self::prefixed('CRM', (string) ($doctor['professional_register'] ?? '')),
            self::prefixed('RQE', (string) ($doctor['rqe'] ?? '')),
        ], static fn (string $v): bool => $v !== ''));
        $name = trim((string) ($doctor['name'] ?? ''));
        $address = trim((string) ($head['rx_address'] ?? ''));
        $city = trim((string) ($head['rx_city'] ?? ''));
        if ($city !== '' && mb_stripos($address, $city) !== false) {
            $city = '';
        }
        $phone = trim((string) ($head['rx_phone'] ?? ''));
        $place = mb_strlen($address) + mb_strlen($city);
        $patientName = $patient === null ? '' : (string) ($patient['social_name'] ?: $patient['full_name']);
        return View::render('Letterhead/views/_doc.php', [
            'title' => PaperType::TITLES[$type],
            'background' => self::art('fundo'),
            'icons' => ['whatsapp' => self::art('whatsapp'), 'local' => self::art('local'), 'site' => self::art('site')],
            'doctorName' => $name,
            'doctorSize' => self::fit($name, 44, 13.0, 8.0),
            'specialty' => trim((string) ($doctor['specialty'] ?? '')),
            'credentials' => $creds,
            'phone' => $phone,
            'phoneSize' => self::fit($phone, 22, 12.0, 8.0),
            'address' => $address,
            'city' => $city,
            'addressSize' => self::sizeClass($place <= 90 ? 10.0 : ($place <= 180 ? 7.5 : 6.5)),
            'patientName' => $patientName,
            'patientSize' => self::fit($patientName, 50, 12.0, 8.0),
            'date' => $patient === null ? null : self::parts($today),
            'birth' => $patient === null ? null : self::parts((string) $patient['birth_date']),
        ], null);
    }

    public static function cssFile(): string
    {
        return __DIR__ . '/papel.css';
    }

    public static function fontDir(): string
    {
        return APP_ROOT . '/lib/papel-fonts';
    }

    /** Classe com o corpo da letra para o texto caber na linha: reduz de $max a partir de $chars caracteres, ate $min (pt). */
    private static function fit(string $text, int $chars, float $max, float $min): string
    {
        $weight = mb_strlen($text) + 0.3 * (int) preg_match_all('/\p{Lu}/u', $text);
        return self::sizeClass($weight <= $chars ? $max : max($min, $max * $chars / $weight));
    }

    /** Views nao usam style="": os corpos de 6,5 a 13 pt (de meio em meio) sao classes do papel.css. */
    private static function sizeClass(float $pt): string
    {
        return 'pt-c' . 5 * (int) floor($pt * 2);
    }

    /** @return array{0:string,1:string,2:string} dia, mes, ano */
    private static function parts(string $ymd): array
    {
        [$y, $m, $d] = explode('-', substr($ymd, 0, 10)) + ['', '', ''];
        return [$d, $m, $y];
    }

    private static function prefixed(string $prefix, string $value): string
    {
        $value = trim($value);
        return $value === '' || stripos($value, $prefix) === 0 ? $value : $prefix . ' ' . $value;
    }

    private static function art(string $name): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode((string) file_get_contents(__DIR__ . '/art/' . $name . '.svg'));
    }
}
