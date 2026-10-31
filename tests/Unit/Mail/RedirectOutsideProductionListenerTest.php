<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mail;

use App\Mail\RedirectOutsideProductionListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class RedirectOutsideProductionListenerTest extends TestCase
{
    public function testOnStagingEveryEmailGoesToItSupport(): void
    {
        $email = self::email();
        $event = self::event($email);

        (new RedirectOutsideProductionListener('staging', 'itsupport@usiscs.com'))->onMessage($event);

        self::assertSame(['itsupport@usiscs.com'], self::addresses($email->getTo()));
        self::assertSame([], $email->getCc());
        self::assertSame([], $email->getBcc());
        self::assertSame(['itsupport@usiscs.com'], self::addresses($event->getEnvelope()->getRecipients()));
        self::assertSame('[Staging, to jane@acme.example, buyer@acme.example, audit@acme.example] Order 000000042 received', $email->getSubject());
        self::assertSame('jane@acme.example, buyer@acme.example, audit@acme.example', $email->getHeaders()->get('X-Original-To')?->getBodyAsString());
        // Replies still go where they would have.
        self::assertSame(['sales@usiscs.com'], self::addresses($email->getReplyTo()));
    }

    public function testLocallyTheSubjectSaysLocal(): void
    {
        $email = self::email();

        (new RedirectOutsideProductionListener('', 'itsupport@usiscs.com'))->onMessage(self::event($email));

        self::assertStringStartsWith('[Local, to jane@acme.example', (string) $email->getSubject());
    }

    public function testProductionEmailsAreLeftAlone(): void
    {
        $email = self::email();

        (new RedirectOutsideProductionListener('production', 'itsupport@usiscs.com'))->onMessage(self::event($email));

        self::assertSame(['jane@acme.example'], self::addresses($email->getTo()));
        self::assertSame('Order 000000042 received', $email->getSubject());
        self::assertFalse($email->getHeaders()->has('X-Original-To'));
    }

    public function testWithoutARedirectAddressNothingChanges(): void
    {
        $email = self::email();

        (new RedirectOutsideProductionListener('staging', null))->onMessage(self::event($email));

        self::assertSame(['jane@acme.example'], self::addresses($email->getTo()));
    }

    public function testAnEmailIsOnlyRedirectedOnce(): void
    {
        $email = self::email();
        $listener = new RedirectOutsideProductionListener('staging', 'itsupport@usiscs.com');

        $listener->onMessage(self::event($email));
        $listener->onMessage(self::event($email));

        self::assertSame('[Staging, to jane@acme.example, buyer@acme.example, audit@acme.example] Order 000000042 received', $email->getSubject());
    }

    private static function email(): Email
    {
        return (new Email())
            ->from('no-reply@usiscs.com')
            ->to('jane@acme.example')
            ->cc('buyer@acme.example')
            ->bcc('audit@acme.example')
            ->replyTo('sales@usiscs.com')
            ->subject('Order 000000042 received')
            ->text('Thanks');
    }

    private static function event(Email $email): MessageEvent
    {
        return new MessageEvent($email, Envelope::create($email), 'null://null');
    }

    /**
     * @param array<Address> $addresses
     *
     * @return list<string>
     */
    private static function addresses(array $addresses): array
    {
        return array_values(array_map(static fn (Address $address): string => $address->getAddress(), $addresses));
    }
}
