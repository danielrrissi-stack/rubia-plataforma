<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\WeightGoals\WeightGoalProgress;
use PHPUnit\Framework\TestCase;

final class WeightGoalProgressTest extends TestCase
{
    private const TODAY = '2026-02-20'; // dia 50 da meta

    private function goal(array $over = []): array
    {
        return $over + ['kind' => 'peso', 'start_on' => '2026-01-01', 'start_value' => '100.000', 'target_value' => '90.000', 'target_date' => '2026-04-11'];
    }

    private function p(int $day, float $v, string $source = 'equipe'): array
    {
        $on = (new \DateTimeImmutable('2026-01-01'))->modify('+' . $day . ' days')->format('Y-m-d');
        return ['on' => $on, 'value' => $v, 'bmi' => null, 'z' => null, 'weight' => $v, 'height' => null, 'source' => $source, 'id' => $day + 1];
    }

    private function at50(float $v, array $goal = []): array
    {
        $start = (float) ($this->goal($goal)['start_value']);
        return WeightGoalProgress::compute($this->goal($goal), [$this->p(0, $start), $this->p(50, $v)], self::TODAY);
    }

    public function test_status_against_path(): void
    {
        $r = $this->at50(95.8);
        $this->assertSame('no_caminho', $r['status']);
        $this->assertEqualsWithDelta(95.0, $r['expected'], 1e-9);
        $this->assertEqualsWithDelta(0.8, $r['diff'], 1e-9);
        $this->assertSame('no_caminho', $this->at50(96.0)['status']); // borda = tolerancia
        $this->assertSame('atras', $this->at50(96.2)['status']);
        $this->assertSame('a_frente', $this->at50(93.9)['status']);
    }

    public function test_waiting_expired_and_reached(): void
    {
        $this->assertSame('aguardando', WeightGoalProgress::compute($this->goal(), [$this->p(0, 100.0)], self::TODAY)['status']);
        $late = WeightGoalProgress::compute($this->goal(), [$this->p(0, 100.0), $this->p(110, 92.0)], '2026-05-01');
        $this->assertSame('prazo_vencido', $late['status']);
        $done = WeightGoalProgress::compute($this->goal(), [$this->p(0, 100.0), $this->p(40, 89.5), $this->p(45, 90.5)], self::TODAY);
        $this->assertSame('a_frente', $done['status']); // voltou a 90,5: vale o ultimo ponto, o evento fica no 1o

        $this->assertSame('2026-02-10', $done['reachedOn']);
        $reached = WeightGoalProgress::compute($this->goal(), [$this->p(0, 100.0), $this->p(45, 89.5)], self::TODAY);
        $this->assertSame('atingida', $reached['status']);
        $this->assertSame('2026-02-15', $reached['reachedOn']);
        $this->assertNull(WeightGoalProgress::trendText($reached));
    }

    public function test_gain_goal(): void
    {
        $gain = ['start_value' => '50.000', 'target_value' => '55.000'];
        $this->assertSame('no_caminho', $this->at50(52.0, $gain)['status']);
        $this->assertSame('atras', $this->at50(51.0, $gain)['status']);
        $this->assertSame('a_frente', $this->at50(54.0, $gain)['status']);
    }

    public function test_z_goal_tolerance(): void
    {
        $z = ['kind' => 'imc_z', 'start_value' => '2.500', 'target_value' => '1.500'];
        $this->assertSame('no_caminho', $this->at50(1.85, $z)['status']);
        $this->assertSame('atras', $this->at50(2.25, $z)['status']);
    }

    public function test_trend_from_fifth_point(): void
    {
        $pts = [$this->p(0, 100), $this->p(10, 99), $this->p(20, 98), $this->p(30, 97), $this->p(40, 96)];
        $r = WeightGoalProgress::compute($this->goal(), $pts, self::TODAY);
        $this->assertEqualsWithDelta(-0.1, $r['trend']['b'], 1e-9);
        $this->assertEqualsWithDelta(100.0, $r['trend']['a'], 1e-9);
        $this->assertSame('chega', $r['trend']['kind']);
        $this->assertSame('2026-04-11', $r['trend']['date']);
        $this->assertTrue($r['trend']['onTime']);
        $this->assertSame(0, $r['trendMissing']);
        $this->assertSame('Nesse ritmo, chega ao alvo em 04/2026, antes do prazo.', WeightGoalProgress::trendText($r));

        $four = WeightGoalProgress::compute($this->goal(), array_slice($pts, 0, 4), self::TODAY);
        $this->assertNull($four['trend']);
        $this->assertSame(1, $four['trendMissing']);
        $this->assertSame('A tendência aparece a partir da 5ª medição (faltam 1).', WeightGoalProgress::trendText($four));
    }

    public function test_trend_late_flat_and_too_slow(): void
    {
        $late = [$this->p(0, 100), $this->p(10, 99.5), $this->p(20, 99), $this->p(30, 98.5), $this->p(40, 98)];
        $r = WeightGoalProgress::compute($this->goal(), $late, self::TODAY);
        $this->assertSame('chega', $r['trend']['kind']);
        $this->assertFalse($r['trend']['onTime']);
        $this->assertSame('Nesse ritmo, chega ao alvo em 07/2026, depois do prazo.', WeightGoalProgress::trendText($r));

        $flat = array_map(fn ($d) => $this->p($d, 100), [0, 10, 20, 30, 40]);
        $r = WeightGoalProgress::compute($this->goal(), $flat, self::TODAY);
        $this->assertSame('nao_aproxima', $r['trend']['kind']);
        $this->assertSame('Nesse ritmo, não se aproxima do alvo.', WeightGoalProgress::trendText($r));

        $slow = [$this->p(0, 100), $this->p(10, 99.99), $this->p(20, 99.98), $this->p(30, 99.97), $this->p(40, 99.96)];
        $r = WeightGoalProgress::compute($this->goal(), $slow, self::TODAY);
        $this->assertSame('mais_de_5_anos', $r['trend']['kind']);
        $this->assertSame('Nesse ritmo, levaria mais de 5 anos.', WeightGoalProgress::trendText($r));
    }

    public function test_trend_crossing_before_today_is_not_a_past_date(): void
    {
        // atingiu no dia 20 e voltou a subir: a reta cruza o alvo no dia 42, antes de hoje (dia 50)
        $pts = [$this->p(0, 100), $this->p(10, 95), $this->p(20, 89), $this->p(30, 92), $this->p(40, 93)];
        $r = WeightGoalProgress::compute($this->goal(), $pts, self::TODAY);
        $this->assertSame('ja_deveria', $r['trend']['kind']);
        $this->assertNull($r['trend']['date']);
        $this->assertSame('Pela média das medições já estaria no alvo, mas a última medição ainda não chegou lá.', WeightGoalProgress::trendText($r));
    }

    public function test_labels(): void
    {
        $this->assertSame('À frente do caminho', WeightGoalProgress::LABELS['a_frente']);
        $this->assertSame('Aguardando a primeira medição', WeightGoalProgress::LABELS['aguardando']);
    }
}
