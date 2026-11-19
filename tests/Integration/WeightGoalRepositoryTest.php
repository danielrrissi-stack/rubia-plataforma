<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Container;
use App\Core\Request;
use App\WeightGoals\InvalidWeightGoalException;
use Tests\DbTestCase;

final class WeightGoalRepositoryTest extends DbTestCase
{
    private const TODAY = '2026-11-19';

    private array $medico;

    protected function setUp(): void
    {
        parent::setUp();
        $this->medico = $this->makeUser('medico');
    }

    private function r(): Request
    {
        return new Request('POST', '/');
    }

    private function patient(string $birth): array
    {
        return $this->makePatient(['birth_date' => $birth, 'created_by' => $this->medico['id'], 'responsible_professional_id' => $this->medico['id']]);
    }

    private function data(array $over = []): array
    {
        return $over + [
            'kind' => 'peso', 'start_on' => '2026-11-10', 'start_value' => 92.0,
            'target_value' => 82.5, 'target_date' => '2027-05-19', 'notes' => null,
        ];
    }

    private function create(array $patient, array $over = []): int
    {
        return Container::weightGoals()->create((int) $patient['id'], $this->data($over), $patient, self::TODAY, (int) $this->medico['id'], $this->r());
    }

    private function invalid(callable $fn): array
    {
        try {
            $fn();
        } catch (InvalidWeightGoalException $e) {
            return $e->errors;
        }
        $this->fail('Deveria lançar InvalidWeightGoalException.');
    }

    public function test_create_then_replace_keeps_history(): void
    {
        $p = $this->patient('1990-04-02');
        $first = $this->create($p);
        $second = $this->create($p, ['target_value' => 85.0]);
        $rows = $this->db->all('SELECT * FROM weight_goals WHERE patient_id = ? ORDER BY id', [$p['id']]);
        $this->assertCount(2, $rows);
        $this->assertSame($first, (int) $rows[0]['id']);
        $this->assertSame('substituida', $rows[0]['end_reason']);
        $this->assertNotNull($rows[0]['ended_at']);
        $this->assertSame($second, (int) Container::weightGoals()->active((int) $p['id'])['id']);
        $this->assertSame([$second, $first], array_map(fn ($g) => (int) $g['id'], Container::weightGoals()->history((int) $p['id'])));
        $audits = (int) $this->db->one("SELECT COUNT(*) n FROM audit_log WHERE entity = 'weight_goals'")['n'];
        $this->assertSame(3, $audits);
    }

    public function test_end(): void
    {
        $p = $this->patient('1990-04-02');
        $id = $this->create($p);
        Container::weightGoals()->end($id, (int) $this->medico['id'], $this->r());
        $this->assertNull(Container::weightGoals()->active((int) $p['id']));
        $this->assertSame('encerrada', Container::weightGoals()->find($id)['end_reason']);
        $this->invalid(fn () => Container::weightGoals()->end($id, (int) $this->medico['id'], $this->r()));
    }

    public function test_rejects_kind_not_allowed_for_age(): void
    {
        $adult = $this->patient('1990-04-02');
        $this->assertNotEmpty($this->invalid(fn () => $this->create($adult, ['kind' => 'imc_z', 'start_value' => 2.1, 'target_value' => 1.0])));
        $child = $this->patient('2016-05-10');
        $this->assertNotEmpty($this->invalid(fn () => $this->create($child, ['start_value' => 40.0, 'target_value' => 36.0])));
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) n FROM weight_goals')['n']);
    }

    public function test_rejects_invalid_values(): void
    {
        $p = $this->patient('1990-04-02');
        foreach ([
            ['target_date' => self::TODAY],
            ['target_date' => '2031-11-20'],
            ['target_value' => 92.0],
            ['target_value' => 15.0],
        ] as $bad) {
            $this->assertCount(1, $this->invalid(fn () => $this->create($p, $bad)), json_encode($bad));
        }
        $child = $this->patient('2016-05-10');
        $this->assertCount(1, $this->invalid(fn () => $this->create($child, ['kind' => 'imc_z', 'start_value' => 2.1, 'target_value' => 6.0])));
    }

    public function test_rejects_z_deadline_after_limit(): void
    {
        $teen = $this->patient('2008-01-19'); // 18 anos e 10 meses em 2026-11-19
        $this->assertNotEmpty($this->invalid(fn () => $this->create($teen, ['kind' => 'imc_z', 'start_value' => 2.1, 'target_value' => 1.5])));
        $id = $this->create($teen, ['kind' => 'peso']);
        $this->assertSame('peso', Container::weightGoals()->find($id)['kind']);
    }

    public function test_same_goal_sent_twice_is_saved_once(): void
    {
        $p = $this->patient('1990-04-02');
        $first = $this->create($p);
        $second = $this->create($p);
        $this->assertSame($first, $second);
        $this->assertCount(1, Container::weightGoals()->history((int) $p['id']));
        $other = $this->create($p, ['target_value' => 80.0]);
        $this->assertNotSame($first, $other);
        $this->assertCount(2, Container::weightGoals()->history((int) $p['id']));
    }

    public function test_db_allows_only_one_active(): void
    {
        $p = $this->patient('1990-04-02');
        $row = ['patient_id' => $p['id'], 'kind' => 'peso', 'start_on' => '2026-11-10', 'start_value' => 92, 'target_value' => 82, 'target_date' => '2027-05-19', 'created_by' => $this->medico['id']];
        $this->db->insert('weight_goals', $row);
        $this->expectException(\PDOException::class);
        $this->db->insert('weight_goals', $row);
    }
}
