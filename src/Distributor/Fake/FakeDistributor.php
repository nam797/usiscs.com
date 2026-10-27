<?php

declare(strict_types=1);

namespace App\Distributor\Fake;

use App\Distributor\DistributorInterface;
use App\Distributor\DistributorUnavailableException;
use App\Distributor\OrderRejectedException;
use App\Distributor\SubmissionResult;
use App\Entity\Procurement\PurchaseOrder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * A pretend distributor for trying the order flow locally (dev and test
 * only). Set a variant's distributor to "Test distributor" in the admin,
 * then choose how it behaves with FAKE_DISTRIBUTOR_MODE in .env.local:
 *
 *   accept  takes every order (default)
 *   down    always unavailable, so orders retry and finally need attention
 *   reject  refuses every order
 *   manual  has no order API, like ORS today
 */
#[When(env: 'dev')]
#[When(env: 'test')]
#[When(env: 'test_cached')]
final class FakeDistributor implements DistributorInterface
{
    public const CODE = 'fake';

    public function __construct(
        #[Autowire(env: 'FAKE_DISTRIBUTOR_MODE')]
        private readonly string $mode = 'accept',
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getName(): string
    {
        return 'Test distributor';
    }

    public function canSubmit(): bool
    {
        return 'manual' !== $this->mode;
    }

    public function submit(PurchaseOrder $purchaseOrder): SubmissionResult
    {
        return match ($this->mode) {
            'down' => throw new DistributorUnavailableException('Test distributor is down (FAKE_DISTRIBUTOR_MODE=down)'),
            'reject' => throw new OrderRejectedException('Test distributor refused the order (FAKE_DISTRIBUTOR_MODE=reject)'),
            default => new SubmissionResult('FAKE-'.$purchaseOrder->getNumber(), 'Accepted by the test distributor'),
        };
    }

    public function findExisting(PurchaseOrder $purchaseOrder): ?string
    {
        return null;
    }
}
