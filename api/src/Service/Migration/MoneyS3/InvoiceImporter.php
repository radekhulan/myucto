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
 * Money drží základ daně po sazbách (`Zaklad_0` mimo DPH, `Zaklad_1` … `Zaklad_6`),
 * sazby na dokladu (`SazbaDPH1` …) a daň (`DPH_1` …), takže doklad ze staršího roku
 * přijde se sazbou, kterou opravdu měl. Z každé sazby vznikne jedna položka. Ceny jsou
 * vždy bez DPH (`prices_include_vat = 0`) — kdyby se převzaly jako brutto, přepočítaly
 * by se znovu.
 *
 * Doklad zaúčtovaný deníkem z Money ({@see DocumentLinker}) má stav „zaúčtováno" nebo
 * „uhrazeno". **Doklad, jehož daňovou povahu z Money spolehlivě neznáme, se převezme
 * jako koncept k ruční kontrole** ({@see classify()}): zálohové a jiné než běžné
 * faktury, dobropisy, stornované a neúčtované doklady, cizí měna a členění DPH mimo
 * tuzemské řádky přiznání. Koncept do DPH evidence ani do účtování nevstoupí, dokud ho
 * účetní neopraví a nepotvrdí — hádat by znamenalo zálohu vedle konečné faktury
 * započíst do DPH dvakrát nebo přenesenou daňovou povinnost vykázat jako tuzemské plnění.
 */
final class InvoiceImporter
{
    public const STEP_PURCHASE = 'purchase_invoices';
    public const STEP_ISSUED = 'issued_invoices';

    /** Rozdíl do 1 Kč mezi součtem sazeb a celkem z Money je zaokrouhlení dokladu. */
    private const ROUNDING_LIMIT = 1.0;

    /** Sazby dokladu Money: `Zaklad_n` + `SazbaDPHn` + `DPH_n`. */
    private const RATE_SLOTS = 6;

    /** Řádky přiznání, na které Money zařazuje tuzemské přijaté plnění s nárokem na odpočet. */
    private const DOMESTIC_PURCHASE_ROWS = [40, 41];

    /** Řádky přiznání tuzemského uskutečněného plnění. */
    private const DOMESTIC_SALE_ROWS = [1, 2];

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
                 note_above_items, note_below_items, external_barcode, vat_deduction, prices_include_vat, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "import", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)'
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
                $this->reportChangedInMoney($ctx, self::STEP_PURCHASE, 'purchase_invoices', $existing[$key], $docNo, $year, $r);
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
            $number = $this->freeNumber('purchase_invoices', $ctx->supplierId, $docNo, $year);
            if ($number === null) {
                $p->error(self::STEP_PURCHASE, 'number_taken', "Číslo dokladu {$docNo} ({$year}) už ve firmě má jiný doklad, faktura nepřevzata.", ['document_no' => $docNo, 'year' => $year]);
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
            $class = self::classify($r, false, $amounts['vat']);
            $review = $class['reasons'] !== [];
            $paidAt = self::date($r, ['Uhrazeno']);
            $vendorNumber = mb_substr(trim((string) ($r['PrijatDokl'] ?? '')) ?: (trim((string) ($r['VarSymbol'] ?? '')) ?: $docNo), 0, 50);
            $duplicate->execute([$ctx->supplierId, $vendorId, $vendorNumber, $issue]);
            if ($duplicate->fetchColumn() !== false) {
                $vendorNumber = mb_substr($vendorNumber . ' (' . $docNo . ')', 0, 50);
            }
            try {
                $insert->execute([
                    $ctx->supplierId,
                    $vendorId,
                    $snapshot['dic'] !== '' ? 1 : 0,
                    $number['number'],
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
                    self::paymentMethod((string) ($r['Uhrada'] ?? '')),
                    $review ? 'draft' : ($paidAt !== null ? 'paid' : 'booked'),
                    $paidAt,
                    $review ? null : (self::date($r, ['DatUcPr']) ?? $issue) . ' 00:00:00',
                    $review || $ctx->userId <= 0 ? null : $ctx->userId,
                    mb_substr(trim((string) ($r['Popis'] ?? '')), 0, 255) ?: null,
                    self::note($docNo, $class['reasons']),
                    // Čárový kód z Money je jistý klíč pro párování naskenovaných příloh.
                    mb_substr(trim((string) ($r['BarCode'] ?? '')), 0, 64) ?: null,
                    $class['vat_deduction'],
                    $ctx->userId,
                ]);
            } catch (\PDOException $e) {
                if ((string) $e->getCode() !== '23000') {
                    throw $e;
                }
                $p->error(self::STEP_PURCHASE, 'insert_conflict', "Faktura {$docNo} ({$year}) koliduje s existujícím dokladem firmy, nepřevzata.", ['document_no' => $docNo, 'year' => $year]);
                continue;
            }
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
            $this->reportNumberAndReview($ctx, self::STEP_PURCHASE, $docNo, $year, $number, $class['reasons']);
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
                $this->reportChangedInMoney($ctx, self::STEP_ISSUED, 'invoices', $existing[$key], $docNo, $year, $r);
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
            $number = $this->freeNumber('invoices', $ctx->supplierId, $docNo, $year);
            if ($number === null) {
                $p->error(self::STEP_ISSUED, 'number_taken', "Číslo faktury {$docNo} ({$year}) už ve firmě má jiný doklad, faktura nepřevzata.", ['document_no' => $docNo, 'year' => $year]);
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
            $amounts = $this->amounts($ctx, self::STEP_ISSUED, $docNo, $r, self::date($r, ['PlnenoDPH']) ?? $issue);
            $class = self::classify($r, true, $amounts['vat']);
            $review = $class['reasons'] !== [];
            $paidAt = self::date($r, ['Uhrazeno']);
            try {
                $insert->execute([
                    $ctx->supplierId,
                    $clientId,
                    $number['number'],
                    $issue,
                    self::date($r, ['PlnenoDPH']) ?? $issue,
                    self::date($r, ['Splatno']) ?? $issue,
                    $currencyId,
                    mb_substr(trim((string) ($r['Popis'] ?? '')), 0, 255) ?: null,
                    self::note($docNo, $class['reasons']),
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
                    $review ? 'draft' : ($paidAt !== null ? 'paid' : 'sent'),
                    $review ? null : (self::date($r, ['DatUcPr']) ?? $issue) . ' 00:00:00',
                    $review || $ctx->userId <= 0 ? null : $ctx->userId,
                    $ctx->userId > 0 ? $ctx->userId : null,
                ]);
            } catch (\PDOException $e) {
                if ((string) $e->getCode() !== '23000') {
                    throw $e;
                }
                $p->error(self::STEP_ISSUED, 'insert_conflict', "Faktura {$docNo} ({$year}) koliduje s existujícím dokladem firmy, nepřevzata.", ['document_no' => $docNo, 'year' => $year]);
                continue;
            }
            $clients[$clientId] = $clientId;
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
            $this->reportNumberAndReview($ctx, self::STEP_ISSUED, $docNo, $year, $number, $class['reasons']);
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
     * Daňová povaha dokladu z Money. Vrací důvody, proč doklad NEJDE převzít jako
     * běžný tuzemský daňový doklad (prázdné = jde), a nárok na odpočet u přijatého
     * dokladu mimo přiznání.
     *
     * Jisté je jen to, co v záloze ověřitelně nese význam: druh `N` je běžná faktura,
     * členění DPH (`KodDPH`, např. `19Ř40,41`) jmenuje řádky přiznání, na které Money
     * doklad zařadilo, a řádek `00` znamená „do přiznání nezahrnovat". Ostatní druhy
     * (zálohová, proforma, daňový doklad k platbě) ani zahraniční a zvláštní řádky se
     * na MyÚčto nepřevádějí naslepo.
     *
     * @param array<string,mixed> $r
     * @return array{reasons:list<string>,vat_deduction:string}
     */
    public static function classify(array $r, bool $issued, float $vat): array
    {
        $reasons = [];
        $deduction = 'full';
        $kind = strtoupper(trim((string) ($r['Druh'] ?? '')));
        if ($kind !== '' && $kind !== 'N') {
            $reasons[] = "druh dokladu „{$kind}“ (zálohová faktura, proforma nebo daňový doklad k platbě)";
        }
        if ((int) ($r['Dobropis'] ?? 0) === 1) {
            $reasons[] = 'dobropis';
        }
        if ((int) ($r['Storno'] ?? 0) === 1) {
            $reasons[] = 'stornovaný doklad';
        }
        if ((int) ($r['Neuctovat'] ?? 0) === 1) {
            $reasons[] = 'v Money označený „neúčtovat“';
        }
        $currency = strtoupper(trim((string) ($r['Mena'] ?? '')));
        if (!in_array($currency, ['', 'CZK', 'KČ'], true)) {
            $rate = (float) ($r['Kurs'] ?? 0);
            $reasons[] = "cizí měna {$currency}" . ($rate > 0 ? ' (kurz ' . rtrim(rtrim(number_format($rate, 4, ',', ''), '0'), ',') . ')' : '')
                . ', částky převzaty v Kč';
        }

        $code = trim((string) ($r['KodDPH'] ?? ''));
        $rows = self::vatReturnRows($code);
        $domestic = $issued ? self::DOMESTIC_SALE_ROWS : self::DOMESTIC_PURCHASE_ROWS;
        if ($code === '') {
            if (abs($vat) >= 0.005) {
                $reasons[] = 'doklad s daní bez členění DPH';
            }
        } elseif ($rows === null) {
            $reasons[] = "neznámé členění DPH „{$code}“";
        } elseif ($rows === [0]) {
            if (abs($vat) >= 0.005) {
                if ($issued) {
                    $reasons[] = "členění DPH „{$code}“ mimo přiznání u dokladu s daní";
                } else {
                    // Přijatý doklad mimo přiznání: daň je součástí nákladu, odpočet se neuplatnil.
                    $deduction = 'none';
                }
            }
        } elseif (array_diff($rows, $domestic) !== []) {
            $reasons[] = "členění DPH „{$code}“ (zahraniční plnění, přenesená daňová povinnost nebo zvláštní režim)";
        }
        return ['reasons' => $reasons, 'vat_deduction' => $deduction];
    }

    /**
     * Řádky přiznání z členění DPH Money (`19Ř40,41` → [40, 41], `19Ř00P` → [0]).
     *
     * @return list<int>|null null = kód neodpovídá tvaru „RRŘřádky"
     */
    private static function vatReturnRows(string $code): ?array
    {
        if (preg_match('/^\d{2}Ř\s*([0-9][0-9 ,]*?)\s*[A-Z]?$/u', $code, $m) !== 1) {
            return null;
        }
        $rows = array_map('intval', preg_split('/[\s,]+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        return $rows === [] ? null : array_values(array_unique($rows));
    }

    /** Způsob úhrady z Money (volný text `Uhrada`, viz {@see \MyInvoice\Service\Export\MoneyS3XmlExporter}). */
    public static function paymentMethod(string $label): string
    {
        $l = mb_strtolower(trim($label));
        return match (true) {
            $l === '' => 'bank_transfer',
            str_contains($l, 'kart') => 'card',
            str_contains($l, 'hotov') => 'cash',
            str_contains($l, 'dobír') || str_contains($l, 'dobir') => 'cash_on_delivery',
            str_contains($l, 'inkas') => 'direct_debit',
            str_contains($l, 'zápoč') || str_contains($l, 'zapoc') => 'offset',
            str_contains($l, 'převod') || str_contains($l, 'prevod') || str_contains($l, 'příkaz') || str_contains($l, 'prikaz') => 'bank_transfer',
            default => 'other',
        };
    }

    /**
     * Číslo dokladu, které ve firmě ještě není. Money čísluje řady každý rok od začátku,
     * takže FP001 z roku 2025 narazí na FP001 z roku 2024 — dostane příponu roku, stejně
     * jako pokladní doklady ({@see CashBankImporter}).
     *
     * @return array{number:string,suffixed:bool}|null
     */
    private function freeNumber(string $table, int $supplierId, string $docNo, int $year): ?array
    {
        $stmt = $this->db->pdo()->prepare("SELECT 1 FROM {$table} WHERE supplier_id = ? AND varsymbol = ? LIMIT 1");
        $suffix = '/' . $year;
        foreach ([[mb_substr($docNo, 0, 20), false], [mb_substr($docNo, 0, 20 - mb_strlen($suffix)) . $suffix, true]] as [$candidate, $suffixed]) {
            $stmt->execute([$supplierId, $candidate]);
            if ($stmt->fetchColumn() === false) {
                return ['number' => $candidate, 'suffixed' => $suffixed];
            }
        }
        return null;
    }

    /**
     * @param array{number:string,suffixed:bool} $number
     * @param list<string> $reasons
     */
    private function reportNumberAndReview(ImportContext $ctx, string $step, string $docNo, int $year, array $number, array $reasons): void
    {
        $p = $ctx->protocol;
        if ($number['suffixed']) {
            $p->count($step, 'suffixed');
            $p->info($step, 'number_suffixed', "Doklad {$docNo} ({$year}): číslo už ve firmě je, převzat jako {$number['number']}.", ['document_no' => $docNo, 'year' => $year]);
        }
        if ($reasons !== []) {
            $p->count($step, 'review');
            $p->warn($step, 'needs_review', "Doklad {$docNo} ({$year}) převzat jako koncept k ruční kontrole: " . implode('; ', $reasons)
                . '. Do DPH ani do účtování nevstoupí, dokud ho neopravíte a nepotvrdíte.', ['document_no' => $docNo, 'year' => $year, 'reasons' => $reasons]);
        }
    }

    /**
     * Opakovaný převod (novější zálohy) už převedený doklad NEPŘEPISUJE — mohl být mezitím
     * zaúčtovaný, spárovaný nebo upravený v MyÚčtu. Liší-li se ale v Money, protokol to
     * řekne, ať ho účetní upraví ručně.
     *
     * @param array<string,mixed> $r
     */
    private function reportChangedInMoney(ImportContext $ctx, string $step, string $table, int $id, string $docNo, int $year, array $r): void
    {
        if (!array_key_exists('CelkemSDPH', $r)) {
            return;
        }
        $stmt = $this->db->pdo()->prepare("SELECT total_with_vat FROM {$table} WHERE id = ? AND supplier_id = ?");
        $stmt->execute([$id, $ctx->supplierId]);
        $stored = $stmt->fetchColumn();
        $money = round((float) $r['CelkemSDPH'], 2);
        if ($stored === false || abs((float) $stored - $money) < 0.005) {
            return;
        }
        $ctx->protocol->count($step, 'changed');
        $ctx->protocol->warn($step, 'changed_in_money', sprintf(
            'Doklad %s (%d) se v Money od převodu změnil (celkem %s → %s). V MyÚčtu zůstává beze změny, upravte ho ručně.',
            $docNo, $year, number_format((float) $stored, 2, ',', ' '), number_format($money, 2, ',', ' ')
        ), ['document_no' => $docNo, 'year' => $year, 'id' => $id]);
    }

    /** @param list<string> $reasons */
    private static function note(string $docNo, array $reasons): string
    {
        $note = 'Převzato z Money S3, doklad ' . $docNo;
        return $reasons === [] ? $note : $note . '. K ruční kontrole: ' . implode('; ', $reasons) . '.';
    }

    /**
     * Položky dokladu po sazbách a jeho součty. DPH se bere z Money (`DPH_1` …), když ho
     * doklad nese, jinak se dopočte ze základu. Rozdíl proti celku z Money do 1 Kč je
     * zaokrouhlení dokladu, větší rozdíl se ohlásí (rekonciliace dokladů proti deníku
     * ho pak ukáže i v součtu).
     *
     * @param array<string,mixed> $r
     * @return array{items:list<array{base:float,rate:float,vat:float,rate_id:int}>,base:float,vat:float,total:float,rounding:float}
     */
    private function amounts(ImportContext $ctx, string $step, string $docNo, array $r, string $taxDate): array
    {
        $slots = [['Zaklad_0', null, []]];
        for ($i = 1; $i <= self::RATE_SLOTS; $i++) {
            $slots[] = ['Zaklad_' . $i, 'SazbaDPH' . $i, ['DPH_' . $i, 'DPH' . $i]];
        }
        $items = [];
        foreach ($slots as [$baseField, $rateField, $vatFields]) {
            $base = round((float) ($r[$baseField] ?? 0), 2);
            if ($base === 0.0) {
                continue;
            }
            $rate = $rateField === null ? 0.0 : (float) ($r[$rateField] ?? 0);
            $vat = null;
            foreach ($vatFields as $vatField) {
                if (array_key_exists($vatField, $r)) {
                    $vat = round((float) $r[$vatField], 2);
                    break;
                }
            }
            $vat ??= round($base * $rate / 100, 2);
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
