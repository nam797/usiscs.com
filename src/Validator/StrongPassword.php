<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraints\Compound;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\PasswordStrength;

/**
 * What a customer's or admin's password must be: at least 12 characters,
 * and not easy to guess (Symfony's PasswordStrength at "medium": about 80
 * bits by its estimate, so a 12-character password needs a mix of
 * letters, numbers and symbols, while a few random words pass on length).
 *
 * Applied to every place a password is set, alongside Sylius's own rules:
 * config/validator/password.yaml. The hint shown under password fields
 * (templates/shop/account/password_hint.html.twig) says the same.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class StrongPassword extends Compound
{
    public const MIN_LENGTH = 12;

    public const TOO_SHORT = 'Use at least {{ limit }} characters.';

    public const TOO_WEAK = 'This password is too easy to guess. Make it longer, or mix in numbers and symbols (a few random words works well).';

    /** @param array<string, mixed> $options */
    protected function getConstraints(array $options): array
    {
        return [
            new Length(min: self::MIN_LENGTH, max: 254, minMessage: self::TOO_SHORT),
            new PasswordStrength(minScore: PasswordStrength::STRENGTH_MEDIUM, message: self::TOO_WEAK),
        ];
    }
}
