<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Uložený návrh mzdových předkontací odvozený z převzatého zaúčtování.
 *
 * Proč se to vůbec ukládá: převod z původního programu a rozhodnutí účetní jsou
 * dvě různé chvíle. Převod běží jednou, často na pozadí a často ho pouští někdo
 * jiný; předkontace pak potvrzuje účetní ve chvíli, kdy původní soubor už nikdo
 * po ruce nemá. Bez uloženého návrhu by se musel celý export nahrávat znovu.
 *
 * Jeden návrh na firmu a zdroj (UNIQUE): opakovaný převod téhož exportu
 * návrh přepíše, nezaloží druhý. Zdroj může výslovně chránit již potvrzený
 * návrh; výchozí chování starších převodů zůstává obnovitelný návrh.
 */
final class PayrollPostingMapProposalStore
{
    /**
     * Musí sedět na ENUM `source` v migraci 1852 a na
     * {@see PayrollMigrationReferenceTotalsWriter::SOURCES}. `other` je obecný
     * zdroj - převzaté zaúčtování není vázané na PAMICU.
     */
    public const SOURCES = ['pamica', 'pohoda', 'money_s3', 'other', 'stereo_nx'];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_CONFIRMED = 'confirmed';

    public function __construct(private readonly Connection $db) {}

    public function available(): bool
    {
        return $this->db->hasTable('payroll_posting_map_proposals');
    }

    /**
     * Uloží (nebo přepíše) návrh a vrátí ho tak, jak ho čte {@see self::find()}.
     * Při `$preserveConfirmed` nechá již potvrzený návrh i metadata beze změny.
     *
     * @param array<string,mixed> $proposal výstup {@see PayrollPostingMapProposalBuilder::build()}
     * @return array<string,mixed>
     */
    public function store(
        int $supplierId,
        string $source,
        array $proposal,
        ?int $year = null,
        ?string $sourceReference = null,
        bool $preserveConfirmed = false,
    ): array {
        $this->assertSource($source);
        $payload = json_encode($proposal, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $confirmed = "status = '" . self::STATUS_CONFIRMED . "'";
        $update = $preserveConfirmed
            ? "source_year = IF({$confirmed}, source_year, VALUES(source_year)),
                 source_reference = IF({$confirmed}, source_reference, VALUES(source_reference)),
                 proposal_json = IF({$confirmed}, proposal_json, VALUES(proposal_json)),
                 confirmed_json = IF({$confirmed}, confirmed_json, NULL),
                 confirmed_at = IF({$confirmed}, confirmed_at, NULL),
                 confirmed_by = IF({$confirmed}, confirmed_by, NULL),
                 status = IF({$confirmed}, status, VALUES(status))"
            : 'status = VALUES(status),
                 source_year = VALUES(source_year),
                 source_reference = VALUES(source_reference),
                 proposal_json = VALUES(proposal_json),
                 confirmed_json = NULL,
                 confirmed_at = NULL,
                 confirmed_by = NULL';

        $this->db->pdo()->prepare(
            'INSERT INTO payroll_posting_map_proposals
                 (supplier_id, source, status, source_year, source_reference, proposal_json)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE ' . $update,
        )->execute([$supplierId, $source, self::STATUS_DRAFT, $year, $sourceReference, $payload]);

        $stored = $this->find($supplierId, $source);
        if ($stored === null) {
            throw new \RuntimeException('Návrh mzdových předkontací se nepodařilo uložit.');
        }

        return $stored;
    }

    /**
     * Návrh firmy. Bez `$source` se vrací ten naposledy dotčený - účetní má
     * typicky jediný, a když jich je víc, chce vidět ten čerstvý.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $supplierId, ?string $source = null): ?array
    {
        if (!$this->available()) {
            return null;
        }
        if ($source !== null) {
            $this->assertSource($source);
        }
        $sql = 'SELECT id, source, status, source_year, source_reference,
                       proposal_json, confirmed_json, confirmed_at, updated_at
                  FROM payroll_posting_map_proposals
                 WHERE supplier_id = ?';
        $params = [$supplierId];
        if ($source !== null) {
            $sql .= ' AND source = ?';
            $params[] = $source;
        }
        $sql .= ' ORDER BY updated_at DESC, id DESC LIMIT 1';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'source' => (string) $row['source'],
            'status' => (string) $row['status'],
            'source_year' => $row['source_year'] === null ? null : (int) $row['source_year'],
            'source_reference' => $row['source_reference'] === null ? null : (string) $row['source_reference'],
            'proposal' => self::decode((string) $row['proposal_json']),
            'confirmed_accounts' => $row['confirmed_json'] === null
                ? null
                : self::decode((string) $row['confirmed_json']),
            'confirmed_at' => $row['confirmed_at'] === null ? null : (string) $row['confirmed_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * Zaznamená, co účetní potvrdila. Volá se AŽ POTOM, co nastavení
     * zaměstnavatele skutečně prošlo uložením - stav `confirmed` je doklad, ne
     * příslib.
     *
     * @param array<string,string> $confirmations
     */
    public function markConfirmed(int $proposalId, array $confirmations, ?int $userId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_posting_map_proposals
                SET status = ?, confirmed_json = ?, confirmed_at = NOW(), confirmed_by = ?
              WHERE id = ?',
        )->execute([
            self::STATUS_CONFIRMED,
            json_encode($confirmations, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $userId,
            $proposalId,
        ]);
    }

    /** @return array<string,mixed> */
    private static function decode(string $json): array
    {
        $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);

        return is_array($value) ? $value : [];
    }

    private function assertSource(string $source): void
    {
        if (!in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException("Neznámý zdroj převzatého zaúčtování: {$source}.");
        }
    }
}
