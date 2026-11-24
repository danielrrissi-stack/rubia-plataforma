<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Growth\WhoReferenceRepository;
use Tests\DbTestCase;

final class WhoReferenceTest extends DbTestCase
{
    private WhoReferenceRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new WhoReferenceRepository($this->db);
    }

    public function testBirthValuesMatchWho2006(): void
    {
        $boy = $this->repo->lms('wfa', 'M', 0, 'who2006');
        $this->assertEqualsWithDelta(3.3464, $boy['m'], 1e-4);
        $this->assertEqualsWithDelta(0.3487, $boy['l'], 1e-4);
        $this->assertEqualsWithDelta(0.14602, $boy['s'], 1e-4);
        $this->assertEqualsWithDelta(3.2322, $this->repo->lms('wfa', 'F', 0, 'who2006')['m'], 1e-4);
        $this->assertEqualsWithDelta(49.8842, $this->repo->lms('hfa', 'M', 0, 'who2006')['m'], 1e-4);
        $this->assertEqualsWithDelta(49.1477, $this->repo->lms('hfa', 'F', 0, 'who2006')['m'], 1e-4);
    }

    public function testRowCounts(): void
    {
        foreach ([1, 2] as $sex) {
            $this->assertSame(1827, $this->rows('bfa', $sex, 'who2006'));
            $this->assertSame(170, $this->rows('bfa', $sex, 'who2007'));
        }
        $this->assertSame(['min' => 60.0, 'max' => 229.0], $this->repo->range('bfa', 'F', 'who2007'));
        $this->assertSame(['min' => 45.0, 'max' => 110.0], $this->repo->range('wfl', 'M', 'who2006'));
    }

    public function testWfa2007EndsAt121(): void
    {
        $this->assertNotNull($this->repo->lms('wfa', 'M', 121, 'who2007'));
        $this->assertNull($this->repo->lms('wfa', 'M', 121.5, 'who2007'));
        $this->assertNull($this->repo->lms('wfa', 'M', 59.9, 'who2007'));
    }

    public function testLinearInterpolation(): void
    {
        $a = $this->repo->lms('bfa', 'M', 10, 'who2006');
        $b = $this->repo->lms('bfa', 'M', 11, 'who2006');
        $mid = $this->repo->lms('bfa', 'M', 10.5, 'who2006');
        $this->assertGreaterThan(min($a['m'], $b['m']), $mid['m']);
        $this->assertLessThan(max($a['m'], $b['m']), $mid['m']);
        $this->assertEqualsWithDelta(($a['m'] + $b['m']) / 2, $mid['m'], 1e-9);
        $this->assertEqualsWithDelta(($a['s'] + $b['s']) / 2, $mid['s'], 1e-9);
    }

    public function testOutOfRangeIsNull(): void
    {
        $this->assertNull($this->repo->lms('wfl', 'F', 44.9, 'who2006'));
        $this->assertNull($this->repo->lms('bfa', 'F', 1827, 'who2006'));
    }

    private function rows(string $ind, int $sex, string $src): int
    {
        return (int) $this->db->one(
            'SELECT COUNT(*) AS n FROM who_lms WHERE indicator = ? AND sex = ? AND source = ?',
            [$ind, $sex, $src],
        )['n'];
    }
}
