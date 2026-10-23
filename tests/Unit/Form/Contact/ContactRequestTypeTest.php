<?php

declare(strict_types=1);

namespace App\Tests\Unit\Form\Contact;

use App\Form\Contact\ContactRequestType;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

final class ContactRequestTypeTest extends TypeTestCase
{
    /** @return array<string, string> */
    private static function validSubmission(): array
    {
        return [
            'fullName' => 'Jane Doe',
            'company' => 'Acme Construction',
            'email' => 'jane@acme.example',
            'phone' => '912-665-7257',
            'howCanWeHelp' => ContactRequestType::TOPIC_SUPPLY_PROBLEM,
            'message' => 'Need 200 hard hats by Friday.',
        ];
    }

    public function testAValidSubmissionMapsToAPlainArray(): void
    {
        $form = $this->submit(self::validSubmission());

        self::assertTrue($form->isSynchronized());
        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame(self::validSubmission(), $form->getData());
    }

    public function testCompanyAndPhoneAreOptional(): void
    {
        $data = self::validSubmission();
        $data['company'] = '';
        $data['phone'] = '';

        $form = $this->submit($data);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidFields(): iterable
    {
        yield 'blank name' => ['fullName', ''];
        yield 'name too long' => ['fullName', str_repeat('a', 256)];
        yield 'company too long' => ['company', str_repeat('a', 256)];
        yield 'blank email' => ['email', ''];
        yield 'malformed email' => ['email', 'jane-at-acme'];
        yield 'malformed phone' => ['phone', '555-1234'];
        yield 'phone too long' => ['phone', str_repeat('1', 51)];
        yield 'no topic' => ['howCanWeHelp', ''];
        yield 'unknown topic' => ['howCanWeHelp', 'free_stuff'];
        yield 'blank message' => ['message', ''];
        yield 'message too long' => ['message', str_repeat('a', 5001)];
    }

    #[DataProvider('invalidFields')]
    public function testInvalidFieldsAreRejected(string $field, string $value): void
    {
        $data = self::validSubmission();
        $data[$field] = $value;

        $form = $this->submit($data);

        self::assertFalse($form->isValid());
        self::assertFalse($form->get($field)->isValid(), sprintf('Expected "%s" to carry the error.', $field));
    }

    public function testEveryTopicHasALabelAndIsSelectable(): void
    {
        $choices = $this->factory->create(ContactRequestType::class)->get('howCanWeHelp')->getConfig()->getOption('choices');

        self::assertIsArray($choices);
        self::assertSame(array_keys(ContactRequestType::TOPIC_LABELS), array_values($choices));
    }

    public function testItUsesAStableBlockPrefix(): void
    {
        // The templates and the contact form's POST field names depend on it.
        self::assertSame('contact_request', (new ContactRequestType())->getBlockPrefix());
    }

    /** @param array<string, string> $data */
    private function submit(array $data): FormInterface
    {
        $form = $this->factory->create(ContactRequestType::class);
        $form->submit($data);

        return $form;
    }

    protected function getExtensions(): array
    {
        return [
            new ValidatorExtension(Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()),
        ];
    }
}
