<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Evolution\EvolutionRate;
use PHPUnit\Framework\TestCase;

final class EvolutionRateTest extends TestCase
{
    private const S = ['adult_mode' => 'kg', 'kg_per_week' => 0.2, 'pct_per_4w' => 1.0, 'z_per_4w' => 0.05];

    private function p(int $day, float $v): array
    {
        $on = (new \DateTimeImmutable('2026-01-01'))->modify('+' . $day . ' days')->format('Y-m-d');
        return ['on' => $on, 'value' => $v, 'weight' => $v, 'source' => 'familia', 'id' => $day + 1];
    }

    private function line(float $start, float $perDay): array
    {
        return array_map(fn (int $d): array => $this->p($d, $start + $d * $perDay), [0, 7, 14, 21, 28]);
    }

    public function test_slope(): void
    {
        $r = EvolutionRate::slopePerDay([$this->p(0, 100), $this->p(7, 99), $this->p(14, 98)]);
        $this->assertEqualsWithDelta(-1 / 7, $r['b'], 1e-9);
        $this->assertEqualsWithDelta(100.0, $r['a'], 1e-9);
        $this->assertNull(EvolutionRate::slopePerDay([$this->p(0, 100)]));
    }

    public function test_kg_mode_working_and_stabilized(): void
    {
        $r = EvolutionRate::plateau($this->line(100, -0.05), ['2025-12-01'], self::S, false);
        $this->assertSame('funcionando', $r['status']);
        $this->assertEqualsWithDelta(0.35, $r['rate'], 1e-9);
        $this->assertSame('kg_semana', $r['unit']);
        $this->assertSame('estabilizou', EvolutionRate::plateau($this->line(100, -0.02), [], self::S, false)['status']);
        $this->assertSame('estabilizou', EvolutionRate::plateau($this->line(100, 0.01), [], self::S, false)['status']);
    }

    public function test_pct_mode_uses_fitted_start_weight(): void
    {
        $pts = $this->line(100, -0.03);
        $s = ['adult_mode' => 'pct'] + self::S;
        $r = EvolutionRate::plateau($pts, [], $s, false);
        $this->assertSame('estabilizou', $r['status']);
        $this->assertEqualsWithDelta(0.84, $r['rate'], 1e-9);
        $this->assertSame('pct_4sem', $r['unit']);
        $this->assertSame('funcionando', EvolutionRate::plateau($pts, [], ['pct_per_4w' => 0.5] + $s, false)['status']);
    }

    public function test_child_z_mode(): void
    {
        $r = EvolutionRate::plateau($this->line(2.5, -0.001), [], self::S, true);
        $this->assertSame('estabilizou', $r['status']);
        $this->assertSame('z_4sem', $r['unit']);
        $this->assertEqualsWithDelta(0.028, $r['rate'], 1e-9);
        $this->assertSame('funcionando', EvolutionRate::plateau($this->line(2.5, -0.003), [], self::S, true)['status']);
    }

    public function test_window_is_last_28_days(): void
    {
        // perdeu muito no inicio (dias 0-21), parado nos ultimos 28 dias (21-49)
        $pts = [$this->p(0, 110), $this->p(7, 105), $this->p(14, 100), $this->p(21, 95), $this->p(28, 95), $this->p(35, 95), $this->p(42, 95), $this->p(49, 95)];
        $r = EvolutionRate::plateau($pts, [], self::S, false);
        $this->assertSame('estabilizou', $r['status']);
        $this->assertSame('2026-01-22', $r['from']);
        $this->assertSame('2026-02-19', $r['to']);
    }

    public function test_recent_dose_change_waits(): void
    {
        $r = EvolutionRate::plateau($this->line(100, 0), ['2026-01-24', '2025-12-01'], self::S, false);
        $this->assertSame('aguardando', $r['status']);
        $this->assertSame(5, $r['daysSinceChange']);
        $this->assertSame('Aguardando dados: nova dose há 5 dias.', EvolutionRate::plateauText($r));
    }

    public function test_too_few_points_and_no_points(): void
    {
        $r = EvolutionRate::plateau([$this->p(0, 100), $this->p(20, 99)], [], self::S, false);
        $this->assertSame('aguardando', $r['status']);
        $this->assertSame('Aguardando dados: são precisas 3 pesagens em pelo menos 14 dias.', EvolutionRate::plateauText($r));
        $this->assertSame('aguardando', EvolutionRate::plateau([$this->p(0, 100), $this->p(5, 99), $this->p(10, 99)], [], self::S, false)['status']);
        $this->assertSame('sem_dados', EvolutionRate::plateau([], [], self::S, false)['status']);
    }

    public function test_segments_follow_doses_and_end_date(): void
    {
        $rx = ['start_date' => '2026-01-01', 'end_date' => '2026-03-15'];
        $doses = [['effective_date' => '2026-01-01', 'dose' => '2,5 mg'], ['effective_date' => '2026-02-01', 'dose' => '5 mg']];
        $this->assertSame(
            [['from' => '2026-01-01', 'to' => '2026-01-31', 'dose' => '2,5 mg'], ['from' => '2026-02-01', 'to' => '2026-03-15', 'dose' => '5 mg']],
            EvolutionRate::segments($rx, $doses, '2026-04-01'),
        );
        $open = EvolutionRate::segments(['start_date' => '2026-01-10', 'end_date' => null], [['effective_date' => '2026-01-01', 'dose' => '1 cp'], ['effective_date' => '2026-05-01', 'dose' => '2 cp']], '2026-04-01');
        $this->assertSame([['from' => '2026-01-10', 'to' => null, 'dose' => '1 cp']], $open); // dose futura nao aparece
    }

    public function test_segment_rate(): void
    {
        $seg = ['from' => '2026-01-01', 'to' => '2026-01-29', 'dose' => '5 mg'];
        $this->assertEqualsWithDelta(0.35, EvolutionRate::segmentRate($this->line(100, -0.05), $seg, '2026-04-01', false), 1e-9);
        $this->assertEqualsWithDelta(0.084, EvolutionRate::segmentRate($this->line(2.5, -0.003), $seg, '2026-04-01', true), 1e-9);
        $this->assertNull(EvolutionRate::segmentRate($this->line(100, -0.05), ['from' => '2026-01-01', 'to' => '2026-01-05', 'dose' => 'x'], '2026-04-01', false));
    }

    public function test_texts(): void
    {
        $base = ['daysSinceChange' => null];
        $this->assertSame('Perda de peso estabilizou nesta dose (0,14 kg/semana nas últimas 4 semanas).', EvolutionRate::plateauText(['status' => 'estabilizou', 'rate' => 0.14, 'unit' => 'kg_semana'] + $base));
        $this->assertSame('Ainda perdendo peso: 0,35 kg/semana nas últimas 4 semanas.', EvolutionRate::plateauText(['status' => 'funcionando', 'rate' => 0.35, 'unit' => 'kg_semana'] + $base));
        $this->assertSame('Perda de peso estabilizou nesta dose (0,84% do peso em 4 semanas).', EvolutionRate::plateauText(['status' => 'estabilizou', 'rate' => 0.84, 'unit' => 'pct_4sem'] + $base));
        $this->assertSame('Ainda perdendo peso: queda de 0,084 no escore-z em 4 semanas.', EvolutionRate::plateauText(['status' => 'funcionando', 'rate' => 0.084, 'unit' => 'z_4sem'] + $base));
        $this->assertSame('Perda de peso estabilizou nesta dose (-0,07 kg/semana nas últimas 4 semanas).', EvolutionRate::plateauText(['status' => 'estabilizou', 'rate' => -0.07, 'unit' => 'kg_semana'] + $base));
        $this->assertSame('Sem pesagens para avaliar.', EvolutionRate::plateauText(['status' => 'sem_dados', 'rate' => null, 'unit' => 'kg_semana'] + $base));
    }
}
