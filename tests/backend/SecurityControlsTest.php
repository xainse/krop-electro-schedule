<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../../api/security.php';
class SecurityControlsTest extends TestCase {
    private $dir;
    protected function setUp(): void {
        $this->dir = sys_get_temp_dir() . '/krop-security-test-' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0700);
    }
    protected function tearDown(): void {
        foreach (glob($this->dir . '/*') as $file) unlink($file);
        @unlink($this->dir . '/.write.lock'); rmdir($this->dir);
    }
    public function testRateLimitAndWindowReset(): void {
        $p = $this->dir . '/limit.json';
        $this->assertSame(0, checkRequestLimit('192.0.2.1', 600, $p, 2, 3));
        $this->assertSame(0, checkRequestLimit('192.0.2.1', 600, $p, 2, 3));
        $this->assertSame(60, checkRequestLimit('192.0.2.1', 600, $p, 2, 3));
        $this->assertSame(0, checkRequestLimit('192.0.2.2', 600, $p, 2, 3));
        $this->assertSame(59, checkRequestLimit('192.0.2.3', 601, $p, 2, 3));
        $this->assertSame(0, checkRequestLimit('192.0.2.1', 660, $p, 2, 3));
        $this->assertStringNotContainsString('192.0.2', file_get_contents($p));
    }
    public function testBusyLimiterRejectsInsteadOfWaiting(): void {
        $p = $this->dir . '/limit.json'; $h = fopen($p, 'c+'); flock($h, LOCK_EX);
        try { $this->assertSame(1, checkRequestLimit('192.0.2.1', 600, $p)); }
        finally { flock($h, LOCK_UN); fclose($h); }
    }
    public function testPrivateRuntimeAndConfigOverridesInFreshProcess(): void {
        $config = $this->dir . '/private-config.php';
        file_put_contents($config, "<?php define('ENABLE_LOGGING', false);");
        $bootstrap = realpath(__DIR__ . '/../../api/bootstrap.php');
        $script = 'putenv(' . var_export('KROP_CONFIG_FILE=' . $config, true) . ');'
            . 'putenv(' . var_export('KROP_RUNTIME_DIR=' . $this->dir, true) . ');'
            . 'require ' . var_export($bootstrap, true) . ';'
            . 'file_put_contents(CACHE_DIR . "-probe", "test");'
            . 'echo json_encode([CACHE_DIR, LOGS_DIR, ENABLE_LOGGING, fileperms(CACHE_DIR . "-probe") & 0777]);';
        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script));
        $this->assertSame([$this->dir . '/cache', $this->dir . '/logs', false, 0600], json_decode($output, true));
    }
    public function testLogCapsPreserveValidJsonAndPrivatePermissions(): void {
        $p = $this->dir . '/api.log';
        $this->assertTrue(appendBoundedLog($p, ['text'=>str_repeat('a',30)],100,150));
        $this->assertTrue(appendBoundedLog($p, ['text'=>str_repeat('b',30)],100,150));
        $this->assertFalse(appendBoundedLog($p, ['text'=>str_repeat('c',30)],100,150));
        $this->assertLessThanOrEqual(100, filesize($p));
        $this->assertSame(0600, fileperms($p)&0777);
        foreach (file($p) as $line) $this->assertIsArray(json_decode($line,true));
        $this->assertTrue(appendBoundedLog($this->dir.'/source.log', ['text'=>str_repeat('d',60)],100,150));
        $this->assertLessThanOrEqual(150,array_sum(array_map('filesize',glob($this->dir.'/*.log'))));
    }
}
