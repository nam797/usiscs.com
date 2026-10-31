<?php

declare(strict_types=1);

namespace App\Mail\Tracking;

use App\Repository\Mail\SentEmailRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\RemoteEvent\Attribute\AsRemoteEventConsumer;
use Symfony\Component\RemoteEvent\Consumer\ConsumerInterface;
use Symfony\Component\RemoteEvent\Event\Mailer\AbstractMailerEvent;
use Symfony\Component\RemoteEvent\Event\Mailer\MailerDeliveryEvent;
use Symfony\Component\RemoteEvent\Event\Mailer\MailerEngagementEvent;
use Symfony\Component\RemoteEvent\RemoteEvent;

/**
 * What Postmark reports about an email after it left: delivered to the
 * recipient's mail server, bounced, marked as spam, opened. Updates the
 * matching sent-email row (SentEmailRecorder); events for emails this site
 * didn't log (sent from another environment on the same Postmark server)
 * are ignored.
 */
#[AsRemoteEventConsumer('postmark')]
final class PostmarkEventConsumer implements ConsumerInterface
{
    public function __construct(
        private readonly SentEmailRepository $sentEmails,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function consume(RemoteEvent $event): void
    {
        if (!$event instanceof AbstractMailerEvent) {
            return;
        }

        $email = $this->sentEmails->findOneByMessageId($event->getId());
        if (null === $email) {
            $this->logger->info(sprintf('Postmark "%s" event for an email this site has no record of (%s).', $event->getName(), $event->getId()));

            return;
        }

        match ($event->getName()) {
            MailerDeliveryEvent::DELIVERED => $email->markDelivered($event->getDate()),
            MailerDeliveryEvent::BOUNCE => $email->markBounced($event instanceof MailerDeliveryEvent ? self::bounceReason($event) : ''),
            MailerEngagementEvent::SPAM => $email->markSpam(),
            MailerEngagementEvent::OPEN => $email->markOpened($event->getDate()),
            default => null,
        };

        $this->entityManager->flush();
    }

    /** Postmark's one-line description, plus its details when they add something. */
    private static function bounceReason(MailerDeliveryEvent $event): string
    {
        $details = $event->getPayload()['Details'] ?? null;
        $reason = $event->getReason();

        return is_string($details) && '' !== $details && $details !== $reason ? trim($reason.' '.$details) : $reason;
    }
}
