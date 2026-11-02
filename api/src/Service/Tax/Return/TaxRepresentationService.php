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
 * Promítá se do věty P všech EPO podání daňového subjektu (DPPO, DPFO, DPH,
 * kontrolní a souhrnné hlášení) přes {@see \MyInvoice\Service\Report\EpoSupplierBlockBuilder::fillSignerAttributes()}
 * a do `dan_por`/`pln_moc` a lhůty § 136 odst. 2 DŘ přes {@see isTaxAdvisor()}.
 *
 * Kód podepisující osoby (`zast_kod`) je číselník EPO. Daňovým poradcem nebo
 * advokátem jsou jen 4b a 4c; ostatní kódy (zákonný zástupce, obecný zmocněnec,
 * dědic…) podepisují, ale `dan_por` ani prodlouženou lhůtu nezakládají.
 */
final class TaxRepresentationService
{
    /** @var list<string> kódy podepisující osoby přípustné u zástupce fyzické osoby */
    public const CODES_NATURAL_PERSON = ['1', '2', '3', '4a', '4b', '5a', '5b', '6a', '6b', '7b'];

    /** @var list<string> kódy podepisující osoby přípustné u zástupce právnické osoby */
    public const CODES_LEGAL_PERSON = ['1', '2', '3', '4a', '4c', '5a', '5b', '6a', '6b', '7a', '7b'];

    /** @var list<string> daňový poradce nebo advokát (§ 29 odst. 2 a § 136 odst. 2 DŘ) */
    public const TAX_ADVISOR_CODES = ['4b', '4c'];

    /** @return list<string> */
    public static function codesForType(string $type): array
    {
        return match ($type) {
            'F' => self::CODES_NATURAL_PERSON,
            'P' => self::CODES_LEGAL_PERSON,
            default => [],
        };
    }

    /**
     * Kód podepisující osoby, null u nezastoupené firmy. Stav bez kódu (volající,
     * který ho skládá ručně) dostane kód daňového poradce podle typu, stejně jako
     * před zavedením kódu.
     *
     * @param array<string,mixed> $representation
     */
    public static function codeOf(array $representation): ?string
    {
        if (empty($representation['represented'])) {
            return null;
        }
        $code = (string) ($representation['code'] ?? '');
        if ($code !== '') {
            return $code;
        }

        return ($representation['type'] ?? null) === 'P' ? '4c' : '4b';
    }

    /**
     * Zastupuje firmu daňový poradce nebo advokát? Řídí `dan_por`/`pln_moc`
     * i prodlouženou lhůtu podání podle § 136 odst. 2 DŘ.
     *
     * @param array<string,mixed> $representation
     */
    public static function isTaxAdvisor(array $representation): bool
    {
        return in_array(self::codeOf($representation), self::TAX_ADVISOR_CODES, true);
    }

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Stav zastoupení k danému datu (YYYY-MM-DD).
     *
     * @return array{
     *   represented: bool,
     *   type: ?string,
     *   code: ?string,
     *   first_name: ?string,
     *   last_name: ?string,
     *   company_name: ?string,
     *   ico: ?string,
     *   ev_number: ?string,
     *   birth_date: ?string,
     *   signer_first_name: ?string,
     *   signer_last_name: ?string,
     *   signer_position: ?string,
     *   power_of_attorney_granted_on: ?string,
     *   effective_from: ?string,
     * }
     */
    public function at(int $supplierId, string $date): array
    {
        return self::statusAt($this->db->pdo(), $supplierId, $date);
    }

    /**
     * Zástupce pro podání, jehož XML se vytváří teď: ke dni `representation_date`
     * z `$meta`, je-li to datum ve tvaru Y-m-d, jinak k dnešku. Sdílí ho hlášení
     * nerezidentů, vyúčtování daně ze závislé činnosti i žádosti o daňový bonus.
     *
     * @param array<string,mixed> $meta
     * @return array<string,mixed> stejný tvar jako {@see at()}
     */
    public function atSubmissionDate(int $supplierId, array $meta): array
    {
        $date = (string) ($meta['representation_date'] ?? '');

        return $this->at(
            $supplierId,
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : date('Y-m-d'),
        );
    }

    /**
     * Statická varianta {@see at()} pro kontexty bez DI kontejneru (bin skripty,
     * dry-run ověřovací nástroje) — stejná SQL sémantika, jeden zdroj pravdy.
     *
     * @return array{
     *   represented: bool,
     *   type: ?string,
     *   code: ?string,
     *   first_name: ?string,
     *   last_name: ?string,
     *   company_name: ?string,
     *   ico: ?string,
     *   ev_number: ?string,
     *   birth_date: ?string,
     *   signer_first_name: ?string,
     *   signer_last_name: ?string,
     *   signer_position: ?string,
     *   power_of_attorney_granted_on: ?string,
     *   effective_from: ?string,
     * }
     */
    public static function statusAt(\PDO $pdo, int $supplierId, string $date): array
    {
        $stmt = $pdo->prepare(
            'SELECT represented, representative_type, representative_code, representative_first_name,
                    representative_last_name, representative_company_name,
                    representative_ico, representative_ev_number, representative_birth_date,
                    representative_signer_first_name, representative_signer_last_name,
                    representative_signer_position, power_of_attorney_granted_on, effective_from
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
                'code' => null,
                'first_name' => null,
                'last_name' => null,
                'company_name' => null,
                'ico' => null,
                'ev_number' => null,
                'birth_date' => null,
                'signer_first_name' => null,
                'signer_last_name' => null,
                'signer_position' => null,
                'power_of_attorney_granted_on' => null,
                'effective_from' => $row !== false ? (string) $row['effective_from'] : null,
            ];
        }

        $status = [
            'represented' => true,
            'type' => (string) $row['representative_type'],
            'code' => $row['representative_code'] !== null ? (string) $row['representative_code'] : null,
            'first_name' => $row['representative_first_name'] !== null ? (string) $row['representative_first_name'] : null,
            'last_name' => $row['representative_last_name'] !== null ? (string) $row['representative_last_name'] : null,
            'company_name' => $row['representative_company_name'] !== null ? (string) $row['representative_company_name'] : null,
            'ico' => $row['representative_ico'] !== null ? (string) $row['representative_ico'] : null,
            'ev_number' => $row['representative_ev_number'] !== null ? (string) $row['representative_ev_number'] : null,
            'birth_date' => $row['representative_birth_date'] !== null ? (string) $row['representative_birth_date'] : null,
            'signer_first_name' => $row['representative_signer_first_name'] !== null ? (string) $row['representative_signer_first_name'] : null,
            'signer_last_name' => $row['representative_signer_last_name'] !== null ? (string) $row['representative_signer_last_name'] : null,
            'signer_position' => $row['representative_signer_position'] !== null ? (string) $row['representative_signer_position'] : null,
            'power_of_attorney_granted_on' => $row['power_of_attorney_granted_on'] !== null ? (string) $row['power_of_attorney_granted_on'] : null,
            'effective_from' => (string) $row['effective_from'],
        ];
        $status['code'] = self::codeOf($status);

        return $status;
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
        ?string $code = null,
        ?string $birthDate = null,
        ?string $signerFirstName = null,
        ?string $signerLastName = null,
        ?string $signerPosition = null,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO supplier_tax_representation_history
                (supplier_id, effective_from, represented, representative_type, representative_code,
                 representative_first_name, representative_last_name, representative_company_name,
                 representative_ico, representative_ev_number, representative_birth_date,
                 representative_signer_first_name, representative_signer_last_name, representative_signer_position,
                 power_of_attorney_granted_on, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                represented = VALUES(represented),
                representative_type = VALUES(representative_type),
                representative_code = VALUES(representative_code),
                representative_first_name = VALUES(representative_first_name),
                representative_last_name = VALUES(representative_last_name),
                representative_company_name = VALUES(representative_company_name),
                representative_ico = VALUES(representative_ico),
                representative_ev_number = VALUES(representative_ev_number),
                representative_birth_date = VALUES(representative_birth_date),
                representative_signer_first_name = VALUES(representative_signer_first_name),
                representative_signer_last_name = VALUES(representative_signer_last_name),
                representative_signer_position = VALUES(representative_signer_position),
                power_of_attorney_granted_on = VALUES(power_of_attorney_granted_on),
                note = VALUES(note),
                created_by = VALUES(created_by)'
        )->execute([
            $supplierId, $effectiveFrom, $represented ? 1 : 0, $type,
            $represented ? self::codeOf(['represented' => true, 'type' => $type, 'code' => $code]) : null,
            $firstName, $lastName, $companyName,
            $ico, $evNumber, $birthDate,
            $signerFirstName, $signerLastName, $signerPosition,
            $powerOfAttorneyGrantedOn, $note, $userId ?: null,
        ]);
    }

    /** @return list<array<string,mixed>> historie firmy, nejstarší první */
    public function history(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, effective_from, represented, representative_type, representative_code,
                    representative_first_name, representative_last_name, representative_company_name,
                    representative_ico, representative_ev_number, representative_birth_date,
                    representative_signer_first_name, representative_signer_last_name,
                    representative_signer_position, power_of_attorney_granted_on, note
               FROM supplier_tax_representation_history WHERE supplier_id = ? ORDER BY effective_from'
        );
        $stmt->execute([$supplierId]);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'effective_from' => (string) $r['effective_from'],
            'represented' => (bool) $r['represented'],
            'type' => $r['representative_type'] !== null ? (string) $r['representative_type'] : null,
            'code' => $r['representative_code'] !== null ? (string) $r['representative_code'] : null,
            'first_name' => $r['representative_first_name'] !== null ? (string) $r['representative_first_name'] : null,
            'last_name' => $r['representative_last_name'] !== null ? (string) $r['representative_last_name'] : null,
            'company_name' => $r['representative_company_name'] !== null ? (string) $r['representative_company_name'] : null,
            'ico' => $r['representative_ico'] !== null ? (string) $r['representative_ico'] : null,
            'ev_number' => $r['representative_ev_number'] !== null ? (string) $r['representative_ev_number'] : null,
            'birth_date' => $r['representative_birth_date'] !== null ? (string) $r['representative_birth_date'] : null,
            'signer_first_name' => $r['representative_signer_first_name'] !== null ? (string) $r['representative_signer_first_name'] : null,
            'signer_last_name' => $r['representative_signer_last_name'] !== null ? (string) $r['representative_signer_last_name'] : null,
            'signer_position' => $r['representative_signer_position'] !== null ? (string) $r['representative_signer_position'] : null,
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
