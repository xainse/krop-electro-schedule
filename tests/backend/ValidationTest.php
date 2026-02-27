<?php

use PHPUnit\Framework\TestCase;

class ValidationTest extends TestCase
{
    // --- validateSchedule ---

    public function testValidSchedule(): void
    {
        $this->assertTrue(validateSchedule('02:00-04:00'));
    }

    public function testValidScheduleMultipleRanges(): void
    {
        $this->assertTrue(validateSchedule('02:00-04:00, 06:00-08:00'));
    }

    public function testValidScheduleWithHalfHours(): void
    {
        $this->assertTrue(validateSchedule('10:00-11:30, 14:00-16:00'));
    }

    public function testEmptyScheduleIsInvalid(): void
    {
        $this->assertFalse(validateSchedule(''));
    }

    public function testScheduleWithoutTimeRangeIsInvalid(): void
    {
        $this->assertFalse(validateSchedule('no time ranges here'));
    }

    public function testScheduleWithOldFormatAfterNormalization(): void
    {
        $normalized = normalizeSchedule('2-4');
        $this->assertTrue(validateSchedule($normalized));
    }

    // --- extractDate edge cases ---

    public function testExtractDateFromComplexText(): void
    {
        $text = "Шановні мешканці! Графіки планових відключень електроенергії на 15.02.2026 для м. Кропивницький";
        $this->assertSame('15.02.2026', extractDate($text));
    }

    public function testExtractDateDoesNotMatchPartialDates(): void
    {
        $text = "Рік 2026, день 5";
        $this->assertFalse(extractDate($text));
    }

    // --- normalizeSchedule edge cases ---

    public function testNormalizeScheduleRemovesNewlines(): void
    {
        $result = normalizeSchedule("02-04\n06-08");
        $this->assertStringNotContainsString("\n", $result);
    }

    public function testNormalizeScheduleDoesNotCorruptValidFormat(): void
    {
        $input = '02:00-04:00, 06:00-08:30, 10:00-12:00';
        $this->assertSame($input, normalizeSchedule($input));
    }

    public function testNormalizeSchedulePadsSingleDigits(): void
    {
        $this->assertSame('02:00-04:00', normalizeSchedule('2-4'));
        $this->assertSame('08:00-09:00', normalizeSchedule('8-9'));
    }
}
