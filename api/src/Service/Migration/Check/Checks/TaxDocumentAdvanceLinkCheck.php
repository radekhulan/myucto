<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Check\Checks;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\Migration\Check\CheckItem;
use MyInvoice\Service\Migration\Check\CheckResult;
use MyInvoice\Service\Migration\Check\FixableMigrationDataCheck;
use MyInvoice\Service\Migration\Check\FixPreview;
use MyInvoice\Service\Migration\Check\FixResult;
use MyInvoice\Service\Migration\Check\MigrationCheckLocks;
use MyInvoice\Service\Migration\Check\MigrationSources;
use MyInvoice\Service\Migration\Check\SourceScopedCheck;
use MyInvoice\Service\Migration\Check\TaxDocumentAdvanceLinker;
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
 *  - DDKP neleží v uzavřeném či zamčeném účetním období ani v podaném období DPH (jen hlášení).
 * Oprava doplní vazbu ({@see TaxDocumentAdvanceLinker}); částky, DPH ani zaúčtování se nemění.
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
        private readonly TaxDocumentAdvanceLinker $linker,
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
                if ($c['item']->state === CheckItem::FIXABLE && $this->linker->link($supplierId, (int) $c['item']->entityId, (int) $c['proforma_id'], $userId, 'migration_data_check')) {
                    $fixed[] = $c['item'];
                } else {
                    $skipped[] = $c['item'];
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
            "SELECT td.id, td.client_id, td.varsymbol, td.issue_date, td.tax_date, td.total_with_vat, td.total_vat, td.note_above_items,
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
            $matches = array_values(array_filter($advances[$r['ref']] ?? [], static fn (array $p): bool => (int) $p['client_id'] === (int) $r['client_id']));
            $details = $base + ['advance_no' => $r['ref']];
            if (count($matches) !== 1) {
                $out[] = ['item' => new CheckItem('invoice', (int) $r['id'], $label, $date, $amount, CheckItem::REVIEW,
                    $matches === []
                        ? sprintf('Záloha %s u téhož odběratele neexistuje.', $r['ref'])
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
            $lock = $this->locks->reason($supplierId, $date, true);
            $out[] = ['item' => new CheckItem('invoice', (int) $r['id'], $label, $date, $amount,
                $lock === null ? CheckItem::FIXABLE : CheckItem::LOCKED,
                $lock ?? sprintf('DDKP se naváže na zálohu %s%s.', $r['ref'], $diff !== 0.0 ? sprintf(' (rozdíl částek %s)', self::money($diff)) : ''),
                details: $details), 'proforma_id' => (int) $p['id']];
        }
        return $out;
    }

    /**
     * Nestornované zálohy firmy podle čísla.
     *
     * @param list<string> $numbers
     * @return array<string,list<array{id:int,client_id:int,total_with_vat:string}>>
     */
    private function advances(int $supplierId, array $numbers): array
    {
        $out = [];
        foreach (array_chunk($numbers, 500) as $chunk) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT id, client_id, varsymbol, total_with_vat FROM invoices
                  WHERE supplier_id = ? AND varsymbol IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")
                    AND invoice_type = 'proforma' AND status <> 'cancelled'"
            );
            $stmt->execute([$supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $out[(string) $p['varsymbol']][] = $p;
            }
        }
        return $out;
    }
}
