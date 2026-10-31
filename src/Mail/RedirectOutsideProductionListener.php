<?php

declare(strict_types=1);

namespace App\Mail;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Outside production, every email goes to one inbox (app.mail.redirect_to,
 * itsupport@) instead of the customers and staff it was addressed to.
 * Staging starts as a copy of production's database, so without this a
 * test on staging would email real customers.
 *
 * Production is Upsun's environment type "production" and nothing else, so
 * anything unrecognised (staging, a dev environment, a laptop) is
 * redirected. The subject says where the email came from and who it was
 * for, e.g. "[Staging, to jane@acme.com] Order confirmation", and the
 * original addresses are kept in an X-Original-To header.
 *
 * Off (redirect_to null) in the test environment, whose tests check the
 * real recipients.
 */
#[AsEventListener(event: MessageEvent::class, method: 'onMessage', priority: 100)]
final class RedirectOutsideProductionListener
{
    public const ORIGINAL_TO_HEADER = 'X-Original-To';

    public function __construct(
        #[Autowire(env: 'PLATFORM_ENVIRONMENT_TYPE')]
        private readonly string $environmentType,
        #[Autowire(param: 'app.mail.redirect_to')]
        private readonly ?string $redirectTo,
    ) {
    }

    public function onMessage(MessageEvent $event): void
    {
        $email = $event->getMessage();
        if (null === $this->redirectTo || 'production' === $this->environmentType || !$email instanceof Email) {
            return;
        }

        $headers = $email->getHeaders();
        // Already redirected: queued emails pass through here twice.
        if ($headers->has(self::ORIGINAL_TO_HEADER)) {
            return;
        }

        $original = array_map(
            static fn (Address $address): string => $address->getAddress(),
            [...$email->getTo(), ...$email->getCc(), ...$email->getBcc()],
        );
        $original = array_values(array_unique($original));

        $headers->addTextHeader(self::ORIGINAL_TO_HEADER, implode(', ', $original));
        $headers->remove('Cc');
        $headers->remove('Bcc');
        $email->to($this->redirectTo);
        $email->subject(sprintf(
            '[%s, to %s] %s',
            $this->label(),
            [] === $original ? 'nobody' : implode(', ', $original),
            $email->getSubject() ?? '',
        ));
        $event->getEnvelope()->setRecipients([new Address($this->redirectTo)]);
    }

    private function label(): string
    {
        return match ($this->environmentType) {
            'staging' => 'Staging',
            'development' => 'Dev',
            '' => 'Local',
            default => ucfirst($this->environmentType),
        };
    }
}
