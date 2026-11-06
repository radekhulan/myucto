<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PremierImportRepository;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;
use MyInvoice\Service\Migration\OssMigrationPolicy;
use MyInvoice\Service\Migration\Pohoda\PartnerImporter as PohodaPartners;
use MyInvoice\Service\Migration\Shared\ForeignCurrencyDecision;
use MyInvoice\Service\Migration\Shared\ForeignCurrencyTakeover;
use MyInvoice\Service\Migration\Shared\MigratedDocumentItem;
use MyInvoice\Service\Migration\Shared\MigratedDocumentWriter;
use MyInvoice\Service\Migration\Shared\MigratedIssuedDocument;
use MyInvoice\Service\Migration\Shared\MigratedPurchaseDocument;
use MyInvoice\Service\Migration\Shared\MigrationHomeCurrency;
use MyInvoice\Service\Migration\Shared\MigrationVatRateLookup;
use MyInvoice\Service\Migration\Shared\VatReturnLineClassifier;
use MyInvoice\Service\Stats\StatsRecomputer;

/**
 * Vydané a přijaté faktury z PREMIER ({@see PremierDocuments}).
 *
 * | PREMIER                               | MyÚčto                                      |
 * |---------------------------------------|---------------------------------------------|
 * | vydaná faktura (`FA_OUT`)             | `invoices` faktura, záporná = dobropis      |
 * | vydaný zálohový list (řada typu 11)   | `invoices` proforma (neúčtuje se)           |
 * | přijatá faktura (`FA_IN`)             | `purchase_invoices` faktura / dobropis      |
 * | přijatý zálohový list (řada typu 10)  | `purchase_invoices` záloha (neúčtuje se)    |
 *
 * **Zařazení DPH je po položkách** podle kódu DPH položky a jeho definice v číselníku
 * PREMIER ({@see PremierVat}). U samovyměření (pořízení z EU, služby ze zahraničí, dovoz,
 * tuzemský přenos) má položka daň 0 a kód zařazení - daň na výstupu i odpočet dopočte
 * evidence DPH ze základu a sazby, takže nezáleží na tom, jestli ji účetní v PREMIER
 * zaúčtovala na 343. Vydaná položka s kódem mimo přiznání, která nese daň, je režim OSS
 * a rozhodne o ní {@see OssMigrationPolicy} (táž autorita jako u ostatních kanálů).
 *
 * Doklad, jehož daňovou povahu převod spolehlivě nezná (kód jiných řádků přiznání, daň
 * u plnění bez daně…), se převezme jako koncept k ruční kontrole a do DPH nevstoupí,
 * dokud ho uživatel nepotvrdí. Faktura minulého roku neuhrazená k začátku roku se
 * převezme kvůli saldu a párování úhrad (zápis má v deníku svého roku).
 */
final class InvoiceImporter
{
    public const STEP_ISSUED = 'issued_invoices';
    public const STEP_PURCHASE = 'purchase_invoices';

    /** Limit KH pro jednotlivý doklad (A.4/B.2), vč. DPH. */
    private const KH_LIMIT = 10000.0;

    private const PAYMENT_FORMS = [
        'převod' => 'bank_transfer', 'prevod' => 'bank_transfer', 'příkaz' => 'bank_transfer',
        'hotov' => 'cash', 'pokladn' => 'cash', 'kart' => 'card', 'zápoč' => 'offset', 'zapoc' => 'offset',
        'dobír' => 'cash_on_delivery', 'dobir' => 'cash_on_delivery', 'inkas' => 'direct_debit', 'záloh' => 'other',
    ];

    private readonly MigrationVatRateLookup $rates;

    private readonly ForeignCurrencyTakeover $foreignCurrency;

    /** @var array<string,\PDOStatement> */
    private array $stmts = [];

    public function __construct(
        private readonly Connection $db,
        private readonly PremierImportRepository $map,
        private readonly PartnerImporter $partners,
        private readonly StatsRecomputer $stats,
        private readonly OssMigrationPolicy $oss,
        private readonly MigratedDocumentWriter $writer,
        private readonly MigrationHomeCurrency $homeCurrency,
    ) {
        $this->rates = new MigrationVatRateLookup($db);
        $this->foreignCurrency = new ForeignCurrencyTakeover($db);
    }

    public function importIssued(PremierContext $ctx, PremierDocuments $documents): void
    {
        $existing = $this->map->all($ctx->supplierId, PremierImportRepository::KIND_INVOICE);
        foreach ($documents->forYear(PremierDocuments::ISSUED, $ctx->year) as $doc) {
            $this->importIssuedDocument($ctx, $doc, $existing);
        }
        $ctx->protocol->finish(self::STEP_ISSUED);
    }

    public function importPurchases(PremierContext $ctx, PremierDocuments $documents): void
    {
        $existing = $this->map->all($ctx->supplierId, PremierImportRepository::KIND_PURCHASE_INVOICE);
        foreach ($documents->forYear(PremierDocuments::PURCHASE, $ctx->year) as $doc) {
            $this->importPurchaseDocument($ctx, $doc, $existing);
        }
        $ctx->protocol->finish(self::STEP_PURCHASE);
    }

    /**
     * Doklad s DPH složený z deníku (banka, interní doklad, pokladna se samovyměřením -
     * {@see VatDocumentImporter}): zapíše se stejnou cestou jako faktura, jen s vlastním
     * klíčem v mapě převodu a druhem dokladu.
     *
     * @param array<string,mixed> $doc tvar {@see PremierDocuments::build()} + `map_key`, `forced_type`, `numbers`
     * @return int|null id dokladu (`invoices` / `purchase_invoices`), null = nepřevzat
     */
    public function importJournalDocument(PremierContext $ctx, array $doc, string $step): ?int
    {
        $existing = $this->map->all($ctx->supplierId, PremierImportRepository::KIND_VAT_DOCUMENT);
        return $doc['direction'] === PremierDocuments::ISSUED
            ? $this->importIssuedDocument($ctx, $doc, $existing, $step)
            : $this->importPurchaseDocument($ctx, $doc, $existing, $step);
    }

    /**
     * Seznam klientů čte cache statistik - převedené faktury by v něm bez přepočtu chyběly.
     * Běží mimo transakci kroku, zkouška nanečisto ho vynechá.
     */
    public function recomputeClientStats(PremierContext $ctx): void
    {
        if ($ctx->statsClients === [] || $this->db->pdo()->inTransaction()) {
            return;
        }
        $this->stats->recomputeMany(array_values(array_unique($ctx->statsClients)));
    }

    /**
     * @param array<string,mixed> $doc
     * @param array<string,int> $existing
     */
    private function importIssuedDocument(PremierContext $ctx, array $doc, array $existing, string $step = self::STEP_ISSUED): ?int
    {
        $p = $ctx->protocol;
        $key = $doc['map_key'] ?? ($doc['series'] . '|' . $doc['inter']);
        $label = $doc['label'] ?? ($doc['series'] . ' ' . $doc['number']);
        if (isset($existing[$key])) {
            if (!isset($doc['map_key'])) {
                $ctx->issuedInvoices[$doc['inter']] = $existing[$key];
                $ctx->issuedPaymentRows[$existing[$key]] = $doc['payment_rows'];
            }
            if ($doc['previous']) {
                $ctx->previousPeriod['invoice|' . $existing[$key]] = true;
            }
            $p->count($step, 'existing');
            $this->reportChanged($ctx, $step, 'invoices', $existing[$key], $label, $doc['total']);
            return $existing[$key];
        }
        $reasons = $doc['reasons'];
        $type = $doc['forced_type'] ?? ($doc['kind'] === 'advance' ? 'proforma' : (($doc['total'] < 0 || ($doc['storno'] && $doc['total'] <= 0)) ? 'credit_note' : 'invoice'));
        $snapshot = $this->partners->documentSnapshot($doc['header']);
        $clientId = $this->partners->resolvePartner($ctx, $snapshot);
        $taxDate = $doc['vat_date'] ?? $doc['supply'];
        $items = $doc['items'];
        $notes = [];
        $ossItems = 0;
        $ossClient = null;
        $forcedSummary = false;
        foreach ($items as $i => $item) {
            $items[$i]['oss'] = OssMigrationPolicy::DOMESTIC_COLUMNS;
            $items[$i]['rate_id'] = null;
            $items[$i]['target_code'] = null;
            if ($type === 'proforma') {
                continue;
            }
            $code = (string) $item['code'];
            $hasVat = abs((float) $item['vat']) >= 0.005;
            if ($code === '') {
                if ($hasVat) {
                    $reasons[] = 'položka s daní bez kódu DPH';
                }
                continue;
            }
            $class = $ctx->vat->sale($code);
            if ($class === null) {
                $reasons[] = "kód DPH „{$code}“ ({$ctx->vat->name($code)}) převod nezařazuje";
                continue;
            }
            if ($ctx->vat->forcesSummaryKh($code)) {
                $forcedSummary = true;
            }
            if (!$class['in_return']) {
                if (!$hasVat) {
                    continue; // plnění mimo přiznání bez daně (nepodléhá DPH)
                }
                if ($ossClient === null) {
                    $warning = $this->oss->runWarning($ctx->supplierId);
                    if ($warning !== null) {
                        $p->warn($step, 'oss_setup', $warning);
                    }
                    $ossClient = $this->oss->clientContext($clientId, $snapshot['country'], $snapshot['dic']);
                }
                $plan = $this->oss->planItem($ctx->supplierId, $ossClient, (float) $item['rate'], $item['unit'], $taxDate, $code);
                if ($plan['rate_id'] !== null) {
                    $items[$i]['rate_id'] = $plan['rate_id'];
                    $items[$i]['rate'] = $plan['rate_percent'];
                    // Doklad v EUR: do OSS podání jdou eura z položky, ne koruny přepočtené
                    // zpátky kurzem ECB konce čtvrtletí (jako POHODA). Tuzemská evidence zůstává v Kč.
                    $items[$i]['oss'] = $plan['columns']
                        + $this->oss->returnAmounts($ctx->supplierId, $doc['currency'], $item['foreign_base'] ?? null, $item['foreign_vat'] ?? null);
                    $ossItems++;
                    foreach ($plan['warnings'] as $w) {
                        $p->warn($step, 'oss_item_warning', "Doklad {$label} (režim OSS): {$w}", ['document_no' => $label]);
                    }
                } elseif ($plan['reason'] !== null) {
                    $reasons[] = $plan['reason'];
                }
                continue;
            }
            if ($class['code'] !== null) {
                if ($hasVat) {
                    $reasons[] = "kód DPH „{$code}“ (plnění bez daně) u položky s daní";
                    continue;
                }
                $items[$i]['target_code'] = $class['code'];
                continue;
            }
            $reduced = $this->isReduced($ctx->vat, $code, (float) $item['rate']);
            $items[$i]['target_code'] = ($reduced ? '2' : '1') . ($class['asset_sale'] ? 'm' : '');
        }
        $reasons = array_values(array_unique($reasons));
        if ($ossItems > 0) {
            $p->count($step, 'oss_items', $ossItems);
        }
        if ($forcedSummary && $snapshot['dic'] !== '' && abs($doc['total']) > self::KH_LIMIT) {
            // PREMIER vykázal doklad v KH souhrnně (A.5) - MyÚčto rozhoduje podle DIČ ve
            // snapshotu dokladu, proto se tam nepřebírá. Na kartě klienta zůstává.
            $notes[] = 'v KH vykázáno souhrnně (A.5), DIČ ' . $snapshot['dic'] . ' ve snapshotu dokladu vynecháno';
            $snapshot['dic'] = '';
            $p->count($step, 'kh_a5_forced');
        }
        if (!$this->rateItems($ctx, $step, $label, $items, $taxDate)) {
            return null;
        }
        $number = $this->freeNumber('invoices', $ctx->supplierId, $doc['numbers'] ?? [$doc['number']], (int) substr($doc['issue'], 0, 4));
        if ($number === null) {
            $p->warn($step, 'number_taken', "Číslo dokladu {$label} už ve firmě má jiný doklad, nepřevzat.", ['document_no' => $label]);
            return null;
        }
        $review = $reasons !== [];
        $status = $review ? 'draft' : ($doc['settled'] ? 'paid' : 'sent');
        $booked = !$review && $type !== 'proforma' && $doc['booked'];
        $codes = array_values(array_unique(array_filter(array_column($items, 'target_code'))));
        $vs = preg_replace('/\D/', '', $doc['variable_symbol']) ?? '';
        $reverse = VatReturnLineClassifier::isDomesticReverseSale($codes);
        [$fx, $items, $amounts] = $this->takeOverCurrency($ctx, $doc, $items, ForeignCurrencyTakeover::paymentBlock((float) ($doc['advance_deducted'] ?? 0.0), (float) $doc['paid'], (float) $doc['total'] - (float) ($doc['advance_deducted'] ?? 0.0)));
        try {
            $id = $this->writer->insertIssued(new MigratedIssuedDocument(
                supplierId: $ctx->supplierId,
                invoiceType: $type,
                clientId: $clientId,
                varsymbol: $number['number'],
                issueDate: $doc['issue'],
                taxDate: $type === 'proforma' ? null : $taxDate,
                dueDate: $doc['due'],
                currencyId: $fx->currencyId ?? $this->homeCurrency->id($ctx->supplierId),
                exchangeRate: $fx->rate,
                // Položky jsou základy po kódech DPH - bez DPH.
                pricesIncludeVat: false,
                reverseCharge: $reverse,
                noteAboveItems: $doc['text'] !== '' ? mb_substr($doc['text'], 0, 1000) : null,
                noteBelowItems: self::note($label, $reasons, $doc, $fx, $notes),
                clientSnapshot: PohodaPartners::snapshotJson($snapshot),
                totalWithoutVat: $amounts['base'],
                totalVat: $amounts['vat'],
                totalWithVat: $amounts['total'],
                rounding: $amounts['rounding'],
                status: $status,
                createdBy: $ctx->userOrNull(),
                paymentVariableSymbol: $vs !== '' && strlen(ltrim($vs, '0')) <= VariableSymbolNormalizer::MAX_LENGTH && $vs !== VariableSymbolNormalizer::forPayment((string) $number['number']) ? $vs : null,
                advancePaidAmount: $fx->inForeignCurrency() ? 0.0 : (float) ($doc['advance_deducted'] ?? 0.0),
                paidTotal: $amounts['paid'],
                paidAt: $doc['settled'] ? ($doc['paid_at'] ?? $doc['issue']) : null,
                bookedAt: $booked ? $doc['accounting'] . ' 00:00:00' : null,
                bookedBy: $booked ? $ctx->userOrNull() : null,
                vatClassificationCode: count($codes) === 1 ? $codes[0] : null,
                paymentMethod: self::paymentMethod($doc['payment_form']),
            ));
        } catch (\PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            $p->warn($step, 'insert_conflict', "Doklad {$label} koliduje s existujícím dokladem firmy (stejné číslo nebo variabilní symbol), nepřevzat.", ['document_no' => $label]);
            return null;
        }
        $rows = [];
        foreach ($items as $i => $item) {
            $rows[$i] = MigratedDocumentItem::issued(
                $item['description'], $item['quantity'], $item['unit'] ?? 'ks', $item['unit_price'],
                $item['rate_id'], $item['rate'], $item['base'], $item['vat'], round($item['base'] + $item['vat'], 2),
                $item['target_code'],
                $item['oss'],
            );
        }
        $this->writer->insertIssuedItems($id, $rows);
        ForeignCurrencyTakeover::report($p, $step, $label, $fx);
        $this->map->put($ctx->supplierId, isset($doc['map_key']) ? PremierImportRepository::KIND_VAT_DOCUMENT : PremierImportRepository::KIND_INVOICE, $key, $id, $ctx->runId);
        if (!isset($doc['map_key'])) {
            $ctx->issuedInvoices[$doc['inter']] = $id;
            $ctx->issuedPaymentRows[$id] = $doc['payment_rows'];
        }
        $ctx->statsClients[] = $clientId;
        if ($doc['previous']) {
            $ctx->previousPeriod['invoice|' . $id] = true;
            $p->count($step, 'previous_period');
        }
        $p->count($step, 'created');
        $p->count($step, 'items_' . $doc['items_source'], count($items));
        $this->reportNumberAndReview($ctx, $step, $label, $number, $reasons);
        return $id;
    }

    /**
     * @param array<string,mixed> $doc
     * @param array<string,int> $existing
     */
    private function importPurchaseDocument(PremierContext $ctx, array $doc, array $existing, string $step = self::STEP_PURCHASE): ?int
    {
        $p = $ctx->protocol;
        $key = $doc['map_key'] ?? ($doc['series'] . '|' . $doc['inter']);
        $label = $doc['label'] ?? ($doc['series'] . ' ' . $doc['number'] . '/' . $doc['year']);
        if (isset($existing[$key])) {
            if (!isset($doc['map_key'])) {
                $ctx->purchaseInvoices[$doc['inter']] = $existing[$key];
            }
            if ($doc['previous']) {
                $ctx->previousPeriod['purchase_invoice|' . $existing[$key]] = true;
            }
            $p->count($step, 'existing');
            $this->reportChanged($ctx, $step, 'purchase_invoices', $existing[$key], $label, $doc['total']);
            return $existing[$key];
        }
        $reasons = $doc['reasons'];
        $type = $doc['forced_type'] ?? ($doc['kind'] === 'advance' ? 'advance' : (($doc['total'] < 0 || ($doc['storno'] && $doc['total'] <= 0)) ? 'credit_note' : 'invoice'));
        $items = $doc['items'];
        $deductions = [];
        $reverse = false;
        $outside = [];
        foreach ($items as $i => $item) {
            $items[$i]['target_code'] = null;
            $items[$i]['fixed_asset'] = false;
            if ($type === 'advance') {
                continue;
            }
            $code = (string) $item['code'];
            $hasVat = abs((float) $item['vat']) >= 0.005;
            if ($code === '') {
                if ($hasVat) {
                    $reasons[] = 'položka s daní bez kódu DPH';
                } else {
                    $outside[] = $i;
                }
                continue;
            }
            $class = $ctx->vat->purchase($code);
            if ($class === null) {
                $reasons[] = "kód DPH „{$code}“ ({$ctx->vat->name($code)}) převod nezařazuje";
                continue;
            }
            if (!$class['in_return']) {
                if ($hasVat) {
                    $deductions['none'] = true; // daň je součástí nákladu, odpočet se neuplatnil
                } else {
                    $outside[] = $i;
                }
                continue;
            }
            if ($class['reverse']) {
                if ($hasVat) {
                    $reasons[] = "kód DPH „{$code}“ (samovyměření) u položky s daní od dodavatele";
                    continue;
                }
                $reverse = true;
                $items[$i]['target_code'] = $class['code'];
                $deductions[$class['deduction']] = true;
            } else {
                $items[$i]['target_code'] = $this->isReduced($ctx->vat, $code, (float) $item['rate']) ? '41' : '40';
                $deductions[$class['deduction']] = true;
            }
            if ($class['fixed_asset']) {
                $items[$i]['fixed_asset'] = true;
            }
        }
        if ($reverse) {
            // Položka bez daně, kterou PREMIER do přiznání nezahrnul (bez kódu, kód bez řádků),
            // potřebuje na dokladu se samovyměřením kód mimo předmět daně - bez kódu by ji
            // evidence DPH podle příznaku `reverse_charge` zdanila jako samovyměření.
            foreach ($outside as $i) {
                $items[$i]['target_code'] = VatReturnLineClassifier::PURCHASE_OUTSIDE_SCOPE_CODE;
            }
        }
        if (count($deductions) > 1) {
            $reasons[] = 'doklad kombinuje položky s nárokem a bez nároku na odpočet (' . implode(', ', array_keys($deductions)) . ')';
        }
        $deduction = count($deductions) === 1 ? (string) array_key_first($deductions) : 'full';
        $reasons = array_values(array_unique($reasons));

        // Období odpočtu: PREMIER uplatňuje DPH k datu pro KH / DPH, jinak k datu zápisu.
        $vatDate = $doc['kh_date'] ?? $doc['vat_date'] ?? $doc['accounting'];
        $taxDate = $reverse && ($doc['kh_date'] !== null || $doc['vat_date'] !== null) ? $vatDate : $doc['supply'];
        $default = max($taxDate, $doc['issue']);
        $claim = null;
        if (!$reverse && $vatDate > $default) {
            $claim = $vatDate;
        } elseif (!$reverse && $vatDate < $default && substr($vatDate, 0, 7) !== substr($default, 0, 7) && $type !== 'advance') {
            $p->warn($step, 'claim_before_document', sprintf(
                'Doklad %s: PREMIER uplatnil odpočet k %s, dřív než je datum plnění nebo vystavení (%s). MyÚčto odpočet před držením dokladu nepřipustí - doklad je v DPH v období %s.',
                $label, $vatDate, $default, substr($default, 0, 7)
            ), ['document_no' => $label]);
            $p->count($step, 'claim_moved');
        }

        if (!$this->rateItems($ctx, $step, $label, $items, $taxDate)) {
            return null;
        }
        $snapshot = $this->partners->documentSnapshot($doc['header']);
        $vendorId = $this->partners->resolvePartner($ctx, $snapshot);
        $number = $this->freeNumber('purchase_invoices', $ctx->supplierId, $doc['numbers'] ?? [$doc['series'] . $doc['number'] . '/' . $doc['year']], $doc['year']);
        if ($number === null) {
            $p->warn($step, 'number_taken', "Číslo dokladu {$label} už ve firmě má jiný doklad, nepřevzat.", ['document_no' => $label]);
            return null;
        }
        $vendorNumber = mb_substr($doc['vendor_number'] !== '' ? $doc['vendor_number'] : $number['number'], 0, 50);
        $duplicate = $this->stmt('purchase_duplicate', 'SELECT 1 FROM purchase_invoices WHERE supplier_id = ? AND vendor_id = ? AND vendor_invoice_number = ? AND issue_date = ? LIMIT 1');
        $duplicate->execute([$ctx->supplierId, $vendorId, $vendorNumber, $doc['issue']]);
        if ($duplicate->fetchColumn() !== false) {
            $vendorNumber = mb_substr($vendorNumber . ' (' . $label . ')', 0, 50);
        }
        $review = $reasons !== [];
        $unbooked = $review || $type === 'advance' || !$doc['booked'];
        $status = $review ? 'draft' : ($doc['settled'] ? 'paid' : ($unbooked ? 'received' : 'booked'));
        // Kód hlavičky jen ze zařazení do přiznání - položka mimo předmět daně ho neurčuje.
        $codes = array_values(array_unique(array_filter(array_column($items, 'target_code'),
            static fn (?string $c): bool => $c !== null && $c !== VatReturnLineClassifier::PURCHASE_OUTSIDE_SCOPE_CODE)));
        [$accountNo, $bankCode] = self::bankAccount($doc['account_no']);
        $vs = preg_replace('/\D/', '', $doc['variable_symbol']) ?? '';
        // Přijatý doklad úhradu v částce nevede (jen stav), částečná úhrada ho proto neblokuje.
        [$fx, $items, $amounts] = $this->takeOverCurrency($ctx, $doc, $items, abs((float) ($doc['advance_deducted'] ?? 0.0)) >= 0.005 ? ForeignCurrencyTakeover::paymentBlock((float) $doc['advance_deducted'], 0.0, 0.0) : ForeignCurrencyTakeover::purchaseBlock($reverse, $deduction));
        try {
            $id = $this->writer->insertPurchase(new MigratedPurchaseDocument(
                supplierId: $ctx->supplierId,
                vendorId: $vendorId,
                vendorIsVatPayer: PohodaPartners::vatId($snapshot['dic']) !== '',
                varsymbol: $number['number'],
                vendorInvoiceNumber: $vendorNumber,
                documentKind: $type,
                issueDate: $doc['issue'],
                taxDate: $taxDate,
                dueDate: $doc['due'],
                receivedAt: $claim ?? $taxDate,
                receivedAtSource: $claim !== null ? 'manual' : 'import',
                currencyId: $fx->currencyId ?? $this->homeCurrency->id($ctx->supplierId),
                exchangeRate: $fx->rate,
                // Položky jsou základy po kódech DPH - bez DPH.
                pricesIncludeVat: false,
                reverseCharge: $reverse,
                vendorSnapshot: PohodaPartners::snapshotJson($snapshot),
                totalWithoutVat: $amounts['base'],
                totalVat: $amounts['vat'],
                totalWithVat: $amounts['total'],
                rounding: $amounts['rounding'],
                status: $status,
                vatDeduction: $deduction,
                noteAboveItems: $doc['text'] !== '' ? mb_substr($doc['text'], 0, 1000) : null,
                noteBelowItems: self::note($label, $reasons, $doc, $fx),
                createdBy: $ctx->userId,
                advancePaidAmount: $fx->inForeignCurrency() ? 0.0 : (float) ($doc['advance_deducted'] ?? 0.0),
                paymentVariableSymbol: $vs !== '' && strlen(ltrim($vs, '0')) <= VariableSymbolNormalizer::MAX_LENGTH && ltrim($vs, '0') !== '' ? $vs : null,
                paymentConstantSymbol: preg_match('/^\d{1,4}$/', $doc['constant_symbol']) === 1 ? $doc['constant_symbol'] : null,
                paymentAccountNumber: $accountNo,
                paymentBankCode: $bankCode,
                paymentMethod: self::paymentMethod($doc['payment_form']),
                paidAt: $doc['settled'] ? ($doc['paid_at'] ?? $doc['issue']) : null,
                bookedAt: $unbooked ? null : $doc['accounting'] . ' 00:00:00',
                bookedBy: $unbooked ? null : $ctx->userOrNull(),
                vatClassificationCode: count($codes) === 1 ? $codes[0] : null,
                isFixedAsset: MigratedDocumentItem::wholeDocumentFixedAsset(array_column($items, 'fixed_asset')),
            ));
        } catch (\PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            $p->warn($step, 'insert_conflict', "Doklad {$label} koliduje s existujícím dokladem firmy (stejné číslo nebo variabilní symbol), nepřevzat.", ['document_no' => $label]);
            return null;
        }
        $rows = [];
        foreach ($items as $i => $item) {
            // Drobný majetek odvozený z účtu položky, když PREMIER evidenci nevede ({@see PremierSmallAssets}).
            // Práh je v Kč - u dokladu v cizí měně rozhoduje korunová cena ze zdroje.
            $qty = (float) $item['quantity'];
            $homeBase = (float) ($item['home_base'] ?? $item['base']);
            $expenseKind = $ctx->smallAssets?->expenseKind((string) ($item['account'] ?? ''), $qty != 0.0 ? $homeBase / $qty : $homeBase);
            if ($expenseKind !== null && $expenseKind !== 'material') {
                $p->count($step, 'small_asset_items');
            }
            $rows[$i] = MigratedDocumentItem::purchase(
                $item['description'], $item['quantity'], $item['unit'] ?? 'ks', $item['unit_price'],
                $item['rate_id'], $item['rate'], $item['base'], $item['vat'], round($item['base'] + $item['vat'], 2),
                $item['target_code'], $item['fixed_asset'], $expenseKind,
            );
        }
        $this->writer->insertPurchaseItems($id, $rows);
        ForeignCurrencyTakeover::report($p, $step, $label, $fx);
        $this->map->put($ctx->supplierId, isset($doc['map_key']) ? PremierImportRepository::KIND_VAT_DOCUMENT : PremierImportRepository::KIND_PURCHASE_INVOICE, $key, $id, $ctx->runId);
        if (!isset($doc['map_key'])) {
            $ctx->purchaseInvoices[$doc['inter']] = $id;
        }
        if ($doc['previous']) {
            $ctx->previousPeriod['purchase_invoice|' . $id] = true;
            $p->count($step, 'previous_period');
        }
        if ($reverse) {
            $p->count($step, 'self_assessed');
        }
        if ($claim !== null) {
            $p->count($step, 'claim_date_from_premier');
        }
        $p->count($step, 'created');
        $p->count($step, 'items_' . $doc['items_source'], count($items));
        $this->reportNumberAndReview($ctx, $step, $label, $number, $reasons);
        return $id;
    }

    /**
     * Doklad v cizí měně (`MENA`, `KURS` za `M_KURS`) v jeho měně, když částky položek
     * v měně kurzem dají přesně Kč ze zaúčtování ({@see ForeignCurrencyTakeover}). Položka
     * složená z deníku ani položka s haléřovým dorovnáním kurzu na deník tomu nevyhoví
     * a doklad zůstane v Kč.
     *
     * @param array<string,mixed> $doc
     * @param list<array<string,mixed>> $items
     * @return array{0:ForeignCurrencyDecision,1:list<array<string,mixed>>,2:array{base:float,vat:float,total:float,rounding:float,paid:float}}
     */
    private function takeOverCurrency(PremierContext $ctx, array $doc, array $items, ?string $blocked): array
    {
        $rate = (float) $doc['factor'] !== 1.0 ? (float) $doc['factor'] : 0.0;
        $fx = $this->foreignCurrency->decide($ctx->supplierId, (string) $doc['currency'], $rate, 1.0, $items, (float) $doc['total'], $blocked);
        $amounts = ['base' => (float) $doc['base'], 'vat' => (float) $doc['vat'], 'total' => (float) $doc['total'], 'rounding' => (float) $doc['rounding'], 'paid' => (float) $doc['paid']];
        if ($fx->inForeignCurrency()) {
            $items = ForeignCurrencyTakeover::foreignItems($items);
            $totals = ForeignCurrencyTakeover::totals($items);
            $amounts = $totals + ['rounding' => 0.0, 'paid' => ForeignCurrencyTakeover::paidInForeignCurrency((float) $doc['paid'], $totals['total'])];
        }
        return [$fx, $items, $amounts];
    }

    /** Snížená sazba? Podle třídy sazby kódu, u jiné (historické) sazby podle její výše. */
    private function isReduced(PremierVat $vat, string $code, float $rate): bool
    {
        $class = $vat->rateClass($code);
        if ($class !== null && $vat->otherRate($code) <= 0.0) {
            return $class === PremierVat::RATE_REDUCED;
        }
        return $rate > 0.0 && $rate < 19.0;
    }

    /**
     * Tuzemská sazba položek (OSS řádky už ji mají ze státu spotřeby). `false` = doklad
     * nejde zapsat, protože sazba v číselníku firmy chybí.
     *
     * @param list<array<string,mixed>> $items MĚNÍ SE
     */
    private function rateItems(PremierContext $ctx, string $step, string $label, array &$items, string $taxDate): bool
    {
        foreach ($items as $i => $item) {
            if (($item['rate_id'] ?? null) !== null) {
                continue;
            }
            $rateId = $this->rateId((float) $item['rate'], $taxDate);
            if ($rateId === null) {
                $ctx->protocol->difference($step, 'unknown_vat_rate', sprintf(
                    'Doklad %s: sazba DPH %s %% není v číselníku sazeb, nepřevzat. Založte ji v Nastavení → Číselníky → DPH sazby a převod zopakujte.',
                    $label, rtrim(rtrim(number_format((float) $item['rate'], 2, ',', ''), '0'), ',')
                ), ['document_no' => $label, 'rate' => $item['rate']]);
                return false;
            }
            $items[$i]['rate_id'] = $rateId;
        }
        return true;
    }

    /**
     * Číslo dokladu, které ve firmě ještě není; obsazené dostane příponu roku.
     *
     * @return array{number:string,suffixed:bool}|null
     */
    /** @param list<string> $numbers kandidáti v pořadí preference */
    private function freeNumber(string $table, int $supplierId, array $numbers, int $year): ?array
    {
        $stmt = $this->stmt('free_' . $table, "SELECT 1 FROM {$table} WHERE supplier_id = ? AND varsymbol = ? LIMIT 1");
        $suffix = '-' . $year;
        $candidates = [];
        foreach ($numbers as $i => $docNo) {
            $candidates[] = [mb_substr($docNo, 0, 20), $i > 0];
        }
        $candidates[] = [mb_substr($numbers[0], 0, 20 - mb_strlen($suffix)) . $suffix, true];
        foreach ($candidates as [$candidate, $suffixed]) {
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
    private function reportNumberAndReview(PremierContext $ctx, string $step, string $label, array $number, array $reasons): void
    {
        $p = $ctx->protocol;
        if ($number['suffixed']) {
            $p->count($step, 'suffixed');
            $p->info($step, 'number_suffixed', "Doklad {$label}: číslo už ve firmě je, převzat jako {$number['number']}.", ['document_no' => $label]);
        }
        if ($reasons !== []) {
            $p->count($step, 'review');
            $p->warn($step, 'needs_review', "Doklad {$label} převzat jako koncept k ruční kontrole: " . self::reasonList($reasons)
                . '. Do DPH ani do účtování nevstoupí, dokud ho neopravíte a nepotvrdíte.', ['document_no' => $label, 'reasons' => $reasons]);
        }
    }

    /**
     * Opakovaný převod už převedený doklad NEPŘEPISUJE (mohl být mezitím upravený nebo
     * spárovaný). Liší-li se v PREMIER, protokol to řekne.
     */
    private function reportChanged(PremierContext $ctx, string $step, string $table, int $id, string $label, float $total): void
    {
        // Doklad převzatý v cizí měně se porovná v Kč přepočtený kurzem dokladu.
        $stmt = $this->stmt('changed_' . $table, 'SELECT ' . ForeignCurrencyTakeover::homeAmountSql('total_with_vat', 'exchange_rate') . " FROM {$table} WHERE id = ? AND supplier_id = ?");
        $stmt->execute([$id, $ctx->supplierId]);
        $stored = $stmt->fetchColumn();
        if ($stored === false || abs((float) $stored - $total) < 0.005) {
            return;
        }
        $ctx->protocol->count($step, 'changed');
        $ctx->protocol->warn($step, 'changed_in_premier', sprintf(
            'Doklad %s se v PREMIER od převodu změnil (celkem %s → %s). V MyÚčtu zůstává beze změny, upravte ho ručně.',
            $label, number_format((float) $stored, 2, ',', ' '), number_format($total, 2, ',', ' ')
        ), ['document_no' => $label, 'id' => $id]);
    }

    /**
     * @param list<string> $reasons
     * @param array<string,mixed> $doc
     * @param list<string> $notes
     */
    private static function note(string $label, array $reasons, array $doc, ForeignCurrencyDecision $fx, array $notes = []): string
    {
        $note = 'Převzato z PREMIER, doklad ' . $label;
        if ($doc['previous']) {
            $note .= ' (doklad minulého období, zůstatek je v počátečních stavech)';
        }
        if ($fx->note('převzat v Kč podle zaúčtování') !== null) {
            $note .= '; ' . $fx->note('převzat v Kč podle zaúčtování');
        }
        foreach ($notes as $n) {
            $note .= '; ' . $n;
        }
        return $reasons === [] ? $note : $note . '. K ruční kontrole: ' . self::reasonList($reasons) . '.';
    }

    /**
     * Důvody ke konceptu jako jedna věta bez koncové tečky - tu přidává volající. Důvod
     * převzatý z OSS plánovače je celá věta s tečkou a bez ořezu by hláška končila „..".
     *
     * @param list<string> $reasons
     */
    private static function reasonList(array $reasons): string
    {
        return implode('; ', array_map(static fn (string $r): string => rtrim($r, '. '), $reasons));
    }

    private static function paymentMethod(string $form): string
    {
        foreach (self::PAYMENT_FORMS as $needle => $method) {
            if ($form !== '' && str_contains($form, $needle)) {
                return $method;
            }
        }
        return 'bank_transfer';
    }

    /** @return array{0:?string,1:?string} číslo účtu a kód banky z „123-456/0100" */
    private static function bankAccount(string $value): array
    {
        $value = str_replace(' ', '', $value);
        if (preg_match('/^([0-9-]{1,40})\/(\d{4})$/', $value, $m) === 1) {
            return [$m[1], $m[2]];
        }
        return [$value !== '' ? mb_substr($value, 0, 34) : null, null];
    }

    private function stmt(string $key, string $sql): \PDOStatement
    {
        return $this->stmts[$key] ??= $this->db->pdo()->prepare($sql);
    }

    /** Tuzemská sazba firmy k datu, `null` = v číselníku není. */
    private function rateId(float $rate, string $date): ?int
    {
        return $this->rates->find($rate, $date);
    }
}
