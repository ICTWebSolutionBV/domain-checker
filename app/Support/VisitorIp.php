<?php

namespace App\Support;

class VisitorIp
{
    /**
     * The rate-limit bucket for a visitor address a partner reports, or null
     * when it is not an IP at all.
     *
     * One IPv6 subscriber gets a whole /64, so keying on the full address let a
     * single visitor rotate through it for a fresh budget per request. IPv6 is
     * therefore keyed on its /64, and an IPv4-mapped address (::ffff:a.b.c.d)
     * on the IPv4 address it carries, so both spellings share one bucket.
     */
    public static function bucket(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = (string) inet_pton($ip);

        if (strlen($packed) === 4) {
            return (string) inet_ntop($packed);
        }

        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return inet_ntop($packed & (str_repeat("\xff", 8).str_repeat("\0", 8))).'/64';
    }
}
