<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Property\Receivables;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Index množin osob osobního salda (migrace 2130): pro každý titul dluhu, předpis, kredit
 * a potvrzený přechod dluhu otisk množiny dlužníků nebo věřitelů a jednotku. Je to nadmnožina
 * množin platných k libovolnému datu, takže stránka po otiscích pozná, historii kterých
 * jednotek potřebuje.
 */
final class PartyClaimKeyRepository
{
    public function __construct(private readonly Connection $db) {}

    public static function hash(string $partyKey): string
    {
        return hash('sha256', $partyKey);
    }

    /** @return list<string> další otisky v pořadí indexu */
    public function keysAfter(int $supplier, ?string $after, int $limit): array
    {
        $q = $this->db->pdo()->prepare(
            'SELECT DISTINCT key_hash FROM property_party_claim_keys WHERE supplier_id = ? AND key_hash > ? ORDER BY key_hash LIMIT ' . $limit
        );
        $q->execute([$supplier, $after ?? '']);
        return array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param list<string> $keys @return list<int> jednotky, na kterých některý z otisků vznikl */
    public function unitsForKeys(int $supplier, array $keys): array
    {
        if ($keys === []) return [];
        $q = $this->db->pdo()->prepare(
            'SELECT DISTINCT unit_id FROM property_party_claim_keys WHERE supplier_id = ? AND key_hash IN ('
            . implode(',', array_fill(0, count($keys), '?')) . ') ORDER BY unit_id'
        );
        $q->execute([$supplier, ...$keys]);
        return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<int> další jednotky s nárokem nebo kreditem */
    public function unitsAfter(int $supplier, int $after, int $limit): array
    {
        $q = $this->db->pdo()->prepare(
            'SELECT DISTINCT unit_id FROM property_party_claim_keys WHERE supplier_id = ? AND unit_id > ? ORDER BY unit_id LIMIT ' . $limit
        );
        $q->execute([$supplier, $after]);
        return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    }
}
