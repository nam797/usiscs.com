<?php

declare(strict_types=1);

namespace App\RateLimiting;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * A minimal throttle for public forms (RFQ, Contact), built on the app's
 * default PSR-6 cache pool (`cache.app`, already installed) rather than
 * symfony/rate-limiter -- that package isn't required by this app yet
 * and composer can't be run from the sandbox this was written in. Swap
 * to symfony/rate-limiter (`composer require symfony/rate-limiter`) if
 * something more featureful is ever needed (token-bucket bursts, etc.);
 * this just closes the real gap, which was zero throttling at all.
 *
 * Fixed window per client IP + form key: at most $maxAttempts submission
 * attempts (valid or not -- an attacker can flood with "valid" payloads
 * just as easily as broken ones) per $windowSeconds.
 */
final class FormSubmissionThrottle
{
    public function __construct(private readonly CacheItemPoolInterface $cache)
    {
    }

    public function tooManyAttempts(string $formKey, string $clientIp, int $maxAttempts, int $windowSeconds): bool
    {
        $item = $this->cache->getItem($this->cacheKey($formKey, $clientIp));

        return $this->attempts($item) >= $maxAttempts;
    }

    public function hit(string $formKey, string $clientIp, int $windowSeconds): void
    {
        $item = $this->cache->getItem($this->cacheKey($formKey, $clientIp));

        $item->set($this->attempts($item) + 1);
        $item->expiresAfter($windowSeconds);

        $this->cache->save($item);
    }

    private function attempts(CacheItemInterface $item): int
    {
        // Only this class writes these keys, so anything that isn't an int
        // (a miss, or a value left by some other format) counts as zero.
        $count = $item->isHit() ? $item->get() : 0;

        return is_int($count) ? $count : 0;
    }

    private function cacheKey(string $formKey, string $clientIp): string
    {
        // Cache keys can't contain {}()/\@: swap the characters an IPv4/
        // IPv6 address can contain for ones that are safe.
        $safeIp = str_replace([':', '.'], '_', $clientIp);

        return sprintf('throttle.%s.%s', $formKey, $safeIp);
    }
}
