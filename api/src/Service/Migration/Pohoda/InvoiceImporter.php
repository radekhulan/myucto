<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;
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
use MyInvoice\Repository\Section74bCorrectionRepository;
use MyInvoice\Service\Stats\StatsRecomputer;
use MyInvoice\Support\Sql\PayablePredicate;

/**
 * Vydané a přijaté doklady z agend faktur a daňové doklady k platbám z interních dokladů.
 *
 * | agenda Pohody                           | MyÚčto                                    |
 * |-----------------------------------------|-------------------------------------------|
 * | vydané faktury, vrubopisy               | `invoices` faktura                         |
 * | opravné daňové doklady, dobropisy       | `invoices` dobropis (záporné částky)       |
 * | vydané zálohové a proforma faktury      | `invoices` proforma (neúčtuje se)          |
 * | ostatní pohledávky v přiznání DPH       | `invoices` faktura, zápis se naváže        |
 * | interní doklad s tuzemským výstupem     | `invoices` daňový doklad k přijaté platbě  |
 * | přijaté faktury, vrubopisy              | `purchase_invoices` faktura                |
 * | přijaté opravné doklady, dobropisy      | `purchase_invoices` dobropis               |
 * | přijaté zálohové faktury                | `purchase_invoices` záloha (neúčtuje se)   |
 * | ostatní závazky v přiznání DPH          | `purchase_invoices` faktura                |
 * | interní doklad s tuzemským odpočtem     | `purchase_invoices` daňový doklad k záloze |
 *
 * Ostatní pohledávky a závazky mimo přiznání (odvody FÚ, mzdové závazky, půjčky…) zůstávají
 * jen v deníku - v MyÚčtu pro ně druh dokladu není a do DPH nevstupují.
 *
 * Položky se berou z rozpisu dokladu (text, množství, sazba), když rozpis sedí na
 * rekapitulaci; jinak z rekapitulace po sazbách. Ceny jsou vždy bez DPH.
 *
 * **Doklad v cizí měně** (`foreignCurrency`, v MDB `RefCM`/`CmKurs`/`CmMnoz` a na položkách
 * `Cm`/`CmDPH`) se převezme v měně a kurzu dokladu, když částky položek v měně kurzem dají
 * přesně Kč z POHODY ({@see ForeignCurrencyTakeover}); jinak v Kč s důvodem v poznámce.
 *
 * **Odpočet zálohy** (`invoiceAdvancePaymentItem`): zdaněná záloha (daňový doklad k platbě)
 * je záporná položka se sazbou a daní - v přiznání i v KH je za konečnou fakturu jen
 * rozdíl, přesně jak to podává Pohoda. Nezdaněná záloha je jen úhrada
 * (`advance_paid_amount`).
 *
 * **Doklad, jehož daňovou povahu z Pohody spolehlivě neznáme, se převezme jako koncept
 * k ruční kontrole.** Doklad minulého období (Pohoda přenáší neuhrazené doklady do nové
 * agendy) se převezme kvůli saldu a párování úhrad, v deníku roku zápis nemá.
 *
 * **Vydaný doklad s členěním mimo přiznání, který nese daň, je typicky plnění v režimu
 * OSS** (e-shop prodávající koncovým zákazníkům do EU: vlastní zkratka členění bez řádku
 * přiznání, sazba státu spotřeby, odběratel bez DIČ). Rozhoduje o tom {@see OssMigrationPolicy},
 * tedy táž autorita jako u ostatních vstupních kanálů; konceptem zůstane jen řádek, který
 * neprojde ani tudy.
 */
final class InvoiceImporter
{
    public const STEP_ISSUED = 'issued_invoices';
    public const STEP_PURCHASE = 'purchase_invoices';
    public const STEP_INTERNAL = 'internal_tax_documents';

    /** Rozdíl do 1 Kč mezi položkami a celkem dokladu je zaokrouhlení dokladu. */
    private const ROUNDING_LIMIT = 1.0;

    /** Limit KH pro jednotlivý doklad (A.4/B.2), vč. DPH. */
    private const KH_LIMIT = 10000.0;

    /**
     * Ostatní pohledávka či závazek POHODY v přiznání DPH vstupuje do evidence DPH a KH jen
     * jako faktura; agenda Ostatní pohledávky a závazky řádky DPH nevede. Poznámka dokladu
     * vysvětlí, proč je mezi fakturami.
     */
    private const OTHER_ITEM_IN_VAT_NOTE = [
        'receivable' => 'v POHODĚ ostatní pohledávka; v přiznání DPH, proto převedena jako vydaná faktura',
        'commitment' => 'v POHODĚ ostatní závazek; v přiznání DPH, proto převeden jako přijatá faktura',
    ];

    private const ISSUED_AGENDAS = [
        'issued' => 'invoice',
        'issued_debit' => 'invoice',
        'issued_credit' => 'credit_note',
        'issued_corrective' => 'credit_note',
        'issued_advance' => 'proforma',
        'issued_proforma' => 'proforma',
        'receivable' => 'invoice',
    ];

    private const PURCHASE_AGENDAS = [
        'received' => 'invoice',
        'received_debit' => 'invoice',
        'received_credit' => 'credit_note',
        'received_corrective' => 'credit_note',
        'received_advance' => 'advance',
        'received_proforma' => 'advance',
        'commitment' => 'invoice',
    ];

    /** `typ:paymentType` Pohody → způsob úhrady MyÚčta. */
    private const PAYMENT_TYPES = [
        '' => 'bank_transfer',
        'draft' => 'bank_transfer',
        'cash' => 'cash',
        'creditcard' => 'card',
        'delivery' => 'cash_on_delivery',
        'encashment' => 'direct_debit',
        'compensation' => 'offset',
    ];

    /** Přihrádky rekapitulace a jejich výchozí sazba, když export `@rate` nenese. */
    private const BUCKETS = ['Low' => 12.0, 'High' => 21.0, '3' => 10.0];

    /** Kód zařazení tuzemského odpočtu (ř. 40 / 41) pro položku na dokladu se samovyměřením. */
    private const DOMESTIC_DEDUCTION_BASE = '40';
    private const DOMESTIC_DEDUCTION_REDUCED = '41';

    private readonly MigrationVatRateLookup $rates;

    private readonly ForeignCurrencyTakeover $foreignCurrency;

    /** @var array<string,\PDOStatement> */
    private array $stmts = [];

    public function __construct(
        private readonly Connection $db,
        private readonly PohodaImportRepository $map,
        private readonly PartnerImporter $partners,
        private readonly StatsRecomputer $stats,
        private readonly OssMigrationPolicy $oss,
        private readonly MigratedDocumentWriter $writer,
        private readonly MigrationHomeCurrency $homeCurrency,
        private readonly Section74bCorrectionRepository $section74b,
    ) {
        $this->rates = new MigrationVatRateLookup($db);
        $this->foreignCurrency = new ForeignCurrencyTakeover($db, createMissingCurrency: true);
    }

    public function importIssued(PohodaContext $ctx): void
    {
        $existing = $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_INVOICE);
        foreach (self::ISSUED_AGENDAS as $agenda => $kind) {
            foreach ($ctx->export->records($agenda, 'invoice') as $r) {
                $this->importIssuedDocument($ctx, self::STEP_ISSUED, $r, $agenda, $kind, 'invoice', $existing);
            }
        }
        $ctx->protocol->finish(self::STEP_ISSUED);
    }

    public function importPurchases(PohodaContext $ctx): void
    {
        $existing = $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_PURCHASE_INVOICE);
        $selfAssessed = $this->selfAssessments($ctx);
        $used = [];
        foreach (self::PURCHASE_AGENDAS as $agenda => $kind) {
            foreach ($ctx->export->records($agenda, 'invoice') as $r) {
                $this->importPurchaseDocument($ctx, self::STEP_PURCHASE, $r, $agenda, $kind, 'invoice', $existing, $selfAssessed, $used);
            }
        }
        $unlinked = array_values(array_diff(array_keys($selfAssessed), array_keys($used)));
        if ($unlinked !== []) {
            $ctx->protocol->warn(self::STEP_PURCHASE, 'self_assessment_unlinked', count($unlinked) . ' interních dokladů s vyměřením DPH nejde přiřadit k převedené faktuře (' . implode(', ', array_slice($unlinked, 0, 20)) . '). Jejich DPH doplňte ručně.', ['documents' => $unlinked]);
        }
        $ctx->protocol->finish(self::STEP_PURCHASE);
    }

    /**
     * Daňové doklady k přijaté platbě (tuzemský výstup, ř. 1/2) a k uhrazené záloze
     * (tuzemský odpočet, ř. 40/41), které Pohoda vede jako interní doklady. Do přiznání
     * vstupují samostatně v měsíci platby a konečná faktura je pak odečítá položkou
     * „odpočet zálohy". Převádějí se jen doklady roku agendy - starší jsou v přiznáních
     * minulých let. Interní doklady se samovyměřením řeší přijatá faktura, ostatní
     * (mimo přiznání) zůstávají jen v deníku.
     */
    public function importInternalTaxDocuments(PohodaContext $ctx): void
    {
        $p = $ctx->protocol;
        $existingSales = $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_INVOICE);
        $existingPurchases = $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_PURCHASE_INVOICE);
        $none = [];
        foreach ($ctx->export->records('internal', 'intDoc') as $r) {
            $h = PohodaXml::get($r, 'intDocHeader');
            $code = PohodaXml::text($h, 'classificationVAT/ids');
            if ($code === '' || $ctx->vat->selfAssessmentCode($code) !== null) {
                continue;
            }
            if ($ctx->vat->isDebtorDeductionCorrection($code)) {
                $this->importDebtorCorrection($ctx, $r);
                continue;
            }
            $sale = $ctx->vat->sale($code, 21.0);
            $purchase = $ctx->vat->purchase($code);
            $isSale = $sale !== null && $sale['in_return'] && $sale['code'] === null;
            $isPurchase = !$isSale && $purchase !== null && $purchase['in_return'] && !$purchase['reverse'];
            if (!$isSale && !$isPurchase) {
                $p->count(self::STEP_INTERNAL, 'journal_only');
                continue;
            }
            $taxDate = PohodaXml::date($h, 'dateTax') ?? PohodaXml::date($h, 'date');
            // Doklady účetního období agendy (u hospodářského roku přes přelom kalendářního roku).
            if ($taxDate === null || $ctx->calendar->fiscalYearOfDate($taxDate) !== $ctx->year()) {
                $p->count(self::STEP_INTERNAL, 'previous_years');
                continue;
            }
            if ($isSale) {
                $this->importIssuedDocument($ctx, self::STEP_INTERNAL, $r, 'internal', 'tax_document', 'intDoc', $existingSales);
            } else {
                $this->importPurchaseDocument($ctx, self::STEP_INTERNAL, $r, 'internal', 'tax_document', 'intDoc', $existingPurchases, $none, $none);
            }
        }
        $p->finish(self::STEP_INTERNAL);
    }

    /**
     * Oprava odpočtu dlužníka (§ 74b), kterou POHODA vede interním dokladem: kladná daň vrací
     * uplatněný odpočet, záporná ho po úhradě znovu uplatňuje. Do přiznání (ř. 34, 40, 41)
     * a KH (B.2, `P`) jde přes evidenci § 74b v měsíci dokladu, zápis v deníku nese doklad
     * sám. Číslo opravovaného dokladu POHODA uvádí jen v textu.
     *
     * @param array<string,mixed> $r
     */
    private function importDebtorCorrection(PohodaContext $ctx, array $r): void
    {
        $p = $ctx->protocol;
        $h = PohodaXml::get($r, 'intDocHeader');
        $number = PohodaXml::text($h, 'number/numberRequested');
        $date = PohodaXml::date($h, 'dateAccounting') ?? PohodaXml::date($h, 'date');
        if ($number === '' || $date === null || $ctx->skipsDate($date)) {
            return;
        }
        $key = 'internal|' . $number . '|' . $date;
        if ($this->map->get($ctx->supplierId, PohodaImportRepository::KIND_S74B_CORRECTION, $key) !== null) {
            $p->count(self::STEP_INTERNAL, 'existing');
            return;
        }
        if ($ctx->skipsNew(self::STEP_INTERNAL)) {
            return;
        }
        $summary = PohodaXml::get($r, 'intDocSummary/homeCurrency');
        $vat = round(PohodaXml::num($summary, 'priceHighVAT') + PohodaXml::num($summary, 'priceLowVAT') + PohodaXml::num($summary, 'price3VAT'), 2);
        $text = PohodaXml::text($h, 'text');
        $target = preg_match('/dokladu\s+([^\s,]+)/u', $text, $m) === 1 ? $m[1] : '';
        $invoiceId = $ctx->purchaseInvoices[$target] ?? $ctx->commitments[$target] ?? null;
        if ($invoiceId === null || abs($vat) < 0.005) {
            $p->count(self::STEP_INTERNAL, 's74b_review');
            $p->warn(self::STEP_INTERNAL, 's74b_review', "Oprava odpočtu podle § 74b {$number} se nepřevedla: opravovaný doklad {$target} v převodu není, nebo doklad nenese daň. Zápis zůstává v deníku, opravu zadejte ručně.", ['document_no' => $number]);
            return;
        }
        $claimed = $this->db->pdo()->prepare('SELECT total_vat FROM purchase_invoices WHERE id = ? AND supplier_id = ?');
        $claimed->execute([$invoiceId, $ctx->supplierId]);
        $claimedVat = (float) $claimed->fetchColumn();
        if ($claimedVat <= 0.0 || abs($vat) - $claimedVat >= 0.005) {
            $p->count(self::STEP_INTERNAL, 's74b_review');
            $p->warn(self::STEP_INTERNAL, 's74b_review', "Oprava odpočtu podle § 74b {$number}: daň {$vat} Kč neodpovídá dani dokladu {$target} ({$claimedVat} Kč). Zápis zůstává v deníku, opravu zadejte ručně.", ['document_no' => $number]);
            return;
        }
        $reduction = $vat > 0;
        $id = $this->section74b->recordMovement(
            $ctx->supplierId, $invoiceId, (int) substr($date, 0, 4), (int) substr($date, 5, 2),
            $reduction ? 'reduction' : 'restoration', abs($vat), $claimedVat, abs($vat) / $claimedVat,
            $reduction ? 'corrected' : 'restored', mb_substr('Převzato z POHODY, doklad ' . $number, 0, 255), $ctx->userOrNull(),
        );
        $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_S74B_CORRECTION, $key, $id, $ctx->runId);
        $p->count(self::STEP_INTERNAL, 's74b_corrections');
    }

    /**
     * Seznam klientů čte cache statistik - převedené faktury by v něm bez přepočtu chyběly.
     * Běží mimo transakci kroku, zkouška nanečisto ho vynechá.
     */
    public function recomputeClientStats(PohodaContext $ctx): void
    {
        if ($ctx->statsClients === [] || $this->db->pdo()->inTransaction()) {
            return;
        }
        $this->stats->recomputeMany(array_values(array_unique($ctx->statsClients)));
    }

    /** @param array<string,int> $existing */
    private function importIssuedDocument(PohodaContext $ctx, string $step, array $r, string $agenda, string $kind, string $prefix, array $existing): void
    {
        $p = $ctx->protocol;
        $bucket = match ($agenda) { 'receivable' => 'receivable', 'internal' => 'internal_sale', default => 'issued' };
        $doc = self::withoutSkippedPayments($ctx, $this->header($r, $prefix), $r);
        if ($doc['number'] === '' || $doc['issue'] === null) {
            $p->warn($step, 'missing_number_or_date', "Doklad {$doc['number']} ({$agenda}) nemá číslo nebo datum, nepřevzat.");
            return;
        }
        $key = $agenda . '|' . $doc['number'] . '|' . $doc['issue'];
        if (isset($existing[$key])) {
            $this->remember($ctx, $bucket, $doc['number'], $existing[$key]);
            self::rememberRemaining($ctx, 'invoice', $existing[$key], $doc);
            $this->captureLiquidations($ctx, 'invoice', $existing[$key], $doc['number'], $r);
            // Vazby na deník se při opakovaném běhu hodnotí znovu: doklad minulého období
            // zápis v deníku roku nemá a nesmí se napodruhé hlásit jako nezaúčtovaný.
            if ($this->isPreviousPeriod($ctx, $doc)) {
                $ctx->previousPeriod['invoice|' . $existing[$key]] = true;
            }
            $p->count($step, 'existing');
            $this->reportChanged($ctx, $step, 'invoices', $existing[$key], $doc['number'], $r, $prefix);
            $this->refreshSettlement($ctx, $step, 'invoices', $existing[$key], $doc);
            return;
        }
        if ($ctx->skipsNew($step)) {
            return;
        }
        if ($ctx->skipsDate($doc['accounting'])) {
            $p->count($step, 'later_year_skipped');
            return;
        }
        $amounts = $this->amounts($ctx, $step, $doc, $r, $prefix);
        $class = $this->classifyIssued($ctx, $kind, $doc['vat_class'], $amounts);
        if (($agenda === 'receivable' || $agenda === 'internal') && !$class['in_return']) {
            // Pohledávka mimo přiznání (odvod, půjčka…) zůstává jen v deníku.
            $p->count($step, 'journal_only');
            return;
        }
        $type = $kind === 'invoice' && $amounts['gross_total'] < 0 ? 'credit_note' : $kind;
        $previous = $this->isPreviousPeriod($ctx, $doc);
        $number = $this->freeNumber('invoices', $ctx->supplierId, $doc['number'], (int) substr($doc['issue'], 0, 4));
        if ($number === null) {
            $p->warn($step, 'number_taken', "Číslo dokladu {$doc['number']} už ve firmě má jiný doklad, nepřevzat.", ['document_no' => $doc['number']]);
            return;
        }
        $snapshot = PartnerImporter::snapshot(PohodaXml::get($doc['h'], 'partnerIdentity'));
        $clientId = $this->partners->resolvePartner($ctx, $snapshot);
        if (!$this->rateIssuedItems($ctx, $step, $doc, $amounts, $class, $clientId, $snapshot)) {
            return;
        }
        $review = $class['reasons'] !== [];
        $notes = $bucket === 'receivable' ? [self::OTHER_ITEM_IN_VAT_NOTE['receivable']] : [];
        if ($snapshot['dic'] !== '' && $ctx->vat->forcesA5($doc['vat_class']) && abs($amounts['total']) > self::KH_LIMIT) {
            // Pohoda doklad vykázala v KH A.5 (odběratel bez DIČ plátce) - MyÚčto rozhoduje
            // podle DIČ ve snapshotu dokladu, proto se tam nepřebírá. Na kartě klienta zůstává.
            $notes[] = "členění {$doc['vat_class']}: v KH vykázáno v A.5, DIČ {$snapshot['dic']} ve snapshotu dokladu vynecháno";
            $p->count($step, 'kh_a5_forced');
            $p->info($step, 'kh_a5_forced', "Doklad {$doc['number']} ({$snapshot['name']}, DIČ {$snapshot['dic']}) má v Pohodě členění {$doc['vat_class']}, které ho podle číselníku členění DPH řadí do oddílu A.5 kontrolního hlášení, přestože je nad 10 000 Kč a odběratel má DIČ. Ověřte, že odběratel není plátce DPH.", ['document_no' => $doc['number']]);
            $snapshot['dic'] = '';
        }
        $pay = $type === 'tax_document'
            ? ['paid' => $amounts['total'], 'settled' => true, 'paid_at' => $doc['tax'] ?? $doc['issue']]
            : self::payment($doc, $amounts);
        $status = $review ? 'draft' : ($pay['settled'] ? 'paid' : 'sent');
        $booked = !$review && $type !== 'proforma' && !self::postedLater($doc);
        if ($doc['foreign'] !== '') {
            $amounts['items'] = ForeignCurrencyTakeover::withDerivedAmounts($amounts['items'], $doc['foreign_rate'], $doc['foreign_units'], $doc['foreign_total']);
        }
        $fx = $this->foreignCurrency->decide($ctx->supplierId, $doc['foreign'], $doc['foreign_rate'], $doc['foreign_units'], $amounts['items'], $amounts['total'],
            ForeignCurrencyTakeover::paymentBlock($amounts['advance'], $pay['paid'], round($amounts['total'] - $amounts['advance'], 2)));
        if ($fx->inForeignCurrency()) {
            $amounts = self::inForeignCurrency($amounts);
            $pay['paid'] = ForeignCurrencyTakeover::paidInForeignCurrency($pay['paid'], $amounts['total']);
        }
        try {
            $id = $this->writer->insertIssued(new MigratedIssuedDocument(
                supplierId: $ctx->supplierId,
                invoiceType: $type,
                clientId: $clientId,
                varsymbol: $number['number'],
                issueDate: $doc['issue'],
                taxDate: $type === 'proforma' ? $doc['tax'] : ($doc['tax'] ?? $doc['issue']),
                dueDate: $doc['due'],
                // Doklad v cizí měně v ní, když částky v měně kurzem dají Kč z Pohody ({@see ForeignCurrencyTakeover}).
                currencyId: $fx->currencyId ?? $this->homeCurrency->id($ctx->supplierId),
                exchangeRate: $fx->rate,
                // Položky i rekapitulace Pohody jsou bez DPH.
                pricesIncludeVat: false,
                // Tuzemské přenesení daňové povinnosti (ř. 25) nese kód zařazení i příznak hlavičky.
                reverseCharge: VatReturnLineClassifier::isDomesticReverseSale([$class['code'], ...array_column($amounts['items'], 'code')]),
                noteAboveItems: $doc['text'] !== '' ? mb_substr($doc['text'], 0, 1000) : null,
                noteBelowItems: self::note($doc['number'], $class['reasons'], $previous, $fx, $notes),
                clientSnapshot: PartnerImporter::snapshotJson($snapshot),
                totalWithoutVat: $amounts['base'],
                totalVat: $amounts['vat'],
                totalWithVat: $amounts['total'],
                rounding: $amounts['rounding'],
                status: $status,
                createdBy: $ctx->userOrNull(),
                // Platební VS z Pohody, liší-li se od čísla dokladu (#249).
                paymentVariableSymbol: preg_match('/^\d{1,10}$/', $doc['symvar']) === 1
                    && $doc['symvar'] !== VariableSymbolNormalizer::forPayment((string) $number['number'])
                    ? $doc['symvar'] : null,
                advancePaidAmount: $amounts['advance'],
                paidTotal: $pay['paid'],
                paidAt: $pay['settled'] ? $pay['paid_at'] : null,
                bookedAt: $booked ? $doc['accounting'] . ' 00:00:00' : null,
                bookedBy: $booked ? $ctx->userOrNull() : null,
                vatClassificationCode: $class['code'],
                paymentMethod: $doc['payment'],
            ));
        } catch (\PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            $p->warn($step, 'insert_conflict', "Doklad {$doc['number']} koliduje s existujícím dokladem firmy (stejné číslo nebo variabilní symbol), nepřevzat.", ['document_no' => $doc['number']]);
            return;
        }
        $items = [];
        foreach ($amounts['items'] as $i => $item) {
            $items[$i] = MigratedDocumentItem::issued(
                $item['description'], $item['quantity'], $item['unit'] ?? 'ks', $item['unit_price'],
                $item['rate_id'], $item['rate'], $item['base'], $item['vat'], round($item['base'] + $item['vat'], 2),
                $item['code'] ?? $class['code'],
                $item['oss'] ?? OssMigrationPolicy::DOMESTIC_COLUMNS,
            );
        }
        $this->writer->insertIssuedItems($id, $items);
        ForeignCurrencyTakeover::report($p, $step, $doc['number'], $fx);
        $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_INVOICE, $key, $id, $ctx->runId);
        $this->remember($ctx, $bucket, $doc['number'], $id);
        self::rememberRemaining($ctx, 'invoice', $id, $doc);
        $this->captureLiquidations($ctx, 'invoice', $id, $doc['number'], $r);
        if ($previous) {
            $ctx->previousPeriod['invoice|' . $id] = true;
            $p->count($step, 'previous_period');
        }
        $ctx->statsClients[] = $clientId;
        $p->count($step, match ($bucket) { 'receivable' => 'receivables', 'internal_sale' => 'tax_documents_sale', default => 'created' });
        $p->count($step, 'items_' . $amounts['source'], count($amounts['items']));
        if ($amounts['advance_items'] > 0) {
            $p->count($step, 'advance_deductions_with_vat', $amounts['advance_items']);
        }
        $this->reportNumberAndReview($ctx, $step, $doc['number'], $number, $class['reasons']);
    }

    /**
     * @param array<string,int> $existing
     * @param array<string,array<string,mixed>> $selfAssessed
     * @param array<string,true> $used
     */
    private function importPurchaseDocument(PohodaContext $ctx, string $step, array $r, string $agenda, string $kind, string $prefix, array $existing, array $selfAssessed, array &$used): void
    {
        $p = $ctx->protocol;
        $bucket = match ($agenda) { 'commitment' => 'commitment', 'internal' => 'internal_purchase', default => 'purchase' };
        $doc = self::withoutSkippedPayments($ctx, $this->header($r, $prefix), $r);
        if ($doc['number'] === '' || $doc['issue'] === null) {
            $p->warn($step, 'missing_number_or_date', "Doklad {$doc['number']} ({$agenda}) nemá číslo nebo datum, nepřevzat.");
            return;
        }
        $key = $agenda . '|' . $doc['number'] . '|' . $doc['issue'];
        if (isset($existing[$key])) {
            $this->remember($ctx, $bucket, $doc['number'], $existing[$key]);
            self::rememberRemaining($ctx, 'purchase_invoice', $existing[$key], $doc);
            $this->captureLiquidations($ctx, 'purchase_invoice', $existing[$key], $doc['number'], $r);
            if ($this->isPreviousPeriod($ctx, $doc)) {
                $ctx->previousPeriod['purchase_invoice|' . $existing[$key]] = true;
            }
            // Samovyměření už převedené faktury je přiřazené - jinak by ho opakovaný běh
            // hlásil jako interní doklad bez faktury.
            if (isset($selfAssessed[$doc['number']])) {
                $used[$doc['number']] = true;
            }
            $p->count($step, 'existing');
            $this->reportChanged($ctx, $step, 'purchase_invoices', $existing[$key], $doc['number'], $r, $prefix);
            $this->refreshSettlement($ctx, $step, 'purchase_invoices', $existing[$key], $doc);
            return;
        }
        if ($ctx->skipsNew($step)) {
            return;
        }
        if ($ctx->skipsDate($doc['accounting'])) {
            $p->count($step, 'later_year_skipped');
            return;
        }
        $amounts = $this->amounts($ctx, $step, $doc, $r, $prefix);
        $class = $this->classifyPurchase($ctx, $kind, $doc['vat_class'], $amounts);
        if (($agenda === 'commitment' || $agenda === 'internal') && !$class['in_return']) {
            $p->count($step, 'journal_only');
            return;
        }
        if (!$class['reverse'] && $kind !== 'advance' && $class['reasons'] === [] && isset($selfAssessed[$doc['number']])) {
            $class = self::selfAssessedByInternalDocuments($class, $selfAssessed[$doc['number']], $amounts);
        }
        if ($class['reverse']) {
            $sa = $selfAssessed[$doc['number']] ?? null;
            if ($sa === null) {
                $class['reasons'][] = 'odpočet ze samovyměření bez interního dokladu s vyměřením daně';
            } elseif ($sa['error'] !== null) {
                $class['reasons'][] = 'samovyměření z interního dokladu ' . $sa['doc'] . ': ' . $sa['error'];
            } else {
                $used[$doc['number']] = true;
                $amounts = $this->applySelfAssessment($sa, $amounts, $doc['tax'] ?? $doc['issue']);
                $doc['tax'] = $sa['date'] ?? $doc['tax'];
                $p->count($step, 'self_assessed');
            }
        }
        // Přijatá strana OSS nezná (režim je pro plnění, která poskytujeme), takže se sazba
        // páruje tuzemsky - jen až tady, protože `amounts()` ji kvůli vydané větvi nechává
        // nenapárovanou. Nenalezená sazba je pořád tvrdá chyba celého běhu jako dřív.
        foreach ($amounts['items'] as $i => $item) {
            $amounts['items'][$i]['rate_id'] ??= $this->rateId((float) $item['rate'], $doc['tax'] ?? $doc['issue']);
        }
        $type = $kind === 'invoice' && $amounts['gross_total'] < 0 ? 'credit_note' : $kind;
        $review = $class['reasons'] !== [];
        $previous = $this->isPreviousPeriod($ctx, $doc);
        $number = $this->freeNumber('purchase_invoices', $ctx->supplierId, $doc['number'], (int) substr($doc['issue'], 0, 4));
        if ($number === null) {
            $p->warn($step, 'number_taken', "Číslo dokladu {$doc['number']} už ve firmě má jiný doklad, nepřevzat.", ['document_no' => $doc['number']]);
            return;
        }
        $snapshot = PartnerImporter::snapshot(PohodaXml::get($doc['h'], 'partnerIdentity'));
        $vendorId = $this->partners->resolvePartner($ctx, $snapshot);
        $vendorNumber = mb_substr(self::vendorDocumentNumber($doc), 0, 50);
        $duplicate = $this->stmt('purchase_duplicate', 'SELECT 1 FROM purchase_invoices WHERE supplier_id = ? AND vendor_id = ? AND vendor_invoice_number = ? AND issue_date = ? LIMIT 1');
        $duplicate->execute([$ctx->supplierId, $vendorId, $vendorNumber, $doc['issue']]);
        if ($duplicate->fetchColumn() !== false) {
            $vendorNumber = mb_substr($vendorNumber . ' (' . $doc['number'] . ')', 0, 50);
        }
        $pay = $type === 'tax_document'
            ? ['paid' => $amounts['total'], 'settled' => true, 'paid_at' => $doc['tax'] ?? $doc['issue']]
            : self::payment($doc, $amounts);
        $unbooked = $review || $type === 'advance' || self::postedLater($doc);
        $status = $review ? 'draft' : ($pay['settled'] ? 'paid' : ($unbooked ? 'received' : 'booked'));
        // Datum pro KH je v Pohodě den, ke kterému účetní odpočet uplatnila - MyÚčto
        // ho respektuje jen jako ručně zadané datum přijetí (§ 73).
        $claim = $doc['claim'];
        $assets = array_map(static fn (array $item): bool => MigratedDocumentItem::fixedAssetLine($class['fixed_asset'], (float) $item['rate'], (float) $item['vat'],
            ($item['code'] ?? null) === VatReturnLineClassifier::PURCHASE_OUTSIDE_SCOPE_CODE ? null : ($item['code'] ?? null)), $amounts['items']);
        if ($doc['foreign'] !== '') {
            $amounts['items'] = ForeignCurrencyTakeover::withDerivedAmounts($amounts['items'], $doc['foreign_rate'], $doc['foreign_units'], $doc['foreign_total']);
        }
        // Přijatý doklad úhradu v částce nevede (jen stav), částečná úhrada ho proto neblokuje.
        $fx = $this->foreignCurrency->decide($ctx->supplierId, $doc['foreign'], $doc['foreign_rate'], $doc['foreign_units'], $amounts['items'], $amounts['total'],
            ForeignCurrencyTakeover::purchaseBlock($class['reverse'], $class['deduction']) ?? ForeignCurrencyTakeover::paymentBlock($amounts['advance'], 0.0, 0.0));
        if ($fx->inForeignCurrency()) {
            $amounts = self::inForeignCurrency($amounts);
        }
        try {
            $id = $this->writer->insertPurchase(new MigratedPurchaseDocument(
                supplierId: $ctx->supplierId,
                vendorId: $vendorId,
                vendorIsVatPayer: $snapshot['dic'] !== '',
                varsymbol: $number['number'],
                vendorInvoiceNumber: $vendorNumber,
                documentKind: $type,
                issueDate: $doc['issue'],
                taxDate: $doc['tax'] ?? $doc['issue'],
                dueDate: $doc['due'],
                receivedAt: $claim ?? ($doc['tax'] ?? $doc['issue']),
                receivedAtSource: $claim !== null ? 'manual' : 'import',
                currencyId: $fx->currencyId ?? $this->homeCurrency->id($ctx->supplierId),
                exchangeRate: $fx->rate,
                // Položky i rekapitulace Pohody jsou bez DPH.
                pricesIncludeVat: false,
                // Samovyměření z interního dokladu (classifyPurchase()).
                reverseCharge: $class['reverse'],
                vendorSnapshot: PartnerImporter::snapshotJson($snapshot),
                totalWithoutVat: $amounts['base'],
                totalVat: $amounts['vat'],
                totalWithVat: $amounts['total'],
                rounding: $amounts['rounding'],
                status: $status,
                vatDeduction: $class['deduction'],
                noteAboveItems: $doc['text'] !== '' ? mb_substr($doc['text'], 0, 1000) : null,
                noteBelowItems: self::note($doc['number'], $class['reasons'], $previous, $fx, $bucket === 'commitment' ? [self::OTHER_ITEM_IN_VAT_NOTE['commitment']] : []),
                createdBy: $ctx->userId,
                advancePaidAmount: $amounts['advance'],
                paymentVariableSymbol: preg_match('/^\d{1,10}$/', $doc['symvar']) === 1 ? $doc['symvar'] : null,
                paymentConstantSymbol: preg_match('/^\d{1,4}$/', $doc['symconst']) === 1 ? $doc['symconst'] : null,
                paymentAccountNumber: $doc['account_no'] !== '' ? mb_substr($doc['account_no'], 0, 34) : null,
                paymentBankCode: $doc['bank_code'] !== '' ? mb_substr($doc['bank_code'], 0, 10) : null,
                paymentMethod: $doc['payment'],
                paidAt: $pay['settled'] ? $pay['paid_at'] : null,
                bookedAt: $unbooked ? null : $doc['accounting'] . ' 00:00:00',
                bookedBy: $unbooked ? null : $ctx->userOrNull(),
                vatClassificationCode: null,
                isFixedAsset: MigratedDocumentItem::wholeDocumentFixedAsset($assets),
            ));
        } catch (\PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            $p->warn($step, 'insert_conflict', "Doklad {$doc['number']} koliduje s existujícím dokladem firmy (stejné číslo nebo variabilní symbol), nepřevzat.", ['document_no' => $doc['number']]);
            return;
        }
        $items = [];
        foreach ($amounts['items'] as $i => $item) {
            $items[$i] = MigratedDocumentItem::purchase(
                $item['description'], $item['quantity'], $item['unit'] ?? 'ks', $item['unit_price'],
                $item['rate_id'], $item['rate'], $item['base'], $item['vat'], round($item['base'] + $item['vat'], 2),
                $item['code'] ?? null,
                $assets[$i],
            );
        }
        $this->writer->insertPurchaseItems($id, $items);
        $this->claimPeriod($ctx, $step, $doc, $type);
        ForeignCurrencyTakeover::report($p, $step, $doc['number'], $fx);
        $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_PURCHASE_INVOICE, $key, $id, $ctx->runId);
        $this->remember($ctx, $bucket, $doc['number'], $id);
        self::rememberRemaining($ctx, 'purchase_invoice', $id, $doc);
        $this->captureLiquidations($ctx, 'purchase_invoice', $id, $doc['number'], $r);
        if ($previous) {
            $ctx->previousPeriod['purchase_invoice|' . $id] = true;
            $p->count($step, 'previous_period');
        }
        $p->count($step, match ($bucket) { 'commitment' => 'commitments', 'internal_purchase' => 'tax_documents_purchase', default => 'created' });
        $p->count($step, 'items_' . $amounts['source'], count($amounts['items']));
        if ($amounts['advance_items'] > 0) {
            $p->count($step, 'advance_deductions_with_vat', $amounts['advance_items']);
        }
        $this->reportNumberAndReview($ctx, $step, $doc['number'], $number, $class['reasons']);
    }

    /**
     * Předkontace „Nevím" (zaúčtuje se později): POHODA doklad do deníku nezapsala, takže
     * převzatý doklad zaúčtovaný není a dostupné musí zůstat jeho zaúčtování. Jiné doklady
     * bez zápisu se stejným číslem (předkontace „Bez", mzdové závazky, nulové doklady,
     * zápis pod jiným číslem) se tím nemění, příznak zaúčtování u nich zůstává.
     */
    private static function postedLater(array $doc): bool
    {
        return mb_strtolower(trim(PohodaXml::text($doc['h'], 'accounting/ids'))) === 'nevím';
    }

    /** @return array<string,mixed> */
    private function header(array $r, string $prefix): array
    {
        $h = PohodaXml::get($r, $prefix . 'Header') ?? [];
        $issue = PohodaXml::date($h, 'date') ?? PohodaXml::date($h, 'dateAccounting');
        return [
            'h' => $h,
            'number' => PohodaXml::text($h, 'number/numberRequested'),
            'issue' => $issue,
            'tax' => PohodaXml::date($h, 'dateTax'),
            'accounting' => PohodaXml::date($h, 'dateAccounting') ?? $issue,
            'due' => PohodaXml::date($h, 'dateDue') ?? $issue,
            'claim' => PohodaXml::date($h, 'dateKHDPH'),
            'vat_class' => PohodaXml::text($h, 'classificationVAT/ids'),
            'text' => PohodaXml::text($h, 'text'),
            'symvar' => PohodaXml::text($h, 'symVar'),
            'symconst' => PohodaXml::text($h, 'symConst'),
            'original' => PohodaXml::text($h, 'originalDocument'),
            // Číslo dokladu pro kontrolní hlášení (evidenční číslo dokladu dodavatele). POHODA
            // ho vede zvlášť hlavně u interních daňových dokladů k platbám, kde číslo
            // dokladu i variabilní symbol jsou vlastní.
            'kh_number' => PohodaXml::text($h, 'numberKHDPH'),
            'account_no' => PohodaXml::text($h, 'paymentAccount/accountNo'),
            'bank_code' => PohodaXml::text($h, 'paymentAccount/bankCode'),
            'payment' => self::PAYMENT_TYPES[PohodaXml::text($h, 'paymentType/paymentType')] ?? 'other',
            'remaining' => PohodaXml::text($h, 'liquidation/amountHome'),
            'paid_date' => PohodaXml::date($h, 'liquidation/date'),
            'foreign' => PohodaXml::text($r, $prefix . 'Summary/foreignCurrency/currency/ids'),
            // Kurz dokladu za `amount` jednotek měny (MDB: `CmKurs` za `CmMnoz`).
            'foreign_rate' => PohodaXml::num($r, $prefix . 'Summary/foreignCurrency/rate'),
            'foreign_units' => PohodaXml::num($r, $prefix . 'Summary/foreignCurrency/amount') ?: 1.0,
            'foreign_total' => PohodaXml::num($r, $prefix . 'Summary/foreignCurrency/priceSum'),
            // Stát spotřeby dokladu v režimu OSS; POHODA element vynechává, není-li doklad v OSS.
            'moss' => strtoupper(PohodaXml::text($h, 'MOSS/ids')),
        ];
    }

    /**
     * Číslo dokladu dodavatele, které jde do KH jako evidenční číslo (`c_evid_dd`):
     * číslo pro KH, jinak původní doklad, jinak variabilní symbol, jinak číslo POHODY.
     *
     * @param array<string,mixed> $doc
     */
    public static function vendorDocumentNumber(array $doc): string
    {
        foreach (['kh_number', 'original', 'symvar', 'number'] as $key) {
            $value = trim((string) ($doc[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    /**
     * Položky a součty dokladu v Kč.
     *
     * @param array<string,mixed> $doc
     * @return array{items:list<array<string,mixed>>,base:float,vat:float,total:float,gross_total:float,rounding:float,advance:float,advance_items:int,source:string}
     */
    private function amounts(PohodaContext $ctx, string $step, array $doc, array $r, string $prefix): array
    {
        $summary = PohodaXml::get($r, $prefix . 'Summary/homeCurrency');
        $rates = ['none' => 0.0];
        $buckets = [];
        $none = round(PohodaXml::num($summary, 'priceNone'), 2);
        foreach (self::BUCKETS as $bucket => $default) {
            $rate = PohodaXml::attr($summary, 'price' . $bucket . 'VAT', 'rate');
            $rate = is_numeric($rate) ? (float) $rate : $default;
            $rates[match ($bucket) { 'Low' => 'low', 'High' => 'high', default => 'third' }] = $rate;
            $base = round(PohodaXml::num($summary, 'price' . $bucket), 2);
            $vat = round(PohodaXml::num($summary, 'price' . $bucket . 'VAT'), 2);
            if ($base !== 0.0 || $vat !== 0.0) {
                $buckets[] = ['base' => $base, 'vat' => $vat, 'rate' => $rate];
            }
        }
        $docRound = round(PohodaXml::num($summary, 'round/priceRound'), 2);
        $taxedSum = round(array_sum(array_map(static fn (array $b): float => $b['base'] + $b['vat'], $buckets)), 2);
        $docTotal = round($none + $taxedSum + $docRound, 2);
        $itemRate = static function (mixed $it) use ($rates): float {
            $value = PohodaXml::attr($it, 'rateVAT', 'value');
            return is_numeric($value) ? (float) $value : ($rates[PohodaXml::text($it, 'rateVAT')] ?? 0.0);
        };

        $items = [];
        foreach (PohodaXml::all($r, $prefix . 'Detail/' . $prefix . 'Item') as $it) {
            $base = round(PohodaXml::num($it, 'homeCurrency/price'), 2);
            $qty = PohodaXml::num($it, 'quantity');
            $percent = PohodaXml::text($it, 'percentVAT');
            $items[] = [
                'description' => PohodaXml::text($it, 'text') ?: ($doc['text'] ?: 'Převzato z Pohody'),
                'quantity' => $qty !== 0.0 ? round($qty, 3) : 1.0,
                'unit' => mb_substr(PohodaXml::text($it, 'unit'), 0, 20) ?: null,
                'unit_price' => $qty !== 0.0 ? round($base / $qty, 6) : $base,
                'base' => $base,
                'vat' => round(PohodaXml::num($it, 'homeCurrency/priceVAT'), 2),
                'rate' => $itemRate($it),
                // Skutečné procento sazby, která se do české úrovně nevejde - čte ho jen OSS
                // větev ({@see rateIssuedItems()}), tuzemské párování zůstává na `rate`.
                'percent' => is_numeric($percent) && (float) $percent > 0.0 ? (float) $percent : null,
                'supply_type' => self::mossSupplyType(PohodaXml::text($it, 'typeServiceMOSS/ids')),
            ] + self::foreignAmounts($it);
        }
        $source = 'detail';
        if ($items !== []) {
            $itemSum = round(array_sum(array_map(static fn (array $i): float => $i['base'] + $i['vat'], $items)), 2);
            $tolerance = max(0.05, 0.005 * abs($itemSum));
            // Haléřové vyrovnání zaokrouhlení (`math2one`) parkuje Pohoda v priceNone.
            $fits = abs($itemSum - ($taxedSum + $none)) <= $tolerance
                || (abs($none) <= self::ROUNDING_LIMIT && abs($itemSum - $taxedSum) <= $tolerance);
            if (!$fits) {
                $ctx->protocol->info($step, 'items_from_summary', sprintf(
                    'Doklad %s: rozpis položek (%s) nesedí na rekapitulaci (%s), položky jsou z rekapitulace po sazbách.',
                    $doc['number'], number_format($itemSum, 2, ',', ' '), number_format($taxedSum + $none, 2, ',', ' ')
                ), ['document_no' => $doc['number']]);
                $items = [];
            }
        }
        if ($items === []) {
            $source = 'summary';
            $lines = [];
            if ($none !== 0.0 && !(abs($none) <= self::ROUNDING_LIMIT && $buckets !== [])) {
                $lines[] = ['base' => $none, 'vat' => 0.0, 'rate' => 0.0];
            }
            foreach ($buckets as $b) {
                $lines[] = $b;
            }
            foreach ($lines as $line) {
                $items[] = [
                    'description' => $doc['text'] ?: ('Doklad ' . $doc['number']),
                    'quantity' => 1.0, 'unit' => null, 'unit_price' => $line['base'],
                    'base' => $line['base'], 'vat' => $line['vat'], 'rate' => $line['rate'],
                ];
            }
        }

        $grossTotal = round(array_sum(array_column($items, 'base')) + array_sum(array_column($items, 'vat')), 2);
        $rounding = round($docTotal - $grossTotal, 2);
        if (abs($rounding) > self::ROUNDING_LIMIT) {
            $ctx->protocol->warn($step, 'total_mismatch', sprintf(
                'Doklad %s: položky %s nesedí na celkem %s z Pohody.',
                $doc['number'], number_format($grossTotal, 2, ',', ' '), number_format($docTotal, 2, ',', ' ')
            ), ['document_no' => $doc['number']]);
            $rounding = 0.0;
        }

        // Rekapitulace Pohody je před odpočtem záloh - odpočty se přidávají až teď.
        $advance = 0.0;
        $advanceItems = 0;
        foreach (PohodaXml::all($r, $prefix . 'Detail/invoiceAdvancePaymentItem') as $a) {
            $vat = round(PohodaXml::num($a, 'homeCurrency/priceVAT'), 2);
            if (abs($vat) < 0.005) {
                $advance += PohodaXml::num($a, 'homeCurrency/priceSum');
                continue;
            }
            $base = round(PohodaXml::num($a, 'homeCurrency/price'), 2);
            $ref = PohodaXml::text($a, 'note') ?: PohodaXml::text($a, 'sourceDocument/number');
            $items[] = [
                'description' => 'Odpočet zálohy' . ($ref !== '' ? ' ' . $ref : ''),
                'quantity' => 1.0, 'unit' => null, 'unit_price' => $base,
                'base' => $base, 'vat' => $vat, 'rate' => $itemRate($a),
            ] + self::foreignAmounts($a);
            $advanceItems++;
        }
        foreach ($items as &$item) {
            // Sazba se páruje až v zapisující větvi: u OSS řádku se hledá ve STÁTĚ SPOTŘEBY,
            // a dokud není rozhodnuto o místě plnění, není známá země, ve které se má hledat.
            $item['rate_id'] = null;
            $item['code'] = null;
        }
        unset($item);

        $base = round(array_sum(array_column($items, 'base')), 2);
        $vat = round(array_sum(array_column($items, 'vat')), 2);
        return [
            'items' => $items,
            'base' => $base,
            'vat' => $vat,
            'total' => round($base + $vat + $rounding, 2),
            'gross_total' => round($grossTotal + $rounding, 2),
            'rounding' => $rounding,
            'advance' => round(-$advance, 2),
            'advance_items' => $advanceItems,
            'source' => $source,
        ];
    }

    /**
     * Opakovaný převod doplní úhradu, kterou Pohoda mezitím zaznamenala (likvidace dokladu).
     * Stav se posouvá jen dopředu: neuhrazený doklad se označí jako uhrazený, u vydaného se
     * zvýší uhrazená částka. Zpět se nevrací nic - úhradu zapsanou v MyÚčtu (spárovaná platba,
     * ruční označení) převod nepřepíše, a koncept k ruční kontrole zůstává konceptem.
     *
     * @param array<string,mixed> $doc
     */
    private function refreshSettlement(PohodaContext $ctx, string $step, string $table, int $id, array $doc): void
    {
        $remaining = is_numeric($doc['remaining']) ? round((float) $doc['remaining'], 2) : 0.0;
        $settled = abs($remaining) < 0.005;
        if ($table === 'purchase_invoices') {
            if (!$settled) {
                return;
            }
            $stmt = $this->stmt('settle_purchase',
                "UPDATE purchase_invoices SET status = 'paid', paid_at = COALESCE(paid_at, ?)
                  WHERE id = ? AND supplier_id = ? AND status IN ('received', 'booked')" . PayablePredicate::excludeAdvanceVatDocument(''));
            $stmt->execute([$doc['paid_date'], $id, $ctx->supplierId]);
        } else {
            // Zbývá uhradit je v Kč. Doklad převzatý v cizí měně se proto doplní jen o celou
            // úhradu - částečnou úhradu v měně dokladu POHODA spolehlivě nenese.
            $stmt = $this->stmt('settle_issued',
                "UPDATE invoices
                    SET paid_total = total_with_vat - advance_paid_amount - ?,
                        paid_at = IF(?, COALESCE(paid_at, ?), paid_at),
                        status = IF(?, 'paid', status)
                  WHERE id = ? AND supplier_id = ? AND status IN ('issued', 'sent', 'reminded')
                    AND (exchange_rate IS NULL OR ?)
                    AND ABS(total_with_vat - advance_paid_amount - ?) > ABS(paid_total) + 0.005");
            $stmt->execute([$remaining, (int) $settled, $doc['paid_date'], (int) $settled, $id, $ctx->supplierId, (int) $settled, $remaining]);
        }
        if ($stmt->rowCount() > 0) {
            $ctx->protocol->count($step, $settled ? 'settled_in_pohoda' : 'partially_paid_in_pohoda');
        }
    }

    /**
     * Stav úhrady z Pohody: `liquidation/amountHome` je zbývá uhradit (chybí = uhrazeno),
     * `liquidation/date` datum poslední úhrady.
     *
     * @param array<string,mixed> $doc
     * @param array{total:float,advance:float} $amounts
     * @return array{paid:float,settled:bool,paid_at:?string}
     */
    private static function payment(array $doc, array $amounts): array
    {
        $toPay = round($amounts['total'] - $amounts['advance'], 2);
        $remaining = is_numeric($doc['remaining']) ? round((float) $doc['remaining'], 2) : 0.0;
        $settled = abs($remaining) < 0.005;
        return [
            'paid' => round($toPay - $remaining, 2),
            'settled' => $settled,
            'paid_at' => $doc['paid_date'] ?? ($settled && abs($toPay) < 0.005 ? $doc['issue'] : null),
        ];
    }

    /**
     * Sazba a režim OSS na řádcích vydaného dokladu. `false` = doklad nelze zapsat.
     *
     * Běží až tady, protože potřebuje odběratele (zemi a DIČ), a ten je znám teprve po
     * `resolvePartner()`. Doklad, jehož členění stojí mimo přiznání a přesto nese daň,
     * projde politikou převodu ({@see OssMigrationPolicy}); ostatní řádky se párují
     * tuzemsky přesně jako dosud.
     *
     * Sazba, kterou nejde napárovat ani jednou cestou, doklad PŘESKOČÍ s chybou v
     * protokolu. Dřív shodila celý běh výjimkou - jenže u agendy, kde takových dokladů
     * bývají stovky, je „převod spadl na prvním z nich" ta nejhorší z možných odpovědí:
     * uživatel nezjistí ani kolik jich je, ani co všechno mu chybí v číselníku.
     *
     * @param array<string,mixed> $doc
     * @param array{items:list<array<string,mixed>>,vat:float} $amounts MĚNÍ SE: řádky dostanou sazbu a OSS sloupce
     * @param array{reasons:list<string>,code:?string,in_return:bool} $class MĚNÍ SE: přibývají důvody k ruční kontrole
     * @param array<string,mixed> $snapshot odběratel z dokladu
     */
    private function rateIssuedItems(PohodaContext $ctx, string $step, array $doc, array &$amounts, array &$class, int $clientId, array $snapshot): bool
    {
        $p = $ctx->protocol;
        $taxDate = $doc['tax'] ?? $doc['issue'];
        $code = (string) $doc['vat_class'];
        $candidate = !$class['in_return'] && abs($amounts['vat']) >= 0.005;
        $client = null;
        $ossItems = 0;
        $manualReview = 0;
        $warnings = [];

        if ($candidate) {
            $warning = $this->oss->runWarning($ctx->supplierId);
            if ($warning !== null) {
                $p->warn($step, 'oss_setup', $warning);
            }
        }

        // Doklad, který POHODA zahrnuje do svého OSS přiznání, nese stát spotřeby (MOSS);
        // prázdný element POHODA do exportu nepíše. Členění mimo přiznání s daní BEZ MOSS
        // tedy v POHODĚ v OSS není a převod ho tam nesmí poslat sám podle země odběratele.
        // Doklad zůstane konceptem: řádky dostanou návrh plánovače (sazba ve správné zemi,
        // ať jde doklad otevřít v editoru) s příznakem k ručnímu posouzení - do přiznání
        // koncept nevstupuje, rozhodne člověk.
        $outsideOss = $candidate && $doc['moss'] === '';
        if ($outsideOss) {
            $class['reasons'][] = "členění DPH „{$code}“ s daní, ale doklad v POHODĚ není v režimu OSS (chybí stát MOSS) - "
                . 'POHODA ho do OSS přiznání nezahrnula; řádky s daní jsou navržené podle země odběratele, '
                . 'rozhodněte, zda plnění patří do OSS, nebo do tuzemského přiznání';
        }

        foreach ($amounts['items'] as $i => $item) {
            if ($candidate && abs((float) $item['vat']) >= 0.005) {
                // Stát spotřeby, který účetní v POHODĚ dokladu zadala (MOSS), je pravdivější
                // než adresa odběratele - ta může být fakturační, ne místo dodání.
                $client ??= $this->oss->clientContext($clientId, $doc['moss'] !== '' ? $doc['moss'] : (string) $snapshot['country'], (string) $snapshot['dic']);
                // Sazbu státu spotřeby píše POHODA jako `historyHigh` bez `@value` a skutečné
                // procento dává do `percentVAT`. Bez něj měl řádek s daní sazbu 0 %, politika ho
                // vzala jako plnění bez daně a doklad skončil konceptem (issue #75).
                $plan = $this->oss->planItem($ctx->supplierId, $client, $item['percent'] ?? (float) $item['rate'], $item['unit'], $taxDate, $code, $item['supply_type'] ?? null);
                if ($plan['reason'] !== null && !in_array($plan['reason'], $class['reasons'], true)) {
                    $class['reasons'][] = $plan['reason'];
                }
                if ($plan['rate_id'] !== null) {
                    $amounts['items'][$i]['rate_id'] = $plan['rate_id'];
                    $amounts['items'][$i]['rate'] = $plan['rate_percent'];
                    // Doklad v EUR: do OSS podání jdou eura z dokladu, ne koruny přepočtené
                    // zpátky kurzem ECB konce čtvrtletí. Tuzemská evidence zůstává v Kč.
                    $amounts['items'][$i]['oss'] = $plan['columns']
                        + $this->oss->returnAmounts($ctx->supplierId, $doc['foreign'], $item['foreign_base'] ?? null, $item['foreign_vat'] ?? null);
                    if ($outsideOss) {
                        $amounts['items'][$i]['oss']['oss_needs_manual_review'] = 1;
                        continue;
                    }
                    $ossItems++;
                    $manualReview += (int) $plan['columns']['oss_needs_manual_review'] === 1 ? 1 : 0;
                    foreach ($plan['warnings'] as $warning) {
                        $warnings[$warning] = true;
                    }
                    continue;
                }
            }
            $rateId = $this->rateId((float) $item['rate'], $taxDate, false);
            if ($rateId === null) {
                $p->difference($step, 'unknown_vat_rate', sprintf(
                    'Doklad %s: sazba DPH %s %% není v číselníku sazeb firmy, nepřevzat. Založte ji '
                        . 'v Nastavení → Číselníky → DPH sazby (u zahraniční sazby nezapomeňte na sloupec '
                        . 'Stát, formulář ho předvyplňuje na CZ) a převod zopakujte.',
                    $doc['number'],
                    rtrim(rtrim(number_format((float) $item['rate'], 2, ',', ''), '0'), ','),
                ), ['document_no' => $doc['number'], 'rate' => $item['rate']]);
                return false;
            }
            $amounts['items'][$i]['rate_id'] = $rateId;
        }

        if ($candidate && $ossItems === 0 && $class['reasons'] === []) {
            // Pojistka pro doklad, u kterého se daň nedá přiřadit k žádnému řádku se sazbou
            // (rozpis z Pohody nesedí na rekapitulaci, sazba řádku s daní v exportu chybí).
            // OSS se nerozhodlo, ale doklad daň nese a do přiznání nepatří - to člověk vidět musí.
            $class['reasons'][] = "členění DPH „{$code}“ mimo přiznání u dokladu s daní: sazbu DPH "
                . 'řádků s daní se z exportu Pohody nepodařilo určit, režim OSS proto nešlo posoudit';
        }
        if ($ossItems > 0) {
            $p->count($step, 'oss_items', $ossItems);
        }
        if ($warnings !== []) {
            $p->warn($step, 'oss_item_warning', sprintf('Doklad %s (režim OSS): %s', $doc['number'], implode('; ', array_keys($warnings))),
                ['document_no' => $doc['number']]);
        }
        if ($manualReview > 0) {
            $p->count($step, 'oss_manual_review', $manualReview);
            $p->info($step, 'oss_manual_review', sprintf(
                'Doklad %s je v režimu OSS, ale u %d řádků si převod místem plnění nebo typem sazby '
                    . 'není jistý - jsou označené k ručnímu posouzení. Projděte je v náhledu OSS přiznání '
                    . 'nebo hromadnou akcí Nastavit OSS.',
                $doc['number'],
                $manualReview,
            ), ['document_no' => $doc['number'], 'items' => $manualReview]);
        }

        return true;
    }

    /**
     * @param array{items:list<array<string,mixed>>,vat:float} $amounts
     * @return array{reasons:list<string>,code:?string,in_return:bool}
     */
    private function classifyIssued(PohodaContext $ctx, string $kind, string $code, array &$amounts): array
    {
        if ($kind === 'proforma') {
            return ['reasons' => [], 'code' => null, 'in_return' => true];
        }
        $hasVat = abs($amounts['vat']) >= 0.005;
        if ($code === '') {
            return ['reasons' => $hasVat ? ['doklad s daní bez členění DPH'] : [], 'code' => null, 'in_return' => true];
        }
        $rate = 0.0;
        foreach ($amounts['items'] as $i) {
            $rate = max($rate, abs($i['vat']) >= 0.005 ? $i['rate'] : 0.0);
        }
        $res = $ctx->vat->sale($code, $rate);
        if ($res === null) {
            return ['reasons' => ["členění DPH „{$code}“ ({$ctx->vat->name($code)}) převod nezařazuje"], 'code' => null, 'in_return' => true];
        }
        if (!$res['in_return']) {
            // Doklad s daní mimo přiznání ještě není vada - je to podpis režimu OSS.
            // Rozhoduje o něm až {@see rateIssuedItems()}, které jediné zná odběratele.
            return ['reasons' => [], 'code' => null, 'in_return' => false];
        }
        if ($res['code'] !== null && $hasVat && !in_array($res['code'], ['1m', '2m'], true)) {
            return ['reasons' => ["členění DPH „{$code}“ (plnění bez daně) u dokladu s daní"], 'code' => null, 'in_return' => true];
        }
        if ($res['code'] !== null) {
            foreach ($amounts['items'] as &$item) {
                $item['code'] = $res['code'];
            }
            unset($item);
        }
        return ['reasons' => [], 'code' => $res['code'], 'in_return' => true];
    }

    /**
     * @param array{items:list<array<string,mixed>>,vat:float} $amounts
     * @return array{reasons:list<string>,deduction:string,in_return:bool,reverse:bool,fixed_asset:bool}
     */
    private function classifyPurchase(PohodaContext $ctx, string $kind, string $code, array $amounts): array
    {
        if ($kind === 'advance') {
            return ['reasons' => [], 'deduction' => 'full', 'in_return' => true, 'reverse' => false, 'fixed_asset' => false];
        }
        $hasVat = abs($amounts['vat']) >= 0.005;
        if ($code === '') {
            return ['reasons' => $hasVat ? ['doklad s daní bez členění DPH'] : [], 'deduction' => 'full', 'in_return' => true, 'reverse' => false, 'fixed_asset' => false];
        }
        $res = $ctx->vat->purchase($code);
        if ($res === null) {
            return ['reasons' => ["členění DPH „{$code}“ ({$ctx->vat->name($code)}) převod nezařazuje"], 'deduction' => 'full', 'in_return' => true, 'reverse' => false, 'fixed_asset' => false];
        }
        if (!$res['in_return']) {
            // Přijatý doklad mimo přiznání: daň je součástí nákladu, odpočet se neuplatnil.
            return ['reasons' => [], 'deduction' => $hasVat ? 'none' : 'full', 'in_return' => false, 'reverse' => false, 'fixed_asset' => false];
        }
        if ($res['reverse'] && $hasVat) {
            return ['reasons' => ["členění DPH „{$code}“ (samovyměření) u dokladu s daní od dodavatele"], 'deduction' => $res['deduction'], 'in_return' => true, 'reverse' => false, 'fixed_asset' => false];
        }
        return ['reasons' => [], 'deduction' => $res['deduction'], 'in_return' => true, 'reverse' => $res['reverse'], 'fixed_asset' => $res['fixed_asset']];
    }

    /**
     * Samovyměření DPH, které Pohoda vede interním dokladem (`DD…` - ř. 3–13) s vazbou
     * na přijatou fakturu (`linkedDocuments`, agenda `receivedInvoice`).
     *
     * `deducted` = k téže faktuře vede Pohoda i interní doklad s odpočtem ze samovyměření
     * (`PD…` - ř. 43, 44). Rozhoduje jen u faktury, která sama členění samovyměření nemá
     * ({@see selfAssessedByInternalDocuments()}).
     *
     * @return array<string,array{doc:string,date:?string,lines:list<array{base:float,rate:float,code:string}>,error:?string,deducted:bool}>
     */
    private function selfAssessments(PohodaContext $ctx): array
    {
        $out = [];
        $deducted = [];
        foreach ($ctx->export->records('internal', 'intDoc') as $r) {
            $h = PohodaXml::get($r, 'intDocHeader');
            $headerClass = PohodaXml::text($h, 'classificationVAT/ids');
            $headerCode = $ctx->vat->selfAssessmentCode($headerClass);
            $ref = '';
            foreach (PohodaXml::all($r, 'linkedDocuments/link') as $link) {
                if (PohodaXml::text($link, 'sourceAgenda') === 'receivedInvoice') {
                    $ref = PohodaXml::text($link, 'sourceDocument/number');
                }
            }
            if ($headerCode === null) {
                if ($ref !== '' && ($ctx->vat->purchase($headerClass)['reverse'] ?? false)) {
                    $deducted[$ref] = true;
                }
                continue;
            }
            $docNo = PohodaXml::text($h, 'number/numberRequested');
            $entry = ['doc' => $docNo, 'date' => PohodaXml::date($h, 'dateTax'), 'lines' => [], 'error' => null];
            foreach (PohodaXml::all($r, 'intDocDetail/intDocItem') as $it) {
                $code = $ctx->vat->selfAssessmentCode(PohodaXml::text($it, 'classificationVAT/ids')) ?? $headerCode;
                $rate = PohodaXml::attr($it, 'rateVAT', 'value');
                $entry['lines'][] = [
                    'base' => round(PohodaXml::num($it, 'homeCurrency/price'), 2),
                    'rate' => is_numeric($rate) ? (float) $rate : 21.0,
                    'code' => $code,
                ];
            }
            if ($entry['lines'] === []) {
                $summary = PohodaXml::get($r, 'intDocSummary/homeCurrency');
                foreach (self::BUCKETS as $bucket => $default) {
                    $base = round(PohodaXml::num($summary, 'price' . $bucket), 2);
                    $rate = PohodaXml::attr($summary, 'price' . $bucket . 'VAT', 'rate');
                    if ($base !== 0.0) {
                        $entry['lines'][] = ['base' => $base, 'rate' => is_numeric($rate) ? (float) $rate : $default, 'code' => $headerCode];
                    }
                }
            }
            if ($entry['lines'] === []) {
                $entry['error'] = 'interní doklad nemá základ daně';
            }
            if ($ref === '') {
                $ctx->protocol->warn(self::STEP_PURCHASE, 'self_assessment_without_invoice', "Interní doklad {$docNo} s vyměřením DPH nemá vazbu na přijatou fakturu. Jeho DPH doplňte ručně.", ['document_no' => $docNo]);
                continue;
            }
            $out[$ref] = $entry;
        }
        foreach ($out as $ref => $entry) {
            $out[$ref]['deducted'] = isset($deducted[$ref]);
        }
        return $out;
    }

    /**
     * Faktura bez členění samovyměření, ke které Pohoda vede výstup i odpočet dvojicí
     * interních dokladů (`DD…` ř. 3–13 a `PD…` ř. 43, 44). Faktura sama je „mimo
     * přiznání" (PN), u smíšené faktury nese členěním jen tuzemskou část s daní. Daň
     * vyměřená interním dokladem patří k faktuře stejně jako u faktury se členěním
     * samovyměření; interní doklady zůstanou jen v deníku. Bez dokladu s odpočtem Pohoda
     * daň jen vyměřila a nárok neuplatnila.
     *
     * @param array{reasons:list<string>,deduction:string,in_return:bool,reverse:bool,fixed_asset:bool} $class
     * @param array{deducted:bool} $sa
     * @param array{items:list<array<string,mixed>>} $amounts
     * @return array{reasons:list<string>,deduction:string,in_return:bool,reverse:bool,fixed_asset:bool}
     */
    private static function selfAssessedByInternalDocuments(array $class, array $sa, array $amounts): array
    {
        $domesticVat = false;
        foreach ($amounts['items'] as $item) {
            $domesticVat = $domesticVat || abs((float) $item['vat']) >= 0.005;
        }
        if ($domesticVat && (!$class['in_return'] || !$sa['deducted'])) {
            // Nárok na odpočet nese hlavička dokladu: tuzemská daň a samovyměření by se
            // neuplatnily stejně jako v Pohodě.
            $class['reasons'][] = 'samovyměření z interního dokladu u faktury s tuzemskou daní mimo odpočet';
        }
        $class['reverse'] = true;
        $class['in_return'] = true;
        if (!$sa['deducted']) {
            $class['deduction'] = 'none';
        }
        return $class;
    }

    /**
     * Položky faktury se samovyměřením = řádky interního dokladu (základ, sazba, kód
     * zařazení), daň je nulová - výstup i odpočet dopočte evidence DPH z kódu. Rozdíl
     * proti celku faktury (jiný kurz vyměření) zůstane jako položka bez DPH s kódem mimo
     * předmět daně - bez kódu by ji evidence DPH u dokladu s příznakem samovyměření
     * zdanila jako službu z EU ({@see VatReturnLineClassifier::PURCHASE_OUTSIDE_SCOPE_CODE}).
     *
     * @param array{lines:list<array{base:float,rate:float,code:string}>} $sa
     * @param array<string,mixed> $amounts
     * @return array<string,mixed>
     */
    private function applySelfAssessment(array $sa, array $amounts, string $taxDate): array
    {
        $items = [];
        $base = 0.0;
        // Tuzemská část smíšené faktury ({@see selfAssessedByInternalDocuments()}) zůstává
        // s daní dodavatele a s kódem odpočtu - na dokladu s příznakem samovyměření by ji
        // evidence DPH bez kódu zařadila podle sazby jako samovyměření.
        $domestic = [];
        $domesticGross = 0.0;
        $domesticVat = 0.0;
        foreach ($amounts['items'] as $item) {
            if (abs((float) $item['vat']) < 0.005) {
                continue;
            }
            $item['code'] ??= (float) $item['rate'] === VatReturnLineClassifier::domesticRate(VatReturnLineClassifier::RATE_BASE, $taxDate)
                ? self::DOMESTIC_DEDUCTION_BASE : self::DOMESTIC_DEDUCTION_REDUCED;
            $domestic[] = $item;
            $domesticGross += (float) $item['base'] + (float) $item['vat'];
            $domesticVat += (float) $item['vat'];
        }
        foreach ($sa['lines'] as $line) {
            $items[] = [
                'description' => $amounts['items'][0]['description'] ?? 'Převzato z Pohody',
                'quantity' => 1.0, 'unit' => null, 'unit_price' => $line['base'],
                'base' => $line['base'], 'vat' => 0.0, 'rate' => $line['rate'],
                'rate_id' => $this->rateId($line['rate'], $taxDate), 'code' => $line['code'],
            ];
            $base += $line['base'];
        }
        $diff = round($amounts['total'] - $domesticGross - $base, 2);
        if (abs($diff) >= 0.01) {
            $items[] = [
                'description' => 'Rozdíl proti základu samovyměření (kurz)', 'quantity' => 1.0, 'unit' => null, 'unit_price' => $diff,
                'base' => $diff, 'vat' => 0.0, 'rate' => 0.0, 'rate_id' => $this->rateId(0.0, $taxDate),
                'code' => VatReturnLineClassifier::PURCHASE_OUTSIDE_SCOPE_CODE,
            ];
        }
        $amounts['items'] = [...$items, ...$domestic];
        $amounts['base'] = round($amounts['total'] - $domesticVat, 2);
        $amounts['vat'] = round($domesticVat, 2);
        $amounts['rounding'] = 0.0;
        return $amounts;
    }

    private function captureLiquidations(PohodaContext $ctx, string $docType, int $docId, string $number, array $r): void
    {
        foreach (PohodaXml::all($r, 'liquidations/liquidation') as $l) {
            $ctx->liquidations[] = [
                'doc' => $docType,
                'id' => $docId,
                'number' => $number,
                'agenda' => PohodaXml::text($l, 'sourceAgenda'),
                'source' => PohodaXml::text($l, 'sourceDocument/number'),
                'source_id' => PohodaXml::text($l, 'sourceDocument/id'),
                'date' => PohodaXml::date($l, 'date'),
                'amount' => PohodaXml::num($l, 'amount'),
                'liq' => PohodaXml::text($l, 'id') ?: substr(md5((string) json_encode($l)), 0, 12),
            ];
        }
    }

    /**
     * Úhrady s datem v roce, který se nepřevádí, v převodu nejsou - doklad podle nich nesmí
     * být uhrazený. Zbývá uhradit se o ně zvýší a datum úhrady je poslední převedené úhrady.
     *
     * @param array<string,mixed> $doc
     * @return array<string,mixed>
     */
    private static function withoutSkippedPayments(PohodaContext $ctx, array $doc, array $r): array
    {
        if ($ctx->skippedYears === []) {
            return $doc;
        }
        $skipped = 0.0;
        $last = null;
        foreach (PohodaXml::all($r, 'liquidations/liquidation') as $l) {
            $date = PohodaXml::date($l, 'date');
            if ($ctx->skipsDate($date)) {
                $skipped += PohodaXml::num($l, 'amount');
            } elseif ($date !== null && ($last === null || $date > $last)) {
                $last = $date;
            }
        }
        if (abs($skipped) < 0.005) {
            return $doc;
        }
        $remaining = is_numeric($doc['remaining']) ? (float) $doc['remaining'] : 0.0;
        $doc['remaining'] = number_format($remaining + $skipped, 2, '.', '');
        $doc['paid_date'] = $last;
        return $doc;
    }

    /**
     * Zbývá uhradit podle Pohody - podle ní páruje úhrady pohybů bez zápisu v deníku
     * {@see UnbookedBankPayments} (u přijaté faktury MyÚčto částečnou úhradu nevede).
     *
     * @param array<string,mixed> $doc
     */
    private static function rememberRemaining(PohodaContext $ctx, string $docType, int $id, array $doc): void
    {
        if (is_numeric($doc['remaining'])) {
            $ctx->remaining[$docType . '|' . $id] = round((float) $doc['remaining'], 2);
        }
    }

    private function remember(PohodaContext $ctx, string $bucket, string $number, int $id): void
    {
        match ($bucket) {
            'issued' => $ctx->issuedInvoices[$number] = $id,
            'receivable' => $ctx->receivables[$number] = $id,
            'internal_sale' => $ctx->internalSales[$number] = $id,
            'purchase' => $ctx->purchaseInvoices[$number] = $id,
            'commitment' => $ctx->commitments[$number] = $id,
            'internal_purchase' => $ctx->internalPurchases[$number] = $id,
        };
    }

    /**
     * Odpočet, který Pohoda uplatnila (datum pro KH) dřív, než ho MyÚčto připustí - před
     * datem plnění nebo vystavení (§ 73). Evidence DPH ho zařadí až do období plnění,
     * protokol na posun upozorní stejně jako převod z PREMIER.
     *
     * Doklad minulého období s datem pro KH v minulém roce zařadí Pohoda do prvního
     * období agendy - tam ho porovnává i tato kontrola. DPH takového dokladu převod
     * nevyřazuje: odvody DPH v Pohodě ukazují, že ho přiznání převáděného roku obsahuje.
     *
     * @param array<string,mixed> $doc
     */
    private function claimPeriod(PohodaContext $ctx, string $step, array $doc, string $type): void
    {
        $claim = $doc['claim'];
        if ($claim === null || $type === 'advance') {
            return;
        }
        $earliest = max((string) ($doc['tax'] ?? $doc['issue']), (string) $doc['issue']);
        $claim = max($claim, $ctx->periodStart());
        if ($claim >= $earliest || substr($claim, 0, 7) === substr($earliest, 0, 7)) {
            return;
        }
        $ctx->protocol->warn($step, 'claim_before_document', sprintf(
            'Doklad %s: Pohoda uplatnila odpočet k %s, dřív než je datum plnění nebo vystavení (%s). MyÚčto odpočet před držením dokladu nepřipustí - doklad je v DPH v období %s.',
            $doc['number'], $claim, $earliest, substr($earliest, 0, 7)
        ), ['document_no' => $doc['number']]);
        $ctx->protocol->count($step, 'claim_moved');
    }

    /** @param array<string,mixed> $doc */
    private function isPreviousPeriod(PohodaContext $ctx, array $doc): bool
    {
        return (string) $doc['accounting'] < $ctx->periodStart();
    }

    /**
     * Číslo dokladu, které ve firmě ještě není; obsazené dostane příponu roku.
     *
     * @return array{number:string,suffixed:bool}|null
     */
    private function freeNumber(string $table, int $supplierId, string $docNo, int $year): ?array
    {
        $stmt = $this->stmt('free_' . $table, "SELECT 1 FROM {$table} WHERE supplier_id = ? AND varsymbol = ? LIMIT 1");
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
    private function reportNumberAndReview(PohodaContext $ctx, string $step, string $docNo, array $number, array $reasons): void
    {
        $p = $ctx->protocol;
        if ($number['suffixed']) {
            $p->count($step, 'suffixed');
            $p->info($step, 'number_suffixed', "Doklad {$docNo}: číslo už ve firmě je, převzat jako {$number['number']}.", ['document_no' => $docNo]);
        }
        if ($reasons !== []) {
            $p->count($step, 'review');
            $p->warn($step, 'needs_review', "Doklad {$docNo} převzat jako koncept k ruční kontrole: " . self::reasonList($reasons)
                . '. Do DPH ani do účtování nevstoupí, dokud ho neopravíte a nepotvrdíte.', ['document_no' => $docNo, 'reasons' => $reasons]);
        }
    }

    /**
     * Opakovaný převod už převedený doklad NEPŘEPISUJE (mohl být mezitím upravený nebo
     * spárovaný). Liší-li se v Pohodě, protokol to řekne.
     */
    private function reportChanged(PohodaContext $ctx, string $step, string $table, int $id, string $docNo, array $r, string $prefix): void
    {
        $summary = PohodaXml::get($r, $prefix . 'Summary/homeCurrency');
        $pohoda = round(PohodaXml::num($summary, 'priceNone') + PohodaXml::num($summary, 'priceLowSum')
            + PohodaXml::num($summary, 'priceHighSum') + PohodaXml::num($summary, 'price3Sum') + PohodaXml::num($summary, 'round/priceRound'), 2);
        foreach (PohodaXml::all($r, $prefix . 'Detail/invoiceAdvancePaymentItem') as $a) {
            if (abs(PohodaXml::num($a, 'homeCurrency/priceVAT')) >= 0.005) {
                $pohoda = round($pohoda + PohodaXml::num($a, 'homeCurrency/priceSum'), 2);
            }
        }
        // Doklad převzatý v cizí měně se porovná v Kč přepočtený kurzem dokladu.
        $stmt = $this->stmt('changed_' . $table, 'SELECT ' . ForeignCurrencyTakeover::homeAmountSql('total_with_vat', 'exchange_rate') . " FROM {$table} WHERE id = ? AND supplier_id = ?");
        $stmt->execute([$id, $ctx->supplierId]);
        $stored = $stmt->fetchColumn();
        if ($stored === false || abs((float) $stored - $pohoda) < 0.005) {
            return;
        }
        $ctx->protocol->count($step, 'changed');
        $ctx->protocol->warn($step, 'changed_in_pohoda', sprintf(
            'Doklad %s se v Pohodě od převodu změnil (celkem %s → %s). V MyÚčtu zůstává beze změny, upravte ho ručně.',
            $docNo, number_format((float) $stored, 2, ',', ' '), number_format($pohoda, 2, ',', ' ')
        ), ['document_no' => $docNo, 'id' => $id]);
    }

    /**
     * @param list<string> $reasons
     * @param list<string> $notes
     */
    private static function note(string $docNo, array $reasons, bool $previous, ForeignCurrencyDecision $fx, array $notes = []): string
    {
        $note = 'Převzato z Pohody, doklad ' . $docNo;
        if ($previous) {
            $note .= ' (doklad minulého období, zůstatek je v počátečních stavech)';
        }
        if ($fx->note() !== null) {
            $note .= '; ' . $fx->note();
        }
        foreach ($notes as $n) {
            $note .= '; ' . $n;
        }
        return $reasons === [] ? $note : $note . '. K ruční kontrole: ' . self::reasonList($reasons) . '.';
    }

    /**
     * Částky dokladu v měně dokladu místo Kč ({@see ForeignCurrencyTakeover::foreignItems()}).
     * Pojistka převzetí pustí jen doklad bez nedaňové zálohy, zaokrouhlení v měně je 0.
     *
     * @param array<string,mixed> $amounts
     * @return array<string,mixed>
     */
    private static function inForeignCurrency(array $amounts): array
    {
        $amounts['items'] = ForeignCurrencyTakeover::foreignItems($amounts['items']);
        $totals = ForeignCurrencyTakeover::totals($amounts['items']);
        $amounts['base'] = $totals['base'];
        $amounts['vat'] = $totals['vat'];
        $amounts['total'] = $totals['total'];
        $amounts['gross_total'] = $totals['total'];
        $amounts['rounding'] = 0.0;
        $amounts['advance'] = 0.0;
        return $amounts;
    }

    /**
     * Základ a daň řádku v cizí měně dokladu (`foreignCurrency`), jak je na dokladu -
     * čte je OSS větev ({@see OssMigrationPolicy::returnAmounts()}) a převzetí dokladu
     * v měně ({@see ForeignCurrencyTakeover}). Řádek bez cizoměnových částek (doklad v Kč,
     * rozpis z rekapitulace) je nenese.
     *
     * @return array{foreign_base?:float,foreign_vat?:float}
     */
    private static function foreignAmounts(mixed $it): array
    {
        if (!is_numeric(PohodaXml::text($it, 'foreignCurrency/price')) || !is_numeric(PohodaXml::text($it, 'foreignCurrency/priceVAT'))) {
            return [];
        }

        return [
            'foreign_base' => round(PohodaXml::num($it, 'foreignCurrency/price'), 2),
            'foreign_vat' => round(PohodaXml::num($it, 'foreignCurrency/priceVAT'), 2),
        ];
    }

    /**
     * Typ plnění řádku v režimu OSS (`typeServiceMOSS`). POHODA rozlišuje dodání zboží
     * (`GD`) a druhy služeb (`OS` ostatní, telekomunikační, vysílací, elektronické…);
     * MyÚčto vede jen zboží a služby, takže vše kromě zboží je služba. Prázdné = zdroj
     * typ neuvádí a odvodí se jako u ostatních kanálů.
     */
    private static function mossSupplyType(string $code): ?string
    {
        $code = strtoupper(trim($code));
        return match (true) {
            $code === '' => null,
            $code === 'GD' => 'goods',
            default => 'services',
        };
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

    private function stmt(string $key, string $sql): \PDOStatement
    {
        return $this->stmts[$key] ??= $this->db->pdo()->prepare($sql);
    }

    /**
     * Tuzemská sazba firmy. `$required = false` vrací `null` místo výjimky - volající, který
     * umí doklad přeskočit, si o něm řekne v protokolu sám.
     */
    private function rateId(float $rate, string $date, bool $required = true): ?int
    {
        $id = $this->rates->find($rate, $date);
        if ($id === null && $required) {
            throw new PohodaException('unknown_vat_rate', 'Sazba DPH ' . $rate . ' % není v číselníku sazeb.');
        }
        return $id;
    }
}
