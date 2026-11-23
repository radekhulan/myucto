<?php

declare(strict_types=1);

namespace MyInvoice\Service\Nonprofit\Membership;

use MyInvoice\Repository\Nonprofit\Membership\MembershipChargeRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRepository;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;

/**
 * Pečeť výmazu osobních údajů člena (#321, krok 10) — jediné místo, které rozhoduje,
 * zda je obsah revize, operace nebo příkazu průkazný.
 *
 * Bez pečeti platí původní pravidlo: obsah odpovídá uloženému hashi. Po výmazu se osobní
 * texty přepíšou, ale uložené hashe zůstanou (navazuje na ně řetěz i podklady DPPO).
 * Obsah je pak průkazný jen tehdy, když otisk AKTUÁLNÍHO obsahu svázaný s původním hashem
 * a položkou výmazu odpovídá uložené pečeti. Změna čehokoli dalšího (částka, datum, účet,
 * stav) pečeť rozbije stejně, jako by rozbila hash. Před přepisem výmaz ověří celý řetěz
 * původním pravidlem, takže pečeť nikdy nevznikne nad neprůkazným obsahem.
 *
 * Síla důkazu je stejná jako u hashe: kdo má zápis do databáze a přepočítá otisky, obejde
 * obojí; triggery a pořadí zápisů zásah jen zviditelní.
 */
final class MembershipErasureSeal
{
    private const DOMAIN = 'nonprofit_membership_erasure.v1';

    public static function digest(int $erasureItemId, string $originalHash, array $record): string
    {
        return NonprofitTaxRules::hash(['domain' => self::DOMAIN, 'erasure_item_id' => $erasureItemId, 'original_hash' => $originalHash, 'record' => $record]);
    }

    /** Revize; řádek s dekódovanými `legal_basis` a `request`. */
    public static function revisionProven(array $row): bool
    {
        $record = MembershipRepository::revisionRecord($row);
        if (self::sealed($row)) return self::sealMatches($row, (string) $row['record_hash'], $record);
        return hash_equals((string) $row['request_hash'], NonprofitTaxRules::hash($row['request']))
            && hash_equals((string) $row['record_hash'], NonprofitTaxRules::hash($record));
    }

    /** Operace; řádek s dekódovanými `proof` a `receipt`. */
    public static function operationProven(array $row): bool
    {
        $record = MembershipChargeRepository::operationRecord($row);
        if (self::sealed($row)) return self::sealMatches($row, (string) $row['record_hash'], $record);
        return hash_equals((string) $row['record_hash'], NonprofitTaxRules::hash($record));
    }

    /** Příkaz; řádek s dekódovaným `request` (request_hash je otisk celého uloženého požadavku). */
    public static function commandProven(array $row): bool
    {
        if (self::sealed($row)) return self::sealMatches($row, (string) $row['request_hash'], ['request' => $row['request']]);
        return hash_equals((string) $row['request_hash'], NonprofitTaxRules::hash($row['request']));
    }

    public static function sealed(array $row): bool
    {
        return ($row['erasure_item_id'] ?? null) !== null;
    }

    private static function sealMatches(array $row, string $originalHash, array $record): bool
    {
        $digest = $row['erased_digest'] ?? null;
        return is_string($digest) && hash_equals($digest, self::digest((int) $row['erasure_item_id'], $originalHash, $record));
    }
}
