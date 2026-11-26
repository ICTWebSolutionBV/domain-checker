<?php

namespace App\Support;

class DomainName
{
    /** One DNS label: what a name without its extension has to look like. */
    public const LABEL_PATTERN = '/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?$/';

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

    /**
     * A full domain as a visitor types or pastes it, reduced to name.tld, or
     * null when the line does not look like a domain at all. Accepts unicode,
     * a leading http(s):// and a leading www.
     */
    public static function fromInput(string $line): ?string
    {
        $domain = strtolower(self::toAscii($line));
        $domain = (string) preg_replace('#^https?://#', '', $domain);
        $domain = (string) preg_replace('#^www\.#', '', $domain);

        // Must look like name.tld (at least one dot, valid label characters)
        return preg_match('/^[a-z0-9][a-z0-9\-]*(?:\.[a-z0-9][a-z0-9\-]*)+$/', $domain) ? $domain : null;
    }
}
