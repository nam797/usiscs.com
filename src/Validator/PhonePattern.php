<?php

declare(strict_types=1);

namespace App\Validator;

/**
 * Shared phone-number format regex, used by both the RFQ (RfqRequest
 * entity) and Contact (ContactRequestType) forms so the two don't drift.
 * Allows digits, spaces, '+', parens, '.', and '-' only, and requires
 * 10-15 digits somewhere in the string — covers a bare 10-digit US
 * number up to a '+'-prefixed international one with punctuation.
 */
final class PhonePattern
{
    public const REGEX = '/^(?=(?:\D*\d){10,15}\D*$)[0-9+().\s-]+$/';

    private function __construct()
    {
    }
}
