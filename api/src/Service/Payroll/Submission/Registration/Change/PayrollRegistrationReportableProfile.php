<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration\Change;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

/**
 * Plochý průmět hlásitelných údajů jednoho pracovního vztahu.
 *
 * Klíč je cesta z {@see PayrollRegistrationReportableCatalog}, hodnota je vždy
 * `?string` — i logická hodnota a seznam. Porovnávat se totiž musí to, co se
 * nahlásí, ne to, jak si to která vrstva uložila: `false`, `0` a `"0"` jsou
 * pro registr jedna hodnota, ale pro PHP tři, a rozdíl mezi nimi by vyrobil
 * hlášení, které nic nehlásí.
 *
 * Cesta, kterou průmět NEZNÁ, není totéž co cesta s hodnotou `null`:
 * - klíč chybí = „o tomhle údaji tenhle pramen nic neví" → neporovnává se,
 * - klíč s `null` = „údaj je prázdný" → vyplnění prázdného údaje JE změna.
 *
 * Kdyby se obojí slilo, doplnění dosud nevyplněné adresy by se buď ztratilo,
 * nebo by se každý starší snapshot tvářil jako lavina změn.
 */
final readonly class PayrollRegistrationReportableProfile
{
    /** @param array<string,?string> $values */
    private function __construct(public array $values) {}

    /**
     * @param array<string,?string> $values
     */
    public static function fromValues(array $values): self
    {
        foreach ($values as $path => $value) {
            if (!PayrollRegistrationReportableCatalog::isReportable($path)) {
                // Fail-closed: nehlásitelná cesta v průmětu znamená, že někdo
                // rozšířil projekci bez rozšíření katalogu — a tím pádem bez
                // právního zdůvodnění, proč se to má hlásit do osmi dnů.
                throw new \InvalidArgumentException(
                    "Průmět hlásitelných údajů obsahuje cestu {$path}, "
                    . 'kterou katalog REGZEC nezná.',
                );
            }
            if ($value !== null && !is_string($value)) {
                throw new \InvalidArgumentException(
                    "Hodnota cesty {$path} musí být řetězec nebo null.",
                );
            }
        }
        ksort($values, SORT_STRING);

        return new self($values);
    }

    public function has(string $path): bool
    {
        return array_key_exists($path, $this->values);
    }

    public function get(string $path): ?string
    {
        return $this->values[$path] ?? null;
    }

    /**
     * Otisk průmětu. Slouží k idempotenci návrhů: dokud se otisk aktuálního
     * stavu nezmění, je to pořád tatáž změna a nesmí vzniknout druhý návrh.
     */
    public function fingerprint(): string
    {
        return hash('sha256', CanonicalJson::encode([
            'schema_reference' => 'payroll-registration-reportable-profile.v1',
            'values' => $this->values,
        ]));
    }
}
