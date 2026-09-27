<?php

declare(strict_types=1);

namespace App\Entity\Rfq;

use App\Entity\Customer\Customer;
use App\Repository\Rfq\RfqRequestRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The "can't find what you need" RFQ flow, carried over from the
 * usiscs.com /quote page. See the RFQ Flow Spec doc for the source
 * field list and open questions (submission routing, validation rules).
 *
 * customerType and urgencyLevel are kept as plain strings with the
 * choices enforced in the EasyAdmin form (RfqRequestCrudController)
 * rather than PHP enums, so the option lists can be adjusted without a
 * migration.
 */
#[ORM\Entity(repositoryClass: RfqRequestRepository::class)]
#[ORM\Table(name: 'app_rfq_request')]
class RfqRequest
{
    public const CUSTOMER_TYPE_NEW = 'new';
    public const CUSTOMER_TYPE_EXISTING = 'existing';

    public const URGENCY_STANDARD = 'standard';
    public const URGENCY_RUSH = 'rush';
    public const URGENCY_EMERGENCY = 'emergency';

    public const STATUS_NEW = 'new';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_QUOTED = 'quoted';
    public const STATUS_CLOSED = 'closed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 20)]
    #[Assert\Choice(choices: [self::CUSTOMER_TYPE_NEW, self::CUSTOMER_TYPE_EXISTING])]
    private string $customerType = self::CUSTOMER_TYPE_NEW;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Customer $customer = null;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank(message: 'Please enter your full name.')]
    private string $fullName = '';

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank(message: 'Please enter your company name.')]
    private string $company = '';

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank(message: 'Please enter your email address.')]
    #[Assert\Email(message: 'Please enter a valid email address.')]
    private string $email = '';

    #[ORM\Column(type: 'string', length: 50)]
    #[Assert\NotBlank(message: 'Please enter a phone number.')]
    private string $phone = '';

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $jobSite = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $requiredByDate = null;

    #[ORM\Column(type: 'string', length: 20)]
    #[Assert\Choice(choices: [self::URGENCY_STANDARD, self::URGENCY_RUSH, self::URGENCY_EMERGENCY])]
    private string $urgencyLevel = self::URGENCY_STANDARD;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $deliveryAddress = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $additionalNotes = null;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_NEW;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, RfqLineItem> */
    #[ORM\OneToMany(targetEntity: RfqLineItem::class, mappedBy: 'rfqRequest', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Assert\Count(min: 1, minMessage: 'Add at least one item.')]
    #[Assert\Valid]
    private Collection $lineItems;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->lineItems = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomerType(): string
    {
        return $this->customerType;
    }

    public function setCustomerType(string $customerType): void
    {
        $this->customerType = $customerType;
    }

    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function setCustomer(?Customer $customer): void
    {
        $this->customer = $customer;
    }

    public function getFullName(): string
    {
        return $this->fullName;
    }

    public function setFullName(string $fullName): void
    {
        $this->fullName = $fullName;
    }

    public function getCompany(): string
    {
        return $this->company;
    }

    public function setCompany(string $company): void
    {
        $this->company = $company;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function getPhone(): string
    {
        return $this->phone;
    }

    public function setPhone(string $phone): void
    {
        $this->phone = $phone;
    }

    public function getJobSite(): ?string
    {
        return $this->jobSite;
    }

    public function setJobSite(?string $jobSite): void
    {
        $this->jobSite = $jobSite;
    }

    public function getRequiredByDate(): ?\DateTimeImmutable
    {
        return $this->requiredByDate;
    }

    public function setRequiredByDate(?\DateTimeImmutable $requiredByDate): void
    {
        $this->requiredByDate = $requiredByDate;
    }

    public function getUrgencyLevel(): string
    {
        return $this->urgencyLevel;
    }

    public function setUrgencyLevel(string $urgencyLevel): void
    {
        $this->urgencyLevel = $urgencyLevel;
    }

    public function getDeliveryAddress(): ?string
    {
        return $this->deliveryAddress;
    }

    public function setDeliveryAddress(?string $deliveryAddress): void
    {
        $this->deliveryAddress = $deliveryAddress;
    }

    public function getAdditionalNotes(): ?string
    {
        return $this->additionalNotes;
    }

    public function setAdditionalNotes(?string $additionalNotes): void
    {
        $this->additionalNotes = $additionalNotes;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, RfqLineItem> */
    public function getLineItems(): Collection
    {
        return $this->lineItems;
    }

    public function addLineItem(RfqLineItem $lineItem): void
    {
        if (!$this->lineItems->contains($lineItem)) {
            $this->lineItems->add($lineItem);
            $lineItem->setRfqRequest($this);
        }
    }

    public function removeLineItem(RfqLineItem $lineItem): void
    {
        $this->lineItems->removeElement($lineItem);
    }

    public function __toString(): string
    {
        return sprintf('RFQ #%d - %s (%s)', $this->id ?? 0, $this->company, $this->fullName);
    }
}
