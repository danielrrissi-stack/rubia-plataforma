<?php
declare(strict_types=1);

namespace App\Certificates;

use App\Core\View;
use App\Medications\PrescriptionPrintController;

/**
 * Valida os campos de cada tipo e monta o documento completo (cabecalho, corpo e rodape) que fica
 * gravado no atestado. Todo texto vindo do usuario passa por e().
 */
final class CertificateText
{
    public const AUTH_SENTENCE = 'Documento emitido mediante autorização expressa do paciente ou de seu representante legal para inclusão do diagnóstico codificado (CID).';
    private const CID_PATTERN = '/^[A-Z][0-9]{2}(\.[0-9A-Z]{1,2})?$/';
    private const TIME_PATTERN = '/^([01][0-9]|2[0-3]):[0-5][0-9]$/';
    private const UNITS = ['', 'um', 'dois', 'três', 'quatro', 'cinco', 'seis', 'sete', 'oito', 'nove',
        'dez', 'onze', 'doze', 'treze', 'quatorze', 'quinze', 'dezesseis', 'dezessete', 'dezoito', 'dezenove'];
    private const TENS = [2 => 'vinte', 'trinta', 'quarenta', 'cinquenta', 'sessenta', 'setenta', 'oitenta', 'noventa'];

    /**
     * @param array<string,mixed> $in campos do formulario
     * @return array{0: array<string,mixed>, 1: list<string>}
     */
    public static function validate(string $type, array $in, string $today): array
    {
        if (!CertificateType::valid($type)) {
            return [[], ['Tipo de atestado inválido.']];
        }
        $s = static fn (string $k): string => is_string($in[$k] ?? null) ? trim($in[$k]) : '';
        $errors = [];
        $clean = ['data' => null, 'inicio' => null, 'fim' => null, 'dias' => null, 'a_partir' => null,
            'acompanhante_nome' => null, 'acompanhante_cpf' => null, 'cid' => null, 'observacao' => null];

        $data = $s('data');
        if (!self::isDate($data)) {
            $errors[] = 'Informe a data do atendimento.';
        } elseif ($data > $today) {
            $errors[] = 'A data não pode ser no futuro.';
        } else {
            $clean['data'] = $data;
        }

        if (in_array($type, CertificateType::WITH_PERIOD, true)) {
            $ini = $s('inicio');
            $fim = $s('fim');
            if (!preg_match(self::TIME_PATTERN, $ini) || !preg_match(self::TIME_PATTERN, $fim)) {
                $errors[] = 'Informe o horário de início e de término.';
            } elseif ($fim <= $ini) {
                $errors[] = 'O horário de término deve ser depois do início.';
            } else {
                $clean['inicio'] = $ini;
                $clean['fim'] = $fim;
            }
        }

        if ($type === 'afastamento') {
            $dias = $s('dias');
            if (!ctype_digit($dias) || (int) $dias < 1 || (int) $dias > 90) {
                $errors[] = 'Informe de 1 a 90 dias de afastamento.';
            } else {
                $clean['dias'] = (int) $dias;
            }
            $start = $s('a_partir') !== '' ? $s('a_partir') : (string) $clean['data'];
            if (!self::isDate($start)) {
                $errors[] = 'Informe a data de início do afastamento.';
            } elseif ($clean['data'] !== null && $start < $clean['data']) {
                $errors[] = 'O afastamento deve começar na data do atendimento ou depois.';
            } else {
                $clean['a_partir'] = $start;
            }
        }

        if ($type === 'acompanhante') {
            $nome = $s('acompanhante_nome');
            if ($nome === '' || mb_strlen($nome) > 150) {
                $errors[] = 'Informe o nome do acompanhante (até 150 caracteres).';
            } else {
                $clean['acompanhante_nome'] = $nome;
            }
            $cpf = preg_replace('/\D/', '', $s('acompanhante_cpf'));
            if ($s('acompanhante_cpf') !== '' && strlen((string) $cpf) !== 11) {
                $errors[] = 'CPF do acompanhante inválido (11 dígitos).';
            } elseif ($cpf !== '') {
                $clean['acompanhante_cpf'] = $cpf;
            }
        }

        if (in_array($type, CertificateType::WITH_CID, true)) {
            $cid = strtoupper($s('cid'));
            if ($cid !== '') {
                if (!preg_match(self::CID_PATTERN, $cid)) {
                    $errors[] = 'CID inválido (ex.: E66.9).';
                } elseif ($s('cid_autorizado') === '') {
                    $errors[] = 'Marque a autorização do paciente ou responsável para incluir o CID.';
                } else {
                    $clean['cid'] = $cid;
                }
            }
        }

        $obs = $s('observacao');
        if (mb_strlen($obs) > 500) {
            $errors[] = 'A observação deve ter até 500 caracteres.';
        } elseif ($obs !== '') {
            $clean['observacao'] = $obs;
        }

        return [$clean, $errors];
    }

    /** 1 a 90 por extenso. */
    public static function numberWords(int $n): string
    {
        if ($n < 1 || $n > 99) {
            throw new \InvalidArgumentException('Fora da faixa: ' . $n);
        }
        if ($n < 20) {
            return self::UNITS[$n];
        }
        $t = self::TENS[intdiv($n, 10)];
        return $n % 10 === 0 ? $t : $t . ' e ' . self::UNITS[$n % 10];
    }

    /**
     * Documento completo (HTML ja escapado) que fica gravado em certificates.body_html.
     * @param array<string,mixed> $c saida de validate()
     * @param array<string,mixed> $doctor linha de users (name, specialty, professional_register, rqe)
     * @param array<string,string> $head configuracoes da receita (rx_city, rx_phone, rx_address)
     */
    public static function document(string $type, array $c, array $patient, array $doctor, array $head, string $today): string
    {
        $creds = array_values(array_filter([
            trim((string) ($doctor['specialty'] ?? '')),
            trim((string) ($doctor['professional_register'] ?? '')),
            self::rqe((string) ($doctor['rqe'] ?? '')),
        ], static fn (string $v): bool => $v !== ''));
        $sign = array_values(array_filter([
            trim((string) ($doctor['professional_register'] ?? '')),
            self::rqe((string) ($doctor['rqe'] ?? '')),
        ], static fn (string $v): bool => $v !== ''));
        $city = trim((string) ($head['rx_city'] ?? ''));
        return View::render('Certificates/views/_doc.php', [
            'doctorName' => (string) $doctor['name'],
            'credentials' => implode(' | ', $creds),
            'signCredentials' => implode(' | ', $sign),
            'title' => mb_strtoupper(CertificateType::TITLES[$type]),
            'paragraphs' => self::paragraphs($type, $c, $patient, $today),
            'dateLine' => ($city !== '' ? $city . ', ' : '') . PrescriptionPrintController::longDate($today) . '.',
            'phone' => trim((string) ($head['rx_phone'] ?? '')),
            'address' => trim((string) ($head['rx_address'] ?? '')),
        ], null);
    }

    /** @return list<string> paragrafos do corpo (HTML) */
    private static function paragraphs(string $type, array $c, array $patient, string $today): array
    {
        $female = ($patient['sex'] ?? '') === 'F';
        $who = ($female ? 'a paciente ' : 'o paciente ') . self::b(self::patientName($patient)) . self::cpfClause((string) ($patient['cpf'] ?? ''));
        $when = ($c['data'] === $today ? 'nesta data, ' : 'em ') . self::b(self::dmy((string) $c['data']));
        $period = $c['inicio'] !== null ? ', no período das ' . self::b(self::hm((string) $c['inicio']) . ' às ' . self::hm((string) $c['fim'])) : '';

        $p = match ($type) {
            'comparecimento' => [
                'Declaro, para os devidos fins, que ' . $who . ', esteve em atendimento médico ' . $when . $period . '.',
                'Esta declaração comprova o comparecimento ao atendimento médico, não constituindo recomendação de afastamento das atividades por período adicional.',
            ],
            'acompanhante' => [
                'Declaro, para os devidos fins, que ' . self::b((string) $c['acompanhante_nome']) . self::cpfClause((string) ($c['acompanhante_cpf'] ?? ''))
                    . ', esteve presente como acompanhante d' . $who . ', em atendimento médico ' . $when . $period . '.',
                'Esta declaração comprova o acompanhamento ao atendimento médico, não constituindo recomendação de afastamento das atividades por período adicional.',
            ],
            'afastamento' => [
                'Atesto, para os devidos fins, que ' . $who . ', esteve em atendimento médico ' . $when . ', e necessita de '
                    . self::b($c['dias'] . ' (' . self::numberWords((int) $c['dias']) . ') ' . ((int) $c['dias'] === 1 ? 'dia' : 'dias'))
                    . ' de afastamento de suas atividades a partir de ' . self::b(self::dmy((string) $c['a_partir'])) . '.',
            ],
            'aptidao' => [
                'Atesto, para os devidos fins, que ' . $who . ', foi ' . ($female ? 'examinada' : 'examinado') . ' ' . $when
                    . ', e não apresenta, até o momento, contraindicação clínica para a prática de atividades físicas.',
            ],
        };
        if ($c['cid'] !== null) {
            $p[] = '<strong>CID-10:</strong> ' . e((string) $c['cid']);
        }
        if ($c['observacao'] !== null) {
            $p[] = nl2br(e((string) $c['observacao']));
        }
        if ($c['cid'] !== null) {
            $p[] = self::AUTH_SENTENCE;
        }
        return $p;
    }

    /** Nome civil (vai com o CPF); com nome social: "Social (nome civil: Civil)". */
    public static function patientName(array $patient): string
    {
        $civil = trim((string) $patient['full_name']);
        $social = trim((string) ($patient['social_name'] ?? ''));
        return $social !== '' && $social !== $civil ? $social . ' (nome civil: ' . $civil . ')' : $civil;
    }

    private static function cpfClause(string $cpf): string
    {
        $cpf = trim($cpf);
        if ($cpf === '') {
            return '';
        }
        $digits = (string) preg_replace('/\D/', '', $cpf);
        $shown = strlen($digits) === 11
            ? substr($digits, 0, 3) . '.' . substr($digits, 3, 3) . '.' . substr($digits, 6, 3) . '-' . substr($digits, 9, 2)
            : $cpf;
        return ', CPF nº ' . self::b($shown);
    }

    private static function rqe(string $rqe): string
    {
        $rqe = trim($rqe);
        return $rqe === '' || stripos($rqe, 'RQE') === 0 ? $rqe : 'RQE ' . $rqe;
    }

    private static function b(string $text): string
    {
        return '<strong>' . e($text) . '</strong>';
    }

    private static function dmy(string $ymd): string
    {
        [$y, $m, $d] = explode('-', $ymd);
        return $d . '/' . $m . '/' . $y;
    }

    private static function hm(string $hm): string
    {
        return str_replace(':', 'h', $hm);
    }

    private static function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
