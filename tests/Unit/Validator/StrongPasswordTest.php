<?php

declare(strict_types=1);

namespace App\Tests\Unit\Validator;

use App\Validator\StrongPassword;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class StrongPasswordTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function strong(): iterable
    {
        yield 'four random words' => ['correct horse battery staple'];
        yield 'mixed, 13 characters' => ['Tr0ub4dor&3x!'];
        yield 'long lowercase phrase' => ['mybluepickuptruckhauls'];
    }

    /** @return iterable<string, array{string, string}> */
    public static function weak(): iterable
    {
        yield 'Sylius minimum (4)' => ['abcd', 'Use at least 12 characters.'];
        yield 'eight mixed' => ['Pa55w0rd', 'Use at least 12 characters.'];
        yield '12, guessable' => ['Password1234', 'too easy to guess'];
        yield '12, one letter' => ['aaaaaaaaaaaa', 'too easy to guess'];
    }

    #[DataProvider('strong')]
    public function testStrongPasswordsPass(string $password): void
    {
        self::assertCount(0, Validation::createValidator()->validate($password, new StrongPassword()));
    }

    #[DataProvider('weak')]
    public function testWeakPasswordsAreRefusedWithAReason(string $password, string $message): void
    {
        $violations = Validation::createValidator()->validate($password, new StrongPassword());

        self::assertGreaterThan(0, count($violations));
        self::assertStringContainsString($message, (string) $violations->get(0)->getMessage());
    }

    public function testNoPasswordIsLeftToNotBlank(): void
    {
        // Updating an account without changing the password must still validate.
        self::assertCount(0, Validation::createValidator()->validate(null, new StrongPassword()));
    }
}
