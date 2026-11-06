<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Záznam převzatý z jiného účetního programu (SSOT).
 *
 * Převod (Money S3, POHODA, PREMIER, Stereo NX) zapisuje do MyÚčta deník, doklady
 * i úhrady tak, jak je zaúčtoval zdroj, a eviduje je v mapě převodu (`*_import_map`).
 * Převzatý záznam je vazba na doklad v původním programu: automatika MyÚčta (dorovnání
 * haléřů, doúčtování dokladů, přeúčtování bankovních pohybů, přečíslování, přeřazení
 * nákladů) ho nesmí přepsat, jinak se převedený deník rozejde s deníkem zdroje
 * a rekonciliace převodu přestane sedět. Vědomá oprava uživatelem (ruční přeúčtování
 * dokladu) zůstává povolená.
 *
 * Záznam je převzatý, když na něj míří mapa převodu druhem z {@see self::MAPS}. Stejný
 * pohled je v SQL (podmínky do WHERE) i v PHP (dotaz na jeden záznam). Nová mapa převodu
 * se musí přidat sem; hlídá to test `TakenOverRecordMapsTest` proti otisku schématu.
 *
 * Zápisy, které převod dopočítal sám (POHODA `derived_entry` u pohybu bez zápisu
 * v deníku zdroje), převzaté nejsou: vznikly účtovací logikou MyÚčta.
 */
final class TakenOverRecord
{
    public const JOURNAL_ENTRY = 'journal_entry';
    public const INVOICE = 'invoice';
    public const PURCHASE_INVOICE = 'purchase_invoice';

    /**
     * Mapa převodu => druh záznamu MyÚčta => `kind` v mapě.
     *
     * @var array<string, array<string, string|list<string>>>
     */
    public const MAPS = [
        'abra_flexi_import_map' => [
            self::JOURNAL_ENTRY    => 'ucetni-denik',
            self::INVOICE          => ['faktura-vydana', 'prodejka'],
            self::PURCHASE_INVOICE => ['faktura-prijata', 'zavazek'],
        ],
        'money_s3_import_map' => [
            self::JOURNAL_ENTRY    => 'journal_entry',
            self::INVOICE          => 'invoice',
            self::PURCHASE_INVOICE => 'purchase_invoice',
        ],
        'pohoda_import_map' => [
            self::JOURNAL_ENTRY    => 'journal_entry',
            self::INVOICE          => 'invoice',
            self::PURCHASE_INVOICE => 'purchase_invoice',
        ],
        'premier_import_map' => [
            self::JOURNAL_ENTRY    => 'journal_entry',
            self::INVOICE          => 'invoice',
            self::PURCHASE_INVOICE => 'purchase_invoice',
        ],
        'stereo_nx_import_map' => [
            self::JOURNAL_ENTRY    => 'accounting_journal',
            self::INVOICE          => 'issued',
            self::PURCHASE_INVOICE => 'purchase',
        ],
    ];

    /**
     * Mapa převodu => `kind` účetního období, které převod založil (smazání převodu ho
     * uklízí). Stereo NX období do mapy nezapisuje.
     */
    private const PERIOD_KINDS = [
        'abra_flexi_import_map' => 'ucetni-obdobi',
        'money_s3_import_map'   => 'period',
        'pohoda_import_map'     => 'period',
        'premier_import_map'    => 'period',
    ];

    private const REFERENCE = '/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/D';

    public function __construct(private readonly Connection $db) {}

    /**
     * SQL podmínka „záznam `$record` s id `$idSql` firmy `$supplierIdSql` je převzatý".
     * Oba odkazy jsou sloupce (`je.id`), čísla nebo `?` (pak se parametr váže za každou
     * mapu zvlášť: supplier, id, supplier, id …); hodnoty od uživatele sem nepatří.
     */
    public static function existsSql(string $record, string $supplierIdSql, string $idSql): string
    {
        foreach ([$supplierIdSql, $idSql] as $reference) {
            if (!self::isReference($reference)) {
                throw new \InvalidArgumentException('Neplatný odkaz na převzatý záznam.');
            }
        }
        $parts = [];
        foreach (self::MAPS as $map => $kinds) {
            if (!isset($kinds[$record])) {
                throw new \InvalidArgumentException('Neznámý druh převzatého záznamu: ' . $record);
            }
            $sourceKinds = (array) $kinds[$record];
            $kindSql = implode(', ', array_map(static function (string $kind): string {
                if (preg_match('/^[a-z0-9_-]+$/D', $kind) !== 1) {
                    throw new \LogicException('Neplatný druh záznamu v mapě převodu.');
                }
                return "'{$kind}'";
            }, $sourceKinds));
            $parts[] = "EXISTS (SELECT 1 FROM {$map} tom
                WHERE tom.supplier_id = {$supplierIdSql} AND tom.kind IN ({$kindSql}) AND tom.target_id = {$idSql})";
        }
        return '(' . implode(' OR ', $parts) . ')';
    }

    /** Zápis deníku (`journal_entries` pod aliasem `$alias`) je převzatý. */
    public static function journalEntrySql(string $alias): string
    {
        return self::existsSql(self::JOURNAL_ENTRY, $alias . '.supplier_id', $alias . '.id');
    }

    /** Klíč vlastního zaúčtování pro smír s evidencí; nespoléhá na měkké vazby. */
    public static function journalDocumentKeySql(string $alias): string
    {
        return DocumentPostingGroup::documentKeySql($alias);
    }

    /**
     * Doklad (`invoices` resp. `purchase_invoices` pod aliasem `$alias`) je převzatý.
     *
     * @param 'invoice'|'purchase_invoice' $docType
     */
    public static function documentSql(string $docType, string $alias): string
    {
        return self::existsSql(self::documentRecord($docType), $alias . '.supplier_id', $alias . '.id');
    }

    /**
     * Pohyb `$txIdSql` má živý (nestornovaný) bankovní zápis převzatý z jiného programu.
     * Takový pohyb zaúčtoval zdroj; párování, alokace i zápis zůstávají, jak přišly.
     */
    public static function liveBankEntrySql(string $supplierIdSql, string $txIdSql): string
    {
        foreach ([$supplierIdSql, $txIdSql] as $reference) {
            if (!self::isReference($reference)) {
                throw new \InvalidArgumentException('Neplatný odkaz na bankovní pohyb.');
            }
        }
        return "EXISTS (SELECT 1 FROM journal_entries tob
            WHERE tob.supplier_id = {$supplierIdSql} AND tob.source_type = 'bank'
              AND tob.source_id = {$txIdSql} AND tob.reversed_by IS NULL
              AND " . self::journalEntrySql('tob') . ')';
    }

    public function isJournalEntry(int $supplierId, int $entryId): bool
    {
        return $this->ask(self::existsSql(self::JOURNAL_ENTRY, '?', '?'), $supplierId, $entryId);
    }

    /** @param 'invoice'|'purchase_invoice' $docType */
    public function isDocument(int $supplierId, string $docType, int $documentId): bool
    {
        return $this->ask(self::existsSql(self::documentRecord($docType), '?', '?'), $supplierId, $documentId);
    }

    /**
     * Doklad je převzatý sám, nebo jeho živý zápis (převod navázal převzatý deník na doklad
     * nepřevzatý, typicky spárovaný s existující fakturou).
     *
     * @param 'invoice'|'purchase_invoice' $docType
     */
    public function isDocumentOrItsEntry(int $supplierId, string $docType, int $documentId): bool
    {
        if ($this->isDocument($supplierId, $docType, $documentId)) {
            return true;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM journal_entries je
              WHERE je.supplier_id = ? AND je.source_type = ? AND je.source_id = ? AND je.reversed_by IS NULL
                AND ' . self::journalEntrySql('je') . ' LIMIT 1'
        );
        $stmt->execute([$supplierId, $docType, $documentId]);
        if ($stmt->fetchColumn() !== false) {
            return true;
        }
        foreach ((new DocumentPostingGroup($this->db))->active($supplierId, $docType, $documentId) as $entry) {
            if ((bool) $entry['grouped'] && $this->isJournalEntry($supplierId, (int) $entry['id'])) {
                return true;
            }
        }
        return false;
    }

    /** Účetní období založil převod z jiného programu a eviduje ho ve své mapě. */
    public function isAccountingPeriod(int $supplierId, int $periodId): bool
    {
        foreach (self::PERIOD_KINDS as $map => $kind) {
            if (!$this->db->hasTable($map)) {
                continue;
            }
            $stmt = $this->db->pdo()->prepare("SELECT 1 FROM {$map} WHERE supplier_id = ? AND kind = ? AND target_id = ? LIMIT 1");
            $stmt->execute([$supplierId, $kind, $periodId]);
            if ($stmt->fetchColumn() !== false) {
                return true;
            }
        }
        return false;
    }

    public function hasLiveBankEntry(int $supplierId, int $txId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::liveBankEntrySql((string) $supplierId, (string) $txId));
        $stmt->execute();
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Druh záznamu pro zdroj zápisu / typ dokladu; null = zdroj, u kterého převod doklad
     * v mapě nevede.
     */
    public static function documentRecordFor(string $sourceType): ?string
    {
        return match ($sourceType) {
            'invoice'          => self::INVOICE,
            'purchase_invoice' => self::PURCHASE_INVOICE,
            default            => null,
        };
    }

    private static function documentRecord(string $docType): string
    {
        return self::documentRecordFor($docType)
            ?? throw new \InvalidArgumentException('Nepodporovaný typ převzatého dokladu: ' . $docType);
    }

    private static function isReference(string $reference): bool
    {
        return $reference === '?' || ctype_digit($reference) || preg_match(self::REFERENCE, $reference) === 1;
    }

    private function ask(string $sql, int $supplierId, int $id): bool
    {
        $params = [];
        foreach (self::MAPS as $_) {
            $params[] = $supplierId;
            $params[] = $id;
        }
        $stmt = $this->db->pdo()->prepare('SELECT ' . $sql);
        $stmt->execute($params);
        return (bool) $stmt->fetchColumn();
    }
}
