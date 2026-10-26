<?php

declare(strict_types=1);

namespace App\Entity\Contact;

use App\Repository\Contact\ContactMessageRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A message sent through the /contact form. Saved before the team is
 * emailed, so a lead is never lost to a mail outage; staff work through
 * them in /backoffice. Validation lives on ContactRequestType.
 */
#[ORM\Entity(repositoryClass: ContactMessageRepository::class)]
#[ORM\Table(name: 'app_contact_message')]
class ContactMessage
{
    public const STATUS_NEW = 'new';
    public const STATUS_HANDLED = 'handled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_NEW;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Column(type: 'string', length: 255)]
        private string $fullName,
        #[ORM\Column(type: 'string', length: 255)]
        private string $email,
        #[ORM\Column(type: 'string', length: 50)]
        private string $topic,
        #[ORM\Column(type: 'text')]
        private string $message,
        #[ORM\Column(type: 'string', length: 255, nullable: true)]
        private ?string $company = null,
        #[ORM\Column(type: 'string', length: 50, nullable: true)]
        private ?string $phone = null,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getFullName(): string
    {
        return $this->fullName;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getTopic(): string
    {
        return $this->topic;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getCompany(): ?string
    {
        return $this->company;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }
}
