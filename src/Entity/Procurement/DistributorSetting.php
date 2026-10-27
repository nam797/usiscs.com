<?php

declare(strict_types=1);

namespace App\Entity\Procurement;

use Doctrine\ORM\Mapping as ORM;

/**
 * Switches staff can flip per distributor in the backoffice: pause sending
 * (orders stay queued until resumed), or send everything for manual entry
 * even when the distributor has an order API.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_distributor_setting')]
class DistributorSetting
{
    #[ORM\Id]
    #[ORM\Column(name: 'code', type: 'string', length: 50)]
    private string $code;

    #[ORM\Column(name: 'paused', type: 'boolean', options: ['default' => false])]
    private bool $paused = false;

    #[ORM\Column(name: 'manual_entry', type: 'boolean', options: ['default' => false])]
    private bool $manualEntry = false;

    #[ORM\Column(name: 'note', type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $code)
    {
        $this->code = $code;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function isPaused(): bool
    {
        return $this->paused;
    }

    public function setPaused(bool $paused): void
    {
        $this->paused = $paused;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isManualEntry(): bool
    {
        return $this->manualEntry;
    }

    public function setManualEntry(bool $manualEntry): void
    {
        $this->manualEntry = $manualEntry;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): void
    {
        $this->note = null === $note || '' === trim($note) ? null : $note;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
