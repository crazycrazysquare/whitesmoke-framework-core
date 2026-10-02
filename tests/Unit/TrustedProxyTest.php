<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Whitesmoke\Http\Request;

final class TrustedProxyTest extends TestCase
{
    private const KEYS = ['REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_PROTO', 'HTTPS', 'SERVER_PORT'];

    private array $saved = [];

    protected function setUp(): void
    {
        foreach (self::KEYS as $key) {
            $this->saved[$key] = $_SERVER[$key] ?? null;
            unset($_SERVER[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
    }

    private function request(string $remote, ?string $forwardedFor = null, array $trusted = ['10.0.0.0/8'], ?string $proto = null): Request
    {
        $_SERVER['REMOTE_ADDR'] = $remote;
        unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_FORWARDED_PROTO']);

        if ($forwardedFor !== null) {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $forwardedFor;
        }
        if ($proto !== null) {
            $_SERVER['HTTP_X_FORWARDED_PROTO'] = $proto;
        }

        return Request::capture($trusted);
    }

    public function testHeadersAreIgnoredWithoutTrustedProxies(): void
    {
        $request = $this->request('10.0.0.5', '203.0.113.7', [], 'https');

        $this->assertSame('10.0.0.5', $request->ip());
        $this->assertFalse($request->isSecure());
    }

    public function testUntrustedSenderCannotChooseItsIp(): void
    {
        $request = $this->request('198.51.100.9', '203.0.113.7', ['10.0.0.0/8'], 'https');

        $this->assertSame('198.51.100.9', $request->ip(), 'direct request with a forged header');
        $this->assertFalse($request->isSecure());
    }

    public function testClientIsTheFirstUntrustedAddressFromTheRight(): void
    {
        $this->assertSame('203.0.113.7', $this->request('10.0.0.5', '203.0.113.7')->ip());
        $this->assertSame('203.0.113.7', $this->request('10.0.0.5', '203.0.113.7, 10.0.0.9')->ip(), 'two proxies');
        $this->assertSame('203.0.113.7', $this->request('10.0.0.5', '127.0.0.1, 1.1.1.1, 203.0.113.7')->ip(), 'client-supplied entries on the left are ignored');
    }

    public function testMissingHeaderOrOnlyProxiesGiveTheNearestProxy(): void
    {
        $this->assertSame('10.0.0.5', $this->request('10.0.0.5')->ip());
        $this->assertSame('10.0.0.5', $this->request('10.0.0.5', '')->ip());
        $this->assertSame('10.0.0.3', $this->request('10.0.0.5', '10.0.0.3, 10.0.0.4')->ip());
    }

    public function testMalformedEntryStopsAtTheLastTrustedHop(): void
    {
        $this->assertSame('10.0.0.5', $this->request('10.0.0.5', '203.0.113.7, unknown')->ip());
        $this->assertSame('10.0.0.9', $this->request('10.0.0.5', '203.0.113.7, 300.1.1.1, 10.0.0.9')->ip());
        $this->assertSame('10.0.0.5', $this->request('10.0.0.5', "203.0.113.7\0")->ip());
    }

    public function testPortsAreDroppedAndAddressesNormalized(): void
    {
        $this->assertSame('203.0.113.7', $this->request('10.0.0.5', '203.0.113.7:4711')->ip());
        $this->assertSame('2001:db8::1', $this->request('10.0.0.5', '[2001:DB8:0:0::1]:443')->ip());
        $this->assertSame('2001:db8::1', $this->request('10.0.0.5', '2001:0db8:0000::0001')->ip());
        $this->assertSame('2001:db8::1', $this->request('10.0.0.5', '[2001:db8::1]')->ip());
    }

    public function testCidrRangesMatchExactly(): void
    {
        $client = '203.0.113.7';
        $trusted = fn (string $remote, string $range): bool => $this->request($remote, $client, [$range])->ip() === $client;

        $this->assertTrue($trusted('192.168.4.0', '192.168.4.0/22'));
        $this->assertTrue($trusted('192.168.7.255', '192.168.4.0/22'));
        $this->assertFalse($trusted('192.168.8.0', '192.168.4.0/22'));
        $this->assertFalse($trusted('192.168.3.255', '192.168.4.0/22'));
        $this->assertTrue($trusted('10.1.2.3', '10.1.2.3'), 'a single address');
        $this->assertFalse($trusted('10.1.2.4', '10.1.2.3'));
        $this->assertTrue($trusted('2001:db8:ffff::1', '2001:db8::/32'));
        $this->assertFalse($trusted('2001:db9::1', '2001:db8::/32'));
        $this->assertFalse($trusted('::ffff:10.0.0.5', '10.0.0.0/8'), 'IPv4 ranges do not match IPv6 addresses');
        $this->assertTrue($trusted('fe80::1', 'fe80::/10'));
        $this->assertFalse($trusted('fec0::1', 'fe80::/10'));
    }

    public function testHttpsFromATrustedProxy(): void
    {
        $this->assertTrue($this->request('10.0.0.5', null, ['10.0.0.0/8'], 'https')->isSecure());
        $this->assertTrue($this->request('10.0.0.5', null, ['10.0.0.0/8'], ' HTTPS ')->isSecure());
        $this->assertFalse($this->request('10.0.0.5', null, ['10.0.0.0/8'], 'http')->isSecure());
        $this->assertFalse($this->request('10.0.0.5', null, ['10.0.0.0/8'], 'https, http')->isSecure(), 'lists are ambiguous and ignored');
        $this->assertFalse($this->request('10.0.0.5')->isSecure());
    }

    public function testDirectHttpsIsStillDetected(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $this->assertTrue($this->request('198.51.100.9', null, [])->isSecure());

        $_SERVER['HTTPS'] = 'off';
        $_SERVER['SERVER_PORT'] = '443';
        $this->assertTrue($this->request('198.51.100.9', null, [])->isSecure());
    }

    public function testInvalidOrTrustEverythingConfigIsRefused(): void
    {
        foreach (['*', '0.0.0.0/0', '::/0', '10.0.0.0/33', '2001:db8::/129', '10.0.0.0/', '10.0.0.0/x', '10.0.0.0/-1', 'example.com', '', ' ', '10.0.0', 123, null] as $bad) {
            try {
                $this->request('10.0.0.5', null, [$bad]);
                $this->fail('Should refuse ' . var_export($bad, true));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Invalid trusted proxy', $e->getMessage());
            }
        }
    }
}
