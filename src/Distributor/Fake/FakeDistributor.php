<?php

declare(strict_types=1);

namespace App\Distributor\Fake;

use App\Distributor\DistributorInterface;
use App\Distributor\DistributorOrderStatus;
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
 *
 * It reports no order status, except what a test sets with reportStatus().
 */
#[When(env: 'dev')]
#[When(env: 'test')]
#[When(env: 'test_cached')]
final class FakeDistributor implements DistributorInterface
{
    public const CODE = 'fake';

    /** @var array<string, DistributorOrderStatus> by purchase order number */
    private array $statuses = [];

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

    public function fetchStatus(PurchaseOrder $purchaseOrder): ?DistributorOrderStatus
    {
        return $this->statuses[$purchaseOrder->getNumber()] ?? null;
    }

    /** For tests: what fetchStatus() reports for this purchase order from now on. */
    public function reportStatus(string $purchaseOrderNumber, DistributorOrderStatus $status): void
    {
        $this->statuses[$purchaseOrderNumber] = $status;
    }
}
