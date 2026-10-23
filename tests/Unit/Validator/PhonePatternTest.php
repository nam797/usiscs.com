<?php

declare(strict_types=1);

namespace App\Tests\Unit\Validator;

use App\Validator\PhonePattern;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhonePatternTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function validNumbers(): iterable
    {
        yield 'bare 10-digit US' => ['9126657257'];
        yield 'dashed US' => ['912-665-7257'];
        yield 'dotted US' => ['912.665.7257'];
        yield 'parens and space' => ['(912) 665-7257'];
        yield 'US with country code' => ['+1 912 665 7257'];
        yield 'international, 15 digits' => ['+44 20 7946 0958 123'];
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNumbers(): iterable
    {
        yield 'empty' => [''];
        yield 'too few digits' => ['555-1234'];
        yield '9 digits' => ['912-665-725'];
        yield 'more than 15 digits' => ['+1 912 665 7257 12345'];
        yield 'letters' => ['912-665-PIZZA'];
        yield 'extension marker' => ['912-665-7257 x12'];
        yield 'disallowed punctuation' => ['912/665/7257'];
        yield 'script injection' => ['<script>9126657257</script>'];
    }

    #[DataProvider('validNumbers')]
    public function testItAcceptsRealisticPhoneNumbers(string $number): void
    {
        self::assertMatchesRegularExpression(PhonePattern::REGEX, $number);
    }

    #[DataProvider('invalidNumbers')]
    public function testItRejectsMalformedPhoneNumbers(string $number): void
    {
        self::assertDoesNotMatchRegularExpression(PhonePattern::REGEX, $number);
    }
}
