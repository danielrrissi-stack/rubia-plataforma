<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Certificates\CertificateText;
use PHPUnit\Framework\TestCase;

final class CertificateTextTest extends TestCase
{
    private const TODAY = '2026-11-19';
    private const DOCTOR = ['name' => 'Dra. Rúbia Carvalho', 'specialty' => 'Pediatra', 'professional_register' => 'CRM-SP 113058', 'rqe' => '107605'];
    private const HEAD = ['rx_city' => 'Campinas', 'rx_phone' => '(19) 0000-0000', 'rx_address' => 'Rua Exemplo, 10'];
    private const VISIT = ['data' => '2026-11-19', 'inicio' => '08:30', 'fim' => '09:15'];

    private function patient(array $over = []): array
    {
        return $over + ['full_name' => 'Lia Souza', 'social_name' => null, 'cpf' => '12345678901', 'sex' => 'F'];
    }

    private function ok(string $type, array $in): array
    {
        [$clean, $errors] = CertificateText::validate($type, $in, self::TODAY);
        $this->assertSame([], $errors);
        return $clean;
    }

    private function errors(string $type, array $in): array
    {
        return CertificateText::validate($type, $in, self::TODAY)[1];
    }

    private function doc(string $type, array $in, array $patient = []): string
    {
        return CertificateText::document($type, $this->ok($type, $in), $this->patient($patient), self::DOCTOR, self::HEAD, self::TODAY);
    }

    public function test_number_words(): void
    {
        $want = [1 => 'um', 2 => 'dois', 16 => 'dezesseis', 21 => 'vinte e um', 30 => 'trinta', 45 => 'quarenta e cinco', 90 => 'noventa'];
        foreach ($want as $n => $w) {
            $this->assertSame($w, CertificateText::numberWords($n));
        }
    }

    public function test_unknown_type(): void
    {
        $this->assertSame(['Tipo de atestado inválido.'], $this->errors('xx', self::VISIT));
    }

    public function test_cid_without_consent_is_error(): void
    {
        $this->assertContains('Marque a autorização do paciente ou responsável para incluir o CID.',
            $this->errors('comparecimento', self::VISIT + ['cid' => 'E66.9']));
        $clean = $this->ok('comparecimento', self::VISIT + ['cid' => ' e66.9 ', 'cid_autorizado' => '1']);
        $this->assertSame('E66.9', $clean['cid']);
    }

    public function test_bad_cid_format(): void
    {
        $this->assertContains('CID inválido (ex.: E66.9).', $this->errors('afastamento', ['data' => self::TODAY, 'dias' => '2', 'cid' => '66', 'cid_autorizado' => '1']));
    }

    public function test_aptidao_ignores_cid(): void
    {
        $clean = $this->ok('aptidao', ['data' => self::TODAY, 'cid' => 'E66.9', 'cid_autorizado' => '1']);
        $this->assertNull($clean['cid']);
    }

    public function test_days_out_of_range(): void
    {
        foreach (['0', '91', 'abc', ''] as $d) {
            $this->assertContains('Informe de 1 a 90 dias de afastamento.', $this->errors('afastamento', ['data' => self::TODAY, 'dias' => $d]), $d);
        }
    }

    public function test_afastamento_start_not_before_visit(): void
    {
        $this->assertContains('O afastamento deve começar na data do atendimento ou depois.',
            $this->errors('afastamento', ['data' => self::TODAY, 'dias' => '2', 'a_partir' => '2026-11-18']));
        $this->assertSame(self::TODAY, $this->ok('afastamento', ['data' => self::TODAY, 'dias' => '2'])['a_partir']);
    }

    public function test_end_before_start(): void
    {
        $this->assertContains('O horário de término deve ser depois do início.', $this->errors('comparecimento', ['data' => self::TODAY, 'inicio' => '09:00', 'fim' => '08:00']));
        $this->assertContains('Informe o horário de início e de término.', $this->errors('acompanhante', ['data' => self::TODAY, 'acompanhante_nome' => 'Ana']));
    }

    public function test_future_and_missing_date(): void
    {
        $this->assertContains('A data não pode ser no futuro.', $this->errors('aptidao', ['data' => '2026-11-20']));
        $this->assertContains('Informe a data do atendimento.', $this->errors('aptidao', ['data' => '19/11/2026']));
    }

    public function test_companion_fields(): void
    {
        $this->assertContains('Informe o nome do acompanhante (até 150 caracteres).', $this->errors('acompanhante', self::VISIT));
        $this->assertContains('CPF do acompanhante inválido (11 dígitos).', $this->errors('acompanhante', self::VISIT + ['acompanhante_nome' => 'Ana', 'acompanhante_cpf' => '123']));
    }

    public function test_observation_limit(): void
    {
        $this->assertContains('A observação deve ter até 500 caracteres.', $this->errors('aptidao', ['data' => self::TODAY, 'observacao' => str_repeat('a', 501)]));
    }

    public function test_comparecimento_text(): void
    {
        $html = $this->doc('comparecimento', self::VISIT);
        $this->assertStringContainsString('DECLARAÇÃO DE COMPARECIMENTO', $html);
        $this->assertStringContainsString('Declaro, para os devidos fins, que a paciente <strong>Lia Souza</strong>, CPF nº <strong>123.456.789-01</strong>, esteve em atendimento médico nesta data, <strong>19/11/2026</strong>, no período das <strong>08h30 às 09h15</strong>.', $html);
        $this->assertStringContainsString('Esta declaração comprova o comparecimento ao atendimento médico, não constituindo recomendação de afastamento das atividades por período adicional.', $html);
    }

    public function test_past_date_uses_em(): void
    {
        $html = $this->doc('comparecimento', ['data' => '2026-11-18'] + self::VISIT, ['sex' => 'M']);
        $this->assertStringContainsString('que o paciente <strong>Lia Souza</strong>', $html);
        $this->assertStringContainsString('esteve em atendimento médico em <strong>18/11/2026</strong>', $html);
    }

    public function test_no_cpf_omits_clause(): void
    {
        $html = $this->doc('aptidao', ['data' => self::TODAY], ['cpf' => null]);
        $this->assertStringNotContainsString('CPF', $html);
        $this->assertStringContainsString('que a paciente <strong>Lia Souza</strong>, foi examinada nesta data, <strong>19/11/2026</strong>, e não apresenta, até o momento, contraindicação clínica para a prática de atividades físicas.', $html);
    }

    public function test_social_name(): void
    {
        $html = $this->doc('aptidao', ['data' => self::TODAY], ['social_name' => 'Lia', 'full_name' => 'Lia Souza']);
        $this->assertStringContainsString('<strong>Lia (nome civil: Lia Souza)</strong>', $html);
    }

    public function test_cid_lines_only_with_cid(): void
    {
        $auth = 'Documento emitido mediante autorização expressa do paciente ou de seu representante legal para inclusão do diagnóstico codificado (CID).';
        $without = $this->doc('comparecimento', self::VISIT);
        $this->assertStringNotContainsString('CID-10', $without);
        $this->assertStringNotContainsString($auth, $without);
        $with = $this->doc('comparecimento', self::VISIT + ['cid' => 'E66.9', 'cid_autorizado' => '1']);
        $this->assertStringContainsString('<strong>CID-10:</strong> E66.9', $with);
        $this->assertStringContainsString($auth, $with);
    }

    public function test_observation_escaped(): void
    {
        $html = $this->doc('aptidao', ['data' => self::TODAY, 'observacao' => "<script>x</script>\nlinha 2"]);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;x&lt;/script&gt;<br />', $html);
    }

    public function test_afastamento_plural(): void
    {
        $one = $this->doc('afastamento', ['data' => self::TODAY, 'dias' => '1']);
        $this->assertStringContainsString('ATESTADO MÉDICO', $one);
        $this->assertStringContainsString('Atesto, para os devidos fins, que a paciente <strong>Lia Souza</strong>, CPF nº <strong>123.456.789-01</strong>, esteve em atendimento médico nesta data, <strong>19/11/2026</strong>, e necessita de <strong>1 (um) dia</strong> de afastamento de suas atividades a partir de <strong>19/11/2026</strong>.', $one);
        $three = $this->doc('afastamento', ['data' => self::TODAY, 'dias' => '3', 'a_partir' => '2026-11-20']);
        $this->assertStringContainsString('<strong>3 (três) dias</strong> de afastamento de suas atividades a partir de <strong>20/11/2026</strong>.', $three);
    }

    public function test_acompanhante_text(): void
    {
        $html = $this->doc('acompanhante', self::VISIT + ['acompanhante_nome' => 'Ana Souza', 'acompanhante_cpf' => '987.654.321-00']);
        $this->assertStringContainsString('DECLARAÇÃO DE ACOMPANHANTE', $html);
        $this->assertStringContainsString('Declaro, para os devidos fins, que <strong>Ana Souza</strong>, CPF nº <strong>987.654.321-00</strong>, esteve presente como acompanhante da paciente <strong>Lia Souza</strong>, CPF nº <strong>123.456.789-01</strong>, em atendimento médico nesta data, <strong>19/11/2026</strong>, no período das <strong>08h30 às 09h15</strong>.', $html);
        $this->assertStringContainsString('Esta declaração comprova o acompanhamento ao atendimento médico, não constituindo recomendação de afastamento das atividades por período adicional.', $html);
    }

    public function test_header_footer(): void
    {
        $html = $this->doc('aptidao', ['data' => self::TODAY]);
        $this->assertStringContainsString('DRA. RÚBIA CARVALHO', $html);
        $this->assertStringContainsString('Pediatra | CRM-SP 113058 | RQE 107605', $html);
        $this->assertStringContainsString('Campinas, 19 de novembro de 2026.', $html);
        $this->assertStringContainsString('Assinatura e carimbo', $html);
        $this->assertStringContainsString('Telefone: (19) 0000-0000', $html);
        $this->assertStringContainsString('Endereço: Rua Exemplo, 10', $html);
        $bare = CertificateText::document('aptidao', $this->ok('aptidao', ['data' => self::TODAY]), $this->patient(),
            ['name' => 'Dra. X', 'specialty' => null, 'professional_register' => 'CRM 1', 'rqe' => null], ['rx_city' => '', 'rx_phone' => '', 'rx_address' => ''], self::TODAY);
        $this->assertStringContainsString('<p class="atestado-data">19 de novembro de 2026.</p>', $bare);
        $this->assertStringNotContainsString('Telefone:', $bare);
        $this->assertStringNotContainsString('RQE', $bare);
        $this->assertStringNotContainsString(' | ', $bare);
    }
}
