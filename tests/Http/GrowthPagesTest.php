<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\App;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Growth\Lms;
use Tests\DbTestCase;

final class GrowthPagesTest extends DbTestCase
{
    private function get(string $path, array $q = []): Response
    {
        return App::create()->handle(new Request('GET', $path, $q));
    }

    private function post(string $path, array $data = []): Response
    {
        $data['_csrf'] = Csrf::token();
        return App::create()->handle(new Request('POST', $path, [], $data, [], ['REMOTE_ADDR' => '127.0.0.1']));
    }

    private function loginAs(array $u): void
    {
        $this->db->run('UPDATE users SET totp_enabled = 1 WHERE id = ?', [$u['id']]);
        Container::auth()->completeLogin((int) $u['id'], new Request('POST', '/login'));
    }

    private function form(array $over = []): array
    {
        return $over + ['data_medida' => date('d/m/Y'), 'peso' => '32,5', 'altura' => '130,0', 'posicao' => 'em_pe'];
    }

    private function childPatient(string $birth, string $sex = 'F'): array
    {
        return $this->makePatient(['birth_date' => $birth, 'sex' => $sex]);
    }

    public function testPostSavesWeightWithComma(): void
    {
        $med = $this->makeUser('medico');
        $p = $this->childPatient('2015-03-10');
        $this->loginAs($med);
        $res = $this->post('/pacientes/' . $p['id'] . '/medidas/nova', $this->form());
        $this->assertSame(302, $res->status);
        $row = $this->db->one('SELECT * FROM measurements WHERE patient_id = ?', [$p['id']]);
        $this->assertSame('32.500', $row['weight_kg']);
        $this->assertSame('equipe', $row['source']);
    }

    public function testDateBeforeBirthRejected(): void
    {
        $med = $this->makeUser('medico');
        $p = $this->childPatient('2015-03-10');
        $this->loginAs($med);
        $res = $this->post('/pacientes/' . $p['id'] . '/medidas/nova', $this->form(['data_medida' => '09/03/2015']));
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('A data da medida não pode ser anterior ao nascimento', $res->body);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM measurements')['c']);
    }

    public function testFutureDateAndBpRejected(): void
    {
        $med = $this->makeUser('medico');
        $p = $this->childPatient('2015-03-10');
        $this->loginAs($med);
        $res = $this->post('/pacientes/' . $p['id'] . '/medidas/nova', $this->form(['data_medida' => date('d/m/Y', strtotime('+3 days'))]));
        $this->assertStringContainsString('não pode ser futura', $res->body);
        $res = $this->post('/pacientes/' . $p['id'] . '/medidas/nova', $this->form(['sistolica' => '80', 'diastolica' => '90']));
        $this->assertStringContainsString('sistólica deve ser maior', $res->body);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) c FROM measurements')['c']);
    }

    public function testNutritionistWithoutGrantGets403OnCurvasAndPage(): void
    {
        $p = $this->childPatient('2015-03-10');
        $this->loginAs($this->makeUser('nutricionista'));
        $this->assertSame(403, $this->get('/pacientes/' . $p['id'] . '/curvas.json', ['indicador' => 'bfa'])->status);
        $this->assertSame(403, $this->get('/pacientes/' . $p['id'] . '/crescimento')->status);
        $this->assertSame(403, $this->post('/pacientes/' . $p['id'] . '/medidas/nova', $this->form())->status);
    }

    public function testFamilyCannotUseClinicalWriteRoutes(): void
    {
        $p = $this->childPatient('2015-03-10');
        $staff = $this->makeUser('medico');
        $mid = Container::measurements()->create((int) $p['id'], ['measured_on' => date('Y-m-d'), 'weight_kg' => 30.0] + $this->blank(), 'familia', (int) $staff['id']);
        $u = $this->makeUser('paciente');
        $this->db->run('UPDATE patients SET user_id = ? WHERE id = ?', [$u['id'], $p['id']]);
        $this->loginAs($u);
        $this->assertSame(403, $this->post('/pacientes/' . $p['id'] . '/medidas/nova', $this->form())->status);
        $this->assertSame(403, $this->post('/medidas/' . $mid . '/remover', ['motivo' => 'x'])->status);
        $this->assertSame(403, $this->get('/medidas/' . $mid . '/corrigir')->status);
    }

    private function blank(): array
    {
        return ['height_cm' => null, 'position' => null, 'waist_cm' => null, 'hip_cm' => null, 'head_cm' => null, 'systolic' => null, 'diastolic' => null];
    }

    public function testUnknownMeasurementIs404(): void
    {
        $this->loginAs($this->makeUser('medico'));
        $this->assertSame(404, $this->get('/medidas/999999/versoes')->status);
        $this->assertSame(404, $this->post('/medidas/999999/remover', ['motivo' => 'x'])->status);
    }

    public function testCorrectAndRemoveFlow(): void
    {
        $med = $this->makeUser('medico');
        $p = $this->childPatient('2015-03-10');
        $this->loginAs($med);
        $this->post('/pacientes/' . $p['id'] . '/medidas/nova', $this->form());
        $id = (int) $this->db->one('SELECT id FROM measurements')['id'];
        $this->assertSame(200, $this->get('/medidas/' . $id . '/corrigir')->status);
        $res = $this->post('/medidas/' . $id . '/corrigir', $this->form(['peso' => '33,0']));
        $this->assertSame(302, $res->status);
        $this->assertSame(200, $this->get('/medidas/' . $id . '/versoes')->status);
        $cur = Container::measurements()->current((int) $p['id']);
        $this->assertCount(1, $cur);
        $res = $this->post('/medidas/' . $cur[0]['id'] . '/remover', ['motivo' => '']);
        $this->assertSame(302, $res->status);
        $this->assertCount(1, Container::measurements()->current((int) $p['id']));
        $this->post('/medidas/' . $cur[0]['id'] . '/remover', ['motivo' => 'Erro de digitação']);
        $this->assertSame([], Container::measurements()->current((int) $p['id']));
    }

    public function testIndexShowsRowsVelocityAndAudits(): void
    {
        $med = $this->makeUser('medico');
        $p = $this->childPatient('2015-03-10');
        $repo = Container::measurements();
        $repo->create((int) $p['id'], ['measured_on' => '2024-03-10', 'weight_kg' => 25.0, 'height_cm' => 120.0, 'position' => 'em_pe'] + $this->blank(), 'equipe', (int) $med['id']);
        $repo->create((int) $p['id'], ['measured_on' => '2025-03-10', 'weight_kg' => 29.0, 'height_cm' => 126.0, 'position' => 'em_pe', 'systolic' => 150, 'diastolic' => 95] + $this->blank(), 'familia', (int) $med['id']);
        $this->loginAs($med);
        $res = $this->get('/pacientes/' . $p['id'] . '/crescimento');
        $this->assertSame(200, $res->status);
        $this->assertStringContainsString('6,0', $res->body);
        $this->assertStringContainsString('família', $res->body);
        $this->assertStringContainsString('retroativo', $res->body);
        $this->assertStringContainsString('data-url', $res->body);
        $this->assertNotNull($this->db->one("SELECT 1 FROM audit_log WHERE entity='patients' AND action='view' AND patient_id = ?", [$p['id']]));
        $show = $this->get('/pacientes/' . $p['id']);
        $this->assertStringContainsString('/crescimento', $show->body);
    }

    public function testCurvasBfaForEightYearOld(): void
    {
        $med = $this->makeUser('medico');
        $birth = date('Y-m-d', strtotime('-8 years -3 months'));
        $p = $this->childPatient($birth, 'M');
        $this->loginAs($med);
        Container::measurements()->create((int) $p['id'], ['measured_on' => date('Y-m-d'), 'weight_kg' => 28.0, 'height_cm' => 128.0, 'position' => 'em_pe'] + $this->blank(), 'equipe', (int) $med['id']);
        $res = $this->get('/pacientes/' . $p['id'] . '/curvas.json', ['indicador' => 'bfa', 'tipo' => 'z']);
        $this->assertSame(200, $res->status);
        $j = json_decode($res->body, true);
        $this->assertSame('meses', $j['x_unit']);
        $this->assertCount(7, $j['reference']['lines']);
        $labels = array_column($j['reference']['lines'], 'label');
        $this->assertSame(['-3', '-2', '-1', '0', '+1', '+2', '+3'], $labels);
        $zero = $j['reference']['lines'][3]['points'];
        $ageM = \App\Growth\Age::months($birth, date('Y-m-d'));
        $found = false;
        foreach ($zero as [$x, $y]) {
            if (abs($x - $ageM) < 0.011) {
                $lms = Container::whoRef()->lms('bfa', 'M', $ageM, 'who2007');
                $this->assertEqualsWithDelta($lms['m'], $y, 0.01);
                $found = true;
            }
        }
        $this->assertTrue($found, 'ponto na idade do paciente');
        $this->assertCount(1, $j['patient']);
        $this->assertSame(date('d/m/Y'), $j['patient'][0][2]);

        $j = json_decode($this->get('/pacientes/' . $p['id'] . '/curvas.json', ['indicador' => 'bfa', 'tipo' => 'percentil'])->body, true);
        $this->assertSame(['P3', 'P15', 'P50', 'P85', 'P97'], array_column($j['reference']['lines'], 'label'));
    }

    public function testCurvasWfaNotApplicableAt11Years(): void
    {
        $p = $this->childPatient(date('Y-m-d', strtotime('-11 years -2 months')));
        $this->loginAs($this->makeUser('medico'));
        $res = $this->get('/pacientes/' . $p['id'] . '/curvas.json', ['indicador' => 'wfa']);
        $this->assertSame(200, $res->status);
        $this->assertSame(['reference' => null, 'message' => 'Não se aplica para esta idade'], json_decode($res->body, true));
    }

    public function testCurvasAdultReturnsWeightAndBmiSeries(): void
    {
        $med = $this->makeUser('medico');
        $p = $this->childPatient('1990-01-01', 'F');
        Container::measurements()->create((int) $p['id'], ['measured_on' => '2025-01-10', 'weight_kg' => 80.0, 'height_cm' => 160.0, 'position' => null] + $this->blank(), 'equipe', (int) $med['id']);
        $this->loginAs($med);
        $j = json_decode($this->get('/pacientes/' . $p['id'] . '/curvas.json', ['indicador' => 'bfa'])->body, true);
        $this->assertNull($j['reference']);
        $this->assertEquals([['2025-01-10', 80.0]], $j['series']['peso']);
        $this->assertEqualsWithDelta(31.3, $j["series"]["imc"][0][1], 0.06);
    }

    public function testInvalidIndicatorIs400(): void
    {
        $p = $this->childPatient('2015-03-10');
        $this->loginAs($this->makeUser('medico'));
        $this->assertSame(400, $this->get('/pacientes/' . $p['id'] . '/curvas.json', ['indicador' => 'xxx'])->status);
    }
}
