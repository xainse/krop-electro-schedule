<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../../api/http.php';
class UpstreamSecurityTest extends TestCase {
    public function testRejectsUntrustedUrls(): void {
        foreach (['http://t.me/s/test', 'https://evil.example', 'https://t.me.evil.example/', 'https://u:p@t.me/', 'https://t.me:8443/', 'https://127.0.0.1/', "https://t.me/\r\nX:1", 'file:///etc/passwd'] as $url) {
            $this->assertFalse(upstreamUrlAllowed($url), $url);
        }
        $this->assertTrue(upstreamUrlAllowed('https://t.me/s/test'));
    }
    public function testRejectsPrivateAndSpecialAddresses(): void {
        foreach (['127.0.0.1','10.0.0.1','172.16.0.1','192.168.0.1','169.254.169.254','100.64.0.1','192.0.2.1','198.18.0.1','224.0.0.1','255.255.255.255','::1','::ffff:127.0.0.1'] as $ip) $this->assertFalse(publicUpstreamIPv4($ip),$ip);
        $this->assertTrue(publicUpstreamIPv4('8.8.8.8'));
    }
    public function testRedirectCannotDowngradeOrReachOtherHost(): void {
        foreach (['http://t.me/a','https://evil.example/a','https://127.0.0.1/a'] as $target) {
            $calls=0;
            $transport=function() use (&$calls,$target) { $calls++; return ['status'=>302,'body'=>'','location'=>$target]; };
            $this->assertFalse(fetchUpstreamHtml('https://t.me/a',$transport,fn()=>['8.8.8.8']));
            $this->assertSame(1,$calls);
        }
    }
    public function testPinsResolvedAddressAndRejectsMixedPrivateDns(): void {
        $transport=function($url,$ip,$timeout,$limit) { $this->assertSame('8.8.8.8',$ip); return ['status'=>200,'type'=>'text/html; charset=utf-8','body'=>'ok']; };
        $this->assertSame('ok',fetchUpstreamHtml('https://t.me/a',$transport,fn()=>['8.8.8.8']));
        $this->assertFalse(fetchUpstreamHtml('https://t.me/a',function() { $this->fail('Private DNS must not be contacted'); },fn()=>['8.8.8.8','10.0.0.1']));
    }
    public function testOversizeAndRedirectLoopsStop(): void {
        $this->assertFalse(fetchUpstreamHtml('https://t.me/a',fn()=>['status'=>200,'type'=>'text/html','body'=>str_repeat('a',2097153)],fn()=>['8.8.8.8']));
        $calls=0;
        $this->assertFalse(fetchUpstreamHtml('https://t.me/a',function() use (&$calls) { $calls++; return ['status'=>302,'body'=>'','location'=>'/a']; },fn()=>['8.8.8.8']));
        $this->assertSame(4,$calls);
        $this->assertFalse(fetchUpstreamHtml('https://t.me/a',fn()=>['status'=>200,'type'=>'application/octet-stream','body'=>'data'],fn()=>['8.8.8.8']));
    }
}
