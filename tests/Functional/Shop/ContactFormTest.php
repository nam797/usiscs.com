<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shop;

use App\Form\Contact\ContactRequestType;
use App\Tests\Functional\FunctionalTestCase;
use Symfony\Component\Mime\Email;

final class ContactFormTest extends FunctionalTestCase
{
    public function testAVisitorCanSendAMessage(): void
    {
        $this->submit(self::validValues());

        self::assertResponseRedirects('/contact');
        self::assertEmailCount(1);

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'To', self::CHANNEL_CONTACT_EMAIL);
        self::assertEmailHeaderSame($email, 'Subject', 'New contact request from Jane Doe: Supply Problem / Emergency Sourcing');
        self::assertEmailTextBodyContains($email, 'Jane Doe');
        self::assertEmailTextBodyContains($email, 'jane@acme.example');
        self::assertEmailTextBodyContains($email, 'Need 200 hard hats by Friday.');
        self::assertEmailTextBodyContains($email, '(not provided)');
        self::assertEmailAddressContains($email, 'From', 'no-reply@usiscs.com');
        self::assertEmailAddressContains($email, 'Reply-To', 'jane@acme.example');

        $saved = $this->savedContactMessages();
        self::assertCount(1, $saved);
        self::assertSame('Need 200 hard hats by Friday.', $saved[0]->getMessage());
        self::assertEmailTextBodyContains($email, sprintf('http://localhost/backoffice/contact-message/%d', $saved[0]->getId()));

        $this->client->followRedirect();
        self::assertSelectorTextContains('body', "Thanks for reaching out");
    }

    public function testInvalidInputIsShownBackWithoutSending(): void
    {
        $values = self::validValues();
        $values['contact_request[email]'] = 'not-an-email';
        $values['contact_request[message]'] = '';

        $this->submit($values);
        self::assertSame([], $this->savedContactMessages());

        self::assertResponseIsSuccessful();
        self::assertEmailCount(0);
        self::assertSelectorTextContains('h1', 'Get In Touch');
        self::assertSelectorTextContains('form', 'This value is not a valid email address.');
        self::assertSelectorTextContains('form', 'This value should not be blank.');
        // What the visitor typed is kept.
        self::assertInputValueSame('contact_request[fullName]', 'Jane Doe');
    }

    public function testAForgedSubmissionWithoutTheCsrfTokenIsRejected(): void
    {
        $this->client->request('POST', '/contact', ['contact_request' => [
            'fullName' => 'Bot',
            'email' => 'bot@example.com',
            'howCanWeHelp' => ContactRequestType::TOPIC_OTHER,
            'message' => 'spam',
        ]]);

        self::assertResponseIsSuccessful();
        self::assertEmailCount(0);
    }

    public function testASixthSubmissionWithinTenMinutesIsThrottled(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->submit(self::validValues());
            self::assertEmailCount(1);
        }

        $this->submit(self::validValues());

        self::assertResponseRedirects('/contact');
        self::assertEmailCount(0);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Too many submissions');
    }

    /** @return array<string, string> */
    private static function validValues(): array
    {
        return [
            'contact_request[fullName]' => 'Jane Doe',
            'contact_request[email]' => 'jane@acme.example',
            'contact_request[howCanWeHelp]' => ContactRequestType::TOPIC_SUPPLY_PROBLEM,
            'contact_request[message]' => 'Need 200 hard hats by Friday.',
        ];
    }

    /** @param array<string, string> $values */
    private function submit(array $values): void
    {
        $crawler = $this->client->request('GET', '/contact');
        $this->client->submit($crawler->selectButton('Send Message')->form($values));
    }
}
