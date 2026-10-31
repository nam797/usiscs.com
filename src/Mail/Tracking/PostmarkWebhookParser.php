<?php

declare(strict_types=1);

namespace App\Mail\Tracking;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\ChainRequestMatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\IsJsonRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\MethodRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;
use Symfony\Component\Mailer\Bridge\Postmark\RemoteEvent\PostmarkPayloadConverter;
use Symfony\Component\RemoteEvent\Event\Mailer\AbstractMailerEvent;
use Symfony\Component\RemoteEvent\Exception\ParseException;
use Symfony\Component\Webhook\Client\AbstractRequestParser;
use Symfony\Component\Webhook\Exception\RejectWebhookException;

/**
 * Reads Postmark's webhooks at /webhook/postmark (config/packages/webhook.yaml).
 *
 * Symfony's own Postmark parser also checks the caller's IP against
 * Postmark's list, which needs trusted proxies set up behind Upsun's router
 * (they aren't) and breaks whenever Postmark adds an address. So this one
 * checks only the Basic auth Postmark sends (POSTMARK_WEBHOOK_SECRET,
 * "user:password", set in the webhook URL in Postmark), and refuses every
 * call while the secret is unset.
 */
final class PostmarkWebhookParser extends AbstractRequestParser
{
    public function __construct(
        #[Autowire(service: 'mailer.payload_converter.postmark')]
        private readonly PostmarkPayloadConverter $converter,
    ) {
    }

    protected function getRequestMatcher(): RequestMatcherInterface
    {
        return new ChainRequestMatcher([
            new MethodRequestMatcher('POST'),
            new IsJsonRequestMatcher(),
        ]);
    }

    protected function doParse(Request $request, #[\SensitiveParameter] string $secret): AbstractMailerEvent
    {
        if ('' === $secret) {
            throw new RejectWebhookException(403, 'Webhook secret not configured.');
        }
        if (!hash_equals('Basic '.base64_encode($secret), $request->headers->get('Authorization') ?? '')) {
            throw new RejectWebhookException(403, 'Invalid credentials.');
        }

        $payload = $request->toArray();
        if (!isset($payload['RecordType'], $payload['MessageID']) || !(isset($payload['Recipient']) || isset($payload['Email']))) {
            throw new RejectWebhookException(406, 'Payload is malformed.');
        }

        try {
            return $this->converter->convert($payload);
        } catch (ParseException $exception) {
            throw new RejectWebhookException(406, $exception->getMessage(), $exception);
        }
    }
}
