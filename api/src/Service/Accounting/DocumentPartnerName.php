<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

/**
 * Jméno protistrany dokladu z JOINnutého řádku `clients` s fallbackem na snapshot.
 *
 * Vytaženo z JournalSourceSummaryService jako VOLATELNÝ helper: tutéž trojici
 * (company_name → first/last name → snapshot) potřebuje i JournalLinkService a
 * pravidlo schované jako private metoda jedné služby se okopíruje rychleji, než
 * kdyby neexistovalo.
 */
final class DocumentPartnerName
{
    /**
     * @param array<string,mixed> $row       řádek s company_name/first_name/last_name a snapshotem
     * @param string              $snapshotKey 'client_snapshot' (vydané) / 'vendor_snapshot' (přijaté)
     */
    public static function from(array $row, string $snapshotKey = 'client_snapshot'): ?string
    {
        $company = isset($row['company_name']) ? trim((string) $row['company_name']) : '';
        if ($company !== '') {
            return $company;
        }
        $person = trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? '')));
        if ($person !== '') {
            return $person;
        }
        // Fallback na snapshot — klient mohl být smazán, snapshot drží historický stav.
        if (!empty($row[$snapshotKey])) {
            $snap = json_decode((string) $row[$snapshotKey], true);
            if (is_array($snap)) {
                return self::fromSnapshot($snap);
            }
        }
        return null;
    }

    /** @param array<string,mixed> $snapshot */
    public static function fromSnapshot(array $snapshot): ?string
    {
        foreach (['company_name', 'name'] as $key) {
            $name = trim((string) ($snapshot[$key] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }
        $person = trim(($snapshot['first_name'] ?? '') . ' ' . ($snapshot['last_name'] ?? ''));
        return $person !== '' ? $person : null;
    }
}
