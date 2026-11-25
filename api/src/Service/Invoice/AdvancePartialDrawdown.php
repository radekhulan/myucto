<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AdvanceDeductionRepository;
use MyInvoice\Support\Sql\AdvanceDrawSql;

/**
 * Čerpání zaplacených záloh konceptem běžné faktury.
 *
 * Koncept faktury s vlastními položkami plnění odečte z libovolného počtu záloh (proforem
 * s daňovým dokladem k platbě, DDKP) zadané části zápornými odpočtovými řádky § 37a,
 * po zálohách a sazbách. Jedna záloha se zároveň dá vyúčtovat postupně několika fakturami
 * (smlouvy o dílo s milníky).
 *
 * Zdroj pravdy o čerpání jsou odpočtové řádky ({@see AdvanceDrawSql}): každý řádek nese
 * zálohu, ze které odečítá (`invoice_items.advance_invoice_id`), popis s číslem zálohy
 * a jejího DDKP a klasifikaci řádku DDKP. Vazba dokladu `parent_invoice_id` drží zálohu
 * jen u faktury, která čerpá právě jednu (dosavadní chování a čtení vazby jinde v systému);
 * faktura čerpající víc záloh ji nemá, jedna vazba by celé čerpání přisoudila jediné záloze.
 * Zaúčtování (PostingService::advanceDeduction) i saldokonto čtou čerpání z týchž řádků.
 *
 * Rozdělení odpočtu jedné zálohy:
 *   - po sazbách DPH v poměru dosud nevyčerpaných částek DDKP (ve zvoleném základu: s DPH,
 *     nebo bez DPH),
 *   - čerpání, které zálohu vyčerpá, bere přesný zbytek základu každé sazby, takže součet
 *     odpočtů všech faktur sedí na DDKP bez haléřového driftu.
 *
 * Čerpat lze jen zálohu s DDKP. Záloha plátce bez DDKP nemá přiznanou daň, takže odpočtový
 * řádek § 37a by snížil daň, kterou nikdo nepřiznal; vyúčtuje ji faktura vystavená z proformy
 * (úhrada zálohy se odečte mimo daň v hlavičce) nebo saldokonto. Nepodporuje DDKP s řádky
 * OSS (daň z úplaty patří do OSS přiznání a odpočet by musel nést její profil po
 * jednotlivých dokladech) ani proformu čerpanou v saldokontu.
 */
final class AdvancePartialDrawdown
{
    public const BASIS_GROSS = 'gross';
    public const BASIS_NET = 'net';

    private const TOLERANCE = 0.01;

    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceCalculator $calc,
        private readonly AdvanceCycleLock $cycleLock,
    ) {}

    /**
     * Nastaví na konceptu faktury čerpání zálohy `$proformaId` na `$amount` (v měně dokladu,
     * `$basis` = s DPH, nebo bez DPH). Čerpání ostatních záloh na faktuře zůstává.
     *
     * @return array{proforma_id:int, amount:float, base:float, available:float, remaining:float}
     */
    public function apply(int $supplierId, int $invoiceId, int $proformaId, float $amount, string $basis = self::BASIS_GROSS, bool $allowIssued = false): array
    {
        if ($amount <= 0.0) {
            throw new \InvalidArgumentException('Čerpaná částka zálohy musí být kladná.');
        }
        return $this->applySpec($supplierId, $invoiceId, $proformaId, round($amount, 2), null, self::basis($basis), $allowIssued);
    }

    /**
     * Jako {@see apply()}, částka je `$percent` % plnění faktury (součtu jejích řádků mimo
     * odpočty záloh) ve zvoleném základu, typicky 30 %.
     *
     * @return array{proforma_id:int, amount:float, base:float, available:float, remaining:float}
     */
    public function applyPercent(int $supplierId, int $invoiceId, int $proformaId, float $percent, string $basis = self::BASIS_GROSS, bool $allowIssued = false): array
    {
        if ($percent <= 0.0 || $percent > 100.0) {
            throw new \InvalidArgumentException('Procento plnění faktury musí být větší než 0 a nejvýš 100.');
        }
        return $this->applySpec($supplierId, $invoiceId, $proformaId, null, $percent, self::basis($basis), $allowIssued);
    }

    /**
     * Odebere z konceptu faktury čerpání zálohy `$proformaId`, nebo bez něj čerpání všech
     * záloh i vazbu na proformu.
     *
     * @return list<int> zálohy, jejichž čerpání se odebralo
     */
    public function remove(int $supplierId, int $invoiceId, ?int $proformaId = null): array
    {
        $invoice = $this->draftInvoice($supplierId, $invoiceId);
        $parentProforma = $this->parentProforma($supplierId, $invoice);
        $locks = $proformaId !== null && $proformaId > 0
            ? [$proformaId]
            : array_keys($this->ownDraws($invoiceId, $parentProforma) + ($parentProforma > 0 ? [$parentProforma => true] : []));
        sort($locks);
        $removed = $this->synchronizedMany($locks, fn (): array => $this->transactional(function () use ($supplierId, $invoiceId, $proformaId): array {
            $invoice = $this->draftInvoice($supplierId, $invoiceId, true);
            $parentProforma = $this->parentProforma($supplierId, $invoice);
            $own = $this->ownDraws($invoiceId, $parentProforma);
            $pdo = $this->db->pdo();
            if ($proformaId === null || $proformaId <= 0) {
                $this->deleteDeductionLines($invoiceId, null, $parentProforma);
                $pdo->prepare('UPDATE invoices SET parent_invoice_id = NULL WHERE id = ? AND supplier_id = ?')
                    ->execute([$invoiceId, $supplierId]);
                $ids = array_keys($own);
                if ($parentProforma > 0 && !in_array($parentProforma, $ids, true)) {
                    $ids[] = $parentProforma;
                }
                sort($ids);
                return $ids;
            }
            if (!isset($own[$proformaId])) {
                throw new \DomainException('Faktura ze zadané zálohy nečerpá.');
            }
            $this->stampLegacyLines($invoiceId, $parentProforma);
            $this->deleteDeductionLines($invoiceId, $proformaId);
            $this->normalizeParent($supplierId, $invoiceId, $parentProforma);
            return [$proformaId];
        }));
        $this->calc->recompute($invoiceId);
        return $removed;
    }

    /**
     * Zálohy odběratele, ze kterých může koncept čerpat, s dosud nevyčerpanou částkou
     * (bez čerpání tohoto konceptu) a s částkou, kterou z nich koncept čerpá teď.
     *
     * @return list<array{id:int, varsymbol:?string, issue_date:string, total_with_vat:float, available:float,
     *                    available_base:float, drawn:float, drawn_base:float, tax_documents:string, receipt_posted:bool}>
     */
    public function candidates(int $supplierId, int $invoiceId): array
    {
        $invoice = $this->draftInvoice($supplierId, $invoiceId, false, true);
        $own = $this->ownDraws($invoiceId, $this->parentProforma($supplierId, $invoice));
        $ownSql = $own === [] ? '' : ' OR p.id IN (' . implode(',', array_map('intval', array_keys($own))) . ')';
        $stmt = $this->db->pdo()->prepare(
            "SELECT p.id, p.varsymbol, p.issue_date, p.total_with_vat
               FROM invoices p
              WHERE p.supplier_id = ? AND p.client_id = ? AND p.currency_id = ?
                AND p.invoice_type = 'proforma' AND p.status NOT IN ('draft', 'cancelled')
                AND (EXISTS (SELECT 1 FROM invoices td
                              WHERE td.supplier_id = p.supplier_id AND td.parent_invoice_id = p.id
                                AND td.invoice_type = 'tax_document' AND td.status NOT IN ('draft', 'cancelled')){$ownSql})
              ORDER BY p.issue_date DESC, p.id DESC
              LIMIT 50"
        );
        $stmt->execute([$supplierId, (int) $invoice['client_id'], (int) $invoice['currency_id']]);
        $out = [];
        $receipts = new AdvanceReceiptGuard($this->db);
        $doubleEntry = $receipts->isDoubleEntry($supplierId);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            $groups = $this->taxDocumentGroups($supplierId, $id, false);
            $drawn = $this->drawnGroups($supplierId, $id, $invoiceId);
            $available = 0.0;
            $availableBase = 0.0;
            foreach ($groups as $rateId => $g) {
                $available += $g['gross'] - ($drawn[$rateId]['gross'] ?? 0.0);
                $availableBase += $g['base'] - ($drawn[$rateId]['base'] ?? 0.0);
            }
            $available = round($available, 2);
            if ($available <= self::TOLERANCE && !isset($own[$id])) {
                continue;
            }
            $out[] = [
                'id'             => $id,
                'varsymbol'      => $row['varsymbol'],
                'issue_date'     => (string) $row['issue_date'],
                'total_with_vat' => (float) $row['total_with_vat'],
                'available'      => max(0.0, $available),
                'available_base' => max(0.0, round($availableBase, 2)),
                'drawn'          => $own[$id]['gross'] ?? 0.0,
                'drawn_base'     => $own[$id]['base'] ?? 0.0,
                'tax_documents'  => $groups === [] ? '' : (string) reset($groups)['label'],
                // Lze čerpání zaúčtovat ({@see AdvanceReceiptGuard})? Bez zaúčtované nebo převzaté
                // úhrady zálohy čerpání POST odmítne (advance_receipt_not_posted).
                'receipt_posted' => $receipts->covers($supplierId, $id, 0.0, $doubleEntry),
            ];
        }
        return $out;
    }

    /**
     * Rozpad řádků konceptu na plnění a čerpání záloh, pro detail faktury. Odpočtový řádek
     * patří záloze z `advance_invoice_id`, jinak záloze z hlavičky (`$parentProformaId`),
     * ostatní řádky (i záporné bez zálohy) jsou plnění. Částky v měně dokladu.
     *
     * @param list<array<string,mixed>> $items řádky faktury (InvoiceRepository::itemsFor)
     * @return array{supply:array{gross:float, base:float}, drawdowns:list<array{proforma_id:int, gross:float, base:float, vat:float}>}
     */
    public static function summarize(array $items, ?int $parentProformaId): array
    {
        $supplyGross = 0.0;
        $supplyBase = 0.0;
        $draws = [];
        foreach ($items as $it) {
            $gross = (float) ($it['total_with_vat'] ?? 0);
            $base = (float) ($it['total_without_vat'] ?? 0);
            $advance = self::lineAdvance($it, $parentProformaId);
            if ($advance > 0) {
                $draws[$advance] ??= ['proforma_id' => $advance, 'gross' => 0.0, 'base' => 0.0, 'vat' => 0.0];
                $draws[$advance]['gross'] -= $gross;
                $draws[$advance]['base'] -= $base;
                $draws[$advance]['vat'] -= (float) ($it['total_vat'] ?? 0);
                continue;
            }
            $supplyGross += $gross;
            $supplyBase += $base;
        }
        return [
            'supply'    => ['gross' => round($supplyGross, 2), 'base' => round($supplyBase, 2)],
            'drawdowns' => array_values(array_map(static fn (array $d): array => [
                'proforma_id' => $d['proforma_id'],
                'gross'       => round($d['gross'], 2),
                'base'        => round($d['base'], 2),
                'vat'         => round($d['vat'], 2),
            ], $draws)),
        ];
    }

    /** Záloha, kterou řádek čerpá (0 = není odpočtový řádek zálohy). */
    private static function lineAdvance(array $item, ?int $parentProformaId): int
    {
        if ((float) ($item['total_with_vat'] ?? 0) >= 0.0 || (string) ($item['item_kind'] ?? 'standard') === 'discount') {
            return 0;
        }
        $stamped = (int) ($item['advance_invoice_id'] ?? 0);
        return $stamped > 0 ? $stamped : (int) ($parentProformaId ?? 0);
    }

    /** @return array{proforma_id:int, amount:float, base:float, available:float, remaining:float} */
    private function applySpec(int $supplierId, int $invoiceId, int $proformaId, ?float $amount, ?float $percent, string $basis, bool $allowIssued): array
    {
        if ($proformaId <= 0) {
            throw new \InvalidArgumentException('Zadejte zálohu (proforma_id).');
        }
        return $this->cycleLock->synchronized(
            $proformaId,
            fn (): array => $this->transactional(fn (): array => $this->applyUnlocked($supplierId, $invoiceId, $proformaId, $amount, $percent, $basis, $allowIssued)),
        );
    }

    /** @return array{proforma_id:int, amount:float, base:float, available:float, remaining:float} */
    private function applyUnlocked(int $supplierId, int $invoiceId, int $proformaId, ?float $amount, ?float $percent, string $basis, bool $allowIssued): array
    {
        $pdo = $this->db->pdo();
        $invoice = $this->draftInvoice($supplierId, $invoiceId, true, $allowIssued);
        $parentProforma = $this->parentProforma($supplierId, $invoice);
        $own = $this->ownDraws($invoiceId, $parentProforma);

        $proforma = $pdo->prepare(
            "SELECT id, client_id, currency_id, status, invoice_type, varsymbol FROM invoices
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
        if (!isset($own[$proformaId]) && $parentProforma !== $proformaId) {
            AdvanceDeductionRepository::assertLinkAllowed($pdo, $supplierId, 'invoice', $invoiceId, $proformaId);
        }
        if ($parentProforma > 0 && $parentProforma !== $proformaId && !isset($own[$parentProforma])) {
            // Vazba na zálohu bez odpočtových řádků: úhrada zálohy bez DDKP odečtená v hlavičce
            // (advance_paid_amount). Takové vyúčtování nejde rozdělit mezi víc záloh.
            throw new \DomainException('Faktura vyúčtovává zálohu bez daňového dokladu k platbě (úhrada odečtená v hlavičce). Další zálohu k ní přidat nelze, vyúčtujte ji samostatnou fakturou.');
        }
        $advancesAfter = array_unique([...array_keys($own), $proformaId]);
        $multi = count($advancesAfter) > 1;
        if ($multi && abs((float) ($invoice['advance_paid_amount'] ?? 0)) >= self::TOLERANCE) {
            throw new \DomainException('Faktura odečítá v hlavičce úhradu zálohy bez daňového dokladu. Čerpání více záloh jednou fakturou ji nepřipouští, odečet úhrady zrušte nebo vyúčtujte zálohy samostatně.');
        }

        $groups = $this->taxDocumentGroups($supplierId, $proformaId, true);
        if ($groups === []) {
            throw new \DomainException('K záloze není vystavený daňový doklad k přijaté platbě. Čerpat lze jen zálohu s daňovým dokladem.');
        }
        // Postupné čerpání: stačí, že záloha nějakou přijatou úhradu v deníku má; pokrytí
        // částky po fakturách hlídá zaúčtování.
        (new AdvanceReceiptGuard($this->db))->assertCovers($supplierId, $proformaId, (string) ($p['varsymbol'] ?? ''));
        $drawn = $this->drawnGroups($supplierId, $proformaId, $invoiceId);

        $rest = [];
        $availableGross = 0.0;
        $availableBase = 0.0;
        foreach ($groups as $rateId => $g) {
            $restBase = round($g['base'] - ($drawn[$rateId]['base'] ?? 0.0), 2);
            $restGross = round($g['gross'] - ($drawn[$rateId]['gross'] ?? 0.0), 2);
            if ($restGross > self::TOLERANCE) {
                $rest[$rateId] = ['base' => $restBase, 'gross' => $restGross] + $g;
                $availableGross += $restGross;
                $availableBase += $restBase;
            }
        }
        $availableGross = round($availableGross, 2);
        $availableBase = round($availableBase, 2);
        $available = $basis === self::BASIS_NET ? $availableBase : $availableGross;

        $supply = $this->supply($invoiceId, $parentProforma);
        if ($percent !== null) {
            $amount = round(($basis === self::BASIS_NET ? $supply['base'] : $supply['gross']) * $percent / 100, 2);
            if ($amount <= 0.0) {
                throw new \DomainException('Faktura nemá plnění, ze kterého by šlo procento zálohy spočítat.');
            }
        }
        $amount = (float) $amount;
        if ($amount > $available + self::TOLERANCE) {
            throw new \DomainException(sprintf(
                'Ze zálohy zbývá k čerpání jen %.2f %s.',
                max(0.0, $available),
                $basis === self::BASIS_NET ? 'bez DPH' : 's DPH',
            ));
        }

        $lines = [];
        if ($amount >= $available - self::TOLERANCE) {
            // Vyčerpání zálohy: přesný zbytek každé sazby, ať součet odpočtů sedí na DDKP.
            foreach ($rest as $rateId => $r) {
                $lines[] = ['vat_rate_id' => $rateId] + $r;
            }
        } else {
            $key = $basis === self::BASIS_NET ? 'base' : 'gross';
            $allocated = 0.0;
            $keys = array_keys($rest);
            $last = end($keys);
            foreach ($rest as $rateId => $r) {
                $part = $rateId === $last
                    ? round($amount - $allocated, 2)
                    : round($amount * $r[$key] / $available, 2);
                $allocated += $part;
                $factor = 1 + $r['rate'] / 100;
                $base = $key === 'base' ? $part : round($part / $factor, 2);
                $gross = $key === 'gross' ? $part : round($part * $factor, 2);
                $lines[] = ['vat_rate_id' => $rateId, 'base' => $base, 'gross' => $gross] + $r;
            }
        }
        $drawGross = round(array_sum(array_column($lines, 'gross')), 2);
        $drawBase = round(array_sum(array_column($lines, 'base')), 2);

        if ($multi) {
            // Faktura čerpající víc záloh zúčtuje každou zálohu jejím základem proti předpisu
            // faktury; odpočty proto nesmí převýšit plnění (přeplatek by zůstal na zálohách
            // a čerpání by nešlo rozdělit). Postupné čerpání jediné zálohy se nemění.
            $otherGross = 0.0;
            foreach ($own as $advanceId => $d) {
                if ($advanceId !== $proformaId) {
                    $otherGross += $d['gross'];
                }
            }
            if (round($otherGross + $drawGross, 2) > $supply['gross'] + self::TOLERANCE) {
                throw new \DomainException(sprintf(
                    'Odpočty záloh (%.2f s DPH) by převýšily plnění faktury (%.2f s DPH). Při čerpání více záloh odečtěte nejvýš plnění faktury.',
                    round($otherGross + $drawGross, 2),
                    $supply['gross'],
                ));
            }
        }

        $this->stampLegacyLines($invoiceId, $parentProforma);
        $this->deleteDeductionLines($invoiceId, $proformaId);
        $grossMode = !empty($invoice['prices_include_vat']);
        $isEn = ($invoice['language'] ?? 'cs') === 'en';
        $proformaNo = trim((string) ($p['varsymbol'] ?? ''));
        $order = $pdo->prepare('SELECT COALESCE(MAX(order_index), 0) FROM invoice_items WHERE invoice_id = ?');
        $order->execute([$invoiceId]);
        $maxOrder = (int) $order->fetchColumn();
        $insert = $pdo->prepare(
            "INSERT INTO invoice_items
               (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                total_without_vat, total_vat, total_with_vat, order_index, item_kind, vat_classification_code,
                advance_invoice_id)
             VALUES (?, ?, 1, '', ?, ?, ?, 0, 0, 0, ?, 'standard', ?, ?)"
        );
        foreach ($lines as $line) {
            $insert->execute([
                $invoiceId,
                self::lineDescription($isEn, $proformaNo, $line['label']),
                $grossMode ? -$line['gross'] : -$line['base'],
                $line['vat_rate_id'],
                $line['rate'],
                ++$maxOrder,
                $line['code'],
                $proformaId,
            ]);
        }
        // Součty nových řádků dopočítá kalkulace; vazbu dokladu pak určí čerpání podle nich.
        $this->calc->recompute($invoiceId);
        $this->normalizeParent($supplierId, $invoiceId, $parentProforma);

        return [
            'proforma_id' => $proformaId,
            'amount'      => $drawGross,
            'base'        => $drawBase,
            'available'   => $availableGross,
            'remaining'   => max(0.0, round($availableGross - $drawGross, 2)),
        ];
    }

    private static function lineDescription(bool $isEn, string $proformaNo, string $taxDocuments): string
    {
        if ($isEn) {
            return 'Advance deduction' . ($proformaNo !== '' ? ' ' . $proformaNo : '') . ', tax document ' . $taxDocuments;
        }
        return 'Odpočet zálohy' . ($proformaNo !== '' ? ' ' . $proformaNo : '') . ', daňový doklad ' . $taxDocuments;
    }

    private static function basis(string $basis): string
    {
        if (!in_array($basis, [self::BASIS_GROSS, self::BASIS_NET], true)) {
            throw new \InvalidArgumentException('Základ částky je gross (s DPH), nebo net (bez DPH).');
        }
        return $basis;
    }

    /**
     * Řádky živých DDKP proformy po sazbách. Definice „živého" DDKP je stejná jako
     * ve FinalFromProformaCreator a PostingService::advanceDeduction.
     *
     * Odpočet nese klasifikaci řádku DDKP, který ruší. Čerpání se dělí po sazbách, proto
     * dva různé kódy v jedné sazbě nejde rozdělit jednoznačně a odmítají se, stejně jako OSS.
     * `$strict = false` (výpis kandidátů) jen sečte částky a neodmítá.
     *
     * @return array<int, array{base:float, gross:float, rate:float, code:?string, label:string}>
     */
    private function taxDocumentGroups(int $supplierId, int $proformaId, bool $strict): array
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
            if ($strict && (int) $row['oss'] === 1) {
                throw new \DomainException('Daňový doklad k záloze obsahuje řádky OSS. Částečné čerpání takové zálohy není podporované.');
            }
            $rateId = (int) $row['vat_rate_id'];
            $groups[$rateId] ??= ['base' => 0.0, 'gross' => 0.0, 'rate' => (float) $row['vat_rate_snapshot'], 'code' => null];
            $code = $row['vat_classification_code'] !== null ? (string) $row['vat_classification_code'] : null;
            if ($code !== null) {
                if ($strict && $groups[$rateId]['code'] !== null && $groups[$rateId]['code'] !== $code) {
                    throw new \DomainException('Daňový doklad k záloze má v jedné sazbě různé klasifikace DPH. Částečné čerpání takové zálohy není podporované.');
                }
                $groups[$rateId]['code'] = $code;
            }
            $groups[$rateId]['base'] += (float) $row['total_without_vat'];
            $groups[$rateId]['gross'] += (float) $row['total_with_vat'];
            $labels[(string) ($row['varsymbol'] ?? '')] = true;
        }
        $label = implode(', ', array_filter(array_map('strval', array_keys($labels)), static fn (string $v): bool => $v !== ''));
        foreach ($groups as $rateId => $g) {
            $groups[$rateId]['base'] = round($g['base'], 2);
            $groups[$rateId]['gross'] = round($g['gross'], 2);
            $groups[$rateId]['label'] = $label;
        }
        return $groups;
    }

    /**
     * Co ze zálohy už čerpaly ostatní faktury (bez stornovaných a bez `$exceptInvoiceId`),
     * po sazbách. Odpočtové řádky zálohy určuje {@see AdvanceDrawSql::advanceLines()},
     * shodně se zaúčtováním.
     *
     * @return array<int, array{base:float, gross:float}>
     */
    private function drawnGroups(int $supplierId, int $proformaId, int $exceptInvoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT d.vat_rate_id, -SUM(d.total_without_vat) AS base, -SUM(d.total_with_vat) AS gross
               FROM (' . AdvanceDrawSql::advanceLines((string) $supplierId, (string) $proformaId) . ") d
              WHERE d.status <> 'cancelled' AND d.final_id <> ?
              GROUP BY d.vat_rate_id"
        );
        $stmt->execute([$exceptInvoiceId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['vat_rate_id']] = ['base' => round((float) $row['base'], 2), 'gross' => round((float) $row['gross'], 2)];
        }
        return $out;
    }

    /**
     * Čerpání záloh tímto konceptem podle odpočtových řádků (v měně dokladu).
     *
     * @return array<int, array{gross:float, base:float}>
     */
    private function ownDraws(int $invoiceId, int $parentProforma): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(ii.advance_invoice_id, ?) AS advance_id,
                    -SUM(ii.total_with_vat) AS gross, -SUM(ii.total_without_vat) AS base
               FROM invoice_items ii
              WHERE ii.invoice_id = ? AND ' . AdvanceDrawSql::deductionLine('ii') . '
              GROUP BY COALESCE(ii.advance_invoice_id, ?)'
        );
        $stmt->execute([$parentProforma, $invoiceId, $parentProforma]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['advance_id'];
            if ($id > 0) {
                $out[$id] = ['gross' => round((float) $row['gross'], 2), 'base' => round((float) $row['base'], 2)];
            }
        }
        return $out;
    }

    /**
     * Plnění konceptu: součet řádků, které nejsou odpočtem zálohy (v měně dokladu).
     *
     * @return array{gross:float, base:float}
     */
    private function supply(int $invoiceId, int $parentProforma): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(ii.total_with_vat), 0), COALESCE(SUM(ii.total_without_vat), 0)
               FROM invoice_items ii
              WHERE ii.invoice_id = ?
                AND NOT (' . AdvanceDrawSql::deductionLine('ii') . ' AND COALESCE(ii.advance_invoice_id, ?) > 0)'
        );
        $stmt->execute([$invoiceId, $parentProforma]);
        [$gross, $base] = $stmt->fetch(\PDO::FETCH_NUM) ?: [0, 0];
        return ['gross' => round((float) $gross, 2), 'base' => round((float) $base, 2)];
    }

    /** Proforma z hlavičky faktury, nebo 0 (bez vazby, nebo vazba na jiný doklad). */
    private function parentProforma(int $supplierId, array $invoice): int
    {
        $parentId = (int) ($invoice['parent_invoice_id'] ?? 0);
        if ($parentId <= 0) {
            return 0;
        }
        $stmt = $this->db->pdo()->prepare('SELECT invoice_type FROM invoices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$parentId, $supplierId]);
        return $stmt->fetchColumn() === 'proforma' ? $parentId : 0;
    }

    /**
     * Odpočtové řádky bez vyznačené zálohy patří záloze z hlavičky. Než se čerpání změní
     * (a vazba dokladu se může zrušit), zálohu se jim vyznačí.
     */
    private function stampLegacyLines(int $invoiceId, int $parentProforma): void
    {
        if ($parentProforma <= 0) {
            return;
        }
        $this->db->pdo()->prepare(
            'UPDATE invoice_items ii SET ii.advance_invoice_id = ?
              WHERE ii.invoice_id = ? AND ii.advance_invoice_id IS NULL AND ' . AdvanceDrawSql::deductionLine('ii')
        )->execute([$parentProforma, $invoiceId]);
    }

    /**
     * Vazba dokladu podle čerpání: faktura čerpající právě jednu zálohu ji má v hlavičce,
     * faktura bez čerpání nebo s víc zálohami ne. Vazbu na jiný doklad než proformu nemění.
     */
    private function normalizeParent(int $supplierId, int $invoiceId, int $parentProforma): void
    {
        $current = $this->db->pdo()->prepare('SELECT parent_invoice_id FROM invoices WHERE id = ? AND supplier_id = ?');
        $current->execute([$invoiceId, $supplierId]);
        $parentId = (int) $current->fetchColumn();
        if ($parentId > 0 && $parentProforma !== $parentId) {
            return;
        }
        $advances = array_keys($this->ownDraws($invoiceId, $parentProforma));
        $target = count($advances) === 1 ? $advances[0] : null;
        $this->db->pdo()->prepare('UPDATE invoices SET parent_invoice_id = ? WHERE id = ? AND supplier_id = ?')
            ->execute([$target, $invoiceId, $supplierId]);
    }

    /**
     * Smaže odpočtové řádky zálohy `$proformaId`, nebo (null) řádky všech záloh: vyznačené
     * i nevyznačené řádky zálohy z hlavičky. Záporný řádek, který žádnou zálohu nečerpá, zůstává.
     */
    private function deleteDeductionLines(int $invoiceId, ?int $proformaId, int $parentProforma = 0): void
    {
        if ($proformaId === null) {
            $this->db->pdo()->prepare(
                'DELETE FROM invoice_items WHERE invoice_id = ? AND COALESCE(advance_invoice_id, ?) > 0 AND '
                . AdvanceDrawSql::deductionLine('invoice_items')
            )->execute([$invoiceId, $parentProforma]);
            return;
        }
        $this->db->pdo()->prepare(
            'DELETE FROM invoice_items WHERE invoice_id = ? AND advance_invoice_id = ? AND ' . AdvanceDrawSql::deductionLine('invoice_items')
        )->execute([$invoiceId, $proformaId]);
    }

    /** @return array<string,mixed> */
    /**
     * `$allowIssued` pustí i vystavenou fakturu ({@see IssuedInvoiceAdvanceDrawdown}, které
     * po čerpání srovná stav úhrady a přeúčtuje zápis). Výpis kandidátů je jen čtení.
     */
    private function draftInvoice(int $supplierId, int $invoiceId, bool $forUpdate = false, bool $allowIssued = false): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, client_id, currency_id, status, invoice_type, parent_invoice_id, prices_include_vat, language,
                    advance_paid_amount, import_total_with_vat_czk
               FROM invoices WHERE id = ? AND supplier_id = ?' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$invoiceId, $supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \DomainException('Faktura nenalezena.');
        }
        $status = (string) $row['status'];
        if ((string) $row['invoice_type'] !== 'invoice'
            || !($status === 'draft' || ($allowIssued && in_array($status, IssuedInvoiceAdvanceDrawdown::STATUSES, true)))) {
            throw new \DomainException('Zálohu lze čerpat jen konceptem běžné faktury.');
        }
        if ($row['import_total_with_vat_czk'] !== null) {
            throw new \DomainException('Na převzatém dokladu s částkami ze zdroje nelze změnit čerpání zálohy.');
        }
        return $row;
    }

    /**
     * Zámky zálohového cyklu všech dotčených záloh (vzestupně, ať se souběžné požadavky seřadí).
     *
     * @param list<int> $proformaIds
     */
    private function synchronizedMany(array $proformaIds, callable $fn): mixed
    {
        if ($proformaIds === []) {
            return $fn();
        }
        $first = array_shift($proformaIds);
        return $this->cycleLock->synchronized($first, fn (): mixed => $this->synchronizedMany($proformaIds, $fn));
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
