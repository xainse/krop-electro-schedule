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
    public function testNotAnnouncedRequiresVerifiedNotice(): void {
        $now = strtotime('2026-10-08 12:00:00');
        $data = ['date' => '08.10.2026', 'verified_at' => $now - 10, 'queues' => [], 'not_announced' => true, 'notice_verified' => true];
        $response = scheduleResponse($data, '1.1', $now);
        $this->assertTrue($response['not_announced']);
        $this->assertFalse($response['available']);
        $this->assertFalse($response['stale']);
        $this->assertSame('На сьогодні немає актуального графіку', $response['message']);
        $this->assertNull($response['schedule']);
        $this->assertFalse(isCurrentSchedulePayload(['date' => '08.10.2026', 'queues' => [], 'not_announced' => true], $now));
        $this->assertTrue(isCurrentSchedulePayload(['date' => '08.10.2026', 'queues' => ['1.1' => '']], $now));
        unset($data['notice_verified']);
        $this->assertTrue(scheduleResponse($data, '1.1', $now)['stale']);
        $this->assertFalse(scheduleResponse($data, '1.1', $now)['not_announced']);
        $this->assertNull(selectSourcePayload($data, null, $now));
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
            'notice_verified' => true,
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
            $this->assertSame('На сьогодні немає актуального графіку', $data['message']);
        } finally {
            foreach (glob($runtime . '/*') as $file) if (is_file($file)) unlink($file);
            @unlink($runtime . '/logs/.htaccess'); @rmdir($runtime . '/logs');
            @unlink($runtime . '/.htaccess'); @rmdir($runtime);
        }
    }
    public function testAbsenceRequiresExplicitDatedNotice(): void {
        $now = strtotime('2026-10-08 12:00:00');
        $this->assertNull(selectSourcePayload(null, false, $now));
        $this->assertNull(selectSourcePayload(false, null, $now));
        $this->assertFalse(parseNoScheduleNotice('Сторінка на технічному обслуговуванні'));
        $this->assertFalse(parseNoScheduleNotice('На сьогодні немає актуального графіку'));
        $notice = parseNoScheduleNotice('Сьогодні графіки відключення не оголошені', $now);
        $this->assertTrue($notice['not_announced']);
        $this->assertSame($notice, selectSourcePayload($notice, false, $now));
        $this->assertNull(selectSourcePayload($notice, null, $now + 86400));
        $schedule = ['date' => '08.10.2026', 'queues' => ['1.1' => '02:00-04:00']];
        $this->assertSame($schedule, selectSourcePayload($notice, $schedule, $now));
        $notice['verified_at'] = $now - 900;
        $this->assertFalse(scheduleResponse($notice, '1.1', $now)['not_announced']);
    }
    public function testSuccessfulTelegramEmptyDayReplacesYesterdayCache(): void {
        $now = strtotime('2026-10-18 20:00:00');
        $absence = [
            'date' => '18.10.2026',
            'queues' => [],
            'not_announced' => true,
            'notice_verified' => true,
            'source' => 'https://t.me/s/SvitloKropyvnytskyiMisto',
        ];
        $this->assertSame($absence, selectSourcePayload($absence, false, $now));
        $yesterday = ['date' => '17.10.2026', 'queues' => ['1.1' => '12:00-14:00'], 'verified_at' => $now - 3600];
        $this->assertTrue(scheduleResponse($yesterday, '1.1', $now)['stale']);
        $this->assertFalse(scheduleResponse($yesterday, '1.1', $now)['available']);
        $fresh = $absence;
        $fresh['verified_at'] = $now;
        $response = scheduleResponse($fresh, '1.1', $now);
        $this->assertTrue($response['not_announced']);
        $this->assertFalse($response['stale']);
        $this->assertSame('На сьогодні немає актуального графіку', $response['message']);
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

    public function testSaveSchedulesKeepsHistoryAndSkipsAbsenceMarkers(): void {
        $path = SCHEDULES_FILE;
        $backup = is_file($path) ? file_get_contents($path) : null;
        try {
            @unlink($path);
            $this->assertTrue(saveSchedules(['1.1' => '01:00-02:00'], '01.01.2024', false, 'test'));
            $this->assertTrue(saveSchedules(['1.1' => '03:00-04:00'], '02.01.2024', false, 'test'));
            $store = loadSchedulesFile();
            $this->assertSame('01:00-02:00', $store['dates']['2024-01-01']['queues']['1.1']);
            $this->assertSame('03:00-04:00', $store['dates']['2024-01-02']['queues']['1.1']);
            $this->assertIsInt($store['dates']['2024-01-01']['verified_at']);
            $this->assertFalse(storeFetchedSchedule([
                'date' => '03.01.2024',
                'queues' => [],
                'not_announced' => true,
                'notice_verified' => true,
            ], SOURCE_SITE));
            $this->assertArrayNotHasKey('2024-01-03', loadSchedulesFile()['dates']);
            $this->assertTrue(storeFetchedSchedule([
                'date' => '04.01.2024',
                'queues' => ['1.1' => ''],
                'emergency_mode' => false,
            ], SOURCE_SITE));
            $stored = getScheduleByDate('04.01.2024');
            $this->assertSame('', $stored['queues']['1.1']);
            $this->assertSame(SOURCE_SITE, $stored['source']);
            $this->assertArrayHasKey('2024-01-01', loadSchedulesFile()['dates']);
        } finally {
            if ($backup === null) @unlink($path);
            else file_put_contents($path, $backup);
        }
    }

    public function testDatedScheduleResponseDistinguishesMissingPastAndFuture(): void {
        $now = strtotime('2026-11-04 12:00:00');
        $past = scheduleResponseForDate([
            'date' => '01.01.2024',
            'timestamp' => $now - 86400,
            'queues' => ['1.1' => ''],
            'emergency_mode' => false,
            'source' => 'telegram',
        ], '01.01.2024', '1.1', $now);
        $this->assertSame('', $past['schedule']);
        $this->assertTrue($past['available']);
        $this->assertFalse($past['stale']);
        $this->assertFalse($past['not_announced']);
        $this->assertSame($now - 86400, $past['updated']);

        $future = scheduleResponseForDate([
            'date' => '05.11.2026',
            'verified_at' => $now - 700,
            'queues' => ['1.1' => '02:00-04:00'],
            'emergency_mode' => true,
        ], '05.11.2026', '1.1', $now);
        $this->assertSame('02:00-04:00', $future['schedule']);
        $this->assertTrue($future['available']);
        $this->assertTrue($future['stale']);
        $this->assertTrue($future['emergency_mode']);
        $fresh = scheduleResponseForDate([
            'date' => '05.11.2026',
            'verified_at' => $now - 30,
            'queues' => ['1.1' => '02:00-04:00'],
        ], '05.11.2026', '1.1', $now);
        $this->assertFalse($fresh['stale']);
        $this->assertSame('02:00-04:00', $fresh['schedule']);

        $missingPast = scheduleResponseForDate(null, '01.01.2024', '1.1', $now);
        $this->assertTrue($missingPast['success']);
        $this->assertFalse($missingPast['available']);
        $this->assertFalse($missingPast['not_announced']);
        $this->assertNull($missingPast['schedule']);
        $this->assertNull($missingPast['updated']);
        $this->assertSame('Немає збереженого графіка на 01.01.2024', $missingPast['message']);

        $missingFuture = scheduleResponseForDate(null, '05.11.2026', null, $now);
        $this->assertSame('Графік на 05.11.2026 ще не опубліковано', $missingFuture['message']);
        $this->assertSame([], (array)$missingFuture['queues']);

        $missingQueue = scheduleResponseForDate([
            'date' => '01.01.2024',
            'timestamp' => $now,
            'queues' => ['1.1' => '02:00-03:00'],
        ], '01.01.2024', '6.2', $now);
        $this->assertNull($missingQueue['schedule']);
        $this->assertFalse($missingQueue['available']);
        $this->assertSame('Немає даних для черги 6.2 на 01.01.2024', $missingQueue['message']);
    }

    public function testDateParameterReadsStoredDayWithoutReplacingToday(): void {
        $runtime = sys_get_temp_dir() . '/krop-date-' . bin2hex(random_bytes(5));
        mkdir($runtime, 0700);
        $today = date('d.m.Y');
        $todayKey = date('Y-m-d');
        $tomorrow = date('d.m.Y', strtotime('+1 day'));
        $tomorrowKey = date('Y-m-d', strtotime('+1 day'));
        $yesterday = date('d.m.Y', strtotime('-1 day'));
        $yesterdayKey = date('Y-m-d', strtotime('-1 day'));
        $old = time() - 86400;
        $fresh = time() - 30;
        file_put_contents($runtime . '/blackout_cache.json', json_encode([
            'date' => $today,
            'verified_at' => $fresh,
            'queues' => ['1.1' => '08:00-09:00'],
            'source' => 'cache',
        ]));
        file_put_contents($runtime . '/last_source_check.txt', (string)time());
        file_put_contents($runtime . '/schedules.json', json_encode([
            'version' => 2,
            'dates' => [
                $todayKey => [
                    'date' => $today,
                    'timestamp' => $fresh,
                    'verified_at' => $fresh,
                    'queues' => ['1.1' => '23:00-24:00'],
                    'source' => 'schedules',
                ],
                $yesterdayKey => [
                    'date' => $yesterday,
                    'timestamp' => $old,
                    'emergency_mode' => false,
                    'source' => 'telegram',
                    'queues' => ['1.1' => '12:00-13:00', '1.2' => ''],
                ],
                $tomorrowKey => [
                    'date' => $tomorrow,
                    'timestamp' => $fresh,
                    'verified_at' => $fresh,
                    'emergency_mode' => true,
                    'source' => 'telegram',
                    'queues' => ['1.1' => '05:00-06:00'],
                ],
            ],
        ]));
        try {
            [$default, $defaultStatus] = $this->runBlackout($runtime, ['queue' => '1.1']);
            $this->assertSame(200, $defaultStatus);
            $this->assertSame('08:00-09:00', $default['schedule']);
            $this->assertSame($today, $default['date']);

            [$explicitToday] = $this->runBlackout($runtime, ['queue' => '1.1', 'date' => $today]);
            $this->assertSame('08:00-09:00', $explicitToday['schedule']);

            [$next, $nextStatus] = $this->runBlackout($runtime, ['queue' => '1.1', 'date' => $tomorrow]);
            $this->assertSame(200, $nextStatus);
            $this->assertSame('05:00-06:00', $next['schedule']);
            $this->assertTrue($next['available']);
            $this->assertFalse($next['stale']);
            $this->assertFalse($next['not_announced']);
            $this->assertTrue($next['emergency_mode']);
            $this->assertSame($fresh, $next['updated']);

            [$previous] = $this->runBlackout($runtime, ['queue' => '1.1', 'date' => $yesterday]);
            $this->assertSame('12:00-13:00', $previous['schedule']);
            $this->assertFalse($previous['stale']);
            $this->assertSame($old, $previous['updated']);

            [$emptyQueue] = $this->runBlackout($runtime, ['queue' => '1.2', 'date' => $yesterday]);
            $this->assertSame('', $emptyQueue['schedule']);
            $this->assertTrue($emptyQueue['available']);

            [$absentQueue, $absentStatus] = $this->runBlackout($runtime, ['queue' => '6.2', 'date' => $yesterday]);
            $this->assertSame(200, $absentStatus);
            $this->assertNull($absentQueue['schedule']);
            $this->assertFalse($absentQueue['available']);
            $this->assertFalse($absentQueue['not_announced']);
            $this->assertSame('Немає даних для черги 6.2 на ' . $yesterday, $absentQueue['message']);

            [$missingPast, $missingPastStatus] = $this->runBlackout($runtime, ['queue' => '1.1', 'date' => '15.03.2020']);
            $this->assertSame(200, $missingPastStatus);
            $this->assertNull($missingPast['schedule']);
            $this->assertSame('Немає збереженого графіка на 15.03.2020', $missingPast['message']);

            [$missingFuture] = $this->runBlackout($runtime, ['all' => '1', 'date' => '01.01.2099']);
            $this->assertSame('Графік на 01.01.2099 ще не опубліковано', $missingFuture['message']);
            $this->assertFalse($missingFuture['not_announced']);
            $this->assertSame([], $missingFuture['queues']);

            [$overview] = $this->runBlackout($runtime, ['all' => '1', 'date' => $tomorrow]);
            $this->assertSame('05:00-06:00', $overview['queues']['1.1']);
            $this->assertTrue($overview['available']);
        } finally {
            $this->removeRuntime($runtime);
        }
    }

    public function testDateParameterRejectsInvalidValues(): void {
        $file = realpath(__DIR__ . '/../../api/blackout.php');
        foreach (['31.02.2026', '2026-11-05', '4.11.2026'] as $date) {
            $query = ['queue' => '1.1', 'date' => $date];
            $script = '$_SERVER["REQUEST_METHOD"]="GET"; $_GET=' . var_export($query, true) . '; register_shutdown_function(function(){echo "\nSTATUS=".http_response_code();});require ' . var_export($file, true) . ';';
            $output = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script));
            $this->assertStringContainsString('STATUS=400', $output, $date);
            $json = json_decode(explode("\nSTATUS=", $output)[0], true);
            $this->assertFalse($json['success']);
            $this->assertSame('Invalid date. Expected DD.MM.YYYY', $json['error']);
        }
        $script = '$_SERVER["REQUEST_METHOD"]="GET"; $_GET=' . var_export(['queue' => '1.1', 'date' => ['04.11.2026']], true) . '; register_shutdown_function(function(){echo "\nSTATUS=".http_response_code();});require ' . var_export($file, true) . ';';
        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script));
        $this->assertStringContainsString('STATUS=400', $output);
        $json = json_decode(explode("\nSTATUS=", $output)[0], true);
        $this->assertSame('Parameters must be strings', $json['error']);
    }

    public function testMissingFutureDateIsNotAServiceFailure(): void {
        $runtime = sys_get_temp_dir() . '/krop-date-empty-' . bin2hex(random_bytes(5));
        mkdir($runtime, 0700);
        file_put_contents($runtime . '/last_source_check.txt', (string)time());
        try {
            [$data, $status] = $this->runBlackout($runtime, ['queue' => '1.1', 'date' => '01.01.2099']);
            $this->assertSame(200, $status);
            $this->assertTrue($data['success']);
            $this->assertFalse($data['available']);
            $this->assertFalse($data['not_announced']);
            $this->assertNull($data['schedule']);
            $this->assertSame('Графік на 01.01.2099 ще не опубліковано', $data['message']);
        } finally {
            $this->removeRuntime($runtime);
        }
    }

    private function runBlackout(string $runtime, array $query): array {
        $endpoint = realpath(__DIR__ . '/../../api/blackout.php');
        $script = "define('KROP_TEST_MODE',true); define('ENABLE_LOGGING',false); define('CACHE_DIR'," . var_export($runtime, true) . "); define('LOGS_DIR',CACHE_DIR.'/logs'); \$_SERVER['REQUEST_METHOD']='GET'; \$_GET=" . var_export($query, true) . '; register_shutdown_function(function(){echo "\nSTATUS=".http_response_code();}); require ' . var_export($endpoint, true) . ';';
        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script));
        $parts = explode("\nSTATUS=", (string)$output);
        return [json_decode($parts[0], true), (int)($parts[1] ?? 0)];
    }

    private function removeRuntime(string $runtime): void {
        foreach (glob($runtime . '/*') as $file) if (is_file($file)) unlink($file);
        @unlink($runtime . '/logs/.htaccess');
        @rmdir($runtime . '/logs');
        @unlink($runtime . '/.htaccess');
        @rmdir($runtime);
    }

}
