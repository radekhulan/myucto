<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice\Parser;

use MyInvoice\Service\Bank\EmailNotice\SenderAddress;

/**
 * End-anchored match domény odesílatele pro systémové parsery.
 *
 * `str_contains($sender, '@csob.cz')` pustí i `attacker@csob.cz.evil.com` —
 * tady se doména kontroluje na konci adresy (vč. subdomén, např. noreply@mail.csob.cz).
 * Sender je samozřejmě spoofnutelný (žádná SPF/DKIM validace na této vrstvě);
 * jde jen o routing na správný parser, dopad omezuje mapování účtu + match částky.
 */
final class SenderDomain
{
    public static function matches(string $sender, string ...$domains): bool
    {
        $address = SenderAddress::parse($sender);
        if ($address === null) {
            return false;
        }
        foreach ($domains as $domain) {
            $domain = strtolower(rtrim(trim($domain), '.'));
            if ($domain !== '' && ($address->domain === $domain || str_ends_with($address->domain, '.' . $domain))) {
                return true;
            }
        }
        return false;
    }
}
