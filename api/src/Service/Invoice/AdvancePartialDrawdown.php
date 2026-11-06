<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AdvanceDeductionRepository;

/**
 * Částečné čerpání zaplacené zálohy konceptem běžné faktury (smlouvy o dílo s milníky).
 *
 * Jedna proforma s daňovým dokladem k platbě (DDKP) se vyúčtuje postupně několika
 * fakturami: každá nese vlastní položky plnění a odečte jen část zálohy zápornými
 * odpočtovými řádky § 37a. Vazba na proformu je `parent_invoice_id` stejně jako
 * u {@see FinalFromProformaCreator}, takže zaúčtování (PostingService::advanceDeduction)
 * i saldokonto čtou čerpání ze stejné definice odpočtového řádku: záporný řádek
 * faktury mimo slevový.
 *
 * Rozdělení odpočtu:
 *   - po sazbách DPH v poměru dosud nevyčerpaných částek DDKP,
 *   - čerpání, které zálohu vyčerpá, bere přesný zbytek základu každé sazby, takže součet
 *     odpočtů všech faktur sedí na DDKP bez haléřového driftu.
 *
 * Nepodporuje DDKP s řádky OSS (daň z úplaty patří do OSS přiznání a odpočet by musel
 * nést její profil po jednotlivých dokladech) ani proformu čerpanou v saldokontu.
 */
final class AdvancePartialDrawdown
{
    private const TOLERANCE = 0.01;

    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceCalculator $calc,
        private readonly AdvanceCycleLock $cycleLock,
    ) {}

    /**
     * Nahradí odpočet zálohy na konceptu faktury čerpáním `$amount` (s DPH, v měně dokladu)
     * ze zálohy `$proformaId`.
     *
     * @return array{proforma_id:int, amount:float, available:float, remaining:float}
     */
    public function apply(int $supplierId, int $invoiceId, int $proformaId, float $amount): array
    {
        if ($amount <= 0.0) {
            throw new \InvalidArgumentException('Čerpaná částka zálohy musí být kladná.');
        }
        return $this->cycleLock->synchronized(
            $proformaId,
            fn (): array => $this->transactional(fn (): array => $this->applyUnlocked($supplierId, $invoiceId, $proformaId, round($amount, 2))),
        );
    }

    /** Odebere z konceptu faktury odpočet zálohy i vazbu na proformu. */
    public function remove(int $supplierId, int $invoiceId): void
    {
        $invoice = $this->draftInvoice($supplierId, $invoiceId);
        $proformaId = (int) ($invoice['parent_invoice_id'] ?? 0);
        $this->cycleLock->synchronized($proformaId, function () use ($supplierId, $invoiceId): void {
            $this->transactional(function () use ($supplierId, $invoiceId): void {
                $this->draftInvoice($supplierId, $invoiceId, true);
                $this->deleteDeductionLines($invoiceId);
                $this->db->pdo()
                    ->prepare('UPDATE invoices SET parent_invoice_id = NULL WHERE id = ? AND supplier_id = ?')
                    ->execute([$invoiceId, $supplierId]);
            });
        });
        $this->calc->recompute($invoiceId);
    }

    /**
     * Zálohy odběratele, ze kterých může koncept čerpat, s dosud nevyčerpanou částkou.
     *
     * @return list<array{id:int, varsymbol:?string, issue_date:string, total_with_vat:float, available:float}>
     */
    public function candidates(int $supplierId, int $invoiceId): array
    {
        $invoice = $this->draftInvoice($supplierId, $invoiceId);
        $stmt = $this->db->pdo()->prepare(
            "SELECT p.id, p.varsymbol, p.issue_date, p.total_with_vat
               FROM invoices p
              WHERE p.supplier_id = ? AND p.client_id = ? AND p.currency_id = ?
                AND p.invoice_type = 'proforma' AND p.status NOT IN ('draft', 'cancelled')
                AND EXISTS (SELECT 1 FROM invoices td
                             WHERE td.supplier_id = p.supplier_id AND td.parent_invoice_id = p.id
                               AND td.invoice_type = 'tax_document' AND td.status NOT IN ('draft', 'cancelled'))
              ORDER BY p.issue_date DESC, p.id DESC
              LIMIT 50"
        );
        $stmt->execute([$supplierId, (int) $invoice['client_id'], (int) $invoice['currency_id']]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $groups = $this->taxDocumentGroups($supplierId, (int) $row['id']);
            $drawn = $this->drawnGroups($supplierId, (int) $row['id'], $invoiceId);
            $available = 0.0;
            foreach ($groups as $rateId => $g) {
                $available += $g['gross'] - ($drawn[$rateId]['gross'] ?? 0.0);
            }
            $available = round($available, 2);
            if ($available <= self::TOLERANCE && (int) ($invoice['parent_invoice_id'] ?? 0) !== (int) $row['id']) {
                continue;
            }
            $out[] = [
                'id'             => (int) $row['id'],
                'varsymbol'      => $row['varsymbol'],
                'issue_date'     => (string) $row['issue_date'],
                'total_with_vat' => (float) $row['total_with_vat'],
                'available'      => max(0.0, $available),
            ];
        }
        return $out;
    }

    /** @return array{proforma_id:int, amount:float, available:float, remaining:float} */
    private function applyUnlocked(int $supplierId, int $invoiceId, int $proformaId, float $amount): array
    {
        $pdo = $this->db->pdo();
        $invoice = $this->draftInvoice($supplierId, $invoiceId, true);
        $currentParent = (int) ($invoice['parent_invoice_id'] ?? 0);
        if ($currentParent > 0 && $currentParent !== $proformaId) {
            throw new \DomainException('Faktura už čerpá jinou zálohu. Nejdřív odeberte stávající odpočet.');
        }

        $proforma = $pdo->prepare(
            "SELECT id, client_id, currency_id, status, invoice_type FROM invoices
              WHERE id = ? AND supplier_id = ? FOR UPDATE"
        );
        $proforma->execute([$proformaId, $supplierId]);
        $p = $proforma->fetch(\PDO::FETCH_ASSOC);
        if ($p === false || (string) $p['invoice_type'] !== 'proforma') {
            throw new \DomainException('Zálohová faktura nenalezena.');
        }
        if (in_array((string) $p['status'], ['draft', 'cancelled'], true)) {
            throw new \DomainException('Zálohová faktura není vystavená.');
        }
        if ((int) $p['client_id'] !== (int) $invoice['client_id'] || (int) $p['currency_id'] !== (int) $invoice['currency_id']) {
            throw new \DomainException('Záloha musí patřit stejnému odběrateli a být ve stejné měně jako faktura.');
        }
        if ($currentParent !== $proformaId) {
            AdvanceDeductionRepository::assertLinkAllowed($pdo, $supplierId, 'invoice', $invoiceId, $proformaId);
        }

        $groups = $this->taxDocumentGroups($supplierId, $proformaId);
        if ($groups === []) {
            throw new \DomainException('K záloze není vystavený daňový doklad k přijaté platbě. Čerpat lze jen zálohu s daňovým dokladem.');
        }
        $drawn = $this->drawnGroups($supplierId, $proformaId, $invoiceId);

        $rest = [];
        $available = 0.0;
        foreach ($groups as $rateId => $g) {
            $restBase = round($g['base'] - ($drawn[$rateId]['base'] ?? 0.0), 2);
            $restGross = round($g['gross'] - ($drawn[$rateId]['gross'] ?? 0.0), 2);
            if ($restGross > self::TOLERANCE) {
                $rest[$rateId] = ['base' => $restBase, 'gross' => $restGross] + $g;
                $available += $restGross;
            }
        }
        $available = round($available, 2);
        if ($amount > $available + self::TOLERANCE) {
            throw new \DomainException(sprintf('Ze zálohy zbývá k čerpání jen %.2f.', $available));
        }

        $lines = [];
        if ($amount >= $available - self::TOLERANCE) {
            // Vyčerpání zálohy: přesný zbytek každé sazby, ať součet odpočtů sedí na DDKP.
            foreach ($rest as $rateId => $r) {
                $lines[] = ['vat_rate_id' => $rateId, 'base' => $r['base'], 'gross' => $r['gross']] + $r;
            }
        } else {
            $allocated = 0.0;
            $keys = array_keys($rest);
            $last = end($keys);
            foreach ($rest as $rateId => $r) {
                $gross = $rateId === $last
                    ? round($amount - $allocated, 2)
                    : round($amount * $r['gross'] / $available, 2);
                $allocated += $gross;
                $base = round($gross / (1 + $r['rate'] / 100), 2);
                $lines[] = ['vat_rate_id' => $rateId, 'base' => $base, 'gross' => $gross] + $r;
            }
        }

        $this->deleteDeductionLines($invoiceId);
        $grossMode = !empty($invoice['prices_include_vat']);
        $isEn = ($invoice['language'] ?? 'cs') === 'en';
        $order = $pdo->prepare('SELECT COALESCE(MAX(order_index), 0) FROM invoice_items WHERE invoice_id = ?');
        $order->execute([$invoiceId]);
        $maxOrder = (int) $order->fetchColumn();
        $insert = $pdo->prepare(
            "INSERT INTO invoice_items
               (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                total_without_vat, total_vat, total_with_vat, order_index, item_kind, vat_classification_code)
             VALUES (?, ?, 1, '', ?, ?, ?, 0, 0, 0, ?, 'standard', ?)"
        );
        foreach ($lines as $line) {
            $insert->execute([
                $invoiceId,
                $isEn
                    ? 'Advance deduction — tax document ' . $line['label']
                    : 'Odpočet zálohy — daňový doklad ' . $line['label'],
                $grossMode ? -$line['gross'] : -$line['base'],
                $line['vat_rate_id'],
                $line['rate'],
                ++$maxOrder,
                $line['code'],
            ]);
        }
        $pdo->prepare('UPDATE invoices SET parent_invoice_id = ? WHERE id = ? AND supplier_id = ?')
            ->execute([$proformaId, $invoiceId, $supplierId]);
        $this->calc->recompute($invoiceId);

        return [
            'proforma_id' => $proformaId,
            'amount'      => $amount,
            'available'   => $available,
            'remaining'   => max(0.0, round($available - $amount, 2)),
        ];
    }

    /**
     * Řádky živých DDKP proformy po sazbách. Definice „živého" DDKP je stejná jako
     * ve FinalFromProformaCreator a PostingService::advanceDeduction.
     *
     * Odpočet nese klasifikaci řádku DDKP, který ruší. Čerpání se dělí po sazbách, proto
     * dva různé kódy v jedné sazbě nejde rozdělit jednoznačně a odmítají se, stejně jako OSS.
     *
     * @return array<int, array{base:float, gross:float, rate:float, code:?string, label:string}>
     */
    private function taxDocumentGroups(int $supplierId, int $proformaId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT td.varsymbol, ii.vat_rate_id, ii.vat_rate_snapshot, COALESCE(ii.oss_applicable, 0) AS oss,
                    ii.vat_classification_code, ii.total_without_vat, ii.total_with_vat
               FROM invoices td
               JOIN invoice_items ii ON ii.invoice_id = td.id
              WHERE td.supplier_id = ? AND td.invoice_type = 'tax_document'
                AND td.status NOT IN ('draft', 'cancelled')
                AND (td.parent_invoice_id = ?
                     OR td.id IN (SELECT p.tax_document_invoice_id FROM invoice_payments p
                                   WHERE p.invoice_id = ? AND p.tax_document_invoice_id IS NOT NULL))
              ORDER BY td.id, ii.id"
        );
        $stmt->execute([$supplierId, $proformaId, $proformaId]);
        $groups = [];
        $labels = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ((int) $row['oss'] === 1) {
                throw new \DomainException('Daňový doklad k záloze obsahuje řádky OSS. Částečné čerpání takové zálohy není podporované.');
            }
            $rateId = (int) $row['vat_rate_id'];
            $groups[$rateId] ??= ['base' => 0.0, 'gross' => 0.0, 'rate' => (float) $row['vat_rate_snapshot'], 'code' => null];
            $code = $row['vat_classification_code'] !== null ? (string) $row['vat_classification_code'] : null;
            if ($code !== null) {
                if ($groups[$rateId]['code'] !== null && $groups[$rateId]['code'] !== $code) {
                    throw new \DomainException('Daňový doklad k záloze má v jedné sazbě různé klasifikace DPH. Částečné čerpání takové zálohy není podporované.');
                }
                $groups[$rateId]['code'] = $code;
            }
            $groups[$rateId]['base'] += (float) $row['total_without_vat'];
            $groups[$rateId]['gross'] += (float) $row['total_with_vat'];
            $labels[(string) ($row['varsymbol'] ?? '')] = true;
        }
        $label = implode(', ', array_filter(array_keys($labels), static fn (string $v): bool => $v !== ''));
        foreach ($groups as $rateId => $g) {
            $groups[$rateId]['base'] = round($g['base'], 2);
            $groups[$rateId]['gross'] = round($g['gross'], 2);
            $groups[$rateId]['label'] = $label;
        }
        return $groups;
    }

    /**
     * Co ze zálohy už čerpaly ostatní faktury (bez stornovaných a bez `$exceptInvoiceId`),
     * po sazbách. Odpočtový řádek = záporný řádek mimo slevový, shodně s PostingService.
     *
     * @return array<int, array{base:float, gross:float}>
     */
    private function drawnGroups(int $supplierId, int $proformaId, int $exceptInvoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT ii.vat_rate_id, -SUM(ii.total_without_vat) AS base, -SUM(ii.total_with_vat) AS gross
               FROM invoices f
               JOIN invoice_items ii ON ii.invoice_id = f.id
              WHERE f.supplier_id = ? AND f.parent_invoice_id = ? AND f.invoice_type = 'invoice'
                AND f.status <> 'cancelled' AND f.id <> ?
                AND ii.total_with_vat < 0 AND COALESCE(ii.item_kind, 'standard') <> 'discount'
              GROUP BY ii.vat_rate_id"
        );
        $stmt->execute([$supplierId, $proformaId, $exceptInvoiceId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['vat_rate_id']] = ['base' => round((float) $row['base'], 2), 'gross' => round((float) $row['gross'], 2)];
        }
        return $out;
    }

    private function deleteDeductionLines(int $invoiceId): void
    {
        $this->db->pdo()->prepare(
            "DELETE FROM invoice_items
              WHERE invoice_id = ? AND total_with_vat < 0 AND COALESCE(item_kind, 'standard') <> 'discount'"
        )->execute([$invoiceId]);
    }

    /** @return array<string,mixed> */
    private function draftInvoice(int $supplierId, int $invoiceId, bool $forUpdate = false): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, client_id, currency_id, status, invoice_type, parent_invoice_id, prices_include_vat, language
               FROM invoices WHERE id = ? AND supplier_id = ?' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$invoiceId, $supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \DomainException('Faktura nenalezena.');
        }
        if ((string) $row['invoice_type'] !== 'invoice' || (string) $row['status'] !== 'draft') {
            throw new \DomainException('Zálohu lze čerpat jen konceptem běžné faktury.');
        }
        return $row;
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function transactional(callable $fn): mixed
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        }
        try {
            $result = $fn();
            if ($owns) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
