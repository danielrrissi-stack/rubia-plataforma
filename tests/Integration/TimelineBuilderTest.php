<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Container;
use App\Core\Request;
use App\Timeline\TimelineBuilder;
use Tests\DbTestCase;

final class TimelineBuilderTest extends DbTestCase
{
    private const FROM = '2026-10-01';

    private array $medico;
    private array $nutri;
    private array $patient;
    private string $today;
    private int $certOk = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = date('Y-m-d');
        $this->medico = $this->makeUser('medico');
        $this->nutri = $this->makeUser('nutricionista');
        $this->patient = $this->makePatient(['birth_date' => '1990-04-02', 'created_by' => $this->medico['id'], 'responsible_professional_id' => $this->medico['id']]);
        $this->db->insert('professional_access', ['patient_id' => $this->patient['id'], 'professional_id' => $this->nutri['id'], 'granted_by' => $this->medico['id']]);
        $this->seed();
    }

    private function seed(): void
    {
        $pid = $this->patient['id'];
        $mid = $this->medico['id'];
        foreach ([[$mid, '2026-10-05 09:00:00', 'realizada'], [$this->nutri['id'], '2026-10-06 09:00:00', 'faltou'], [$mid, '2026-09-01 09:00:00', 'realizada']] as [$prof, $at, $st]) {
            $this->db->insert('appointments', ['professional_id' => $prof, 'patient_id' => $pid, 'starts_at' => $at, 'ends_at' => substr($at, 0, 11) . '10:00:00', 'kind' => 'retorno', 'status' => $st, 'created_by' => $mid]);
        }
        $this->db->insert('clinical_entries', ['patient_id' => $pid, 'kind' => 'evolucao', 'payload' => '{"diagnostico":"Obesidade grau I"}', 'encounter_date' => '2026-10-05', 'created_by' => $mid]);
        $test = $this->db->insert('lab_tests', ['name' => 'Exame de teste', 'unit' => 'mg/dL', 'group_name' => 'Teste']);
        $col = $this->db->insert('lab_collections', ['patient_id' => $pid, 'collected_on' => '2026-10-10', 'created_by' => $mid]);
        $this->db->insert('lab_results', ['collection_id' => $col, 'test_id' => $test, 'value_num' => 100]);
        $rx = $this->db->insert('prescriptions', ['patient_id' => $pid, 'drug_name' => 'Metformina', 'route' => 'oral', 'start_date' => '2026-10-02', 'created_by' => $mid]);
        $this->db->insert('prescription_doses', ['prescription_id' => $rx, 'effective_date' => '2026-10-02', 'dose' => '500 mg', 'frequency' => '1x ao dia', 'created_by' => $mid]);
        $this->db->insert('prescription_doses', ['prescription_id' => $rx, 'effective_date' => '2026-10-20', 'dose' => '850 mg', 'frequency' => '1x ao dia', 'created_by' => $mid]);
        $gone = $this->db->insert('prescriptions', ['patient_id' => $pid, 'drug_name' => 'Apagado', 'route' => 'oral', 'start_date' => '2026-10-03', 'created_by' => $mid, 'deleted_at' => '2026-10-04 10:00:00']);
        $this->db->insert('prescription_doses', ['prescription_id' => $gone, 'effective_date' => '2026-10-03', 'dose' => '1 cp', 'frequency' => '1x', 'created_by' => $mid]);
        $this->db->insert('adverse_events', ['patient_id' => $pid, 'prescription_id' => $rx, 'occurred_on' => '2026-10-21', 'description' => 'Náusea', 'source' => 'familia', 'reported_by' => $mid, 'reported_by_role' => 'medico']);
        $m = Container::measurements();
        $m->create($pid, ['measured_on' => '2026-09-15', 'weight_kg' => 95.5, 'height_cm' => 170.0], 'equipe', $mid);
        $m->create($pid, ['measured_on' => '2026-10-05', 'weight_kg' => 92.4, 'height_cm' => 170.0, 'systolic' => 118, 'diastolic' => 76], 'equipe', $mid);
        $del = $m->create($pid, ['measured_on' => '2026-10-07', 'weight_kg' => 50.0], 'equipe', $mid);
        $m->remove($del, 'erro', $mid);
        $this->db->insert('meal_plans', ['patient_id' => $pid, 'mode' => 'texto', 'title' => 'Plano outubro', 'status' => 'publicado', 'created_by' => $mid, 'published_at' => '2026-10-08 10:00:00']);
        $this->db->insert('patient_goals', ['patient_id' => $pid, 'family_text' => 'Beber água', 'emoji' => '💧', 'category' => 'hidratacao', 'times_per_week' => 5, 'start_date' => '2026-10-05', 'created_by' => $mid]);
        $this->certOk = Container::certificates()->create($pid, $mid, 'comparecimento', '2026-10-12', [], '<p>a</p>', null);
        $voided = Container::certificates()->create($pid, $mid, 'aptidao', '2026-10-13', [], '<p>b</p>', null);
        Container::certificates()->void($voided, $mid, 'Engano');
        Container::weightGoals()->create($pid, ['kind' => 'peso', 'start_on' => '2026-10-05', 'start_value' => 92.4, 'target_value' => 85.0, 'target_date' => (new \DateTimeImmutable($this->today))->modify('+6 months')->format('Y-m-d'), 'notes' => null], $this->patient, $this->today, $mid, new Request('POST', '/'));
    }

    private function events(array $user, ?string $from = self::FROM, int $limit = 200): array
    {
        return TimelineBuilder::events($user, (int) $this->patient['id'], $from, $this->today, $limit);
    }

    public function test_doctor_sees_every_type_in_period(): void
    {
        $r = $this->events($this->medico);
        $types = array_values(array_unique(array_column($r['events'], 'type')));
        sort($types);
        $this->assertSame(['atestado', 'consulta', 'evento_adverso', 'evolucao', 'exame', 'medicao', 'meta', 'meta_peso', 'plano', 'remedio'], $types);
        foreach ($r['events'] as $ev) {
            $this->assertGreaterThanOrEqual(self::FROM, $ev['date']);
            $this->assertNotSame('', $ev['link']);
        }
        $texts = implode("\n", array_column($r['events'], 'text'));
        $this->assertStringContainsString('500 mg → 850 mg', $texts);
        $this->assertStringContainsString('Obesidade grau I', $texts);
        $this->assertStringContainsString('92,4 kg', $texts);
        $this->assertStringContainsString('−3,1 kg', $texts);
        $this->assertStringContainsString('PA 118/76', $texts);
        $this->assertStringNotContainsString('Apagado', $texts);
        $this->assertStringNotContainsString('50,0 kg', $texts);
        $this->assertFalse($r['truncated']);
    }

    public function test_certificates_only_for_doctor_with_void_badge(): void
    {
        $ev = array_values(array_filter($this->events($this->medico)['events'], fn ($e) => $e['type'] === 'atestado'));
        $this->assertCount(2, $ev);
        $byDate = array_column($ev, null, 'date');
        $this->assertSame('Atestado emitido: Declaração de comparecimento', $byDate['2026-10-12']['text']);
        $this->assertNull($byDate['2026-10-12']['badge']);
        $this->assertStringEndsWith('/pacientes/' . $this->patient['id'] . '/atestados/' . $this->certOk, $byDate['2026-10-12']['link']);
        $this->assertSame('Atestado emitido: Atestado de aptidão para atividade física', $byDate['2026-10-13']['text']);
        $this->assertSame('anulado', $byDate['2026-10-13']['badge']);
        $this->assertSame('Atestado', $byDate['2026-10-13']['label']);
        $this->assertNotContains('atestado', array_column($this->events($this->nutri)['events'], 'type'));
    }

    public function test_same_day_order_has_visit_last(): void
    {
        $day = array_values(array_filter($this->events($this->medico)['events'], fn ($e) => $e['date'] === '2026-10-05'));
        $this->assertSame(['medicao', 'meta', 'evolucao', 'consulta'], array_column($day, 'type'));
        $dates = array_column($this->events($this->medico)['events'], 'date');
        $sorted = $dates;
        rsort($sorted);
        $this->assertSame($sorted, $dates);
    }

    public function test_nutritionist_sees_only_nutrition_side(): void
    {
        $r = $this->events($this->nutri);
        $types = array_values(array_unique(array_column($r['events'], 'type')));
        sort($types);
        $this->assertSame(['consulta', 'medicao', 'meta_peso', 'plano'], $types);
        $visits = array_values(array_filter($r['events'], fn ($e) => $e['type'] === 'consulta'));
        $this->assertCount(1, $visits); // so a dela
        $this->assertStringContainsString('Faltou', $visits[0]['text']);
    }

    public function test_all_and_limit(): void
    {
        $all = $this->events($this->medico, null);
        $this->assertContains('2026-09-01', array_column($all['events'], 'date'));
        $three = $this->events($this->medico, null, 3);
        $this->assertCount(3, $three['events']);
        $this->assertTrue($three['truncated']);
    }

    public function test_visit_link_only_when_user_can_open_it(): void
    {
        $visits = array_values(array_filter($this->events($this->medico)['events'], fn ($e) => $e['type'] === 'consulta'));
        $byDate = array_column($visits, null, 'date');
        $this->assertStringContainsString('Realizada', $byDate['2026-10-05']['text']);
        $this->assertNotNull($byDate['2026-10-05']['link']); // a propria agenda
        $this->assertStringContainsString('Faltou', $byDate['2026-10-06']['text']);
        $this->assertNull($byDate['2026-10-06']['link']); // agenda da nutricionista: medica nao administradora nao abre
        $admin = $this->makeUser('medico', ['is_admin' => 1]);
        $visits = array_values(array_filter($this->events($admin)['events'], fn ($e) => $e['type'] === 'consulta' && $e['date'] === '2026-10-06'));
        $this->assertNotNull($visits[0]['link']);
    }

    public function test_query_count_does_not_grow_with_records(): void
    {
        $count = function (): int {
            $before = $this->questions();
            $this->events($this->medico, null);
            return $this->questions() - $before;
        };
        $kid = $this->makePatient(['birth_date' => '2018-03-01', 'created_by' => $this->medico['id'], 'responsible_professional_id' => $this->medico['id']]);
        $this->patient = $kid;
        $this->addRecords(2);
        $count(); // carrega as tabelas da OMS (uma vez por requisicao)
        $small = $count();
        $this->addRecords(5);
        $this->assertSame($small, $count());
    }

    private int $recordSeq = 0;

    private function addRecords(int $n): void
    {
        $pid = (int) $this->patient['id'];
        $mid = (int) $this->medico['id'];
        for ($i = 0; $i < $n; $i++) {
            $k = ++$this->recordSeq;
            $rx = $this->db->insert('prescriptions', ['patient_id' => $pid, 'drug_name' => 'Remedio ' . $k, 'route' => 'oral', 'start_date' => '2026-01-0' . min(9, $k), 'created_by' => $mid]);
            $this->db->insert('prescription_doses', ['prescription_id' => $rx, 'effective_date' => '2026-01-01', 'dose' => '1 cp', 'frequency' => '1x', 'created_by' => $mid]);
            Container::measurements()->create($pid, ['measured_on' => '2025-0' . min(9, $k) . '-1' . $k % 10, 'weight_kg' => 30.0 + $k, 'height_cm' => 130.0 + $k], 'equipe', $mid);
            $this->db->insert('weight_goals', ['patient_id' => $pid, 'kind' => 'peso', 'start_on' => '2025-01-01', 'start_value' => 31, 'target_value' => 29,
                'target_date' => '2025-12-01', 'created_by' => $mid, 'ended_at' => '2025-06-0' . min(9, $k) . ' 10:00:00', 'ended_by' => $mid, 'end_reason' => 'encerrada']);
        }
    }

    private function questions(): int
    {
        return (int) $this->db->one("SHOW SESSION STATUS LIKE 'Questions'")['Value'];
    }
}
