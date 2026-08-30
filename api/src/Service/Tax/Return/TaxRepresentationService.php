<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Zastoupení daňovým poradcem/advokátem (§ 29 odst. 2 daňového řádu) K DATU —
 * SSOT čtení `supplier_tax_representation_history` (migrace 1662).
 *
 * Historie je řada řádků {effective_from, represented, …}; stav k datu D =
 * poslední řádek s effective_from <= D. Firma bez jakéhokoli řádku historie
 * = nezastoupena (`represented=false`) — na rozdíl od {@see \MyInvoice\Service\Vat\VatStatusService}
 * tu není žádná "živá" cache na `supplier`, kterou by bylo třeba seedovat;
 * chybějící historie je sama o sobě platný a jednoznačný stav "N" (dnešní
 * chování před zavedením evidence).
 *
 * Promítá se do DPPO (`dan_por`) i DPFO (`pln_moc`) přiznání — {@see DppoXmlBuilder},
 * {@see DpfoXmlBuilder} — a čtou ho přes {@see TaxReturnService::representationAt()},
 * ne přímo, aby datum "k čemu" (finalizace vs. dnešek u draftu) bylo na jednom místě.
 */
final class TaxRepresentationService
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Stav zastoupení k danému datu (YYYY-MM-DD).
     *
     * @return array{
     *   represented: bool,
     *   type: ?string,
     *   first_name: ?string,
     *   last_name: ?string,
     *   company_name: ?string,
     *   ico: ?string,
     *   ev_number: ?string,
     *   power_of_attorney_granted_on: ?string,
     *   effective_from: ?string,
     * }
     */
    public function at(int $supplierId, string $date): array
    {
        return self::statusAt($this->db->pdo(), $supplierId, $date);
    }

    /**
     * Statická varianta {@see at()} pro kontexty bez DI kontejneru (bin skripty,
     * dry-run ověřovací nástroje) — stejná SQL sémantika, jeden zdroj pravdy.
     *
     * @return array{
     *   represented: bool,
     *   type: ?string,
     *   first_name: ?string,
     *   last_name: ?string,
     *   company_name: ?string,
     *   ico: ?string,
     *   ev_number: ?string,
     *   power_of_attorney_granted_on: ?string,
     *   effective_from: ?string,
     * }
     */
    public static function statusAt(\PDO $pdo, int $supplierId, string $date): array
    {
        $stmt = $pdo->prepare(
            'SELECT represented, representative_type, representative_first_name,
                    representative_last_name, representative_company_name,
                    representative_ico, representative_ev_number,
                    power_of_attorney_granted_on, effective_from
               FROM supplier_tax_representation_history
              WHERE supplier_id = ? AND effective_from <= ?
              ORDER BY effective_from DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$supplierId, $date]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false || !(bool) $row['represented']) {
            return [
                'represented' => false,
                'type' => null,
                'first_name' => null,
                'last_name' => null,
                'company_name' => null,
                'ico' => null,
                'ev_number' => null,
                'power_of_attorney_granted_on' => null,
                'effective_from' => $row !== false ? (string) $row['effective_from'] : null,
            ];
        }

        return [
            'represented' => true,
            'type' => (string) $row['representative_type'],
            'first_name' => $row['representative_first_name'] !== null ? (string) $row['representative_first_name'] : null,
            'last_name' => $row['representative_last_name'] !== null ? (string) $row['representative_last_name'] : null,
            'company_name' => $row['representative_company_name'] !== null ? (string) $row['representative_company_name'] : null,
            'ico' => $row['representative_ico'] !== null ? (string) $row['representative_ico'] : null,
            'ev_number' => (string) $row['representative_ev_number'],
            'power_of_attorney_granted_on' => $row['power_of_attorney_granted_on'] !== null ? (string) $row['power_of_attorney_granted_on'] : null,
            'effective_from' => (string) $row['effective_from'],
        ];
    }

    /**
     * Jediná zápisová cesta do historie (upsert po UNIQUE `supplier_id`+`effective_from`).
     * Validace tvaru (povinná identifikace při represented=true) je zdvojená databázovým
     * CHECKem (migrace 1662) — tady je kvůli srozumitelné 422 chybě z API, ne jako
     * jediná pojistka.
     */
    public function upsert(
        int $supplierId,
        string $effectiveFrom,
        bool $represented,
        ?string $type = null,
        ?string $firstName = null,
        ?string $lastName = null,
        ?string $companyName = null,
        ?string $ico = null,
        ?string $evNumber = null,
        ?string $powerOfAttorneyGrantedOn = null,
        ?string $note = null,
        ?int $userId = null,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO supplier_tax_representation_history
                (supplier_id, effective_from, represented, representative_type,
                 representative_first_name, representative_last_name, representative_company_name,
                 representative_ico, representative_ev_number, power_of_attorney_granted_on, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                represented = VALUES(represented),
                representative_type = VALUES(representative_type),
                representative_first_name = VALUES(representative_first_name),
                representative_last_name = VALUES(representative_last_name),
                representative_company_name = VALUES(representative_company_name),
                representative_ico = VALUES(representative_ico),
                representative_ev_number = VALUES(representative_ev_number),
                power_of_attorney_granted_on = VALUES(power_of_attorney_granted_on),
                note = VALUES(note),
                created_by = VALUES(created_by)'
        )->execute([
            $supplierId, $effectiveFrom, $represented ? 1 : 0, $type,
            $firstName, $lastName, $companyName,
            $ico, $evNumber, $powerOfAttorneyGrantedOn, $note, $userId ?: null,
        ]);
    }

    /** @return list<array<string,mixed>> historie firmy, nejstarší první */
    public function history(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, effective_from, represented, representative_type,
                    representative_first_name, representative_last_name, representative_company_name,
                    representative_ico, representative_ev_number, power_of_attorney_granted_on, note
               FROM supplier_tax_representation_history WHERE supplier_id = ? ORDER BY effective_from'
        );
        $stmt->execute([$supplierId]);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'effective_from' => (string) $r['effective_from'],
            'represented' => (bool) $r['represented'],
            'type' => $r['representative_type'] !== null ? (string) $r['representative_type'] : null,
            'first_name' => $r['representative_first_name'] !== null ? (string) $r['representative_first_name'] : null,
            'last_name' => $r['representative_last_name'] !== null ? (string) $r['representative_last_name'] : null,
            'company_name' => $r['representative_company_name'] !== null ? (string) $r['representative_company_name'] : null,
            'ico' => $r['representative_ico'] !== null ? (string) $r['representative_ico'] : null,
            'ev_number' => $r['representative_ev_number'] !== null ? (string) $r['representative_ev_number'] : null,
            'power_of_attorney_granted_on' => $r['power_of_attorney_granted_on'] !== null ? (string) $r['power_of_attorney_granted_on'] : null,
            'note' => $r['note'] !== null ? (string) $r['note'] : null,
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    public function delete(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM supplier_tax_representation_history WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);

        return $stmt->rowCount() > 0;
    }
}
