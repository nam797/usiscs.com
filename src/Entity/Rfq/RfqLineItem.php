<?php

declare(strict_types=1);

namespace App\Entity\Rfq;

use App\Repository\Rfq\RfqLineItemRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One row of the RFQ form's "Items Needed" table (Qty / Part#/Item# /
 * Description / UOM), per the RFQ Flow Spec doc.
 */
#[ORM\Entity(repositoryClass: RfqLineItemRepository::class)]
#[ORM\Table(name: 'app_rfq_line_item')]
class RfqLineItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: RfqRequest::class, inversedBy: 'lineItems')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?RfqRequest $rfqRequest = null;

    #[ORM\Column(type: 'integer')]
    #[Assert\Positive(message: 'Quantity must be at least 1.')]
    private int $quantity = 1;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $partNumber = null;

    #[ORM\Column(type: 'string', length: 500)]
    #[Assert\NotBlank(message: 'Please describe the item.')]
    private string $description = '';

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $unitOfMeasure = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRfqRequest(): ?RfqRequest
    {
        return $this->rfqRequest;
    }

    public function setRfqRequest(?RfqRequest $rfqRequest): void
    {
        $this->rfqRequest = $rfqRequest;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): void
    {
        $this->quantity = $quantity;
    }

    public function getPartNumber(): ?string
    {
        return $this->partNumber;
    }

    public function setPartNumber(?string $partNumber): void
    {
        $this->partNumber = $partNumber;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    public function getUnitOfMeasure(): ?string
    {
        return $this->unitOfMeasure;
    }

    public function setUnitOfMeasure(?string $unitOfMeasure): void
    {
        $this->unitOfMeasure = $unitOfMeasure;
    }

    public function __toString(): string
    {
        return sprintf('%dx %s', $this->quantity, $this->description);
    }
}
