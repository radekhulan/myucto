<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Service\Migration\OssMigrationPolicy;
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
 * jako koncept k ruční kontrole** ({@see classify()}): neznámý druh dokladu, dobropis
 * zálohy, stornované a neúčtované doklady a členění DPH, které převod nezná. Koncept do
 * DPH evidence ani do účtování nevstoupí, dokud ho účetní neopraví a nepotvrdí — hádat
 * by znamenalo zálohu vedle konečné faktury započíst do DPH dvakrát nebo přenesenou
 * daňovou povinnost vykázat jako tuzemské plnění.
 *
 * **Doklad v cizí měně koncept není**: Money drží základ i daň po sazbách v Kč (kurzem,
 * kterým doklad zaúčtovalo a vykázalo v přiznání) i v měně dokladu (`ValutyZakN`,
 * `ValutyDPHN`, kurz `Kurs` za `PocetJedn`). V měně a kurzu dokladu se převezme jen tehdy,
 * když částky v měně kurzem dají přesně Kč z Money ({@see ForeignCurrencyTakeover}) -
 * částky DPH jsou pak pořád přesně ty, které Money vykázalo. Jinak (a u samovyměření)
 * se převezme v Kč bez kurzu jako dřív, s důvodem v poznámce.
 */
final class InvoiceImporter
{
    public const STEP_PURCHASE = 'purchase_invoices';
    public const STEP_ISSUED = 'issued_invoices';

    /** Rozdíl do 1 Kč mezi součtem sazeb a celkem z Money je zaokrouhlení dokladu. */
    private const ROUNDING_LIMIT = 1.0;

    /** Sazby dokladu Money: `Zaklad_n` + `SazbaDPHn` + `DPH_n`. */
    private const RATE_SLOTS = 6;

    /** Druhy zálohové faktury v Money (`L`, starší `Z`, u vydaných `F`) — nejsou daňovým dokladem. */
    private const ADVANCE_KINDS = ['L', 'Z', 'F'];

    private readonly MigrationVatRateLookup $rates;

    private readonly ForeignCurrencyTakeover $foreignCurrency;

    public function __construct(
        private readonly Connection $db,
        private readonly MoneyS3ImportRepository $map,
        private readonly CodebookImporter $codebooks,
        private readonly StatsRecomputer $stats,
        private readonly MigratedDocumentWriter $writer,
        private readonly MigrationHomeCurrency $homeCurrency,
    ) {
        $this->rates = new MigrationVatRateLookup($db);
        $this->foreignCurrency = new ForeignCurrencyTakeover($db);
    }

    public function importPurchases(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        $pdo = $this->db->pdo();
        $currencyId = $this->homeCurrency->id($ctx->supplierId);
        $existing = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_PURCHASE_INVOICE);
        $claimShifts = $this->claimShifts($ctx);
        $selfAssessed = $this->selfAssessments($ctx);
        $usedSelfAssessments = [];
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
                $p->warn(self::STEP_PURCHASE, 'number_taken', "Číslo dokladu {$docNo} ({$year}) už ve firmě má jiný doklad, faktura nepřevzata.", ['document_no' => $docNo, 'year' => $year]);
                continue;
            }
            $snapshot = [
                'name' => trim((string) ($r['D_Nazev'] ?? '')),
                'ico' => CodebookImporter::ico((string) ($r['D_ICO'] ?? '')),
                'dic' => strtoupper(str_replace(' ', '', trim((string) ($r['D_DIC'] ?? '')))),
                'street' => trim((string) ($r['D_Ulice'] ?? '')),
                'city' => trim((string) ($r['D_Mesto'] ?? '')),
                'zip' => trim((string) ($r['D_Psc'] ?? '')),
                'country' => trim((string) ($r['D_Stat'] ?? '')),
            ];
            $vendorId = $this->codebooks->resolvePartner($ctx, $snapshot);
            try {
                $amounts = $this->amounts($ctx, self::STEP_PURCHASE, $docNo, $r, self::date($r, ['PlnenoDPH']) ?? $issue);
            } catch (MoneyS3Exception $e) {
                $this->skipUnknownRate($ctx, self::STEP_PURCHASE, $docNo, $year, $e);
                continue;
            }
            $class = self::classify($r, false, $amounts['vat']);
            $review = $class['reasons'] !== [];
            if ($review && self::historicalUnposted($ctx, $year, 'FP', $docNo)) {
                $p->count(self::STEP_PURCHASE, 'unposted_review_skipped');
                continue;
            }
            $taxDate = self::date($r, ['PlnenoDPH']) ?? $issue;
            $selfAssessment = $review ? null : self::pickSelfAssessment($selfAssessed, $docNo, $year, (string) ($r['PrijatDokl'] ?? ''), $usedSelfAssessments);
            if (!$review && $selfAssessment === null && (int) ($r['RevCH'] ?? 0) === 1) {
                // Přenesená povinnost bez dohledaného samovyměření by v přiznání skončila
                // jako plnění bez daně (ř. 40 s nulovou daní, bez ř. 10 a 43).
                $class['reasons'][] = 'přenesená daňová povinnost bez dohledaného interního dokladu se samovyměřením';
                $review = true;
            }
            $reverseCharge = false;
            if ($selfAssessment !== null) {
                $usedSelfAssessments[$selfAssessment['key']] = true;
                if ($selfAssessment['error'] !== null) {
                    $p->warn(self::STEP_PURCHASE, 'self_assessment_review', "Faktura {$docNo} ({$year}): samovyměření z interního dokladu {$selfAssessment['doc']} převod nezařadí ({$selfAssessment['error']}), DPH doplňte ručně.", ['document_no' => $docNo, 'year' => $year]);
                } else {
                    // Samovyměření se vykazuje ke dni z interního dokladu (datum uplatnění DPH).
                    $taxDate = $selfAssessment['date'] ?? $taxDate;
                    try {
                        [$amounts, $class] = $this->applySelfAssessment($selfAssessment, $amounts, $class, $taxDate);
                    } catch (MoneyS3Exception $e) {
                        $this->skipUnknownRate($ctx, self::STEP_PURCHASE, $docNo, $year, $e);
                        continue;
                    }
                    $reverseCharge = true;
                    $p->count(self::STEP_PURCHASE, 'self_assessed');
                }
            }
            // Daňová evidence: zaplaceno až podle navázaných úhrad z peněžního deníku
            // ({@see TaxRecordsImporter::payments()}). Doklad označený zaplaceným bez vazby
            // na pohyb by peněžní deník MyÚčta započetl jako virtuální výdaj podruhé.
            $paidAt = $ctx->taxRecords ? null : self::date($r, ['Uhrazeno']);
            // Zálohovou fakturu Money neúčtuje — v MyÚčtu je přijatá, ne zaúčtovaná.
            // Daňová evidence doklady neúčtuje vůbec.
            $unbooked = $review || $class['kind'] === 'advance' || $ctx->taxRecords;
            // Konečná faktura s plnými sazbami a odečtenou zálohou (`SumZaloha`): zálohu
            // zaplatil doklad zálohy, faktura ji má jako uhrazenou zálohu.
            $advancePaid = $ctx->taxRecords && (float) ($r['SumZaloha'] ?? 0) < 0
                && abs(round((float) ($r['CelkemSDPH'] ?? 0), 2) - $amounts['total']) < 0.005
                ? min(round(-(float) $r['SumZaloha'], 2), $amounts['total']) : 0.0;
            // Odpočet, který Money přesunulo do pozdějšího období (§ 73), se uplatní ke dni
            // z Money — stejně jako ručně zadané datum přijetí dokladu.
            $claimDate = $claimShifts[$key] ?? null;
            $vendorNumber = mb_substr(trim((string) ($r['PrijatDokl'] ?? '')) ?: (trim((string) ($r['VarSymbol'] ?? '')) ?: $docNo), 0, 50);
            $duplicate->execute([$ctx->supplierId, $vendorId, $vendorNumber, $issue]);
            if ($duplicate->fetchColumn() !== false) {
                $vendorNumber = mb_substr($vendorNumber . ' (' . $docNo . ')', 0, 50);
            }
            $assets = array_map(static fn (array $item): bool => MigratedDocumentItem::fixedAssetLine($class['fixed_asset'], $item['rate'], $item['vat'],
                ($item['code'] ?? null) === VatReturnLineClassifier::PURCHASE_OUTSIDE_SCOPE_CODE ? null : ($item['code'] ?? null)), $amounts['items']);
            $fx = $this->takeOverCurrency($ctx, $r, $amounts, ForeignCurrencyTakeover::purchaseBlock($reverseCharge, $class['vat_deduction']));
            try {
                $id = $this->writer->insertPurchase(new MigratedPurchaseDocument(
                    supplierId: $ctx->supplierId,
                    vendorId: $vendorId,
                    vendorIsVatPayer: $snapshot['dic'] !== '',
                    varsymbol: $number['number'],
                    vendorInvoiceNumber: $vendorNumber,
                    documentKind: $class['kind'],
                    issueDate: $issue,
                    taxDate: $taxDate,
                    dueDate: self::date($r, ['Splatno']) ?? $issue,
                    receivedAt: $claimDate ?? (self::date($r, ['Doruceno', 'DatUcPr']) ?? $issue),
                    receivedAtSource: $claimDate !== null ? 'manual' : 'import',
                    currencyId: $fx->currencyId ?? $currencyId,
                    exchangeRate: $fx->rate,
                    // Položky vznikají ze základů po sazbách - ceny jsou vždy bez DPH.
                    pricesIncludeVat: false,
                    // Samovyměření z interního dokladu (applySelfAssessment()) - jako u POHODY
                    // a PREMIER. Neznámé členění přenesené povinnosti jde do konceptu.
                    reverseCharge: $reverseCharge,
                    vendorSnapshot: self::snapshotJson($snapshot),
                    totalWithoutVat: $amounts['base'],
                    totalVat: $amounts['vat'],
                    totalWithVat: $amounts['total'],
                    rounding: $amounts['rounding'],
                    status: $review ? 'draft' : ($paidAt !== null ? 'paid' : ($unbooked ? 'received' : 'booked')),
                    vatDeduction: $class['vat_deduction'],
                    noteAboveItems: mb_substr(trim((string) ($r['Popis'] ?? '')), 0, 255) ?: null,
                    noteBelowItems: self::note($docNo, $class['reasons'], $fx),
                    createdBy: $ctx->userId,
                    advancePaidAmount: $advancePaid,
                    paymentVariableSymbol: mb_substr(trim((string) ($r['VarSymbol'] ?? '')), 0, 20) ?: null,
                    paymentMethod: self::paymentMethod((string) ($r['Uhrada'] ?? '')),
                    paidAt: $paidAt,
                    bookedAt: $unbooked ? null : (self::date($r, ['DatUcPr']) ?? $issue) . ' 00:00:00',
                    bookedBy: $unbooked || $ctx->userId <= 0 ? null : $ctx->userId,
                    // Čárový kód z Money je jistý klíč pro párování naskenovaných příloh.
                    externalBarcode: mb_substr(trim((string) ($r['BarCode'] ?? '')), 0, 64) ?: null,
                    vatClassificationCode: $class['code'],
                    isFixedAsset: MigratedDocumentItem::wholeDocumentFixedAsset($assets),
                ));
            } catch (\PDOException $e) {
                if ((string) $e->getCode() !== '23000') {
                    throw $e;
                }
                $p->warn(self::STEP_PURCHASE, 'insert_conflict', "Faktura {$docNo} ({$year}) koliduje s existujícím dokladem firmy, nepřevzata.", ['document_no' => $docNo, 'year' => $year]);
                continue;
            }
            $items = [];
            foreach ($amounts['items'] as $i => $item) {
                $items[$i] = MigratedDocumentItem::purchase(
                    trim((string) ($r['Popis'] ?? '')) ?: 'Převzato z Money S3',
                    1.0, 'ks', $item['base'], $item['rate_id'], $item['rate'],
                    $item['base'], $item['vat'], round($item['base'] + $item['vat'], 2),
                    $item['code'] ?? $class['code'],
                    $assets[$i],
                );
            }
            $this->writer->insertPurchaseItems($id, $items);
            if ($ctx->taxRecords) {
                $this->applyTaxRecordsDeductibility($ctx, $id, $r, $docNo, $year);
            }
            ForeignCurrencyTakeover::report($p, self::STEP_PURCHASE, $docNo, $fx);
            if ($claimDate !== null) {
                $p->count(self::STEP_PURCHASE, 'claim_shifted');
            }
            $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_PURCHASE_INVOICE, $key, $id, $ctx->runId);
            $ctx->purchaseInvoices[$key] = $id;
            $p->count(self::STEP_PURCHASE, 'created');
            $this->reportNumberAndReview($ctx, self::STEP_PURCHASE, $docNo, $year, $number, $class['reasons']);
        }
        $unlinked = [];
        foreach ($selfAssessed as $byYear) {
            foreach ($byYear as $list) {
                foreach ($list as $sa) {
                    if (!isset($usedSelfAssessments[$sa['key']]) && count($unlinked) < ImportProtocol::LIST_LIMIT) {
                        $unlinked[$sa['key']] = $sa['doc'];
                    }
                }
            }
        }
        $unlinked = array_values($unlinked);
        if ($unlinked !== []) {
            $p->warn(self::STEP_PURCHASE, 'self_assessment_unlinked', count($unlinked) . ' interních dokladů se samovyměřením DPH nejde přiřadit k převedené faktuře ('
                . implode(', ', array_slice($unlinked, 0, 20)) . '). Jejich DPH doplňte ručně.', ['documents' => $unlinked]);
        }
        $p->finish(self::STEP_PURCHASE);
    }

    public function importIssued(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        $currencyId = $this->homeCurrency->id($ctx->supplierId);
        $existing = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_INVOICE);
        $itemCodes = [];
        foreach ($ctx->backup->rowsAcrossYears('VFaktPol', ['Cislo', 'SazbaDPH', 'KodDPH', 'Cena', 'PocetMJ']) as $item) {
            if (round((float) ($item['Cena'] ?? 0), 2) !== 0.0) {
                $itemCodes[$item['__dir'] . '|' . (int) ($item['Cislo'] ?? 0)][] = $item;
            }
        }

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
                $p->warn(self::STEP_ISSUED, 'number_taken', "Číslo faktury {$docNo} ({$year}) už ve firmě má jiný doklad, faktura nepřevzata.", ['document_no' => $docNo, 'year' => $year]);
                continue;
            }
            $snapshot = [
                'name' => trim((string) ($r['O_Nazev'] ?? $r['AdNazev'] ?? '')),
                'ico' => CodebookImporter::ico((string) ($r['O_ICO'] ?? $r['AdICO'] ?? '')),
                'dic' => strtoupper(str_replace(' ', '', trim((string) ($r['O_DIC'] ?? $r['AdDIC'] ?? '')))),
                'street' => trim((string) ($r['O_Ulice'] ?? $r['AdUlice'] ?? '')),
                'city' => trim((string) ($r['O_Mesto'] ?? $r['AdMesto'] ?? '')),
                'zip' => trim((string) ($r['O_Psc'] ?? $r['AdPSC'] ?? '')),
                'country' => trim((string) ($r['O_Stat'] ?? $r['AdStat'] ?? '')),
            ];
            $clientId = $this->codebooks->resolvePartner($ctx, $snapshot);
            try {
                $amounts = $this->amounts($ctx, self::STEP_ISSUED, $docNo, $r, self::date($r, ['PlnenoDPH']) ?? $issue);
            } catch (MoneyS3Exception $e) {
                $this->skipUnknownRate($ctx, self::STEP_ISSUED, $docNo, $year, $e);
                continue;
            }
            [$class, $amounts] = self::classifyLines($r, true, $amounts, self::lineCodes($r, $itemCodes[$r['__dir'] . '|' . (int) ($r['Cislo'] ?? 0)] ?? []));
            $review = $class['reasons'] !== [];
            if ($review && self::historicalUnposted($ctx, $year, 'FV', $docNo)) {
                $p->count(self::STEP_ISSUED, 'unposted_review_skipped');
                continue;
            }
            // Zálohovou fakturu Money neúčtuje — v MyÚčtu je vystavená, ne zaúčtovaná.
            // Daňová evidence doklady neúčtuje vůbec.
            $unbooked = $review || $class['kind'] === 'proforma' || $ctx->taxRecords;
            // Daňová evidence: úhrady z peněžního deníku ({@see TaxRecordsImporter::payments()}).
            $paidAt = $ctx->taxRecords ? null : self::date($r, ['Uhrazeno']);
            // Money vede úhradu jen jako datum (celá, nebo žádná) - částečná úhrada doklad neblokuje.
            $fx = $this->takeOverCurrency($ctx, $r, $amounts, null);
            try {
                $id = $this->writer->insertIssued(new MigratedIssuedDocument(
                    supplierId: $ctx->supplierId,
                    invoiceType: $class['kind'],
                    clientId: $clientId,
                    varsymbol: $number['number'],
                    issueDate: $issue,
                    taxDate: self::date($r, ['PlnenoDPH']) ?? $issue,
                    dueDate: self::date($r, ['Splatno']) ?? $issue,
                    currencyId: $fx->currencyId ?? $currencyId,
                    exchangeRate: $fx->rate,
                    // Položky vznikají ze základů po sazbách - ceny jsou vždy bez DPH.
                    pricesIncludeVat: false,
                    // Tuzemské přenesení daňové povinnosti (19Ř25, 19Ř25_S) nese kód zařazení
                    // i příznak hlavičky; jiné členění přenesené povinnosti jde do konceptu (classify()).
                    reverseCharge: VatReturnLineClassifier::isDomesticReverseSale([$class['code']]),
                    noteAboveItems: mb_substr(trim((string) ($r['Popis'] ?? '')), 0, 255) ?: null,
                    noteBelowItems: self::note($docNo, $class['reasons'], $fx),
                    clientSnapshot: self::snapshotJson($snapshot),
                    totalWithoutVat: $amounts['base'],
                    totalVat: $amounts['vat'],
                    totalWithVat: $amounts['total'],
                    rounding: $amounts['rounding'],
                    status: $review ? 'draft' : ($paidAt !== null ? 'paid' : 'sent'),
                    createdBy: $ctx->userId > 0 ? $ctx->userId : null,
                    paidTotal: $paidAt !== null ? $amounts['total'] : 0.0,
                    paidAt: $paidAt,
                    bookedAt: $unbooked ? null : (self::date($r, ['DatUcPr']) ?? $issue) . ' 00:00:00',
                    bookedBy: $unbooked || $ctx->userId <= 0 ? null : $ctx->userId,
                    vatClassificationCode: $class['code'],
                ));
            } catch (\PDOException $e) {
                if ((string) $e->getCode() !== '23000') {
                    throw $e;
                }
                $p->warn(self::STEP_ISSUED, 'insert_conflict', "Faktura {$docNo} ({$year}) koliduje s existujícím dokladem firmy, nepřevzata.", ['document_no' => $docNo, 'year' => $year]);
                continue;
            }
            $clients[$clientId] = $clientId;
            $items = [];
            foreach ($amounts['items'] as $i => $item) {
                $items[$i] = self::issuedItem($r, $item['base'], $item['vat'], $item['rate_id'], $item['rate'], array_key_exists('code', $item) ? $item['code'] : $class['code']);
            }
            $this->writer->insertIssuedItems($id, $items);
            ForeignCurrencyTakeover::report($p, self::STEP_ISSUED, $docNo, $fx);
            $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_INVOICE, $key, $id, $ctx->runId);
            $ctx->issuedInvoices[$key] = $id;
            $p->count(self::STEP_ISSUED, 'created');
            $this->reportNumberAndReview($ctx, self::STEP_ISSUED, $docNo, $year, $number, $class['reasons']);
        }
        $this->importOtherReceivables($ctx, $currencyId, $clients);
        $ctx->statsClients = array_values(array_unique(array_merge($ctx->statsClients, array_values($clients))));
        // Doklady deníku potřebují jen přijaté a vydané faktury; další kroky už ne.
        $ctx->journalDocuments = null;
        $p->finish(self::STEP_ISSUED);
    }

    /**
     * Ostatní pohledávky Money (`KnihPohl`, v deníku zdroj KP) v přiznání — věcná břemena,
     * pachty, osvobozená plnění ř. 50 — jsou plněním jako vydaná faktura. Bez nich by
     * v přiznání chyběla daň na výstupu i hodnota osvobozených plnění pro koeficient § 76.
     * Převedou se jako vydané doklady (záporná = opravný doklad) a zápis KP se naváže
     * ({@see DocumentLinker}). Pohledávky mimo přiznání (19Ř00U) zůstanou jen v deníku.
     *
     * @param array<int,int> $clients
     */
    private function importOtherReceivables(ImportContext $ctx, int $currencyId, array &$clients): void
    {
        $p = $ctx->protocol;
        $existing = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_INVOICE);
        foreach ($ctx->backup->rowsAcrossYears('KnihPohl') as $r) {
            $year = $ctx->yearOf($r);
            $docNo = trim((string) ($r['Doklad'] ?? ''));
            $resolved = Ms3VatCode::resolve((string) ($r['Cleneni'] ?? ''), true);
            if ($year === null || $docNo === '' || (int) ($r['Storno'] ?? 0) === 1 || $resolved === null || !$resolved['in_return']) {
                continue;
            }
            $key = 'KP|' . $year . '|' . $docNo;
            if (isset($existing[$key])) {
                $ctx->otherReceivables[$year . '|' . $docNo] = $existing[$key];
                $p->count(self::STEP_ISSUED, 'existing');
                continue;
            }
            $issue = self::date($r, ['DatVyst', 'DatUcPr']);
            if ($ctx->isLocked($year) || $issue === null) {
                continue;
            }
            $number = $this->freeNumber('invoices', $ctx->supplierId, $docNo, $year);
            if ($number === null) {
                $p->warn(self::STEP_ISSUED, 'number_taken', "Číslo pohledávky {$docNo} ({$year}) už ve firmě má jiný doklad, pohledávka nepřevzata.", ['document_no' => $docNo, 'year' => $year]);
                continue;
            }
            $taxDate = self::date($r, ['DatPln', 'DatUcPr']) ?? $issue;
            // Částky pohledávky nesou znaménko z Money (opravná pohledávka je záporná).
            $items = [];
            foreach (CashBankImporter::vatLines($r, false) as $line) {
                $items[] = ['base' => $line['base'], 'vat' => $line['vat'], 'rate' => $line['rate']];
            }
            $zero = round((float) ($r['Zakl0'] ?? 0), 2);
            if ($zero !== 0.0) {
                $items[] = ['base' => $zero, 'vat' => 0.0, 'rate' => 0.0];
            }
            $total = round((float) ($r['Celkem'] ?? 0), 2);
            if ($items === [] && $total !== 0.0) {
                $items[] = ['base' => $total, 'vat' => 0.0, 'rate' => 0.0];
            }
            if ($items === []) {
                continue;
            }
            try {
                foreach ($items as $i => $item) {
                    $items[$i]['rate_id'] = $this->rateId($item['rate'], $taxDate);
                }
            } catch (MoneyS3Exception $e) {
                $this->skipUnknownRate($ctx, self::STEP_ISSUED, $docNo, $year, $e);
                continue;
            }
            $base = round(array_sum(array_column($items, 'base')), 2);
            $vat = round(array_sum(array_column($items, 'vat')), 2);
            $total = $total !== 0.0 ? $total : round($base + $vat, 2);
            $snapshot = [
                'name' => trim((string) ($r['AdNazev'] ?? '')),
                'ico' => CodebookImporter::ico((string) ($r['AdICO'] ?? '')),
                'dic' => strtoupper(str_replace(' ', '', trim((string) ($r['AdDIC'] ?? '')))),
                'street' => trim((string) ($r['AdUlice'] ?? '')),
                'city' => trim((string) ($r['AdMesto'] ?? '')),
                'zip' => trim((string) ($r['AdPSC'] ?? '')),
                'country' => trim((string) ($r['AdStat'] ?? '')),
            ];
            $clientId = $this->codebooks->resolvePartner($ctx, $snapshot);
            $paidAt = $ctx->taxRecords ? null : self::date($r, ['UhDatum']);
            $id = $this->writer->insertIssued(new MigratedIssuedDocument(
                supplierId: $ctx->supplierId,
                invoiceType: $total < 0 ? 'credit_note' : 'invoice',
                clientId: $clientId,
                varsymbol: $number['number'],
                issueDate: $issue,
                taxDate: $taxDate,
                dueDate: self::date($r, ['DatSpl']) ?? $issue,
                currencyId: $currencyId,
                exchangeRate: null,
                pricesIncludeVat: false,
                reverseCharge: VatReturnLineClassifier::isDomesticReverseSale([$resolved['code']]),
                noteAboveItems: mb_substr(trim((string) ($r['Popis'] ?? '')), 0, 255) ?: null,
                noteBelowItems: 'Převzato z Money S3, ostatní pohledávka ' . $docNo,
                clientSnapshot: self::snapshotJson($snapshot),
                totalWithoutVat: $base,
                totalVat: $vat,
                totalWithVat: $total,
                rounding: round($total - $base - $vat, 2),
                status: $paidAt !== null ? 'paid' : 'sent',
                createdBy: $ctx->userId > 0 ? $ctx->userId : null,
                paidTotal: $paidAt !== null ? $total : 0.0,
                paidAt: $paidAt,
                bookedAt: $ctx->taxRecords ? null : (self::date($r, ['DatUcPr']) ?? $issue) . ' 00:00:00',
                bookedBy: !$ctx->taxRecords && $ctx->userId > 0 ? $ctx->userId : null,
                vatClassificationCode: $resolved['code'],
            ));
            foreach ($items as $i => $item) {
                $this->writer->insertIssuedItem(
                    $id,
                    self::issuedItem($r, $item['base'], $item['vat'], $item['rate_id'], $item['rate'], $resolved['code']),
                    $i,
                );
            }
            $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_INVOICE, $key, $id, $ctx->runId);
            $ctx->otherReceivables[$year . '|' . $docNo] = $id;
            $clients[$clientId] = $clientId;
            $p->count(self::STEP_ISSUED, 'other_receivables');
        }
    }

    /**
     * Daňová uznatelnost přijatého dokladu v daňové evidenci podle předkontace daňové
     * evidence Money (`PredKontac` → pohyb → sloupec peněžního deníku,
     * {@see Ms3CashBook::ruleBucket()}). Doklad s nedaňovým výdajem (nedaňové výdaje,
     * investiční majetek, osobní spotřeba) je daňově neuznatelný; doklad bez předkontace
     * zůstává uznatelný jako jinde v MyÚčtu.
     *
     * @param array<string,mixed> $r
     */
    private function applyTaxRecordsDeductibility(ImportContext $ctx, int $id, array $r, string $docNo, int $year): void
    {
        $rule = trim((string) ($r['PredKontac'] ?? ''));
        $book = $ctx->cashBooks[(string) ($r['__dir'] ?? '')] ?? null;
        if ($rule === '' || $book === null) {
            return;
        }
        $bucket = $book->ruleBucket($rule);
        if ($bucket === null) {
            $ctx->protocol->warn(self::STEP_PURCHASE, 'tax_records_rule_unknown', "Faktura {$docNo} ({$year}): předkontaci daňové evidence „{$rule}“ převod nezná, "
                . 'doklad zůstává daňově uznatelný, zkontrolujte ho.', ['document_no' => $docNo, 'year' => $year]);
            return;
        }
        if (!in_array($bucket, ['expense_taxable', Ms3CashBook::VAT], true)) {
            $this->db->pdo()->prepare('UPDATE purchase_invoices SET tax_deductible = 0 WHERE id = ? AND supplier_id = ?')
                ->execute([$id, $ctx->supplierId]);
            $ctx->protocol->count(self::STEP_PURCHASE, 'tax_non_deductible');
        }
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
     * Daňová povaha dokladu z Money. Vrací důvody, proč doklad NEJDE převzít bez ruční
     * kontroly (prázdné = jde), druh dokladu v MyÚčtu, nárok na odpočet a kód zařazení
     * do přiznání.
     *
     * Druh Money: `N` běžná faktura, `D` daňový doklad k přijaté platbě (Money ho účtuje
     * jen 343/314), `L`/`Z`/`F` zálohová faktura — Money ji neúčtuje a do přiznání nejde,
     * DPH nese až daňový doklad k platbě nebo konečná faktura. Dobropis (`Dobropis`) má
     * v Money záporné částky. Členění DPH (`KodDPH`) převádí {@see Ms3VatCode}; členění,
     * které převod nezná, a stornované či neúčtované doklady jdou k ruční kontrole.
     * Doklad v cizí měně má v Money základ i daň v Kč, převezme se tak.
     *
     * @param array<string,mixed> $r
     * `fixed_asset` = odpočet u pořízení majetku (ř. 47, členění Money s příponou M/P/MK/PK).
     *
     * @return array{reasons:list<string>,vat_deduction:string,code:?string,kind:string,fixed_asset:bool}
     */
    public static function classify(array $r, bool $issued, float $vat): array
    {
        $reasons = [];
        $deduction = 'full';
        $vatCode = null;
        $asset = false;
        $kind = 'invoice';
        $druh = strtoupper(trim((string) ($r['Druh'] ?? '')));
        $advance = in_array($druh, self::ADVANCE_KINDS, true);
        if ($druh === 'D') {
            $kind = 'tax_document';
        } elseif ($advance) {
            $kind = $issued ? 'proforma' : 'advance';
        } elseif ($druh !== '' && $druh !== 'N') {
            $reasons[] = "neznámý druh dokladu „{$druh}“";
        }
        if ((int) ($r['Dobropis'] ?? 0) === 1) {
            if ($kind === 'invoice') {
                $kind = 'credit_note';
            } else {
                $reasons[] = 'dobropis zálohy nebo daňového dokladu k platbě';
            }
        }
        if ((int) ($r['Storno'] ?? 0) === 1) {
            $reasons[] = 'stornovaný doklad';
        }
        if ((int) ($r['Neuctovat'] ?? 0) === 1) {
            $reasons[] = 'v Money označený „neúčtovat“';
        }
        if ($advance) {
            return ['reasons' => $reasons, 'vat_deduction' => $deduction, 'code' => null, 'kind' => $kind, 'fixed_asset' => false];
        }

        $code = trim((string) ($r['KodDPH'] ?? ''));
        $resolved = $code === '' ? null : Ms3VatCode::resolve($code, $issued);
        $hasVat = abs($vat) >= 0.005;
        if ($code === '') {
            if ($hasVat) {
                $reasons[] = 'doklad s daní bez členění DPH';
            }
        } elseif ($resolved === null) {
            $reasons[] = "členění DPH „{$code}“ (přenesená daňová povinnost na vstupu, pořízení z EU, dovoz, poměrný nárok nebo zvláštní režim)";
        } elseif (!$resolved['in_return']) {
            if ($hasVat) {
                if ($issued) {
                    $reasons[] = "členění DPH „{$code}“ mimo přiznání u dokladu s daní";
                } else {
                    // Přijatý doklad mimo přiznání: daň je součástí nákladu, odpočet se neuplatnil.
                    $deduction = 'none';
                }
            }
        } elseif ($resolved['code'] !== null && $hasVat) {
            $reasons[] = "členění DPH „{$code}“ (plnění bez daně) u dokladu s daní";
        } else {
            $deduction = $resolved['deduction'];
            $vatCode = $resolved['code'];
            $asset = !$issued && $resolved['fixed_asset'];
        }
        return ['reasons' => $reasons, 'vat_deduction' => $deduction, 'code' => $vatCode, 'kind' => $kind, 'fixed_asset' => $asset];
    }

    /**
     * Členění DPH po sazbách z položek dokladu (`VFaktPol.KodDPH`, prázdné = kód hlavičky).
     * Money vykazuje každou položku jejím členěním: nájem bytu ř. 50 a zabezpečení ř. 1
     * na jedné faktuře, ať hlavička říká cokoli. Sazba s položkami různého členění zůstane
     * na kódu hlavičky. Prázdné pole = položky nic nemění.
     *
     * @param array<string,mixed> $r
     * @param list<array<string,mixed>> $items
     * @return array<string,string> sazba ('21') => členění
     */
    private static function lineCodes(array $r, array $items): array
    {
        $header = trim((string) ($r['KodDPH'] ?? ''));
        $byRate = [];
        foreach ($items as $item) {
            $code = trim((string) ($item['KodDPH'] ?? '')) ?: $header;
            $byRate[self::rateKey((float) ($item['SazbaDPH'] ?? 0))][$code] = true;
        }
        $out = [];
        $differs = false;
        foreach ($byRate as $rate => $codes) {
            if (count($codes) === 1) {
                $out[$rate] = (string) array_key_first($codes);
                $differs = $differs || $out[$rate] !== $header;
            }
        }
        return $differs ? $out : [];
    }

    /**
     * Klasifikace dokladu po řádcích sazeb, když položky nesou vlastní členění
     * ({@see lineCodes()}); jinak klasifikace hlavičky beze změny. Kód řádku jde na položku,
     * hlavička přebírá klasifikaci řádku s daní (jinak prvního řádku).
     *
     * @param array<string,mixed> $r
     * @param array{items:list<array<string,mixed>>,base:float,vat:float,total:float,rounding:float} $amounts
     * @param array<string,string> $codes
     * @return array{0:array{reasons:list<string>,vat_deduction:string,code:?string,kind:string,fixed_asset:bool},1:array{items:list<array<string,mixed>>,base:float,vat:float,total:float,rounding:float}}
     */
    private static function classifyLines(array $r, bool $issued, array $amounts, array $codes): array
    {
        if ($codes === []) {
            return [self::classify($r, $issued, $amounts['vat']), $amounts];
        }
        $header = null;
        $reasons = [];
        foreach ($amounts['items'] as $i => $item) {
            $code = $codes[self::rateKey((float) $item['rate'])] ?? null;
            $line = self::classify(($code !== null ? ['KodDPH' => $code] : []) + $r, $issued, (float) $item['vat']);
            $reasons = array_merge($reasons, $line['reasons']);
            $amounts['items'][$i]['code'] = $line['code'];
            if ($header === null || (abs((float) $item['vat']) >= 0.005 && $header['taxed'] === false)) {
                $header = $line + ['taxed' => abs((float) $item['vat']) >= 0.005];
            }
        }
        if ($header === null) {
            return [self::classify($r, $issued, $amounts['vat']), $amounts];
        }
        unset($header['taxed']);
        $header['reasons'] = array_values(array_unique($reasons));
        return [$header, $amounts];
    }

    private static function rateKey(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
    }

    /**
     * Doklad k ruční kontrole (záloha, proforma, nejisté DPH), který Money v historickém roce
     * vůbec nezaúčtovalo. Do uzavřeného roku nic nepřidá — v účetnictví ani v DPH není —
     * a jen by zaplevelil koncepty; převod ho přeskočí. V posledním (otevřeném) roce se
     * převezme, záloha tam ještě může být rozpracovaná.
     */
    private static function historicalUnposted(ImportContext $ctx, int $year, string $source, string $docNo): bool
    {
        if ($ctx->periods === [] || $year >= max(array_keys($ctx->periods))) {
            return false;
        }
        if ($ctx->journalDocuments === null) {
            $ctx->journalDocuments = [];
            foreach ($ctx->backup->rowsAcrossYears('UcDenik', ['Zdroj', 'Doklad']) as $row) {
                $y = $ctx->yearOf($row);
                if ($y !== null) {
                    $ctx->journalDocuments[$y . '|' . strtoupper(trim((string) ($row['Zdroj'] ?? ''))) . '|' . trim((string) ($row['Doklad'] ?? ''))] = true;
                }
            }
        }
        return !isset($ctx->journalDocuments[$year . '|' . $source . '|' . $docNo]);
    }

    /**
     * Doklady, jejichž odpočet Money přesunulo do pozdějšího období (§ 73 — doklad došel
     * až po podání přiznání za měsíc plnění). Money je vede v tabulce `UcPrvDPH` roku,
     * do kterého odpočet přešel, s datem uplatnění (`DatPln`); doklad sám patří do účetního
     * roku data plnění (`DatumD`, label období firmy), bez něj do roku předchozího.
     *
     * @return array<string,string> "rok|číslo dokladu" => datum uplatnění odpočtu
     */
    private function claimShifts(ImportContext $ctx): array
    {
        $out = [];
        foreach ($ctx->backup->rowsAcrossYears('UcPrvDPH') as $r) {
            $docNo = trim((string) ($r['Doklad'] ?? ''));
            $claim = self::date($r, ['DatPln']);
            $year = $ctx->yearOf($r);
            if ($docNo === '' || $claim === null || $year === null) {
                continue;
            }
            $docDate = self::date($r, ['DatumD']);
            $out[($docDate !== null ? $ctx->calendar->fiscalYearOfDate($docDate) : $year - 1) . '|' . $docNo] = $claim;
        }
        return $out;
    }

    /**
     * Samovyměření DPH, které Money vede interním dokladem (`IntDokl`, řádky `PolUcDID`)
     * k faktuře v přenesené povinnosti — pořízení z EU, služby ze zahraničí, dovoz,
     * tuzemský přenos. Faktura sama má členění mimo přiznání; výstup (ř. 3–13) a zrcadlový
     * odpočet (ř. 43/44) nese interní doklad. Faktura se pozná z popisu („RCH k PFZ…",
     * „PDP k FP 241001", „Přiznání daně z 241003") nebo z čísla dokladu dodavatele, které
     * interní doklad přebírá z faktury ({@see selfAssessmentKeys()}).
     *
     * @return array<string,array<int,list<array{key:string,doc:string,date:?string,lines:list<array{base:float,rate:float,code:string}>,deduction:string,fixed_asset:bool,error:?string}>>>
     *   klíč faktury => rok interního dokladu => samovyměření (jeden doklad pod více klíči)
     */
    private function selfAssessments(ImportContext $ctx): array
    {
        $lines = [];
        foreach ($ctx->backup->rowsAcrossYears('PolUcDID', ['CISLO', 'Cleneni', 'PredmPln', 'Cena', 'PocetMJ', 'SazbaDPH']) as $l) {
            $lines[$l['__dir'] . '|' . (int) ($l['CISLO'] ?? 0)][] = $l;
        }
        $out = [];
        if ($lines === []) {
            return $out;
        }
        foreach ($ctx->backup->rowsAcrossYears('IntDokl') as $h) {
            $year = $ctx->yearOf($h);
            $docNo = trim((string) ($h['Doklad'] ?? ''));
            $docLines = $lines[$h['__dir'] . '|' . (int) ($h['Cislo'] ?? 0)] ?? [];
            if ($year === null || $docNo === '' || $docLines === []) {
                continue;
            }
            $mirror = null;
            $output = [];
            foreach ($docLines as $l) {
                $code = trim((string) ($l['Cleneni'] ?? ''));
                if (Ms3VatCode::isReverseChargeOutput($code)) {
                    $output[] = $l;
                } elseif (preg_match('/^\d{2}Ř\s*4[234]/u', $code) === 1) {
                    $mirror ??= $code;
                }
            }
            if ($output === []) {
                continue;
            }
            $entry = ['key' => $year . '|' . $docNo, 'doc' => $docNo, 'date' => self::date($h, ['DatUplDPH', 'DatPln', 'DatUcPr']),
                'lines' => [], 'deduction' => 'full', 'fixed_asset' => false, 'error' => null];
            foreach ($output as $l) {
                $resolved = Ms3VatCode::reverseCharge((string) $l['Cleneni'], $mirror, (string) ($l['PredmPln'] ?? ''));
                if ($resolved === null) {
                    $entry['error'] = sprintf('členění „%s“ / „%s“', trim((string) $l['Cleneni']), (string) $mirror);
                    break;
                }
                $entry['lines'][] = [
                    'base' => round((float) ($l['Cena'] ?? 0) * (((float) ($l['PocetMJ'] ?? 0)) ?: 1.0), 2),
                    'rate' => (float) ($l['SazbaDPH'] ?? 0),
                    'code' => $resolved['code'],
                ];
                $entry['deduction'] = $resolved['deduction'];
                $entry['fixed_asset'] = $resolved['fixed_asset'];
            }
            foreach (self::selfAssessmentKeys((string) ($h['Popis'] ?? ''), (string) ($h['PrijatDokl'] ?? '')) as $ref) {
                $out[$ref][$year][] = $entry;
            }
        }
        return $out;
    }

    /**
     * Klíče, pod kterými se interní doklad hledá: první číslo dokladu v popisu tak, jak je
     * (bez mezery a velkými písmeny: „PFZ190001"), totéž jen číslicemi („#241001" — řada
     * faktury v Money je číselná, předponu FP/PF dopisuje účetní) a číslo dokladu dodavatele
     * („@DF-2024-001"). Bez čísla v popisu i bez dokladu dodavatele zůstane pod klíčem ''.
     *
     * @return list<string>
     */
    public static function selfAssessmentKeys(string $description, string $vendorDocument): array
    {
        $keys = [];
        if (preg_match('/(?<![\p{L}\d])(\p{L}{0,5})\s?(\d{4,})(?!\d)/u', $description, $m) === 1) {
            $keys[] = mb_strtoupper($m[1]) . $m[2];
            $keys[] = '#' . $m[2];
        }
        $vendor = mb_strtoupper(preg_replace('/\s+/u', '', $vendorDocument) ?? '');
        if ($vendor !== '') {
            $keys[] = '@' . $vendor;
        }
        return $keys === [] ? [''] : array_values(array_unique($keys));
    }

    /**
     * Samovyměření k faktuře: interní doklad z roku faktury, z následujícího (faktura
     * z prosince samovyměřená v lednu) nebo z předchozího. Hledá se podle čísla faktury,
     * pak podle jeho číslic a nakonec podle čísla dokladu dodavatele; doklad už přiřazený
     * jiné faktuře se přeskočí.
     *
     * @param array<string,array<int,list<array<string,mixed>>>> $selfAssessed
     * @param array<string,bool> $used
     * @return array<string,mixed>|null
     */
    private static function pickSelfAssessment(array $selfAssessed, string $docNo, int $year, string $vendorDocument = '', array $used = []): ?array
    {
        $keys = [mb_strtoupper(preg_replace('/\s+/u', '', $docNo) ?? '')];
        $digits = preg_replace('/\D+/', '', $docNo) ?? '';
        if (strlen($digits) >= 4) {
            $keys[] = '#' . $digits;
        }
        $vendor = mb_strtoupper(preg_replace('/\s+/u', '', $vendorDocument) ?? '');
        if ($vendor !== '') {
            $keys[] = '@' . $vendor;
        }
        foreach ($keys as $key) {
            if ($key === '' || !isset($selfAssessed[$key])) {
                continue;
            }
            foreach ([$year, $year + 1, $year - 1] as $y) {
                foreach ($selfAssessed[$key][$y] ?? [] as $sa) {
                    if (!isset($used[$sa['key']])) {
                        return $sa;
                    }
                }
            }
        }
        return null;
    }

    /**
     * Položky faktury se samovyměřením = řádky interního dokladu (základ, sazba a kód
     * zařazení podle výstupního řádku), nárok na odpočet podle zrcadlového řádku. Money
     * samovyměřuje kurzem ke dni plnění, takže základ se od částky faktury může lišit —
     * rozdíl zůstane jako položka bez DPH s kódem mimo předmět daně, aby doklad seděl na
     * závazek. Bez kódu by ji evidence DPH podle příznaku samovyměření na hlavičce
     * zdanila ({@see VatReturnLineClassifier::PURCHASE_OUTSIDE_SCOPE_CODE}). Hlavička kód
     * nenese: položka rozdílu by jinak zdědila kód přenesené povinnosti.
     *
     * @param array<string,mixed> $sa
     * @param array{items:list<array<string,mixed>>,base:float,vat:float,total:float,rounding:float} $amounts
     * @param array{reasons:list<string>,vat_deduction:string,code:?string,kind:string,fixed_asset:bool} $class
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function applySelfAssessment(array $sa, array $amounts, array $class, string $taxDate): array
    {
        $items = [];
        $base = 0.0;
        foreach ($sa['lines'] as $line) {
            $items[] = ['base' => $line['base'], 'rate' => $line['rate'], 'vat' => 0.0,
                'rate_id' => $this->rateId($line['rate'], $taxDate), 'code' => $line['code']];
            $base += $line['base'];
        }
        $diff = round($amounts['total'] - $base, 2);
        if (abs($diff) >= 0.01) {
            $items[] = ['base' => $diff, 'rate' => 0.0, 'vat' => 0.0, 'rate_id' => $this->rateId(0.0, $taxDate),
                'code' => VatReturnLineClassifier::PURCHASE_OUTSIDE_SCOPE_CODE];
        }
        $amounts['items'] = $items;
        $amounts['base'] = $amounts['total'];
        $amounts['vat'] = 0.0;
        $amounts['rounding'] = 0.0;
        $class['vat_deduction'] = $sa['deduction'];
        $class['code'] = null;
        $class['fixed_asset'] = $sa['fixed_asset'];
        return [$amounts, $class];
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
        // Doklad převzatý v cizí měně se porovná v Kč přepočtený kurzem dokladu.
        $stmt = $this->db->pdo()->prepare('SELECT ' . ForeignCurrencyTakeover::homeAmountSql('total_with_vat', 'exchange_rate') . " FROM {$table} WHERE id = ? AND supplier_id = ?");
        $stmt->execute([$id, $ctx->supplierId]);
        $stored = $stmt->fetchColumn();
        $money = self::expectedTotal($r);
        if ($stored === false || abs((float) $stored - $money) < 0.005) {
            return;
        }
        $ctx->protocol->count($step, 'changed');
        $ctx->protocol->warn($step, 'changed_in_money', sprintf(
            'Doklad %s (%d) se v Money od převodu změnil (celkem %s → %s). V MyÚčtu zůstává beze změny, upravte ho ručně.',
            $docNo, $year, number_format((float) $stored, 2, ',', ' '), number_format($money, 2, ',', ' ')
        ), ['document_no' => $docNo, 'year' => $year, 'id' => $id]);
    }

    /**
     * Celkem dokladu tak, jak ho převod uloží ({@see amounts()}): součet sazeb, rozdíl do 1 Kč
     * proti `CelkemSDPH` je zaokrouhlení. Konečná faktura po odpočtu zálohy má v `CelkemSDPH`
     * celou cenu, ale v sazbách jen doplatek — převedený doklad nese doplatek, takže porovnávat
     * se musí s ním, ne se syrovým `CelkemSDPH`. Bez vedlejších účinků.
     *
     * @param array<string,mixed> $r
     */
    private static function expectedTotal(array $r): float
    {
        $sumBase = 0.0;
        $sumVat = 0.0;
        $lines = self::rateLines($r);
        foreach ($lines as $line) {
            $sumBase += $line['base'];
            $sumVat += $line['vat'];
        }
        $moneyTotal = array_key_exists('CelkemSDPH', $r) ? round((float) $r['CelkemSDPH'], 2) : null;
        if ($lines === []) {
            return $moneyTotal ?? 0.0;
        }
        $total = round(round($sumBase, 2) + round($sumVat, 2), 2);
        if ($moneyTotal !== null && abs($moneyTotal - $total) >= 0.005 && abs(round($moneyTotal - $total, 2)) <= self::ROUNDING_LIMIT) {
            return $moneyTotal;
        }
        return $total;
    }

    /** @param list<string> $reasons */
    private static function note(string $docNo, array $reasons, ForeignCurrencyDecision $fx): string
    {
        $note = 'Převzato z Money S3, doklad ' . $docNo;
        if ($fx->note() !== null) {
            $note .= '; ' . $fx->note();
        }
        return $reasons === [] ? $note : $note . '. K ruční kontrole: ' . implode('; ', $reasons) . '.';
    }

    /**
     * Doklad v cizí měně (`Mena`, `Kurs` za `PocetJedn`) v jeho měně, když základ a daň
     * po sazbách v měně (`ValutyZakN`, `ValutyDPHN`) kurzem dají přesně Kč z Money
     * ({@see ForeignCurrencyTakeover}). `$amounts` se pak přepíše na částky v měně.
     *
     * @param array<string,mixed> $r
     * @param array{items:list<array<string,mixed>>,base:float,vat:float,total:float,rounding:float} $amounts MĚNÍ SE
     */
    private function takeOverCurrency(ImportContext $ctx, array $r, array &$amounts, ?string $blocked): ForeignCurrencyDecision
    {
        $units = (float) ($r['PocetJedn'] ?? 0);
        $fx = $this->foreignCurrency->decide($ctx->supplierId, self::currency($r), (float) ($r['Kurs'] ?? 0), $units > 0.0 ? $units : 1.0,
            $amounts['items'], $amounts['total'], $blocked);
        if ($fx->inForeignCurrency()) {
            $amounts['items'] = ForeignCurrencyTakeover::foreignItems($amounts['items']);
            $totals = ForeignCurrencyTakeover::totals($amounts['items']);
            $amounts = ['items' => $amounts['items'], 'base' => $totals['base'], 'vat' => $totals['vat'], 'total' => $totals['total'], 'rounding' => 0.0];
        }
        return $fx;
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
        $items = [];
        foreach (self::rateLines($r) as $line) {
            $items[] = ['base' => $line['base'], 'rate' => $line['rate'], 'vat' => $line['vat'], 'rate_id' => $this->rateId($line['rate'], $taxDate)]
                + array_intersect_key($line, ['foreign_base' => true, 'foreign_vat' => true]);
        }
        $sumBase = round(array_sum(array_column($items, 'base')), 2);
        $sumVat = round(array_sum(array_column($items, 'vat')), 2);
        $moneyTotal = array_key_exists('CelkemSDPH', $r) ? round((float) $r['CelkemSDPH'], 2) : null;
        // Konečná faktura po odečtu zálohy: `CelkemSDPH` je celá cena, sazby (a doklad
        // v MyÚčtu) jen doplatek; odečtená záloha je v `SumZaloha` se záporným znaménkem.
        $advance = round((float) ($r['SumZaloha'] ?? 0), 2);
        if ($moneyTotal !== null && $advance !== 0.0 && abs($moneyTotal + $advance - round($sumBase + $sumVat, 2)) < 0.005) {
            $moneyTotal = round($moneyTotal + $advance, 2);
        }

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

    /**
     * Nenulové sazby dokladu Money v pořadí `Zaklad_0` (mimo DPH), `Zaklad_1` … `Zaklad_6`.
     * Daň z Money (`DPH_n`, starší `DPHn`), když ji doklad nese, jinak dopočtená ze základu.
     *
     * @param array<string,mixed> $r
     * @return list<array{base:float,rate:float,vat:float}>
     */
    private static function rateLines(array $r): array
    {
        $slots = [['Zaklad_0', null, [], 0]];
        for ($i = 1; $i <= self::RATE_SLOTS; $i++) {
            $slots[] = ['Zaklad_' . $i, 'SazbaDPH' . $i, ['DPH_' . $i, 'DPH' . $i], $i];
        }
        $lines = [];
        foreach ($slots as [$baseField, $rateField, $vatFields, $slot]) {
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
            $lines[] = ['base' => $base, 'rate' => $rate, 'vat' => $vat ?? round($base * $rate / 100, 2)] + self::foreignLine($r, $slot);
        }
        return $lines;
    }

    /**
     * Základ a daň sazby v měně dokladu (`ValutyZakN`, `ValutyDPHN`), je-li doklad v cizí
     * měně - čte je jen převzetí dokladu v měně ({@see ForeignCurrencyTakeover}).
     *
     * @param array<string,mixed> $r
     * @return array{foreign_base?:float,foreign_vat?:float}
     */
    private static function foreignLine(array $r, int $slot): array
    {
        if (self::currency($r) === '' || !is_numeric($r['ValutyZak' . $slot] ?? null) || ($slot > 0 && !is_numeric($r['ValutyDPH' . $slot] ?? null))) {
            return [];
        }
        return [
            'foreign_base' => round((float) $r['ValutyZak' . $slot], 2),
            'foreign_vat' => $slot > 0 ? round((float) $r['ValutyDPH' . $slot], 2) : 0.0,
        ];
    }

    /** Cizí měna dokladu Money (`Mena`), prázdné = Kč. @param array<string,mixed> $r */
    private static function currency(array $r): string
    {
        $currency = strtoupper(trim((string) ($r['Mena'] ?? '')));
        return in_array($currency, ['', 'CZK', 'KČ', 'KC'], true) ? '' : $currency;
    }

    private function rateId(float $rate, string $date): int
    {
        return $this->rates->find($rate, $date)
            ?? throw new MoneyS3Exception('unknown_vat_rate', 'Sazba DPH ' . $rate . ' % není v číselníku sazeb.', ['rate' => $rate]);
    }

    /**
     * Doklad se sazbou DPH, kterou číselník firmy nezná, se nepřevede a ostatní doklady
     * kroku ano - jako u POHODY a PREMIER. Jiná výjimka letí dál.
     */
    private function skipUnknownRate(ImportContext $ctx, string $step, string $docNo, int $year, MoneyS3Exception $e): void
    {
        if ($e->errorCode !== 'unknown_vat_rate') {
            throw $e;
        }
        $rate = (float) ($e->context['rate'] ?? 0);
        $ctx->protocol->difference($step, 'unknown_vat_rate', sprintf(
            'Doklad %s (%d): sazba DPH %s %% není v číselníku sazeb firmy, nepřevzat. Založte ji v Nastavení → Číselníky → DPH sazby a převod zopakujte.',
            $docNo, $year, rtrim(rtrim(number_format($rate, 2, ',', ''), '0'), ','),
        ), ['document_no' => $docNo, 'year' => $year, 'rate' => $rate]);
    }

    /**
     * Položka vydaného dokladu z jedné sazby Money.
     *
     * Převedená faktura není OSS: Money S3 v záloze místo plnění pro OSS nedrží,
     * a kdyby šlo o OSS, podané přiznání za ten rok už je v Money. Je to ZÁMĚRNÁ
     * odlišnost od převodu z Pohody, kde členění mimo přiznání prochází
     * {@see OssMigrationPolicy} - Pohoda na rozdíl od Money nese na položce sazbu státu
     * spotřeby i měrnou jednotku, takže je z čeho rozhodovat. Sjednotit to jde teprve
     * tehdy, až půjde z Money zjistit totéž.
     *
     * @param array<string,mixed> $r
     */
    private static function issuedItem(array $r, float $base, float $vat, int $rateId, float $rate, ?string $code): MigratedDocumentItem
    {
        return MigratedDocumentItem::issued(
            trim((string) ($r['Popis'] ?? '')) ?: 'Převzato z Money S3',
            1.0, 'ks', $base, $rateId, $rate,
            $base, $vat, round($base + $vat, 2),
            $code,
            OssMigrationPolicy::DOMESTIC_COLUMNS,
        );
    }

    /** @param array{name:string,ico:string,dic:string,street:string,city:string,zip:string,country:string} $snapshot */
    private static function snapshotJson(array $snapshot): string
    {
        return (string) json_encode([
            'company_name' => $snapshot['name'], 'street' => $snapshot['street'], 'city' => $snapshot['city'],
            'zip' => $snapshot['zip'], 'ic' => $snapshot['ico'], 'dic' => $snapshot['dic'],
        ], JSON_UNESCAPED_UNICODE);
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
