<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Check\Checks;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\Check\CheckItem;
use MyInvoice\Service\Migration\Check\CheckResult;
use MyInvoice\Service\Migration\Check\MigrationCheckLocks;
use MyInvoice\Service\Migration\Check\MigrationSources;
use MyInvoice\Service\Migration\Check\SourceScopedCheck;
use MyInvoice\Service\Migration\Pohoda\DocumentLinker;
use PDO;

/**
 * Kontrola 8: konečná faktura, která zálohu s daňovým dokladem k platbě (DDKP) odečítá jen
 * v hlavičce (`advance_paid_amount`) bez odpočtových řádků. DPH zálohy je pak v přiznání
 * dvakrát: jednou z DDKP, podruhé z celé konečné faktury.
 *
 * Konečná faktura patří k záloze vazbou (`parent_invoice_id`), nebo bez ní částkou odpočtu,
 * která se do koruny shoduje s DDKP téže zálohy a téhož odběratele. DDKP patří k záloze
 * vazbou, nebo číslem zálohy v textu ({@see DocumentLinker::advanceNumberFromText()}).
 * Když částkou sedí víc DDKP, neví se, kterou zálohu faktura vyúčtovává, a nález je jen
 * k ověření. Oprava se dělá v aplikaci podle manuálu (odpočet zálohy řádkem, § 15.4.3),
 * kontrola proto jen hlásí. Částka nálezu je DPH z DDKP, která je v přiznání podruhé.
 */
final class FinalInvoiceAdvanceVatCheck extends SourceScopedCheck
{
    public const KEY = 'final_invoice_advance_vat';

    /** Shoda odpočtu v hlavičce s DDKP do koruny. */
    private const TOLERANCE = 1.0;

    public function __construct(
        MigrationSources $sources,
        private readonly Connection $db,
        private readonly MigrationCheckLocks $locks,
    ) {
        parent::__construct($sources);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function title(): string
    {
        return 'Konečná faktura se zálohou odečtenou jen v hlavičce';
    }

    public function manual(): string
    {
        return self::MANUAL_CHAPTER . '#12610-konecna-faktura-s-dph-ze-zalohy-podruhe';
    }

    protected function sources(): array
    {
        return [MigrationSources::POHODA, MigrationSources::MONEY_S3, MigrationSources::ABRA, MigrationSources::PREMIER,
            MigrationSources::STEREO_NX, MigrationSources::HELIOS];
    }

    public function detect(int $supplierId): CheckResult
    {
        $pdo = $this->db->pdo();
        $finals = $pdo->prepare(
            "SELECT f.id, f.client_id, f.varsymbol, f.status, f.tax_date, f.total_vat, f.advance_paid_amount, f.parent_invoice_id
               FROM invoices f
              WHERE f.supplier_id = ? AND f.invoice_type = 'invoice' AND f.status NOT IN ('draft', 'cancelled') AND f.advance_paid_amount > 0.01
                AND NOT EXISTS (SELECT 1 FROM invoice_items ii WHERE ii.invoice_id = f.id AND ii.total_vat < 0)
              ORDER BY f.tax_date, f.id"
        );
        $finals->execute([$supplierId]);
        $finals = $finals->fetchAll(PDO::FETCH_ASSOC);
        if ($finals === []) {
            return new CheckResult([]);
        }
        $clients = array_fill_keys(array_map(static fn (array $f): int => (int) $f['client_id'], $finals), true);

        $taxDocs = $pdo->prepare(
            "SELECT td.id, td.client_id, td.varsymbol, td.tax_date, td.total_vat, td.total_with_vat, td.parent_invoice_id, td.note_above_items
               FROM invoices td
              WHERE td.supplier_id = ? AND td.invoice_type = 'tax_document' AND td.status NOT IN ('draft', 'cancelled')"
        );
        $taxDocs->execute([$supplierId]);
        $byClient = [];
        $refs = [];
        foreach ($taxDocs->fetchAll(PDO::FETCH_ASSOC) as $d) {
            if (!isset($clients[(int) $d['client_id']])) {
                continue;
            }
            $d['ref'] = $d['parent_invoice_id'] === null ? DocumentLinker::advanceNumberFromText((string) ($d['note_above_items'] ?? '')) : null;
            if ($d['ref'] !== null) {
                $refs[$d['ref']] = true;
            }
            $byClient[(int) $d['client_id']][] = $d;
        }
        $advanceIds = $this->advanceIds($supplierId, array_map('strval', array_keys($refs)));

        // Index DDKP v paměti: podle zálohy (vazbou nebo číslem z textu u téhož odběratele)
        // a podle odběratele a celé koruny částky. Konečná faktura pak hledá jen ve svém
        // klíči, ne ve všech DDKP odběratele.
        $byAdvance = [];
        $byAmount = [];
        foreach ($byClient as $clientId => $docs) {
            foreach ($docs as $d) {
                $advances = $d['parent_invoice_id'] !== null ? [(int) $d['parent_invoice_id']] : ($advanceIds[$d['ref'] ?? ''][$clientId] ?? []);
                if ($advances === []) {
                    continue;
                }
                foreach ($advances as $advanceId) {
                    $byAdvance[$advanceId][(int) $d['id']] = $d;
                }
                $byAmount[$clientId][(int) floor((float) $d['total_with_vat'])][(int) $d['id']] = $d;
            }
        }

        $items = [];
        foreach ($finals as $f) {
            $matches = [];
            if ($f['parent_invoice_id'] !== null) {
                foreach ($byAdvance[(int) $f['parent_invoice_id']] ?? [] as $id => $d) {
                    if ((int) $d['client_id'] === (int) $f['client_id']) {
                        $matches[$id] = $d;
                    }
                }
            } else {
                $koruna = (int) floor((float) $f['advance_paid_amount']);
                foreach ([$koruna - 1, $koruna, $koruna + 1] as $key) {
                    foreach ($byAmount[(int) $f['client_id']][$key] ?? [] as $id => $d) {
                        if (abs((float) $d['total_with_vat'] - (float) $f['advance_paid_amount']) < self::TOLERANCE) {
                            $matches[$id] = $d;
                        }
                    }
                }
            }
            if ($matches === []) {
                continue;
            }
            $label = (string) ($f['varsymbol'] ?: '#' . $f['id']);
            $filed = $f['tax_date'] !== null ? $this->locks->filedPeriod($supplierId, (string) $f['tax_date']) : null;
            $ddkp = array_values($matches);
            $details = ['tax_documents' => implode(', ', array_map(static fn (array $d): string => (string) $d['varsymbol'], $ddkp)),
                'advance_paid_amount' => round((float) $f['advance_paid_amount'], 2), 'linked' => $f['parent_invoice_id'] !== null, 'filed' => $filed];
            if (count($ddkp) > 1) {
                $items[] = new CheckItem('invoice', (int) $f['id'], $label, $f['tax_date'], null, CheckItem::REVIEW,
                    sprintf('Odpočet zálohy %s je jen v hlavičce a částkou sedí %d DDKP (%s). Ověřte, kterou zálohu faktura vyúčtovává.',
                        self::money((float) $f['advance_paid_amount']), count($ddkp), $details['tax_documents']), details: $details);
                continue;
            }
            $vat = round((float) $ddkp[0]['total_vat'], 2);
            $items[] = new CheckItem('invoice', (int) $f['id'], $label, $f['tax_date'], $vat, CheckItem::REVIEW,
                sprintf('Záloha s DDKP %s (DPH %s) je odečtená jen v hlavičce, DPH zálohy je v přiznání podruhé.%s Opravte odpočet zálohy řádkem podle postupu v manuálu.',
                    (string) $ddkp[0]['varsymbol'], self::money($vat), $filed !== null ? ' Období je podané (' . $filed . '), oprava jde dodatečným přiznáním.' : ''),
                details: $details + ['tax_vat' => $vat]);
        }
        return new CheckResult($items);
    }

    /**
     * Nestornované zálohy firmy podle čísla a odběratele.
     *
     * @param list<string> $numbers
     * @return array<string,array<int,list<int>>> číslo => odběratel => id záloh
     */
    private function advanceIds(int $supplierId, array $numbers): array
    {
        $out = [];
        foreach (array_chunk($numbers, 500) as $chunk) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT id, client_id, varsymbol FROM invoices
                  WHERE supplier_id = ? AND varsymbol IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")
                    AND invoice_type = 'proforma' AND status <> 'cancelled'"
            );
            $stmt->execute([$supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $out[(string) $p['varsymbol']][(int) $p['client_id']][] = (int) $p['id'];
            }
        }
        return $out;
    }
}
