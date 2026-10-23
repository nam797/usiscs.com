<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shop;

use App\Entity\Rfq\RfqRequest;
use App\Tests\Functional\FunctionalTestCase;
use Symfony\Component\Mime\Email;

final class RfqFormTest extends FunctionalTestCase
{
    public function testACustomerCanRequestAQuote(): void
    {
        $this->submit(self::validValues(), [
            ['quantity' => '10', 'partNumber' => 'HB-500', 'description' => 'Hex bolt, grade 8', 'unitOfMeasure' => 'box'],
            ['quantity' => '1', 'description' => 'Pallet jack'],
        ]);

        self::assertResponseRedirects('/quote');

        $rfqs = $this->savedRfqs();
        self::assertCount(1, $rfqs);
        $rfq = $rfqs[0];
        self::assertSame('Acme Construction', $rfq->getCompany());
        self::assertSame('Jane Doe', $rfq->getFullName());
        self::assertSame(RfqRequest::URGENCY_RUSH, $rfq->getUrgencyLevel());
        self::assertSame('2026-12-01', $rfq->getRequiredByDate()?->format('Y-m-d'));
        self::assertSame(RfqRequest::STATUS_NEW, $rfq->getStatus());

        $items = array_values($rfq->getLineItems()->toArray());
        self::assertCount(2, $items);
        self::assertSame(10, $items[0]->getQuantity());
        self::assertSame('HB-500', $items[0]->getPartNumber());
        self::assertSame('Hex bolt, grade 8', $items[0]->getDescription());
        self::assertSame('Pallet jack', $items[1]->getDescription());

        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'your request has been submitted');
    }

    public function testTheTeamIsEmailedAboutANewRequest(): void
    {
        $this->submit(self::validValues(), [['quantity' => '3', 'description' => 'Safety gloves']]);

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'To', self::CHANNEL_CONTACT_EMAIL);
        self::assertEmailHeaderSame($email, 'Subject', 'New RFQ from Acme Construction (Jane Doe)');
        self::assertEmailTextBodyContains($email, 'Safety gloves');
    }

    public function testARequestWithoutItemsTellsTheCustomerWhy(): void
    {
        // Regression: this error used to be swallowed, so the form just
        // came back with no explanation.
        $this->submit(self::validValues(), []);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('form', 'Add at least one item.');
        self::assertSame([], $this->savedRfqs());
        self::assertEmailCount(0);
    }

    public function testBlankRequiredFieldsAreValidationErrorsNotAServerError(): void
    {
        $values = self::validValues();
        foreach (['fullName', 'company', 'email', 'phone'] as $field) {
            $values['rfq_request['.$field.']'] = '';
        }

        $this->submit($values, [['quantity' => '', 'description' => '']]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('form', 'Please enter your full name.');
        self::assertSelectorTextContains('form', 'Please enter your company name.');
        self::assertSelectorTextContains('form', 'Please enter your email address.');
        self::assertSelectorTextContains('form', 'Please enter a phone number.');
        self::assertSelectorTextContains('form', 'Please describe the item.');
        self::assertSame([], $this->savedRfqs());
    }

    public function testANinthSubmissionWithinTenMinutesIsThrottled(): void
    {
        for ($i = 0; $i < 8; ++$i) {
            $this->submit(self::validValues(), [['quantity' => '1', 'description' => 'Item '.$i]]);
        }
        self::assertCount(8, $this->savedRfqs());

        $this->submit(self::validValues(), [['quantity' => '1', 'description' => 'One too many']]);

        self::assertResponseRedirects('/quote');
        self::assertCount(8, $this->savedRfqs());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Too many submissions');
    }

    /** @return array<string, string> */
    private static function validValues(): array
    {
        return [
            'rfq_request[customerType]' => RfqRequest::CUSTOMER_TYPE_NEW,
            'rfq_request[fullName]' => 'Jane Doe',
            'rfq_request[company]' => 'Acme Construction',
            'rfq_request[email]' => 'jane@acme.example',
            'rfq_request[phone]' => '(912) 665-7257',
            'rfq_request[requiredByDate]' => '2026-12-01',
            'rfq_request[urgencyLevel]' => RfqRequest::URGENCY_RUSH,
        ];
    }

    /**
     * Line-item rows are added in the browser by JavaScript from the form's
     * prototype, so they aren't in the page's HTML; post them alongside the
     * real form's fields (including its CSRF token).
     *
     * @param array<string, string>        $values
     * @param list<array<string, string>> $lineItems
     */
    private function submit(array $values, array $lineItems): void
    {
        $crawler = $this->client->request('GET', '/quote');
        $form = $crawler->selectButton('Submit RFQ')->form($values);

        $fields = $form->getPhpValues();
        self::assertIsArray($fields['rfq_request']);
        $fields['rfq_request']['lineItems'] = $lineItems;

        $this->client->request($form->getMethod(), $form->getUri(), $fields);
    }
}
