<?php

use PHPUnit\Framework\TestCase;

class ParserTest extends TestCase
{
    private function sampleMessage(): string
    {
        return "Графіки на 23.01.2026\n\n" .
            "Черга 1.1: 02:00-04:00, 06:00-08:00\n" .
            "Черга 1.2: 04:00-06:00, 08:00-10:00\n";
    }

    // --- parseScheduleMessage ---

    public function testParseScheduleMessageReturnsArrayForValidInput(): void
    {
        $result = parseScheduleMessage($this->sampleMessage());
        $this->assertIsArray($result);
        $this->assertArrayHasKey('date', $result);
        $this->assertArrayHasKey('queues', $result);
        $this->assertArrayHasKey('emergency_mode', $result);
    }

    public function testParseScheduleMessageExtractsDate(): void
    {
        $result = parseScheduleMessage($this->sampleMessage());
        $this->assertSame('23.01.2026', $result['date']);
    }

    public function testParseScheduleMessageExtractsQueues(): void
    {
        $result = parseScheduleMessage($this->sampleMessage());
        $this->assertArrayHasKey('1.1', $result['queues']);
        $this->assertArrayHasKey('1.2', $result['queues']);
    }

    public function testParseScheduleMessageReturnsFalseForEmptyText(): void
    {
        $this->assertFalse(parseScheduleMessage(''));
    }

    public function testParseScheduleMessageReturnsFalseWithoutDate(): void
    {
        $text = "Черга 1.1: 02:00-04:00";
        $this->assertFalse(parseScheduleMessage($text));
    }

    public function testParseScheduleMessageReturnsFalseWithoutQueues(): void
    {
        $text = "Інформація на 23.01.2026\nНемає черг тут.";
        $this->assertFalse(parseScheduleMessage($text));
    }

    // --- extractDate ---

    public function testExtractDateFindsDate(): void
    {
        $this->assertSame('23.01.2026', extractDate('Графіки на 23.01.2026'));
    }

    public function testExtractDateReturnsFalseWhenNoDate(): void
    {
        $this->assertFalse(extractDate('No date here'));
    }

    public function testExtractDateFindsContextualDate(): void
    {
        $text = 'Графіки на 23.01.2026 та 24.01.2026';
        $this->assertSame('23.01.2026', extractDate($text));
    }

    public function testExtractDateResolvesTomorrow(): void
    {
        $ref = strtotime('2026-03-01 18:00:00');
        $this->assertSame('02.03.2026', extractDate('Графіки на завтра', $ref));
    }

    public function testExtractDateResolvesToday(): void
    {
        $ref = strtotime('2026-03-01 10:00:00');
        $this->assertSame('01.03.2026', extractDate('Графіки на сьогодні', $ref));
    }

    public function testExtractDatePrefersContextualOverPlain(): void
    {
        $text = 'Опубліковано 01.03.2026. Графіки на 02.03.2026';
        $this->assertSame('02.03.2026', extractDate($text));
    }

    public function testExtractDateSingleDigitDayMonth(): void
    {
        $this->assertSame('05.03.2026', extractDate('Графіки на 5.3.2026'));
    }

    // --- extractQueues ---

    public function testExtractQueuesFindsAllQueues(): void
    {
        $text = "Черга 1.1: 02-04, 06-08\nЧерга 1.2: 04-06, 08-10\nЧерга 2.1: 10-12";
        $queues = extractQueues($text);
        $this->assertCount(3, $queues);
        $this->assertArrayHasKey('1.1', $queues);
        $this->assertArrayHasKey('1.2', $queues);
        $this->assertArrayHasKey('2.1', $queues);
    }

    public function testExtractQueuesSingleQueue(): void
    {
        $text = "Черга 3.2: 06:00-08:00, 18:00-20:00";
        $queues = extractQueues($text);
        $this->assertCount(1, $queues);
        $this->assertArrayHasKey('3.2', $queues);
    }

    public function testExtractQueuesReturnsEmptyForNoQueues(): void
    {
        $text = 'Немає черг у цьому тексті';
        $queues = extractQueues($text);
        $this->assertEmpty($queues);
    }

    /** Черга з "-" (немає відключень) має потрапляти в результат з порожнім графіком */
    public function testExtractQueuesAcceptsNoBlackoutsDash(): void
    {
        $text = "Черга 1.1: -";
        $queues = extractQueues($text);
        $this->assertCount(1, $queues);
        $this->assertArrayHasKey('1.1', $queues);
        $this->assertSame('', $queues['1.1']);
    }

    /** Остання черга без наступної "Черга X.X" — графік обрізається до валідних символів, без реклами */
    public function testExtractQueuesTrimsTrailingNonScheduleText(): void
    {
        $text = "Черга 6.2: 14-16, 22-24ПрАТ \"Кіровоградобленерго\" та інший рекламний текст";
        $queues = extractQueues($text);
        $this->assertCount(1, $queues);
        $this->assertArrayHasKey('6.2', $queues);
        $this->assertSame('14:00-16:00, 22:00-24:00', $queues['6.2']);
    }

    // --- normalizeSchedule ---

    public function testNormalizeScheduleAddsMinutes(): void
    {
        $this->assertSame('02:00-04:00', normalizeSchedule('2-4'));
    }

    public function testNormalizeScheduleKeepsMinutesFormat(): void
    {
        $this->assertSame('06:00-08:00', normalizeSchedule('06:00-08:00'));
    }

    public function testNormalizeScheduleTrimsWhitespace(): void
    {
        $result = normalizeSchedule("  02-04  , 06-08  \n");
        $this->assertSame('02:00-04:00, 06:00-08:00', $result);
    }

    public function testNormalizeScheduleHandlesSingleDigitHours(): void
    {
        $this->assertSame('02:00-04:00', normalizeSchedule('2-4'));
    }

    public function testNormalizeScheduleHandlesHalfHours(): void
    {
        $this->assertSame('10:00-11:30', normalizeSchedule('10:00-11:30'));
    }

    public function testNormalizeScheduleMultipleRanges(): void
    {
        $result = normalizeSchedule('2-4, 6-8, 10-12');
        $this->assertSame('02:00-04:00, 06:00-08:00, 10:00-12:00', $result);
    }

    // --- Full 12-queue message ---

    public function testParseFull12QueueMessage(): void
    {
        $text = "Графіки відключень на 23.01.2026\n\n" .
            "Черга 1.1: 02-04, 06-08\n" .
            "Черга 1.2: 04-06, 08-10\n" .
            "Черга 2.1: 00-02, 12-14\n" .
            "Черга 2.2: 02-04, 14-16\n" .
            "Черга 3.1: 04-06, 16-18\n" .
            "Черга 3.2: 06-08, 18-20\n" .
            "Черга 4.1: 08-10, 20-22\n" .
            "Черга 4.2: 10-12, 22-24\n" .
            "Черга 5.1: 12-14, 00-02\n" .
            "Черга 5.2: 14-16, 02-04\n" .
            "Черга 6.1: 16-18, 04-06\n" .
            "Черга 6.2: 18-20, 06-08\n";

        $result = parseScheduleMessage($text);
        $this->assertIsArray($result);
        $this->assertSame('23.01.2026', $result['date']);
        $this->assertCount(12, $result['queues']);
    }
}
