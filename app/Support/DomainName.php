<?php

namespace App\Support;

class DomainName
{
    /**
     * Convert a unicode domain or label to its punycode form.
     *
     * Typing "café" or "münchen" used to fail validation with a generic error,
     * because the label regex only accepts ASCII -- while the registries these
     * names belong to (.de, .fr, .nl among them) sell them happily. ASCII input
     * is returned untouched, so nothing changes for the common case.
     */
    public static function toAscii(string $input): string
    {
        $input = trim($input);

        if ($input === '' || ! preg_match('/[^\x20-\x7E]/', $input)) {
            return $input;
        }

        $ascii = idn_to_ascii($input, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

        return $ascii === false ? $input : $ascii;
    }
}
