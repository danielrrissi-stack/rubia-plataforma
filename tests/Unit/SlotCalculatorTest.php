<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Agenda\AgendaStatus;
use App\Agenda\SlotCalculator;
use PHPUnit\Framework\TestCase;

final class SlotCalculatorTest extends TestCase
{
    // 2026-11-16 = segunda; 2026-11-21 = sabado; 2026-11-22 = domingo
    private const MONDAY = '2026-11-16';
    private const SATURDAY = '2026-11-21';
    private const SUNDAY = '2026-11-22';

    /** @return list<string> */
    private function starts(array $slots): array
    {
        return array_column($slots, 'start');
    }

    public function test_clinic_default_monday(): void
    {
        $slots = SlotCalculator::daySlots(self::MONDAY, AgendaStatus::CLINIC_DEFAULT, [], 30);
        $starts = $this->starts($slots);
        $this->assertCount(23, $slots);
        $this->assertSame('07:30', $starts[0]);
        $this->assertSame('08:00', $slots[0]['end']);
        $this->assertSame('modelo', $slots[0]['source']);
        $this->assertSame('11:00', $starts[7]);
        $this->assertSame('12:30', $starts[8]);
        $this->assertSame('19:30', $starts[22]);
        $this->assertSame('20:00', $slots[22]['end']);
        $this->assertNotContains('11:30', $starts);
    }

    public function test_saturday_and_sunday(): void
    {
        $sat = $this->starts(SlotCalculator::daySlots(self::SATURDAY, AgendaStatus::CLINIC_DEFAULT, [], 30));
        $this->assertCount(10, $sat);
        $this->assertSame('07:30', $sat[0]);
        $this->assertSame('12:00', $sat[9]);
        $this->assertSame([], SlotCalculator::daySlots(self::SUNDAY, AgendaStatus::CLINIC_DEFAULT, [], 30));
    }

    public function test_extra_adds_and_closure_wins(): void
    {
        $extra = ['kind' => 'extra', 'start_date' => self::SUNDAY, 'end_date' => self::SUNDAY, 'start_time' => '09:00:00', 'end_time' => '10:00:00'];
        $slots = SlotCalculator::daySlots(self::SUNDAY, AgendaStatus::CLINIC_DEFAULT, [$extra], 30);
        $this->assertSame(['09:00', '09:30'], $this->starts($slots));
        $this->assertSame('extra', $slots[0]['source']);

        $closedDay = ['kind' => 'fechado', 'start_date' => '2026-11-20', 'end_date' => '2026-11-23', 'start_time' => null, 'end_time' => null];
        $this->assertSame([], SlotCalculator::daySlots(self::SUNDAY, AgendaStatus::CLINIC_DEFAULT, [$extra, $closedDay], 30));

        $closedMorning = ['kind' => 'fechado', 'start_date' => self::MONDAY, 'end_date' => self::MONDAY, 'start_time' => '08:00', 'end_time' => '09:00'];
        $starts = $this->starts(SlotCalculator::daySlots(self::MONDAY, AgendaStatus::CLINIC_DEFAULT, [$closedMorning], 30));
        $this->assertCount(21, $starts);
        $this->assertNotContains('08:00', $starts);
        $this->assertNotContains('08:30', $starts);
        $this->assertContains('07:30', $starts);
        $this->assertContains('09:00', $starts);

        // fechamento de outro dia nao afeta
        $this->assertCount(23, SlotCalculator::daySlots('2026-11-17', AgendaStatus::CLINIC_DEFAULT, [$closedMorning], 30));
        $this->assertTrue(SlotCalculator::closedOn(self::MONDAY, '08:30', '09:00', [$closedMorning]));
        $this->assertFalse(SlotCalculator::closedOn(self::MONDAY, '09:00', '09:30', [$closedMorning]));
        $this->assertTrue(SlotCalculator::closedOn(self::SUNDAY, '15:00', '15:30', [$closedDay]));
        $this->assertFalse(SlotCalculator::closedOn(self::SUNDAY, '15:00', '15:30', [$extra]));
    }

    public function test_extra_overlapping_model_has_no_duplicates(): void
    {
        $extra = ['kind' => 'extra', 'start_date' => self::MONDAY, 'end_date' => self::MONDAY, 'start_time' => '11:00', 'end_time' => '12:30'];
        $slots = SlotCalculator::daySlots(self::MONDAY, AgendaStatus::CLINIC_DEFAULT, [$extra], 30);
        $starts = $this->starts($slots);
        $this->assertCount(25, $slots);
        $this->assertSame($starts, array_values(array_unique($starts)));
        $sorted = $starts;
        sort($sorted);
        $this->assertSame($sorted, $starts);
        $bySt = array_column($slots, 'source', 'start');
        $this->assertSame('modelo', $bySt['11:00']);
        $this->assertSame('extra', $bySt['11:30']);
        $this->assertSame('extra', $bySt['12:00']);
    }

    public function test_duration_45_steps(): void
    {
        $starts = $this->starts(SlotCalculator::daySlots(self::MONDAY, AgendaStatus::CLINIC_DEFAULT, [], 45));
        $this->assertSame(['07:30', '08:15', '09:00', '09:45', '10:30'], array_slice($starts, 0, 5));
        $this->assertSame('12:30', $starts[5]);
        $this->assertSame('19:15', end($starts));
    }

    public function test_overlaps_half_open(): void
    {
        $this->assertFalse(SlotCalculator::overlaps('08:00', '08:30', '08:30', '09:00'));
        $this->assertFalse(SlotCalculator::overlaps('08:30', '09:00', '08:00', '08:30'));
        $this->assertTrue(SlotCalculator::overlaps('08:00', '08:30', '08:15', '09:00'));
        $this->assertTrue(SlotCalculator::overlaps('08:00', '10:00', '08:30', '09:00'));
        $this->assertTrue(SlotCalculator::overlaps('08:00:00', '08:30:00', '08:00', '08:30'));
    }
}
