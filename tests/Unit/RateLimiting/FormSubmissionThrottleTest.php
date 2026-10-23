<?php

declare(strict_types=1);

namespace App\Tests\Unit\RateLimiting;

use App\RateLimiting\FormSubmissionThrottle;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class FormSubmissionThrottleTest extends TestCase
{
    private ArrayAdapter $cache;

    private FormSubmissionThrottle $throttle;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
        $this->throttle = new FormSubmissionThrottle($this->cache);
    }

    public function testAFreshClientIsNotThrottled(): void
    {
        self::assertFalse($this->throttle->tooManyAttempts('contact', '203.0.113.7', 5, 600));
    }

    public function testItAllowsAttemptsUpToTheLimit(): void
    {
        for ($i = 0; $i < 4; ++$i) {
            $this->throttle->hit('contact', '203.0.113.7', 600);
        }

        self::assertFalse($this->throttle->tooManyAttempts('contact', '203.0.113.7', 5, 600));
    }

    public function testItThrottlesOnceTheLimitIsReached(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->throttle->hit('contact', '203.0.113.7', 600);
        }

        self::assertTrue($this->throttle->tooManyAttempts('contact', '203.0.113.7', 5, 600));
    }

    public function testCountsAreKeptPerClientIp(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->throttle->hit('contact', '203.0.113.7', 600);
        }

        self::assertFalse($this->throttle->tooManyAttempts('contact', '198.51.100.2', 5, 600));
    }

    public function testCountsAreKeptPerForm(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->throttle->hit('contact', '203.0.113.7', 600);
        }

        self::assertFalse($this->throttle->tooManyAttempts('rfq', '203.0.113.7', 5, 600));
    }

    public function testIpv6AddressesProduceValidCacheKeys(): void
    {
        // ':' is reserved in PSR-6 keys; the throttle must translate it or
        // the cache throws InvalidArgumentException on every IPv6 visitor.
        $ip = '2001:db8::ff00:42:8329';

        for ($i = 0; $i < 3; ++$i) {
            $this->throttle->hit('rfq', $ip, 600);
        }

        self::assertTrue($this->throttle->tooManyAttempts('rfq', $ip, 3, 600));
    }

    public function testANonIntegerCachedValueCountsAsZero(): void
    {
        $item = $this->cache->getItem('throttle.contact.203_0_113_7');
        $item->set('garbage');
        $this->cache->save($item);

        self::assertFalse($this->throttle->tooManyAttempts('contact', '203.0.113.7', 1, 600));

        $this->throttle->hit('contact', '203.0.113.7', 600);

        self::assertTrue($this->throttle->tooManyAttempts('contact', '203.0.113.7', 1, 600));
    }

    public function testEachHitSetsTheWindowAsTheExpiry(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn(2);
        $item->expects(self::once())->method('set')->with(3)->willReturnSelf();
        $item->expects(self::once())->method('expiresAfter')->with(600)->willReturnSelf();

        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->with('throttle.rfq.203_0_113_7')->willReturn($item);
        $pool->expects(self::once())->method('save')->with($item)->willReturn(true);

        (new FormSubmissionThrottle($pool))->hit('rfq', '203.0.113.7', 600);
    }
}
