<?php

use PHPUnit\Framework\TestCase;

/**
 * Тести логіки злиття графіків за message_num (telegram_fetcher).
 * Потребує parser.php та telegram_fetcher.php (mergeSchedulesByMessageNum).
 */
class TelegramFetcherTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../api/parser.php';
        require_once __DIR__ . '/../../api/telegram_fetcher.php';
    }

    /** Для двох повідомлень з однаковою датою в результаті лишається графік з новішого (більший message_num). */
    public function testMergeSchedulesKeepsNewerMessageForSameDate(): void
    {
        $older = [
            'parsed' => parseScheduleMessage(
                "Графіки на 03.03.2026\n\nЧерга 1.1: 12-14\nЧерга 6.2: 14-16, 22-24"
            ),
            'message_num' => 1552,
        ];
        $newer = [
            'parsed' => parseScheduleMessage(
                "Зміни на 08:45 03.03.2026\n\nЧерга 1.1: -\nЧерга 6.2: 14-16, 22-24"
            ),
            'message_num' => 1553,
        ];
        $this->assertNotFalse($older['parsed']);
        $this->assertNotFalse($newer['parsed']);
        $this->assertSame('03.03.2026', $older['parsed']['date']);
        $this->assertSame('03.03.2026', $newer['parsed']['date']);

        $merged = mergeSchedulesByMessageNum([$older, $newer]);
        $this->assertCount(1, $merged);
        $this->assertArrayHasKey('03.03.2026', $merged);
        $kept = $merged['03.03.2026'];
        $this->assertSame(1553, $kept['message_num']);
        $this->assertSame('', $kept['parsed']['queues']['1.1'], 'Має лишитися графік з новішого повідомлення (1.1: -)');
    }
}
