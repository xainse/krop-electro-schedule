<?php

use PHPUnit\Framework\TestCase;

class ParseAllQueuesTest extends TestCase
{
    private function fullScheduleText(): string
    {
        return "Черга 1.1: 02-04, 06-08\n" .
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
    }

    public function testParseAllQueuesFinds12Queues(): void
    {
        $queues = parseAllQueues($this->fullScheduleText());
        $this->assertCount(12, $queues);
    }

    public function testParseAllQueuesContainsExpectedKeys(): void
    {
        $queues = parseAllQueues($this->fullScheduleText());
        $expectedKeys = ['1.1', '1.2', '2.1', '2.2', '3.1', '3.2', '4.1', '4.2', '5.1', '5.2', '6.1', '6.2'];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $queues, "Missing queue $key");
        }
    }

    public function testParseAllQueuesNormalizesSchedules(): void
    {
        $queues = parseAllQueues($this->fullScheduleText());
        // "02-04" should be normalized to "02:00-04:00"
        $this->assertStringContainsString('02:00-04:00', $queues['1.1']);
        $this->assertStringContainsString('06:00-08:00', $queues['1.1']);
    }

    public function testParseAllQueuesWithMinuteFormat(): void
    {
        $text = "Черга 1.1: 02:00-04:00, 06:00-08:00\n" .
            "Черга 1.2: 04:00-06:00, 08:00-10:30\n";
        $queues = parseAllQueues($text);
        $this->assertCount(2, $queues);
        $this->assertStringContainsString('10:30', $queues['1.2']);
    }

    public function testParseAllQueuesReturnsEmptyForNoQueues(): void
    {
        $queues = parseAllQueues('Немає графіків сьогодні');
        $this->assertEmpty($queues);
    }

    public function testParseAllQueuesSingleQueue(): void
    {
        $text = "Черга 3.1: 04:00-06:00, 16:00-18:00";
        $queues = parseAllQueues($text);
        $this->assertCount(1, $queues);
        $this->assertArrayHasKey('3.1', $queues);
    }

    public function testParseAllQueuesHandlesExtraWhitespace(): void
    {
        $text = "Черга 1.1:   02-04 ,  06-08  \n\n  Черга 1.2:  04-06 , 08-10  \n";
        $queues = parseAllQueues($text);
        $this->assertCount(2, $queues);
        $this->assertStringContainsString('02:00-04:00', $queues['1.1']);
    }

    public function testParseAllQueuesRealWorldMessage(): void
    {
        $text = <<<TEXT
Графіки планових відключень електроенергії на 23.01.2026

Черга 1.1: 02:00-04:00, 06:00-08:00, 10:00-11:30
Черга 1.2: 04:00-06:00, 08:00-10:00, 12:00-14:00
Черга 2.1: 00:00-02:00, 12:00-14:00, 18:00-20:00
Черга 2.2: 02:00-04:00, 14:00-16:00, 20:00-22:00
Черга 3.1: 04:00-06:00, 16:00-18:00, 22:00-24:00
Черга 3.2: 06:00-08:00, 18:00-20:00
Черга 4.1: 08:00-10:00, 20:00-22:00
Черга 4.2: 10:00-12:00, 22:00-24:00
Черга 5.1: 12:00-14:00, 00:00-02:00
Черга 5.2: 14:00-16:00, 02:00-04:00
Черга 6.1: 16:00-18:00, 04:00-06:00
Черга 6.2: 18:00-20:00, 06:00-08:00
TEXT;

        $queues = parseAllQueues($text);
        $this->assertCount(12, $queues);
        $this->assertStringContainsString('10:00-11:30', $queues['1.1']);
    }
}
