<?php

declare(strict_types=1);

namespace App\Entity\Shipping;

use App\Repository\Shipping\PackageProtectionTierRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One price band for package protection: carts whose items total is at
 * least $minItemsTotal pay $fee, up to the next band (TierPicker). Set by
 * the client in the backoffice. Amounts in cents.
 */
#[ORM\Entity(repositoryClass: PackageProtectionTierRepository::class)]
#[ORM\Table(name: 'app_package_protection_tier')]
#[UniqueEntity(fields: ['minItemsTotal'], message: 'There is already a tier starting at this cart total.')]
class PackageProtectionTier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'min_items_total', type: 'integer', unique: true)]
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    private int $minItemsTotal = 0;

    #[ORM\Column(name: 'fee', type: 'integer')]
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    private int $fee = 0;

    public function __construct(int $minItemsTotal = 0, int $fee = 0)
    {
        $this->minItemsTotal = $minItemsTotal;
        $this->fee = $fee;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMinItemsTotal(): int
    {
        return $this->minItemsTotal;
    }

    public function setMinItemsTotal(?int $minItemsTotal): void
    {
        $this->minItemsTotal = $minItemsTotal ?? 0;
    }

    public function getFee(): int
    {
        return $this->fee;
    }

    public function setFee(?int $fee): void
    {
        $this->fee = $fee ?? 0;
    }
}
