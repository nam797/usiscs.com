<?php

declare(strict_types=1);

namespace App\Entity\Mail;

use App\Repository\Mail\SentEmailRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One email the site sent (or failed to send), and what happened to it
 * after: Postmark's webhooks (/webhook/postmark) report it delivered to the
 * recipient's mail server, bounced, marked as spam or opened.
 *
 * Rows are written by SentEmailRecorder straight through the database
 * connection, so recording an email never flushes someone else's
 * half-finished changes. Staff read them in /backoffice → Emails.
 */
#[ORM\Entity(repositoryClass: SentEmailRepository::class)]
#[ORM\Table(name: 'app_sent_email')]
#[ORM\Index(name: 'idx_app_sent_email_message_id', columns: ['message_id'])]
#[ORM\Index(name: 'idx_app_sent_email_sent_at', columns: ['sent_at'])]
class SentEmail
{
    /** Handed to the mail provider; nothing heard back yet. */
    public const STATUS_SENT = 'sent';
    /** Accepted by the recipient's mail server. */
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_BOUNCED = 'bounced';
    /** The recipient marked it as spam. */
    public const STATUS_SPAM = 'spam';
    /** The mail provider refused it, or couldn't be reached. */
    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_SENT,
        self::STATUS_DELIVERED,
        self::STATUS_BOUNCED,
        self::STATUS_SPAM,
        self::STATUS_FAILED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** The provider's id for it (Postmark's MessageID), which its webhooks quote. */
    #[ORM\Column(name: 'message_id', type: 'string', length: 255, nullable: true)]
    private ?string $messageId = null;

    /** What kind of email: a Sylius email code (order_confirmation) or one of ours (rfq_request). */
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $type = null;

    #[ORM\Column(type: 'string', length: 1000)]
    private string $recipients = '';

    /** Who it was addressed to, when it was redirected outside production. */
    #[ORM\Column(name: 'original_recipients', type: 'string', length: 1000, nullable: true)]
    private ?string $originalRecipients = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $subject = '';

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_SENT;

    /** Why it bounced or failed, as the provider put it. */
    #[ORM\Column(name: 'status_detail', type: 'text', nullable: true)]
    private ?string $statusDetail = null;

    #[ORM\Column(name: 'sent_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $sentAt;

    #[ORM\Column(name: 'delivered_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deliveredAt = null;

    /** First open. Only counted when images load, so "not opened" proves nothing. */
    #[ORM\Column(name: 'opened_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $openedAt = null;

    public function __construct()
    {
        $this->sentAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function getRecipients(): string
    {
        return $this->recipients;
    }

    public function getOriginalRecipients(): ?string
    {
        return $this->originalRecipients;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getStatusDetail(): ?string
    {
        return $this->statusDetail;
    }

    public function getSentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function getDeliveredAt(): ?\DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    public function getOpenedAt(): ?\DateTimeImmutable
    {
        return $this->openedAt;
    }

    public function markDelivered(\DateTimeImmutable $at): void
    {
        $this->deliveredAt = $at;
        // A bounce or complaint can arrive before the delivery report; keep it.
        if (self::STATUS_SENT === $this->status) {
            $this->status = self::STATUS_DELIVERED;
        }
    }

    public function markBounced(string $reason): void
    {
        $this->status = self::STATUS_BOUNCED;
        $this->statusDetail = '' === $reason ? null : $reason;
    }

    public function markSpam(): void
    {
        $this->status = self::STATUS_SPAM;
    }

    public function markOpened(\DateTimeImmutable $at): void
    {
        $this->openedAt ??= $at;
    }
}
