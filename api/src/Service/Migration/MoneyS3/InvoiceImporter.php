<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Service\Stats\StatsRecomputer;
use PDO;

/**
 * Přijaté (`PFaktury`) a vydané (`VFaktury`) faktury.
 *
 * Money drží základ daně po sazbách (`Zaklad_0` mimo DPH, `Zaklad_1` snížená,
 * `Zaklad_2` základní) a sazby na dokladu (`SazbaDPH1`, `SazbaDPH2`), takže doklad ze
 * staršího roku přijde se sazbou, kterou opravdu měl. Z každé sazby vznikne jedna
 * položka. Ceny jsou vždy bez DPH (`prices_include_vat = 0`) — kdyby se převzaly jako
 * brutto, přepočítaly by se znovu.
 *
 * Doklad je zaúčtovaný deníkem z Money ({@see DocumentLinker}), proto stav „zaúčtováno"
 * nebo „uhrazeno", nikdy koncept.
 */
final class InvoiceImporter
{
    public const STEP_PURCHASE = 'purchase_invoices';
    public const STEP_ISSUED = 'issued_invoices';

    /** Rozdíl do 1 Kč mezi součtem sazeb a celkem z Money je zaokrouhlení dokladu. */
    private const ROUNDING_LIMIT = 1.0;

    /** @var array<string,int> */
    private array $rateCache = [];

    public function __construct(
        private readonly Connection $db,
        private readonly MoneyS3ImportRepository $map,
        private readonly CodebookImporter $codebooks,
        private readonly StatsRecomputer $stats,
    ) {}

    public function importPurchases(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        $pdo = $this->db->pdo();
        $currencyId = $this->currencyId($ctx->supplierId);
        $existing = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_PURCHASE_INVOICE);
        $insert = $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_is_vat_payer, varsymbol, vendor_invoice_number,
                 document_kind, issue_date, tax_date, due_date, received_at, received_at_source, currency_id,
                 vendor_snapshot, total_without_vat, total_vat, total_with_vat, rounding,
                 payment_variable_symbol, payment_method, status, paid_at, booked_at, booked_by,
                 note_above_items, note_below_items, external_barcode, prices_include_vat, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "import", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)'
        );
        $insertItem = $pdo->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit_price_without_vat,
                 vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?)'
        );
        $duplicate = $pdo->prepare(
            'SELECT 1 FROM purchase_invoices
              WHERE supplier_id = ? AND vendor_id = ? AND vendor_invoice_number = ? AND issue_date = ? LIMIT 1'
        );

        foreach ($ctx->backup->rowsAcrossYears('PFaktury') as $r) {
            $year = $ctx->yearOf($r);
            $docNo = trim((string) ($r['Doklad'] ?? ''));
            if ($year === null || $docNo === '') {
                continue;
            }
            $key = $year . '|' . $docNo;
            if (isset($existing[$key])) {
                $ctx->purchaseInvoices[$key] = $existing[$key];
                $p->count(self::STEP_PURCHASE, 'existing');
                continue;
            }
            if ($ctx->isLocked($year)) {
                $p->warn(self::STEP_PURCHASE, 'year_locked', "Faktura {$docNo}: rok {$year} je uzavřený, doklad nepřevzat.");
                continue;
            }
            $issue = self::date($r, ['Vystaveno', 'DatUcPr']);
            if ($issue === null) {
                $p->warn(self::STEP_PURCHASE, 'missing_date', "Faktura {$docNo} nemá datum vystavení, nepřevzata.");
                continue;
            }
            $snapshot = [
                'name' => trim((string) ($r['D_Nazev'] ?? '')),
                'ico' => CodebookImporter::ico((string) ($r['D_ICO'] ?? '')),
                'dic' => strtoupper(str_replace(' ', '', trim((string) ($r['D_DIC'] ?? '')))),
                'street' => trim((string) ($r['D_Ulice'] ?? '')),
                'city' => trim((string) ($r['D_Mesto'] ?? '')),
                'zip' => trim((string) ($r['D_Psc'] ?? '')),
            ];
            $vendorId = $this->codebooks->resolvePartner($ctx, $snapshot);
            $amounts = $this->amounts($ctx, self::STEP_PURCHASE, $docNo, $r, self::date($r, ['PlnenoDPH']) ?? $issue);
            $paidAt = self::date($r, ['Uhrazeno']);
            $vendorNumber = mb_substr(trim((string) ($r['PrijatDokl'] ?? '')) ?: (trim((string) ($r['VarSymbol'] ?? '')) ?: $docNo), 0, 50);
            $duplicate->execute([$ctx->supplierId, $vendorId, $vendorNumber, $issue]);
            if ($duplicate->fetchColumn() !== false) {
                $vendorNumber = mb_substr($vendorNumber . ' (' . $docNo . ')', 0, 50);
            }
            $insert->execute([
                $ctx->supplierId,
                $vendorId,
                $snapshot['dic'] !== '' ? 1 : 0,
                mb_substr($docNo, 0, 20),
                $vendorNumber,
                'invoice',
                $issue,
                self::date($r, ['PlnenoDPH']) ?? $issue,
                self::date($r, ['Splatno']) ?? $issue,
                self::date($r, ['Doruceno', 'DatUcPr']) ?? $issue,
                $currencyId,
                json_encode([
                    'company_name' => $snapshot['name'], 'street' => $snapshot['street'], 'city' => $snapshot['city'],
                    'zip' => $snapshot['zip'], 'ic' => $snapshot['ico'], 'dic' => $snapshot['dic'],
                ], JSON_UNESCAPED_UNICODE),
                $amounts['base'],
                $amounts['vat'],
                $amounts['total'],
                $amounts['rounding'],
                mb_substr(trim((string) ($r['VarSymbol'] ?? '')), 0, 20) ?: null,
                'bank_transfer',
                $paidAt !== null ? 'paid' : 'booked',
                $paidAt,
                (self::date($r, ['DatUcPr']) ?? $issue) . ' 00:00:00',
                $ctx->userId > 0 ? $ctx->userId : null,
                mb_substr(trim((string) ($r['Popis'] ?? '')), 0, 255) ?: null,
                'Převzato z Money S3, doklad ' . $docNo,
                // Čárový kód z Money je jistý klíč pro párování naskenovaných příloh.
                mb_substr(trim((string) ($r['BarCode'] ?? '')), 0, 64) ?: null,
                $ctx->userId,
            ]);
            $id = (int) $pdo->lastInsertId();
            foreach ($amounts['items'] as $i => $item) {
                $insertItem->execute([
                    $id,
                    trim((string) ($r['Popis'] ?? '')) ?: 'Převzato z Money S3',
                    $item['base'], $item['rate_id'], $item['rate'],
                    $item['base'], $item['vat'], round($item['base'] + $item['vat'], 2), $i,
                ]);
            }
            $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_PURCHASE_INVOICE, $key, $id, $ctx->runId);
            $ctx->purchaseInvoices[$key] = $id;
            $p->count(self::STEP_PURCHASE, 'created');
        }
        $p->finish(self::STEP_PURCHASE);
    }

    public function importIssued(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        $pdo = $this->db->pdo();
        $currencyId = $this->currencyId($ctx->supplierId);
        $existing = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_INVOICE);
        $insert = $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, invoice_type, client_id, varsymbol, issue_date, tax_date, due_date,
                 currency_id, note_above_items, note_below_items, client_snapshot,
                 total_without_vat, total_vat, total_with_vat, rounding, paid_total,
                 paid_at, status, booked_at, booked_by, prices_include_vat, created_by)
             VALUES (?, "invoice", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)'
        );
        // Převedená faktura je tuzemské plnění: Money S3 v záloze místo plnění pro OSS
        // nedrží, a kdyby šlo o OSS, podané přiznání za ten rok už je v Money.
        $insertItem = $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                 total_without_vat, total_vat, total_with_vat, order_index, oss_applicable)
             VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, 0)'
        );
        $taken = $pdo->prepare('SELECT 1 FROM invoices WHERE supplier_id = ? AND varsymbol = ? LIMIT 1');

        $clients = [];
        foreach ($ctx->backup->rowsAcrossYears('VFaktury') as $r) {
            $year = $ctx->yearOf($r);
            $docNo = trim((string) ($r['Doklad'] ?? ''));
            if ($year === null || $docNo === '') {
                continue;
            }
            $key = $year . '|' . $docNo;
            if (isset($existing[$key])) {
                $ctx->issuedInvoices[$key] = $existing[$key];
                $p->count(self::STEP_ISSUED, 'existing');
                continue;
            }
            if ($ctx->isLocked($year)) {
                $p->warn(self::STEP_ISSUED, 'year_locked', "Faktura {$docNo}: rok {$year} je uzavřený, doklad nepřevzat.");
                continue;
            }
            $issue = self::date($r, ['Vystaveno', 'DatUcPr']);
            if ($issue === null) {
                $p->warn(self::STEP_ISSUED, 'missing_date', "Faktura {$docNo} nemá datum vystavení, nepřevzata.");
                continue;
            }
            $number = mb_substr($docNo, 0, 20);
            $taken->execute([$ctx->supplierId, $number]);
            if ($taken->fetchColumn() !== false) {
                $p->error(self::STEP_ISSUED, 'number_taken', "Číslo faktury {$docNo} už ve firmě má jiný doklad, faktura nepřevzata.");
                continue;
            }
            $snapshot = [
                'name' => trim((string) ($r['O_Nazev'] ?? $r['AdNazev'] ?? '')),
                'ico' => CodebookImporter::ico((string) ($r['O_ICO'] ?? $r['AdICO'] ?? '')),
                'dic' => strtoupper(str_replace(' ', '', trim((string) ($r['O_DIC'] ?? $r['AdDIC'] ?? '')))),
                'street' => trim((string) ($r['O_Ulice'] ?? $r['AdUlice'] ?? '')),
                'city' => trim((string) ($r['O_Mesto'] ?? $r['AdMesto'] ?? '')),
                'zip' => trim((string) ($r['O_Psc'] ?? $r['AdPSC'] ?? '')),
            ];
            $clientId = $this->codebooks->resolvePartner($ctx, $snapshot);
            $clients[$clientId] = $clientId;
            $amounts = $this->amounts($ctx, self::STEP_ISSUED, $docNo, $r, self::date($r, ['PlnenoDPH']) ?? $issue);
            $paidAt = self::date($r, ['Uhrazeno']);
            $insert->execute([
                $ctx->supplierId,
                $clientId,
                $number,
                $issue,
                self::date($r, ['PlnenoDPH']) ?? $issue,
                self::date($r, ['Splatno']) ?? $issue,
                $currencyId,
                mb_substr(trim((string) ($r['Popis'] ?? '')), 0, 255) ?: null,
                'Převzato z Money S3, doklad ' . $docNo,
                json_encode([
                    'company_name' => $snapshot['name'], 'street' => $snapshot['street'], 'city' => $snapshot['city'],
                    'zip' => $snapshot['zip'], 'ic' => $snapshot['ico'], 'dic' => $snapshot['dic'],
                ], JSON_UNESCAPED_UNICODE),
                $amounts['base'],
                $amounts['vat'],
                $amounts['total'],
                $amounts['rounding'],
                $paidAt !== null ? $amounts['total'] : 0,
                $paidAt,
                $paidAt !== null ? 'paid' : 'sent',
                (self::date($r, ['DatUcPr']) ?? $issue) . ' 00:00:00',
                $ctx->userId > 0 ? $ctx->userId : null,
                $ctx->userId > 0 ? $ctx->userId : null,
            ]);
            $id = (int) $pdo->lastInsertId();
            foreach ($amounts['items'] as $i => $item) {
                $insertItem->execute([
                    $id,
                    trim((string) ($r['Popis'] ?? '')) ?: 'Převzato z Money S3',
                    $item['base'], $item['rate_id'], $item['rate'],
                    $item['base'], $item['vat'], round($item['base'] + $item['vat'], 2), $i,
                ]);
            }
            $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_INVOICE, $key, $id, $ctx->runId);
            $ctx->issuedInvoices[$key] = $id;
            $p->count(self::STEP_ISSUED, 'created');
        }
        $ctx->statsClients = array_values(array_unique(array_merge($ctx->statsClients, array_values($clients))));
        $p->finish(self::STEP_ISSUED);
    }

    /**
     * Seznam klientů (počet faktur, obrat) čte cache — převedené faktury by v něm bez
     * přepočtu chyběly. Přepočet si řídí vlastní transakce, proto běží až po zápisu
     * dokladů mimo transakci kroku; zkouška nanečisto ho vynechá (všechno se vrací).
     */
    public function recomputeClientStats(ImportContext $ctx): void
    {
        if ($ctx->statsClients === []) {
            return;
        }
        if ($this->db->pdo()->inTransaction()) {
            $ctx->protocol->info(self::STEP_ISSUED, 'stats_deferred', 'Statistiky klientů se přepočtou po dokončení transakce.');
            return;
        }
        $this->stats->recomputeMany($ctx->statsClients);
    }

    /**
     * Položky dokladu po sazbách a jeho součty. DPH se bere z Money, když ho doklad nese
     * (`DPH1`/`DPH2`), jinak se dopočte ze základu. Rozdíl proti celku z Money do 1 Kč je
     * zaokrouhlení dokladu, větší rozdíl se ohlásí (rekonciliace dokladů proti deníku
     * ho pak ukáže i v součtu).
     *
     * @param array<string,mixed> $r
     * @return array{items:list<array{base:float,rate:float,vat:float,rate_id:int}>,base:float,vat:float,total:float,rounding:float}
     */
    private function amounts(ImportContext $ctx, string $step, string $docNo, array $r, string $taxDate): array
    {
        $items = [];
        foreach ([
            ['Zaklad_0', null, null],
            ['Zaklad_1', 'SazbaDPH1', 'DPH1'],
            ['Zaklad_2', 'SazbaDPH2', 'DPH2'],
        ] as [$baseField, $rateField, $vatField]) {
            $base = round((float) ($r[$baseField] ?? 0), 2);
            if ($base === 0.0) {
                continue;
            }
            $rate = $rateField === null ? 0.0 : (float) ($r[$rateField] ?? 0);
            $vat = $vatField !== null && array_key_exists($vatField, $r)
                ? round((float) $r[$vatField], 2)
                : round($base * $rate / 100, 2);
            $items[] = ['base' => $base, 'rate' => $rate, 'vat' => $vat, 'rate_id' => $this->rateId($rate, $taxDate)];
        }
        $sumBase = round(array_sum(array_column($items, 'base')), 2);
        $sumVat = round(array_sum(array_column($items, 'vat')), 2);
        $moneyTotal = array_key_exists('CelkemSDPH', $r) ? round((float) $r['CelkemSDPH'], 2) : null;

        if ($items === [] && $moneyTotal !== null && $moneyTotal !== 0.0) {
            $items[] = ['base' => $moneyTotal, 'rate' => 0.0, 'vat' => 0.0, 'rate_id' => $this->rateId(0.0, $taxDate)];
            $sumBase = $moneyTotal;
        }
        $total = round($sumBase + $sumVat, 2);
        $rounding = 0.0;
        if ($moneyTotal !== null && abs($moneyTotal - $total) >= 0.005) {
            $diff = round($moneyTotal - $total, 2);
            if (abs($diff) <= self::ROUNDING_LIMIT) {
                $rounding = $diff;
                $total = $moneyTotal;
            } else {
                $ctx->protocol->warn($step, 'total_mismatch', sprintf(
                    'Doklad %s: součet sazeb %s nesedí na celkem %s z Money.',
                    $docNo, number_format($total, 2, ',', ' '), number_format($moneyTotal, 2, ',', ' ')
                ), ['document_no' => $docNo]);
            }
        }
        return ['items' => $items, 'base' => $sumBase, 'vat' => $sumVat, 'total' => $total, 'rounding' => $rounding];
    }

    private function rateId(float $rate, string $date): int
    {
        $cacheKey = number_format($rate, 2, '.', '') . '|' . $date;
        if (isset($this->rateCache[$cacheKey])) {
            return $this->rateCache[$cacheKey];
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM vat_rates
              WHERE country = 'CZ' AND rate_percent = ? AND is_reverse_charge = 0
              ORDER BY ((valid_from IS NULL OR valid_from <= ?) AND (valid_to IS NULL OR valid_to >= ?)) DESC,
                       is_default DESC, id
              LIMIT 1"
        );
        $stmt->execute([number_format($rate, 2, '.', ''), $date, $date]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new MoneyS3Exception('unknown_vat_rate', 'Sazba DPH ' . $rate . ' % není v číselníku sazeb.');
        }
        return $this->rateCache[$cacheKey] = (int) $id;
    }

    private function currencyId(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM currencies WHERE supplier_id = ? AND code = 'CZK' ORDER BY is_default DESC, id LIMIT 1"
        );
        $stmt->execute([$supplierId]);
        $id = (int) $stmt->fetchColumn();
        if ($id === 0) {
            $s = $this->db->pdo()->prepare('SELECT default_currency_id FROM supplier WHERE id = ?');
            $s->execute([$supplierId]);
            $id = (int) $s->fetchColumn();
        }
        return $id;
    }

    /**
     * První vyplněné datum z polí v pořadí.
     *
     * @param array<string,mixed> $r
     * @param list<string> $fields
     */
    public static function date(array $r, array $fields): ?string
    {
        foreach ($fields as $f) {
            $v = $r[$f] ?? null;
            if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1) {
                return $v;
            }
        }
        return null;
    }
}
