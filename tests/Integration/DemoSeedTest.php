<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Evolution\EvolutionData;
use Tests\DbTestCase;
use Tools\Demo\DemoSeed;

require_once dirname(__DIR__, 2) . '/tools/demo/DemoSeed.php';
require_once dirname(__DIR__, 2) . '/tools/release_filter.php';

final class DemoSeedTest extends DbTestCase
{
    private const TODAY = '2026-11-19';

    public function test_guard(): void
    {
        DemoSeed::assertSafeTarget(['host' => '127.0.0.1', 'name' => 'rubia_demo']);
        DemoSeed::assertSafeTarget(['host' => 'localhost', 'name' => 'rubia_demo']);
        foreach ([
            ['host' => 'db.hospedagem', 'name' => 'rubia_demo'],
            ['host' => 'localhost', 'name' => 'rubia_dev'],
            ['host' => 'localhost', 'name' => 'quali498_rubia'],
            ['name' => 'rubia_demo'],
        ] as $bad) {
            try {
                DemoSeed::assertSafeTarget($bad);
                $this->fail('deveria recusar ' . json_encode($bad));
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_run_creates_every_login_role(): void
    {
        $ids = DemoSeed::run($this->db, self::TODAY);
        foreach (['medico', 'nutricionista', 'secretaria', 'responsavel', 'paciente', 'crianca', 'novo'] as $login) {
            $this->assertArrayHasKey($login, DemoSeed::LOGINS, $login);
        }
        foreach (['medico', 'nutricionista', 'secretaria', 'responsavel', 'paciente', 'crianca'] as $role) {
            $n = (int) $this->db->one("SELECT COUNT(*) n FROM users WHERE role = ? AND active = 1 AND (email LIKE '%@demo.test' OR username LIKE 'demo.%')", [$role])['n'];
            $this->assertGreaterThanOrEqual(1, $n, $role);
        }
        $doc = $this->db->one('SELECT * FROM users WHERE email = ?', [DemoSeed::LOGINS['medico']]);
        $this->assertSame(1, (int) $doc['is_admin']);
        foreach (['crianca', 'adolescente', 'adulto', 'novo_paciente'] as $k) {
            $this->assertArrayHasKey($k, $ids, $k);
        }
        $cert = $this->db->one('SELECT * FROM certificates WHERE id = ?', [$ids['atestado']]);
        $this->assertSame($ids['adulto'], (int) $cert['patient_id']);
        $this->assertStringContainsString('Pediatra', $cert['body_html']);
        $nutri = (int) $this->db->one('SELECT id FROM users WHERE email = ?', [DemoSeed::LOGINS['nutricionista']])['id'];
        foreach (['modelo_texto' => 'texto', 'modelo_estruturado' => 'estruturado'] as $k => $mode) {
            $t = $this->db->one('SELECT * FROM meal_plan_templates WHERE id = ?', [$ids[$k]]);
            $this->assertSame($mode, $t['mode'], $k);
            $this->assertSame($nutri, (int) $t['created_by'], $k);
        }
        $structure = json_decode((string) $this->db->one('SELECT structure_json FROM meal_plan_templates WHERE id = ?', [$ids['modelo_estruturado']])['structure_json'], true);
        $this->assertGreaterThanOrEqual(3, count($structure['meals']));
        $this->assertNotEmpty($structure['meals'][0]['options'][0]['items']);
    }

    public function test_adult_shows_plateau_and_child_has_curve(): void
    {
        $ids = DemoSeed::run($this->db, self::TODAY);
        $doc = $this->db->one('SELECT * FROM users WHERE email = ?', [DemoSeed::LOGINS['medico']]);
        $adult = $this->db->one('SELECT * FROM patients WHERE id = ?', [$ids['adulto']]);
        $d = EvolutionData::build($doc, $adult, self::TODAY, '6m');
        $this->assertSame('estabilizou', $d['plateau']['status']);
        $this->assertGreaterThanOrEqual(2, count($d['meds'][0]['segments'] ?? []));
        $m = $this->db->all('SELECT source FROM measurements WHERE patient_id = ? AND deleted_at IS NULL', [$ids['crianca']]);
        $this->assertGreaterThanOrEqual(8, count($m));
        $this->assertContains('familia', array_column($m, 'source'));
    }

    public function test_run_twice_does_not_duplicate(): void
    {
        DemoSeed::run($this->db, self::TODAY);
        DemoSeed::run($this->db, self::TODAY);
        foreach (DemoSeed::LOGINS as $login) {
            $n = (int) $this->db->one('SELECT COUNT(*) n FROM users WHERE email = ? OR username = ?', [$login, $login])['n'];
            $this->assertSame(1, $n, $login);
        }
        $this->assertSame(4, (int) $this->db->one('SELECT COUNT(*) n FROM patients')['n']);
    }

    public function test_release_excludes_demo_tools(): void
    {
        foreach (['tools/demo/DemoSeed.php', 'tools/demo/seed.php', 'tools/manual_shots.mjs', 'tools/manual_shots_lib.js',
            'tools/manual_shots_list.php', 'tools/manual_pdf.php', 'config/config.demo.php', 'config/config.demo.example.php'] as $f) {
            $this->assertTrue(release_is_excluded($f), $f);
        }
        $this->assertFalse(release_is_excluded('app/Help/Manual.php'));
        $this->assertFalse(release_is_excluded('public/assets/manual/pdf/manual-medica.pdf'));
    }
}
