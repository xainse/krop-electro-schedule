<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../../api/response.php';
require_once __DIR__ . '/../../api/telegram_fetcher.php';

class AuditRegressionTest extends TestCase {
    public function testExpiredLiveResponseCannotImplyPowerAllDay(): void {
        $now = strtotime('2026-10-08 12:00:00');
        $data = ['date' => '30.06.2026', 'queues' => ['1.1' => ''], 'timestamp' => $now, 'emergency_mode' => true];
        $response = scheduleResponse($data, '1.1', $now);
        $this->assertNull($response['schedule']);
        $this->assertNull($response['updated']);
        $this->assertNull($response['emergency_mode']);
        $this->assertTrue($response['stale']);
        $this->assertFalse($response['available']);
    }
    public function testCurrentVerifiedEmptyScheduleIsValid(): void {
        $now = strtotime('2026-10-08 12:00:00');
        $data = ['date' => '08.10.2026', 'verified_at' => $now - 20, 'queues' => ['1.1' => '']];
        $this->assertSame('', scheduleResponse($data, '1.1', $now)['schedule']);
        $this->assertFalse(scheduleResponse($data, '1.1', $now)['stale']);
        $this->assertNull(scheduleResponse($data, '6.2', $now)['schedule']);
        $this->assertTrue(scheduleResponse($data, '1.1', $now + 601)['stale']);
        $this->assertSame($now - 20, scheduleResponse($data, '1.1', $now + 601)['updated']);
    }
    public function testNotAnnouncedWhenSourcesCheckedWithoutTodaySchedule(): void {
        $now = strtotime('2026-10-08 12:00:00');
        $data = ['date' => '08.10.2026', 'verified_at' => $now - 10, 'queues' => [], 'not_announced' => true];
        $response = scheduleResponse($data, '1.1', $now);
        $this->assertTrue($response['not_announced']);
        $this->assertFalse($response['available']);
        $this->assertFalse($response['stale']);
        $this->assertSame('Графіки відключення не оголошені', $response['message']);
        $this->assertNull($response['schedule']);
        $this->assertFalse(isCurrentSchedulePayload(['date' => '08.10.2026', 'queues' => [], 'not_announced' => true], $now));
        $this->assertTrue(isCurrentSchedulePayload(['date' => '08.10.2026', 'queues' => ['1.1' => '']], $now));
    }
    public function testEndpointReturnsNotAnnouncedWithoutHttpError(): void {
        $runtime = sys_get_temp_dir() . '/krop-not-announced-' . bin2hex(random_bytes(5));
        mkdir($runtime, 0700);
        $timestamp = time() - 15;
        file_put_contents($runtime . '/blackout_cache.json', json_encode([
            'date' => date('d.m.Y'),
            'verified_at' => $timestamp,
            'queues' => [],
            'not_announced' => true,
        ]));
        file_put_contents($runtime . '/last_source_check.txt', (string)time());
        $endpoint = realpath(__DIR__ . '/../../api/blackout.php');
        try {
            $script = "define('KROP_TEST_MODE',true); define('ENABLE_LOGGING',false); define('CACHE_DIR'," . var_export($runtime, true) . "); define('LOGS_DIR',CACHE_DIR.'/logs'); \$_SERVER['REQUEST_METHOD']='GET'; \$_GET=['queue'=>'1.1']; require " . var_export($endpoint, true) . ';';
            $output = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script));
            $data = json_decode($output, true);
            $this->assertTrue($data['success']);
            $this->assertTrue($data['not_announced']);
            $this->assertFalse($data['available']);
            $this->assertSame('Графіки відключення не оголошені', $data['message']);
        } finally {
            foreach (glob($runtime . '/*') as $file) if (is_file($file)) unlink($file);
            @unlink($runtime . '/logs/.htaccess'); @rmdir($runtime . '/logs');
            @unlink($runtime . '/.htaccess'); @rmdir($runtime);
        }
    }
    public function testTomorrowDoesNotReplaceToday(): void {
        $key = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $this->assertSame($key, resolveCurrentDateKey(['dates' => [$tomorrow => [], $key => []]]));
        $this->assertNull(resolveCurrentDateKey(['dates' => [$tomorrow => []]]));
    }
    public function testInvalidDatesAndRanges(): void {
        foreach (['99:99-88:88', '12:60-13:00', '24:00-24:30', '12:00-12:00', '02:00-04:00 junk', '02:00-04:00, garbage'] as $range) {
            $this->assertFalse(validateSchedule($range), $range);
        }
        $this->assertFalse(extractDate('на 31.02.2026'));
        $this->assertFalse(dateToKey('31.02.2026'));
        $this->assertFalse(dateToKey(null));
        $this->assertTrue(validateSchedule('23:30-01:00'));
    }
    public function testUnicodeRangesAndRelativeMessageDate(): void {
        $parsed = parseScheduleMessage("Графік на завтра\nЧерга 1.1: 02–04, 06:30—08:00\nЧерга 6.2: -", strtotime('2026-02-28 22:00:00'));
        $this->assertSame('01.03.2026', $parsed['date']);
        $this->assertSame('02:00-04:00, 06:30-08:00', $parsed['queues']['1.1']);
        $this->assertSame('', $parsed['queues']['6.2']);
    }
    public function testHtmlPreservesLineBreaksAndReturnsSourceText(): void {
        $html = '<div id="info_popup"><div class="fancybox_body_desc">Графік на 08.10.2026<br>Черга 1.1: 02-04<br>Черга 6.2: 22-24<br>Реклама 123</div></div>';
        $text = null;
        $parsed = parseHTMLSchedule($html, $text);
        $this->assertStringContainsString("\nЧерга", $text);
        $this->assertSame('22:00-24:00', $parsed['queues']['6.2']);
    }
    public function testTelegramExtractsEditedVisibleMessagesAndBreaks(): void {
        $html = '<div class="tgme_widget_message" data-post="channel/10"><div class="tgme_widget_message_text">на завтра<br>Черга 1.1: 02–04</div><time datetime="2026-10-07T22:30:00Z"></time></div>';
        $messages = parseTelegramHTML($html);
        $this->assertCount(1, $messages);
        $this->assertStringContainsString("\nЧерга", $messages[0]['text']);
        $parsed = parseScheduleMessage($messages[0]['text'], strtotime($messages[0]['datetime']));
        $this->assertSame('09.10.2026', $parsed['date']); // Kyiv is already Oct 8.
    }
    public function testApiRejectsArrayParametersAndUnsupportedMethods(): void {
        $file = realpath(__DIR__ . '/../../api/blackout.php');
        foreach ([['GET', ['queue' => ['1.1']], 400], ['GET', ['queue' => '99.99'], 400], ['GET', ['all' => '2'], 400], ['POST', ['queue' => '1.1'], 405]] as [$method, $query, $expected]) {
            $script = '$_SERVER["REQUEST_METHOD"]=' . var_export($method, true) . '; $_GET=' . var_export($query, true) . '; register_shutdown_function(function(){echo "\\nSTATUS=".http_response_code();});require ' . var_export($file, true) . ';';
            $output = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script));
            $this->assertStringContainsString('STATUS=' . $expected, $output);
            $json = json_decode(explode("\nSTATUS=", $output)[0], true);
            $this->assertFalse($json['success']);
        }
    }
    public function testCompleteEndpointUsesCurrentCacheForAllTwelveQueues(): void {
        $runtime = sys_get_temp_dir() . '/krop-endpoint-' . bin2hex(random_bytes(5));
        mkdir($runtime, 0700);
        $queues = [];
        for ($n = 1; $n <= 6; $n++) foreach ([1, 2] as $part) $queues["$n.$part"] = $part === 1 ? '' : '02:00-04:00';
        $timestamp = time() - 30;
        file_put_contents($runtime . '/blackout_cache.json', json_encode(['date' => date('d.m.Y'), 'verified_at' => $timestamp, 'queues' => $queues]));
        file_put_contents($runtime . '/last_source_check.txt', (string)time());
        $endpoint = realpath(__DIR__ . '/../../api/blackout.php');
        try {
            foreach (array_keys($queues) as $queue) {
                $script = "define('KROP_TEST_MODE',true); define('ENABLE_LOGGING',false); define('CACHE_DIR'," . var_export($runtime, true) . "); define('LOGS_DIR',CACHE_DIR.'/logs'); \$_SERVER['REQUEST_METHOD']='GET'; \$_GET=['queue'=>" . var_export($queue, true) . "]; require " . var_export($endpoint, true) . ';';
                $output = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script));
                $data = json_decode($output, true);
                $this->assertSame($queues[$queue], $data['schedule']);
                $this->assertSame($timestamp, $data['updated']);
                $this->assertFalse($data['stale']);
            }
        } finally {
            foreach (glob($runtime . '/*') as $file) if (is_file($file)) unlink($file);
            @unlink($runtime . '/logs/.htaccess'); @rmdir($runtime . '/logs');
            @unlink($runtime . '/.htaccess'); rmdir($runtime);
        }
    }
    public function testDefaultsWorkWithoutPrivateConfiguration(): void {
        $bootstrap = realpath(__DIR__ . '/../../api/bootstrap.php');
        $script = "define('KROP_TEST_MODE',true); require " . var_export($bootstrap, true) . "; echo json_encode([TELEGRAM_CHANNEL_URL, TELEGRAM_CHECK_INTERVAL, TELEGRAM_MESSAGES_FILE, date_default_timezone_get()]);";
        $data = json_decode(shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script)), true);
        $this->assertSame('https://t.me/s/SvitloKropyvnytskyiMisto', $data[0]);
        $this->assertSame(300, $data[1]);
        $this->assertStringEndsWith('/cache/telegram_messages.json', $data[2]);
        $this->assertSame('Europe/Kyiv', $data[3]);
    }

}
