<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Číslo dokladu v hlavičce zápisu vydané a přijaté faktury (journal_entries.document_no).
 *
 * Jediné místo, které ho určuje: vydaná faktura (i dobropis, DDKP, penalizace) nese
 * své číslo (varsymbol), přijatá naše číslo (varsymbol), a když chybí, číslo dokladu
 * dodavatele. Platí pro nové a přeúčtované zápisy, starší zápis si své číslo nechává.
 *
 * Proč SSOT: číslo dřív dodávali volající. Doúčtování ho předávalo, automatické
 * zaúčtování po vystavení a přeúčtování ne — a dokud doúčtování přepisovalo i už
 * zaúčtované doklady, díru nikdo neviděl. Od chvíle, kdy doúčtování bere jen doklady
 * bez zápisu, zůstávaly zápisy z automatiky bez čísla. PostingService proto číslo
 * dopočítá sám, kdykoli ho volající nedodá.
 */
final class DocumentEntryNumber
{
    /** @var list<string> */
    public const SOURCE_TYPES = ['invoice', 'purchase_invoice'];

    public function __construct(private readonly Connection $db) {}

    /** Číslo pro zápis dokladu; NULL = doklad jiného typu, neexistuje nebo nemá číslo. */
    public function forDocument(int $supplierId, string $sourceType, int $sourceId): ?string
    {
        if (!in_array($sourceType, self::SOURCE_TYPES, true) || $sourceId <= 0) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::sql('?', '?', '?'));
        $stmt->execute([$sourceType, $sourceType, $sourceId, $supplierId, $sourceType, $sourceId, $supplierId]);
        $no = $stmt->fetchColumn();
        return $no === false || $no === null || $no === '' ? null : (string) $no;
    }

    /**
     * SQL výraz s číslem dokladu. Argumenty jsou SQL výrazy (sloupce nebo placeholdery);
     * `$typeExpr` se vyskytuje třikrát, `$idExpr` a `$supplierExpr` dvakrát — v pořadí
     * typ, typ, id, firma, typ, id, firma.
     */
    public static function sql(string $typeExpr, string $idExpr, string $supplierExpr): string
    {
        return "LEFT(CASE
                    WHEN {$typeExpr} NOT IN ('invoice', 'purchase_invoice') THEN NULL
                    WHEN {$typeExpr} = 'invoice' THEN (
                        SELECT NULLIF(TRIM(i.varsymbol), '') FROM invoices i
                         WHERE i.id = {$idExpr} AND i.supplier_id = {$supplierExpr})
                    WHEN {$typeExpr} = 'purchase_invoice' THEN (
                        SELECT COALESCE(NULLIF(TRIM(p.varsymbol), ''), NULLIF(TRIM(p.vendor_invoice_number), ''))
                          FROM purchase_invoices p
                         WHERE p.id = {$idExpr} AND p.supplier_id = {$supplierExpr})
                 END, 50)";
    }
}
