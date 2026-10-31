<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mail;

use App\Entity\Mail\SentEmail;
use App\Form\Contact\ContactRequestType;
use App\Tests\Functional\FunctionalTestCase;
use Symfony\Component\Mime\Email;

final class EmailTrackingTest extends FunctionalTestCase
{
    private const AUTH = 'postmark:test-secret'; // POSTMARK_WEBHOOK_SECRET in phpunit.xml.dist

    protected function setUp(): void
    {
        parent::setUp();
        $this->entityManager()->createQuery('DELETE FROM '.SentEmail::class)->execute();
    }

    public function testEverySentEmailIsLoggedAndBranded(): void
    {
        $crawler = $this->client->request('GET', '/contact');
        $this->client->submit($crawler->selectButton('Send Message')->form([
            'contact_request[fullName]' => 'Jane Doe',
            'contact_request[email]' => 'jane@acme.example',
            'contact_request[howCanWeHelp]' => ContactRequestType::TOPIC_SUPPLY_PROBLEM,
            'contact_request[message]' => 'Need <b>200</b> hard hats, see https://acme.example/specs',
        ]));

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('http://localhost/email/logo.png', $html);
        self::assertStringContainsString('US Industrial Supply Chain Solutions', $html);
        // What the visitor typed is escaped, and links work.
        self::assertStringContainsString('Need &lt;b&gt;200&lt;/b&gt;', $html);
        self::assertStringContainsString('<a href="https://acme.example/specs"', $html);
        self::assertEmailTextBodyContains($email, 'Need <b>200</b> hard hats');

        $logged = $this->loggedEmails();
        self::assertCount(1, $logged);
        self::assertSame(self::CHANNEL_CONTACT_EMAIL, $logged[0]->getRecipients());
        self::assertSame('contact_request', $logged[0]->getType());
        self::assertSame(SentEmail::STATUS_SENT, $logged[0]->getStatus());
        self::assertStringStartsWith('New contact request from Jane Doe', $logged[0]->getSubject());
        self::assertNotNull($logged[0]->getMessageId());
    }

    public function testPostmarkReportsUpdateTheLog(): void
    {
        $this->logEmail('pm-1');

        $this->postWebhook(['RecordType' => 'Delivery', 'MessageID' => 'pm-1', 'Recipient' => 'jane@acme.example', 'DeliveredAt' => '2026-10-31T15:00:00Z']);
        self::assertResponseStatusCodeSame(202);
        $email = $this->loggedEmails()[0];
        self::assertSame(SentEmail::STATUS_DELIVERED, $email->getStatus());
        self::assertSame('2026-10-31 15:00', $email->getDeliveredAt()?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'));

        $this->postWebhook(['RecordType' => 'Open', 'MessageID' => 'pm-1', 'Recipient' => 'jane@acme.example', 'ReceivedAt' => '2026-10-31T16:00:00Z']);
        $this->postWebhook(['RecordType' => 'Open', 'MessageID' => 'pm-1', 'Recipient' => 'jane@acme.example', 'ReceivedAt' => '2026-10-31T17:00:00Z']);
        $email = $this->loggedEmails()[0];
        self::assertSame('2026-10-31 16:00', $email->getOpenedAt()?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'), 'First open kept.');
        self::assertSame(SentEmail::STATUS_DELIVERED, $email->getStatus());
    }

    public function testABounceIsLoggedWithItsReason(): void
    {
        $this->logEmail('pm-2');

        $this->postWebhook([
            'RecordType' => 'Bounce',
            'MessageID' => 'pm-2',
            'Email' => 'nobody@acme.example',
            'BouncedAt' => '2026-10-31T15:00:00Z',
            'Description' => 'The server was unable to deliver your message (ex: unknown user, mailbox not found).',
            'Details' => 'smtp; 550 5.1.1 User unknown',
        ]);

        self::assertResponseStatusCodeSame(202);
        $email = $this->loggedEmails()[0];
        self::assertSame(SentEmail::STATUS_BOUNCED, $email->getStatus());
        self::assertStringContainsString('550 5.1.1 User unknown', (string) $email->getStatusDetail());
    }

    public function testAWebhookWithoutTheSecretIsRefused(): void
    {
        $this->logEmail('pm-3');

        $this->postWebhook(['RecordType' => 'Delivery', 'MessageID' => 'pm-3', 'Recipient' => 'jane@acme.example', 'DeliveredAt' => '2026-10-31T15:00:00Z'], 'postmark:wrong');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(SentEmail::STATUS_SENT, $this->loggedEmails()[0]->getStatus());
    }

    public function testReportsForEmailsThisSiteDidNotSendAreIgnored(): void
    {
        $this->postWebhook(['RecordType' => 'Delivery', 'MessageID' => 'from-another-environment', 'Recipient' => 'jane@acme.example', 'DeliveredAt' => '2026-10-31T15:00:00Z']);

        self::assertResponseStatusCodeSame(202);
        self::assertSame([], $this->loggedEmails());
    }

    private function logEmail(string $messageId): void
    {
        $this->entityManager()->getConnection()->insert('app_sent_email', [
            'message_id' => $messageId,
            'recipients' => 'jane@acme.example',
            'subject' => 'Order 000000042 received',
            'status' => SentEmail::STATUS_SENT,
            'sent_at' => '2026-10-31 14:59:00',
        ]);
    }

    /** @param array<string, string> $payload */
    private function postWebhook(array $payload, string $auth = self::AUTH): void
    {
        $this->client->request('POST', '/webhook/postmark', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Basic '.base64_encode($auth),
        ], content: json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    /** @return list<SentEmail> */
    private function loggedEmails(): array
    {
        $entityManager = $this->entityManager();
        $entityManager->clear();

        return $entityManager->getRepository(SentEmail::class)->findBy([], ['id' => 'ASC']);
    }
}
