<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Container;
use App\Core\Request;
use App\Evolution\InvalidEvolutionSettingsException;
use Tests\DbTestCase;

final class EvolutionSettingsRepositoryTest extends DbTestCase
{
    private array $medico;
    private array $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->medico = $this->makeUser('medico');
        $this->patient = $this->makePatient(['created_by' => $this->medico['id'], 'responsible_professional_id' => $this->medico['id']]);
    }

    private function req(): Request
    {
        return new Request('POST', '/');
    }

    private function rows(): int
    {
        return (int) $this->db->one('SELECT COUNT(*) AS n FROM evolution_settings WHERE patient_id = ?', [$this->patient['id']])['n'];
    }

    public function test_defaults_without_row(): void
    {
        $s = Container::evolutionSettings()->get((int) $this->patient['id']);
        $this->assertSame(['adult_mode' => 'kg', 'kg_per_week' => 0.2, 'pct_per_4w' => 1.0, 'z_per_4w' => 0.05, 'saved' => false], $s);
    }

    public function test_save_accepts_comma_and_updates(): void
    {
        $repo = Container::evolutionSettings();
        $pid = (int) $this->patient['id'];
        $repo->save($pid, ['modo' => 'pct', 'kg' => '0,3', 'pct' => '1,5', 'z' => '0,08'], (int) $this->medico['id'], $this->req());
        $repo->save($pid, ['modo' => 'pct', 'kg' => '0,3', 'pct' => '2', 'z' => '0,08'], (int) $this->medico['id'], $this->req());
        $this->assertSame(['adult_mode' => 'pct', 'kg_per_week' => 0.3, 'pct_per_4w' => 2.0, 'z_per_4w' => 0.08, 'saved' => true], $repo->get($pid));
        $this->assertSame(1, $this->rows());
        $this->assertNotNull($this->db->one("SELECT id FROM audit_log WHERE entity = 'evolution_settings' AND patient_id = ?", [$pid]));
    }

    public function test_rejects_bad_values(): void
    {
        $ok = ['modo' => 'kg', 'kg' => '0,2', 'pct' => '1', 'z' => '0,05'];
        foreach ([['modo' => 'x'], ['kg' => ''], ['kg' => '-0,1'], ['kg' => '5'], ['pct' => '20'], ['z' => '1'], ['z' => 'abc']] as $bad) {
            try {
                Container::evolutionSettings()->save((int) $this->patient['id'], $bad + $ok, (int) $this->medico['id'], $this->req());
                $this->fail(json_encode($bad));
            } catch (InvalidEvolutionSettingsException $e) {
                $this->assertArrayHasKey(array_key_first($bad), $e->errors);
            }
        }
        $this->assertSame(0, $this->rows());
    }
}
