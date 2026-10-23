<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\SecurityHeadersListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class SecurityHeadersListenerTest extends TestCase
{
    public function testItAddsTheBaselineHeadersToMainResponses(): void
    {
        $response = $this->dispatch(debug: true);

        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
    }

    public function testItSendsHstsOutsideDebugMode(): void
    {
        $response = $this->dispatch(debug: false);

        self::assertSame('max-age=15768000; includeSubDomains', $response->headers->get('Strict-Transport-Security'));
    }

    public function testItNeverSendsHstsInDebugMode(): void
    {
        // HSTS over local plain HTTP would make browsers force HTTPS on
        // localhost and break the dev server.
        $response = $this->dispatch(debug: true);

        self::assertFalse($response->headers->has('Strict-Transport-Security'));
    }

    public function testItLeavesHeadersAlreadySetByTheResponseAlone(): void
    {
        $response = new Response();
        $response->headers->set('X-Content-Type-Options', 'custom');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Strict-Transport-Security', 'max-age=60');

        $this->dispatch(debug: false, response: $response);

        self::assertSame('custom', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertSame('max-age=60', $response->headers->get('Strict-Transport-Security'));
    }

    public function testItIgnoresSubRequests(): void
    {
        $response = $this->dispatch(debug: false, requestType: HttpKernelInterface::SUB_REQUEST);

        self::assertFalse($response->headers->has('X-Content-Type-Options'));
        self::assertFalse($response->headers->has('Referrer-Policy'));
        self::assertFalse($response->headers->has('Strict-Transport-Security'));
    }

    private function dispatch(
        bool $debug,
        ?Response $response = null,
        int $requestType = HttpKernelInterface::MAIN_REQUEST,
    ): Response {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('isDebug')->willReturn($debug);

        $response ??= new Response();
        $event = new ResponseEvent($kernel, new Request(), $requestType, $response);

        (new SecurityHeadersListener($kernel))->onKernelResponse($event);

        return $response;
    }
}
