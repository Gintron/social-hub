<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\Brand;
use App\Support\PostingSchedule;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class PostingScheduleTest extends TestCase
{
    private PostingSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schedule = new PostingSchedule;
    }

    public function test_a_time_before_the_window_waits_for_it_to_open(): void
    {
        $slot = $this->schedule->nextSlot($this->brand(), $this->zagreb('2026-09-07 03:20'));

        $this->assertSame('2026-09-07 08:00', $this->local($slot));
    }

    public function test_a_time_inside_the_window_is_used_as_is(): void
    {
        $at = $this->zagreb('2026-09-07 12:30');

        $this->assertTrue($at->equalTo($this->schedule->nextSlot($this->brand(), $at)));
    }

    public function test_a_time_after_the_window_rolls_to_the_next_day(): void
    {
        $slot = $this->schedule->nextSlot($this->brand(), $this->zagreb('2026-09-07 22:10'));

        $this->assertSame('2026-09-08 08:00', $this->local($slot));
    }

    public function test_a_gap_between_two_windows_waits_for_the_second(): void
    {
        $brand = $this->brand([['from' => '08:00', 'to' => '10:00'], ['from' => '17:00', 'to' => '20:00']]);

        $this->assertSame('2026-09-07 17:00', $this->local($this->schedule->nextSlot($brand, $this->zagreb('2026-09-07 11:00'))));
        $this->assertSame('2026-09-07 09:30', $this->local($this->schedule->nextSlot($brand, $this->zagreb('2026-09-07 09:30'))));
    }

    public function test_a_brand_without_windows_may_post_at_any_time(): void
    {
        $at = $this->zagreb('2026-09-07 03:20');

        $this->assertTrue($at->equalTo($this->schedule->nextSlot($this->brand([]), $at)));
    }

    public function test_malformed_windows_are_ignored_rather_than_trusted(): void
    {
        $brand = $this->brand([
            ['from' => 'devet', 'to' => '20:00'],
            ['from' => '20:00', 'to' => '08:00'],
            ['from' => '09:00', 'to' => '17:00'],
        ]);

        $this->assertSame('2026-09-07 09:00', $this->local($this->schedule->nextSlot($brand, $this->zagreb('2026-09-07 06:00'))));
    }

    public function test_a_batch_is_spaced_out_inside_the_window(): void
    {
        $slots = $this->schedule->slots($this->brand(), $this->zagreb('2026-09-07 12:00'), 3, 45);

        $this->assertSame(
            ['2026-09-07 12:00', '2026-09-07 12:45', '2026-09-07 13:30'],
            array_map(fn (CarbonImmutable $slot): string => $this->local($slot), $slots),
        );
    }

    public function test_a_batch_that_outgrows_the_window_continues_the_next_day(): void
    {
        $slots = $this->schedule->slots($this->brand([['from' => '08:00', 'to' => '09:00']]), $this->zagreb('2026-09-07 08:30'), 3, 45);

        $this->assertSame(
            ['2026-09-07 08:30', '2026-09-08 08:00', '2026-09-08 08:45'],
            array_map(fn (CarbonImmutable $slot): string => $this->local($slot), $slots),
        );
    }

    public function test_windows_are_read_in_the_brands_timezone(): void
    {
        $brand = $this->brand();

        // 06:30 UTC is 08:30 in Zagreb during summer time: inside the window, not before it.
        $slot = $this->schedule->nextSlot($brand, CarbonImmutable::parse('2026-09-07 06:30', 'UTC'));

        $this->assertSame('2026-09-07 08:30', $this->local($slot));
        $this->assertSame('UTC', $slot->timezone->getName());
    }

    /**
     * @param  list<array{from: string, to: string}>|null  $windows
     */
    private function brand(?array $windows = null): Brand
    {
        return new Brand([
            'name' => 'Test',
            'timezone' => 'Europe/Zagreb',
            'posting_windows' => $windows ?? [['from' => '08:00', 'to' => '20:00']],
        ]);
    }

    private function zagreb(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($time, 'Europe/Zagreb');
    }

    private function local(CarbonImmutable $at): string
    {
        return $at->setTimezone('Europe/Zagreb')->format('Y-m-d H:i');
    }
}
