<?php

declare(strict_types=1);

namespace App\Mail\Tracking;

use App\Entity\Mail\SentEmail;
use App\Mail\RedirectOutsideProductionListener;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\FailedMessageEvent;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

/**
 * Logs every email the site sends (app_sent_email), with the provider's
 * message id so Postmark's webhooks can mark it delivered, bounced or
 * opened later (PostmarkEventConsumer), and every email the provider
 * refused.
 *
 * Writes through the database connection rather than the entity manager:
 * emails go out in the middle of other work (checkout, a state change),
 * and a flush here would save that work's half-finished changes too. A
 * failure to log is itself only logged; it never stops an email.
 */
final class SentEmailRecorder
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsEventListener(event: SentMessageEvent::class)]
    public function onSent(SentMessageEvent $event): void
    {
        $sent = $event->getMessage();
        $this->record($sent->getOriginalMessage(), $sent->getMessageId(), SentEmail::STATUS_SENT, null);
    }

    #[AsEventListener(event: FailedMessageEvent::class)]
    public function onFailed(FailedMessageEvent $event): void
    {
        $this->record($event->getMessage(), null, SentEmail::STATUS_FAILED, $event->getError()->getMessage());
    }

    private function record(RawMessage $message, ?string $messageId, string $status, ?string $detail): void
    {
        if (!$message instanceof Message) {
            return;
        }

        $headers = $message->getHeaders();
        $recipients = $message instanceof Email
            ? array_map(static fn (Address $address): string => $address->getAddress(), [...$message->getTo(), ...$message->getCc(), ...$message->getBcc()])
            : [];

        try {
            $this->connection->insert('app_sent_email', [
                'message_id' => $messageId,
                'type' => self::truncate($headers->get('X-Tag')?->getBodyAsString(), 64),
                'recipients' => self::truncate(implode(', ', $recipients), 1000) ?? '',
                'original_recipients' => self::truncate($headers->get(RedirectOutsideProductionListener::ORIGINAL_TO_HEADER)?->getBodyAsString(), 1000),
                'subject' => self::truncate($message instanceof Email ? $message->getSubject() : null, 255) ?? '',
                'status' => $status,
                'status_detail' => $detail,
                'sent_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error(sprintf('Could not log a sent email (%s): %s', $messageId ?? $status, $exception->getMessage()));
        }
    }

    private static function truncate(?string $value, int $length): ?string
    {
        return null === $value ? null : mb_substr($value, 0, $length);
    }
}
