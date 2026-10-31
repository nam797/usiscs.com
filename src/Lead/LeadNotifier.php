<?php

declare(strict_types=1);

namespace App\Lead;

use App\Mail\TeamEmailBuilder;
use Psr\Log\LoggerInterface;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Webmozart\Assert\Assert;

/**
 * Emails the team about a new lead (an RFQ or a contact message) at the
 * channel's contact email (Sylius admin > Channels).
 *
 * Sent from MAILER_FROM, an address on a domain the mail provider is set up
 * to send for, so the email passes SPF/DKIM; Reply-To is the customer, so
 * hitting Reply answers them directly. The lead is saved before this runs,
 * so a missing address or a mail failure is logged and never thrown.
 */
final class LeadNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly ChannelContextInterface $channelContext,
        private readonly TeamEmailBuilder $emails,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'MAILER_FROM')]
        private readonly string $from,
    ) {
    }

    /**
     * @param string               $lead     what is being sent, for log lines ("RFQ #12")
     * @param array<string, mixed> $context
     */
    public function notify(string $lead, string $subject, string $template, array $context, Address $customer): bool
    {
        $channel = $this->channelContext->getChannel();
        Assert::isInstanceOf($channel, ChannelInterface::class);

        $to = $channel->getContactEmail();
        if (null === $to || '' === $to) {
            $this->logger->warning(sprintf(
                '%s from %s saved but not emailed: channel "%s" has no contact email configured.',
                $lead,
                $customer->toString(),
                $channel->getCode(),
            ));

            return false;
        }

        try {
            $this->mailer->send($this->emails->build($template, $context, $subject)
                ->from(new Address($this->from, $channel->getName() ?? 'USISCS Website'))
                ->to(new Address($to))
                ->replyTo($customer));
        } catch (\Throwable $exception) {
            // Broad catch is deliberate: besides transport errors, a bad
            // address throws RfcComplianceException while building the email.
            $this->logger->error(sprintf('Failed to email %s: %s', $lead, $exception->getMessage()));

            return false;
        }

        return true;
    }
}
