<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Check\Checks;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\Invoice\TaxDocumentAdvanceLink;
use MyInvoice\Service\Migration\Check\CheckItem;
use MyInvoice\Service\Migration\Check\CheckResult;
use MyInvoice\Service\Migration\Check\FixableMigrationDataCheck;
use MyInvoice\Service\Migration\Check\FixPreview;
use MyInvoice\Service\Migration\Check\FixResult;
use MyInvoice\Service\Migration\Check\MigrationCheckLocks;
use MyInvoice\Service\Migration\Check\MigrationSources;
use MyInvoice\Service\Migration\Check\SourceScopedCheck;
use MyInvoice\Service\Migration\Pohoda\DocumentLinker;
use PDO;

/**
 * Kontrola 7: vydané daňové doklady k přijaté platbě (DDKP) nenavázané na zálohu.
 *
 * Bez vazby „Odečíst zálohu" na konceptu konečné faktury zálohu nenabídne, „Vystavit
 * fakturu k záloze" neodečte DPH z DDKP a ruční „Spárovat se zálohou" DPH zdvojí. Převod
 * z POHODY četl číslo zálohy z textu DDKP i se závorkou, takže DDKP dřívějších převodů
 * zůstaly bez vazby. Pravidla navázání jsou stejná jako u převodu:
 *  - číslo zálohy z textu DDKP ({@see DocumentLinker::advanceNumberFromText()}),
 *  - právě jedna nestornovaná záloha s tímto číslem u téhož odběratele,
 *  - celková částka DDKP a zálohy se liší nejvýš o {@see TOLERANCE} (zaokrouhlení POHODY),
 *  - DDKP je vystavený a bez vazby na zálohu (ani přes platbu),
 *  - DDKP neleží v uzavřeném či zamčeném účetním období ani v podaném období DPH (jen hlášení):
 *    vazba rozhoduje o odpočtu DPH zálohy na konečné faktuře, proto platí plné pojistky
 *    ({@see MigrationCheckLocks::reason()}).
 *  - stejný odběratel a měna, kladná částka a součet DDKP navázaných na zálohu nepřekročí její
 *    částku (stejná pravidla jako ruční navázání, nález, který by je porušil, jen ohlásí).
 * Vazbu zapíše ruční navázání DDKP na zálohu ({@see TaxDocumentAdvanceLink::link()}, SSOT
 * s detailem faktury): validace, vazba na jedinou platbu zálohy se stejnou částkou a záznam
 * v auditní stopě. Částky, DPH ani zaúčtování DDKP se nemění.
 */
final class TaxDocumentAdvanceLinkCheck extends SourceScopedCheck implements FixableMigrationDataCheck
{
    public const KEY = 'tax_document_advance_link';

    /** Největší rozdíl celkové částky DDKP a zálohy (zaokrouhlovací řádek POHODY). */
    public const TOLERANCE = 1.0;

    public function __construct(
        MigrationSources $sources,
        private readonly Connection $db,
        private readonly MigrationCheckLocks $locks,
        private readonly TaxDocumentAdvanceLink $links,
    ) {
        parent::__construct($sources);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function title(): string
    {
        return 'Daňové doklady k platbě nenavázané na zálohu';
    }

    public function manual(): string
    {
        return self::MANUAL_CHAPTER . '#1269-ddkp-bez-vazby-na-zalohu';
    }

    protected function sources(): array
    {
        return [MigrationSources::POHODA];
    }

    public function options(): array
    {
        return [];
    }

    public function detect(int $supplierId): CheckResult
    {
        return new CheckResult(array_column($this->candidates($supplierId, false), 'item'));
    }

    /** @param array<string,bool> $options */
    public function preview(int $supplierId, array $options = []): FixPreview
    {
        return FixPreview::fromResult($this->detect($supplierId));
    }

    /** @param array<string,bool> $options */
    public function fix(int $supplierId, ?int $userId, array $options = []): FixResult
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        $own ? $pdo->beginTransaction() : $pdo->exec('SAVEPOINT migration_check_ddkp');
        try {
            $fixed = [];
            $skipped = [];
            foreach ($this->candidates($supplierId, true) as $c) {
                if ($c['item']->state !== CheckItem::FIXABLE) {
                    $skipped[] = $c['item'];
                    continue;
                }
                try {
                    // Ruční navázání ověří doklady dřív, než cokoli zapíše: odmítnutí nález
                    // jen ohlásí a ostatní vazby pokračují.
                    $this->links->link($supplierId, (int) $c['item']->entityId, (int) $c['proforma_id'], $userId, '', 'migration-data-check');
                    $fixed[] = $c['item'];
                } catch (\DomainException|\OutOfBoundsException $e) {
                    $skipped[] = $c['item']->withState(CheckItem::REVIEW, $e->getMessage());
                }
            }
            $own ? $pdo->commit() : $pdo->exec('RELEASE SAVEPOINT migration_check_ddkp');
            return new FixResult($fixed, $skipped);
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            } elseif (!$own) {
                $pdo->exec('ROLLBACK TO SAVEPOINT migration_check_ddkp');
            }
            throw $e;
        }
    }

    /** @return list<array{item:CheckItem,proforma_id:?int}> */
    private function candidates(int $supplierId, bool $forUpdate): array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            "SELECT td.id, td.client_id, td.currency_id, td.varsymbol, td.issue_date, td.tax_date, td.total_with_vat, td.total_vat, td.note_above_items,
                    EXISTS (SELECT 1 FROM pohoda_import_map m WHERE m.supplier_id = td.supplier_id AND m.kind = 'invoice' AND m.target_id = td.id) AS migrated
               FROM invoices td
              WHERE td.supplier_id = ? AND td.invoice_type = 'tax_document' AND td.status NOT IN ('draft', 'cancelled')
                AND td.parent_invoice_id IS NULL
                AND NOT EXISTS (SELECT 1 FROM invoice_payments p WHERE p.supplier_id = td.supplier_id AND p.tax_document_invoice_id = td.id)
              ORDER BY td.tax_date, td.id" . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $refs = [];
        foreach ($rows as &$r) {
            $r['ref'] = DocumentLinker::advanceNumberFromText((string) ($r['note_above_items'] ?? ''));
            if ($r['ref'] !== null) {
                $refs[$r['ref']] = true;
            }
        }
        unset($r);
        $advances = $this->advances($supplierId, array_keys($refs));

        // Zbývající kapacita záloh: částka + tolerance minus DDKP už navázané (stejně jako
        // ruční navázání); fixable nálezy ji postupně čerpají.
        $capacity = [];
        foreach ($advances as $list) {
            foreach ($list as $p) {
                $capacity[(int) $p['id']] = (float) $p['total_with_vat'] + ((string) $p['currency'] === 'CZK' ? 1.0 : 0.01) - (float) $p['linked'];
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $label = (string) ($r['varsymbol'] ?: '#' . $r['id']);
            $date = (string) ($r['tax_date'] ?? $r['issue_date']);
            $amount = (float) $r['total_with_vat'];
            $base = ['tax_vat' => round((float) $r['total_vat'], 2)];
            if ($r['ref'] === null) {
                if ((int) $r['migrated'] === 1) {
                    $out[] = ['item' => new CheckItem('invoice', (int) $r['id'], $label, $date, $amount, CheckItem::REVIEW,
                        'Text DDKP neuvádí číslo zálohy. Navažte DDKP na zálohu ručně, pokud k nějaké patří.', details: $base), 'proforma_id' => null];
                }
                continue;
            }
            $matches = array_values(array_filter($advances[$r['ref']] ?? [], static fn (array $p): bool => (int) $p['client_id'] === (int) $r['client_id']
                && (int) $p['currency_id'] === (int) $r['currency_id']));
            $details = $base + ['advance_no' => $r['ref']];
            if (count($matches) !== 1) {
                $out[] = ['item' => new CheckItem('invoice', (int) $r['id'], $label, $date, $amount, CheckItem::REVIEW,
                    $matches === []
                        ? sprintf('Záloha %s u téhož odběratele ve stejné měně neexistuje.', $r['ref'])
                        : sprintf('Zálohu %s nejde určit jednoznačně (%d zálohy se stejným číslem).', $r['ref'], count($matches)),
                    details: $details), 'proforma_id' => null];
                continue;
            }
            $p = $matches[0];
            $details['advance_id'] = (int) $p['id'];
            $diff = round($amount - (float) $p['total_with_vat'], 2);
            if (abs($diff) > self::TOLERANCE) {
                $out[] = ['item' => new CheckItem('invoice', (int) $r['id'], $label, $date, $amount, CheckItem::REVIEW,
                    sprintf('Částka DDKP %s se liší od zálohy %s (%s).', self::money($amount), $r['ref'], self::money((float) $p['total_with_vat'])),
                    details: $details), 'proforma_id' => null];
                continue;
            }
            if ($amount <= 0.0 || $amount > $capacity[(int) $p['id']] + 0.005) {
                $out[] = ['item' => new CheckItem('invoice', (int) $r['id'], $label, $date, $amount, CheckItem::REVIEW,
                    $amount <= 0.0 ? 'DDKP se zápornou nebo nulovou částkou se na zálohu nenavazuje.'
                        : sprintf('Součet DDKP navázaných na zálohu %s by překročil její částku.', $r['ref']),
                    details: $details), 'proforma_id' => null];
                continue;
            }
            $lock = $this->locks->reason($supplierId, $date, true);
            if ($lock === null) {
                $capacity[(int) $p['id']] -= $amount;
            }
            $out[] = ['item' => new CheckItem('invoice', (int) $r['id'], $label, $date, $amount,
                $lock === null ? CheckItem::FIXABLE : CheckItem::LOCKED,
                $lock ?? sprintf('DDKP se naváže na zálohu %s%s.', $r['ref'], $diff !== 0.0 ? sprintf(' (rozdíl částek %s)', self::money($diff)) : ''),
                details: $details), 'proforma_id' => (int) $p['id']];
        }
        return $out;
    }

    /**
     * Nestornované zálohy firmy podle čísla s částkou DDKP, které už na ně navazují (vazbou
     * i přes platbu, každý doklad jednou, stejně jako ruční navázání).
     *
     * @param list<string> $numbers
     * @return array<string,list<array<string,mixed>>>
     */
    private function advances(int $supplierId, array $numbers): array
    {
        $out = [];
        foreach (array_chunk($numbers, 500) as $chunk) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT a.id, a.client_id, a.currency_id, cur.code AS currency, a.varsymbol, a.total_with_vat,
                        COALESCE((SELECT SUM(d.total_with_vat) FROM invoices d
                                   WHERE d.supplier_id = a.supplier_id AND d.parent_invoice_id = a.id
                                     AND d.invoice_type = 'tax_document' AND d.status <> 'cancelled'), 0)
                      + COALESCE((SELECT SUM(d.total_with_vat) FROM invoice_payments p
                                    JOIN invoices d ON d.id = p.tax_document_invoice_id AND d.supplier_id = p.supplier_id
                                   WHERE p.supplier_id = a.supplier_id AND p.invoice_id = a.id
                                     AND d.invoice_type = 'tax_document' AND d.status <> 'cancelled'
                                     AND (d.parent_invoice_id IS NULL OR d.parent_invoice_id <> p.invoice_id)), 0) AS linked
                   FROM invoices a JOIN currencies cur ON cur.id = a.currency_id
                  WHERE a.supplier_id = ? AND a.varsymbol IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")
                    AND a.invoice_type = 'proforma' AND a.status <> 'cancelled'"
            );
            $stmt->execute([$supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $out[(string) $p['varsymbol']][] = $p;
            }
        }
        return $out;
    }
}
