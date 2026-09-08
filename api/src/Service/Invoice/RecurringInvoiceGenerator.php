<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\RecurringTemplateRepository;
use MyInvoice\Service\Accounting\DocumentAutoPoster;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Currency\ExchangeRateApplier;
use MyInvoice\Service\Oss\OssClientContext;
use MyInvoice\Service\Oss\OssItemDeriver;
use MyInvoice\Service\Oss\OssTemplateItemPolicy;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Stats\StatsRecomputer;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockIssueService;
use MyInvoice\Service\Validation\InvoiceAmountPolicy;

/**
 * Vygeneruje fakturu ze šablony pravidelné fakturace.
 *
 * Kroky:
 *   1. Vytvoří draft (klon šablony — client, project, currency, language,
 *      payment_method, reverse_charge, notes; položky se zkopírují s volitelnou
 *      synchronizací M/YYYY v popisu k DUZP/issue_date — viz MonthSynchronizer).
 *      tax_date_mode řídí DUZP — same_as_issue (default) nebo previous_month_last_day.
 *   2. Recompute totals (InvoiceCalculator).
 *   3. Aplikuje ČNB kurz, pokud měna != CZK (ExchangeRateApplier).
 *   4. Pokud auto_issue=true:
 *        - auto_send_email=true → AutoIssueAndSendService.run() (issue + render + send)
 *        - jinak → in-place issue (varsymbol + snapshots + status='issued')
 *   5. Posune next_run_date na šabloně (PeriodicityCalculator) a updatuje
 *      last_run_date; pokud nové next > end_date, status='expired'.
 *
 * Vrací sumář pro cron / RunNowAction.
 *
 * @phpstan-type Result array{
 *     invoice_id: int,
 *     varsymbol: ?string,
 *     issued: bool,
 *     sent_to: list<string>,
 *     new_next_run_date: ?string,
 *     template_status: string,
 * }
 */
final class RecurringInvoiceGenerator
{
    public function __construct(
        private readonly Connection $db,
        private readonly RecurringTemplateRepository $templates,
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceCalculator $calc,
        private readonly ExchangeRateApplier $rateApplier,
        private readonly AutoIssueAndSendService $issueAndSend,
        private readonly VarsymbolGenerator $varsymbol,
        private readonly SnapshotBuilder $snapshots,
        private readonly InvoicePdfRenderer $pdfRenderer,
        private readonly StatsRecomputer $stats,
        private readonly ActivityLogger $logger,
        private readonly StockIssueService $stockIssue,
        private readonly RecurringPriceListService $priceList,
        private readonly DocumentAutoPoster $autoPoster,
        // Derivace OSS, ne {@see \MyInvoice\Service\Oss\OssItemPlanner}: planner ke
        // každému řádku dohledává i `vat_rate_id`, kdežto šablona ho má PŘIŠPENDLENÝ
        // uživatelem (a jeho platnost k DUZP hlídá VatRateValidityGuard). Přepárovat
        // sazbu tady by uživateli tiše vyměnilo jeho volbu za jinou.
        private readonly OssItemDeriver $ossDeriver,
        private readonly ClientRepository $clients,
    ) {}

    /**
     * @return array{invoice_id:int, varsymbol:?string, issued:bool, sent_to:list<string>, new_next_run_date:?string, template_status:string}
     */
    public function generate(int $templateId, ?string $forcedIssueDate = null, ?int $userId = null, string $ip = '', string $ua = 'cron', bool $forceDraft = false): array
    {
        $template = $this->templates->find($templateId);
        if ($template === null) {
            throw new \RuntimeException("Šablona #$templateId nenalezena");
        }
        if (empty($template['items'])) {
            throw new \DomainException("Šablona #$templateId nemá žádné položky.");
        }

        $issueDate = $forcedIssueDate ?? (string) $template['next_run_date'];

        // Cron volá s $userId=null — fallback na autora šablony, aby invoices.created_by
        // (NOT NULL) i activity_log měly konzistentní audit.
        $userId ??= (int) $template['created_by'];

        // Validate state — paused/expired by neměl cron volat, ale RunNow může
        if ($template['status'] === 'expired') {
            throw new \DomainException('Šablona vypršela (end_date prošel).');
        }

        $invoiceId = $this->createInvoiceFromTemplate($template, $issueDate, $userId);
        $this->calc->recompute($invoiceId);
        $this->rateApplier->applyToInvoice($invoiceId);

        // forceDraft = ruční „Vygenerovat koncept" — vždy nech draft (i u auto_issue=true),
        // uživatel ho pak vystaví/upraví ručně. Rozvrh posouváme stejně jako u běžné
        // generace, aby cron tutéž periodu nevygeneroval podruhé.
        if ($forceDraft) {
            $issued = false;
            $sentTo = [];
            $varsymbol = null;
        } else {
            try {
                ['issued' => $issued, 'sent_to' => $sentTo, 'varsymbol' => $varsymbol] =
                    $this->performIssue($invoiceId, $template, $userId, $ip, $ua);
            } catch (StockException $e) {
                $this->failIssueOnStock($e, $templateId, $template, $issueDate);
            }
        }

        ['next' => $newNext, 'status' => $newStatus] =
            $this->advanceTemplateSchedule($templateId, $template, $issueDate);

        $this->logger->log('recurring.generated', $userId, 'recurring_template', $templateId, [
            'invoice_id'  => $invoiceId,
            'issue_date'  => $issueDate,
            'auto_issue'  => $template['auto_issue'],
            'auto_send'   => $template['auto_send_email'],
            'sent_to'     => $sentTo,
            'next_run'    => $newNext,
            'new_status'  => $newStatus,
        ], $ip, $ua);

        return [
            'invoice_id'        => $invoiceId,
            'varsymbol'         => $varsymbol,
            'issued'            => $issued,
            'sent_to'           => $sentTo,
            'new_next_run_date' => $newNext,
            'template_status'   => $newStatus,
        ];
    }

    /**
     * Otevře koncept pro AKTUÁLNÍ období (draft_open_mode='period_start') — vytvoří
     * draft s fixními řádky šablony, ale NEvystaví ho a NEposune next_run_date.
     * issue_date i tax_date se nastaví na PLÁNOVANÝ konec období (next_run_date),
     * takže koncept od začátku nese správné datum vystavení i DUZP. Uživatel pak
     * celý měsíc edituje výkaz práce na tomto konceptu; cron ho v issuePeriod()
     * uzavře až DEN PO next_run_date (aby se stihla zapsat i práce z posledního dne
     * období) — datum vystavení i DUZP přitom zůstávají na next_run_date (konec období).
     *
     * Idempotentní: pokud už pro období existuje faktura (draft i vystavená),
     * vrátí ji bez vytvoření nové.
     *
     * @return array{invoice_id:int, created:bool}
     */
    public function openDraft(int $templateId, ?int $userId = null, string $ip = '', string $ua = 'cron'): array
    {
        $template = $this->templates->find($templateId);
        if ($template === null) {
            throw new \RuntimeException("Šablona #$templateId nenalezena");
        }
        if (empty($template['items'])) {
            throw new \DomainException("Šablona #$templateId nemá žádné položky.");
        }
        if ($template['status'] === 'expired') {
            throw new \DomainException('Šablona vypršela (end_date prošel).');
        }

        $issueDate = (string) $template['next_run_date'];
        $userId ??= (int) $template['created_by'];

        // Idempotence — koncept (ani vystavená faktura) pro toto období už nesmí existovat.
        $existing = $this->templates->findPeriodInvoice($templateId, $issueDate);
        if ($existing !== null) {
            return ['invoice_id' => (int) $existing['id'], 'created' => false];
        }

        $invoiceId = $this->createInvoiceFromTemplate($template, $issueDate, $userId);
        $this->calc->recompute($invoiceId);
        $this->rateApplier->applyToInvoice($invoiceId);

        $this->logger->log('recurring.draft_opened', $userId, 'recurring_template', $templateId, [
            'invoice_id' => $invoiceId,
            'issue_date' => $issueDate,
        ], $ip, $ua);

        return ['invoice_id' => $invoiceId, 'created' => true];
    }

    /**
     * Uzavře a vystaví koncept pro aktuální období (draft_open_mode='period_start').
     * Najde otevřený koncept (nebo ho vytvoří, kdyby openDraft neproběhl — např.
     * cron neběžel 1. dne), přepočítá totály (zachytí položky výkazu doplněné během
     * měsíce), vystaví a volitelně odešle, pak posune next_run_date.
     *
     * Vrací stejný tvar jako generate().
     *
     * @return array{invoice_id:int, varsymbol:?string, issued:bool, sent_to:list<string>, new_next_run_date:?string, template_status:string}
     */
    public function issuePeriod(int $templateId, ?int $userId = null, string $ip = '', string $ua = 'cron'): array
    {
        $template = $this->templates->find($templateId);
        if ($template === null) {
            throw new \RuntimeException("Šablona #$templateId nenalezena");
        }
        if (empty($template['items'])) {
            throw new \DomainException("Šablona #$templateId nemá žádné položky.");
        }
        if ($template['status'] === 'expired') {
            throw new \DomainException('Šablona vypršela (end_date prošel).');
        }

        $issueDate = (string) $template['next_run_date'];
        $userId ??= (int) $template['created_by'];

        $existing = $this->templates->findPeriodInvoice($templateId, $issueDate);

        $issued = false;
        $sentTo = [];
        $varsymbol = null;

        if ($existing !== null && (string) $existing['status'] !== 'draft') {
            // Už vystaveno (ručně nebo dřívějším během) — nic neděláme, jen posuneme rozvrh.
            $invoiceId = (int) $existing['id'];
            $varsymbol = $existing['varsymbol'] !== null ? (string) $existing['varsymbol'] : null;
            $issued = true;
        } else {
            if ($existing !== null) {
                // Otevřený koncept — přepočítej (zachytí vícepráce z výkazu) a vystav.
                $invoiceId = (int) $existing['id'];
            } else {
                // openDraft neproběhl — vytvoř fakturu teď (fallback / draft_open_mode přepnut pozdě).
                $invoiceId = $this->createInvoiceFromTemplate($template, $issueDate, $userId);
            }
            $this->calc->recompute($invoiceId);
            $this->rateApplier->applyToInvoice($invoiceId);

            try {
                ['issued' => $issued, 'sent_to' => $sentTo, 'varsymbol' => $varsymbol] =
                    $this->performIssue($invoiceId, $template, $userId, $ip, $ua);
            } catch (StockException $e) {
                $this->failIssueOnStock($e, $templateId, $template, $issueDate);
            }
        }

        ['next' => $newNext, 'status' => $newStatus] =
            $this->advanceTemplateSchedule($templateId, $template, $issueDate);

        $this->logger->log('recurring.generated', $userId, 'recurring_template', $templateId, [
            'invoice_id'  => $invoiceId,
            'issue_date'  => $issueDate,
            'auto_issue'  => $template['auto_issue'],
            'auto_send'   => $template['auto_send_email'],
            'sent_to'     => $sentTo,
            'next_run'    => $newNext,
            'new_status'  => $newStatus,
            'from_opened_draft' => $existing !== null,
        ], $ip, $ua);

        return [
            'invoice_id'        => $invoiceId,
            'varsymbol'         => $varsymbol,
            'issued'            => $issued,
            'sent_to'           => $sentTo,
            'new_next_run_date' => $newNext,
            'template_status'   => $newStatus,
        ];
    }

    /**
     * První den měsíce, ve kterém leží next_run_date — datum, kdy se pro
     * draft_open_mode='period_start' otevírá koncept (začátek fakturovaného období).
     */
    public static function draftOpenDate(string $nextRunDate): string
    {
        return (new \DateTimeImmutable($nextRunDate))
            ->modify('first day of this month')
            ->format('Y-m-d');
    }

    /**
     * Vystaví fakturu dle per-šablona flagů auto_issue / auto_send_email.
     *
     * @return array{issued:bool, sent_to:list<string>, varsymbol:?string}
     */
    private function performIssue(int $invoiceId, array $template, ?int $userId, string $ip, string $ua): array
    {
        if (!$template['auto_issue']) {
            return ['issued' => false, 'sent_to' => [], 'varsymbol' => null];
        }
        if ($template['auto_send_email']) {
            $r = $this->issueAndSend->run($invoiceId, $userId, $ip, $ua);
            return ['issued' => $r['issued'], 'sent_to' => $r['sent_to'], 'varsymbol' => $r['varsymbol']];
        }
        $varsymbol = $this->issueOnlyWithoutSend($invoiceId, $userId, $ip, $ua);
        return ['issued' => true, 'sent_to' => [], 'varsymbol' => $varsymbol];
    }

    /**
     * Posune next_run_date o jeden cyklus + případně expiruje. Vrací nové hodnoty.
     *
     * @return array{next:string, status:string}
     */
    private function advanceTemplateSchedule(int $templateId, array $template, string $issueDate): array
    {
        $newNext = PeriodicityCalculator::nextRunDate(
            $issueDate,
            (string) $template['frequency'],
            (bool) $template['end_of_month'],
            $template['day_of_month'] !== null ? (int) $template['day_of_month'] : null,
        );

        $newStatus = (string) $template['status'];
        if (!empty($template['end_date']) && $newNext > (string) $template['end_date']) {
            $newStatus = 'expired';
        }

        $this->templates->advanceSchedule($templateId, $newNext, $issueDate, $newStatus);

        return ['next' => $newNext, 'status' => $newStatus];
    }

    /**
     * A15: sklad zablokoval vystavení (typicky insufficient_stock) — faktura
     * zůstává draft, rozvrh se PŘESTO posune (další běh cronu nesmí založit
     * duplicitní koncept téhož období), chyba se zapíše na šablonu (banner
     * last_error) a propaguje volajícímu (cron/RunNow ji zaloguje).
     *
     * @param array<string,mixed> $template
     */
    private function failIssueOnStock(StockException $e, int $templateId, array $template, string $issueDate): never
    {
        $this->advanceTemplateSchedule($templateId, $template, $issueDate);
        $message = 'Vystavení faktury zablokoval sklad: ' . $e->getMessage() . self::shortageSuffix($e);
        $this->templates->setLastError($templateId, $message);
        throw new \DomainException($message, 0, $e);
    }

    /** Čitelný výčet chybějících položek z insufficient_stock (A3 payload). */
    private static function shortageSuffix(StockException $e): string
    {
        if ($e->errorCode !== 'insufficient_stock' || $e->details === []) {
            return '';
        }
        $parts = [];
        foreach ($e->details as $d) {
            if (!is_array($d)) {
                continue;
            }
            $label = (string) ($d['sku'] ?? $d['name'] ?? $d['stock_item_id'] ?? '?');
            $parts[] = $label . ' (požadováno ' . (string) ($d['requested'] ?? '?')
                . ', dostupné ' . (string) ($d['available'] ?? '?') . ')';
        }
        return $parts === [] ? '' : ' — chybí: ' . implode(', ', $parts);
    }

    /**
     * Splatnost šablony. Hodnota je vždy vlastní (`payment_due_days`, NOT NULL),
     * ale jednotka se smí dědit: NULL = vzít ji z klienta, a když ji nemá ani on,
     * z dodavatele. Bez toho se šablona založená pro klienta se splatností
     * „1× měsíc" chovala jako 1 den.
     *
     * @param array<string,mixed> $template
     */
    private function templateDueDate(array $template, string $issueDate): string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.payment_due_unit, s.default_payment_due_unit
               FROM clients c
               JOIN supplier s ON s.id = c.supplier_id
              WHERE c.id = ?'
        );
        $stmt->execute([(int) $template['client_id']]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        return PaymentDueResolver::dueDate(
            $issueDate,
            $template,
            ['payment_due_default' => null, 'payment_due_unit' => $row['payment_due_unit'] ?? null],
            ['default_payment_due_days' => null, 'default_payment_due_unit' => $row['default_payment_due_unit'] ?? null],
        );
    }

    /**
     * Insert draft + items. Zachovává payment_method, reverse_charge, language,
     * notes a item description s případným month-increment.
     */
    private function createInvoiceFromTemplate(array $template, string $issueDate, ?int $userId): int
    {
        $pdo = $this->db->pdo();

        $type = (string) ($template['invoice_type'] ?? 'invoice');
        $dueDate = $this->templateDueDate($template, $issueDate);
        $taxDate = $type === 'proforma'
            ? null
            : self::computeTaxDate($issueDate, (string) ($template['tax_date_mode'] ?? 'same_as_issue'));

        $template['items'] = $this->priceList->resolveForGeneration(
            $template['items'],
            (int) $template['supplier_id'],
            (int) $template['client_id'],
            (int) $template['currency_id'],
            !empty($template['prices_include_vat']),
            new \DateTimeImmutable($taxDate ?? $issueDate),
        );

        // Pre-flight nad efektivními ceníkovými cenami, ještě před DB zápisem.
        $amountError = InvoiceAmountPolicy::validatePositiveAmountToPay([
            'invoice_type' => $type,
            'advance_paid_amount' => 0,
            'reverse_charge' => !empty($template['reverse_charge']),
            'discount_percent' => (float) ($template['discount_percent'] ?? 0),
            'items' => $template['items'],
        ], $this->invoices->vatRateMap());
        if ($amountError !== null) {
            throw new \DomainException($amountError);
        }

        // Neplátce DPH → položky se přepnou na 0% osvobozenou sazbu (stejně jako u ručně
        // vystavené faktury, viz InvoiceEditor.defaultVatRateId). Autoritativní záchrana i
        // pro šablony uložené dřív s nominální sazbou (21 %) — bez toho by cron tiše vystavil
        // fakturu s DPH u neplátce. Děje se PŘED guardem, aby se validovala coercnutá sazba.
        $template['items'] = $this->coerceNonVatPayerRates(
            $pdo,
            (int) $template['supplier_id'],
            $template['items'],
            $taxDate ?? $issueDate,
        );

        // Safeguard: přišpendlené sazby šablony musí být platné k DUZP (u proformy k issue).
        // Brání tichému vystavení se starou sazbou po její změně (nový řádek vat_rates).
        VatRateValidityGuard::assertValidOn(
            $pdo,
            array_map(static fn ($it) => (int) $it['vat_rate_id'], $template['items']),
            $taxDate ?? $issueDate,
        );

        // Placeholdery {YYYY}/{MM}/{DATE±…}/… v popisech položek a poznámkách (#108) —
        // vyhodnocují se VŽDY (neznámé tokeny zůstávají netknuté → plná zpětná
        // kompatibilita), vůči DUZP (u proformy issue_date), stejně jako month-sync níže.
        $placeholderRef = new \DateTimeImmutable($taxDate ?? $issueDate);
        $lang = (string) ($template['language'] ?? 'cs');
        $noteAbove = $template['note_above_items'] !== null && $template['note_above_items'] !== ''
            ? DescriptionPlaceholders::apply((string) $template['note_above_items'], $placeholderRef, $lang)
            : ($template['note_above_items'] ?? null);
        $noteBelow = $template['note_below_items'] !== null && $template['note_below_items'] !== ''
            ? DescriptionPlaceholders::apply((string) $template['note_below_items'], $placeholderRef, $lang)
            : ($template['note_below_items'] ?? null);

        $pdo->beginTransaction();
        try {
            $discountPercent = round(max(0.0, min(100.0, (float) ($template['discount_percent'] ?? 0))), 2);
            // Kategorie tržby — pevná kategorie šablony (#119) přebíjí dynamický
            // fallback default zakázky > klienta (sdílený helper, stejná logika jako
            // createDraft a import). Hodnota se na fakturu ukládá jako snapshot:
            // pozdější změna šablony/zakázky/klienta už vygenerované faktury nemění.
            $projectId = !empty($template['project_id']) ? (int) $template['project_id'] : null;
            $revenueCategoryId = !empty($template['revenue_category_id'])
                ? (int) $template['revenue_category_id']
                : InvoiceRepository::resolveDefaultRevenueCategoryId(
                    $pdo, (int) $template['client_id'], $projectId
                );
            $stmt = $pdo->prepare(
                'INSERT INTO invoices
                   (invoice_type, client_id, project_id, supplier_id, branding_profile_id,
                    issue_date, tax_date, due_date, currency_id, reverse_charge, prices_include_vat, language,
                    note_above_items, note_below_items, payment_method, discount_percent,
                    recurring_template_id, revenue_category_id, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft", ?)'
            );
            $stmt->execute([
                $type,
                (int) $template['client_id'],
                $projectId,
                (int) $template['supplier_id'],
                isset($template['branding_profile_id']) ? (int) $template['branding_profile_id'] : null,
                $issueDate,
                $taxDate,
                $dueDate,
                (int) $template['currency_id'],
                $template['reverse_charge'] ? 1 : 0,
                !empty($template['prices_include_vat']) ? 1 : 0,
                (string) ($template['language'] ?? 'cs'),
                $noteAbove,
                $noteBelow,
                (string) ($template['payment_method'] ?? 'bank_transfer'),
                $discountPercent,
                (int) $template['id'],
                $revenueCategoryId,
                $userId,
            ]);
            $newId = (int) $pdo->lastInsertId();

            // Description sync — M/YYYY v popisu se synchronizuje k DUZP (tax_date)
            // pokud existuje, jinak k issue_date (proforma). Flag increment_month_in_descriptions
            // řídí, jestli se sync vůbec aplikuje (legacy název zachován pro DB compat).
            $syncTarget = $template['increment_month_in_descriptions']
                ? new \DateTimeImmutable($taxDate ?? $issueDate)
                : null;

            // Položky vkládáme přes kanonickou InvoiceRepository::replaceItems — ta nastaví
            // SPRÁVNÝ vat_rate_snapshot (z aktuální vat_rates), vat_classification_code
            // i zmaterializuje slevovou položku z header discount_percent. Tím se recurring
            // chová stejně jako běžná faktura (dřív se vkládal snapshot=0 → DPH vycházela 0
            // a kódy byly NULL).
            // Kontext odběratele se načte JEDNOU za doklad — derivace si ho jinak vyžádá
            // za každý řádek a cron generuje šablony po stovkách.
            $ossClient = $this->ossDeriver->clientContext((int) $template['client_id']);
            $vatRateMap = $this->invoices->vatRateMap();

            $items = [];
            foreach ($template['items'] as $item) {
                // Pořadí: 1) placeholdery, 2) M/YYYY sync. Obojí míří na stejné ref.
                // datum, takže sync je vůči výstupu placeholderů idempotentní.
                $description = DescriptionPlaceholders::apply((string) $item['description'], $placeholderRef, $lang);
                if ($syncTarget !== null) {
                    $description = MonthSynchronizer::syncTo($description, $syncTarget);
                }
                $items[] = [
                    'description'            => $description,
                    'quantity'               => (float) $item['quantity'],
                    'unit'                   => (string) $item['unit'],
                    'unit_price_without_vat' => (float) $item['unit_price_without_vat'],
                    'vat_rate_id'            => (int) $item['vat_rate_id'],
                    'order_index'            => (int) $item['order_index'],
                    // Vazba na skladovou kartu (A15) — recurring faktura přenáší
                    // stock_item_id/warehouse_id ze šablony, aby auto-výdejka fungovala.
                    'stock_item_id'          => $item['stock_item_id'] ?? null,
                    'warehouse_id'           => $item['warehouse_id'] ?? null,
                ] + $this->ossColumnsFor(
                    $item,
                    (int) $template['supplier_id'],
                    $ossClient,
                    (float) ($vatRateMap[(int) $item['vat_rate_id']] ?? 0.0),
                    $taxDate ?? $issueDate,
                    !empty($template['reverse_charge']),
                );
            }
            $this->invoices->replaceItems($newId, $items);

            $pdo->commit();
            return $newId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * OSS sloupce pro JEDEN řádek generované faktury.
     *
     * ── Dva zdroje odpovědi, a jejich pořadí je věcné ───────────────────────────────
     * 1. ULOŽENÉ ROZHODNUTÍ ŠABLONY (migrace 1297) má přednost. Je to rozhodnutí
     *    ČLOVĚKA — účetní dohledala typ sazby, který číselník nepotvrdil, nebo určila
     *    typ plnění, který se z jednotky odvodit nedá. Kdyby ho derivace při každém
     *    generování přebila, byla by šablona k ničemu: totéž rozhodnutí by se muselo
     *    dělat na každé vygenerované faktuře znovu. Příznak „k ručnímu posouzení"
     *    proto u téhle větve NEVZNIKÁ — nejistota skončila tím, že se člověk rozhodl.
     * 2. DERIVACE ({@see OssItemDeriver}) tam, kde šablona mlčí. Šablony založené před
     *    migrací 1297 mlčí všechny, takže tohle je cesta, kterou zákazník s e-shopem
     *    dostane OSS bez ručního zásahu.
     *
     * ── Odmítnutí NESMÍ zastavit cron ───────────────────────────────────────────────
     * Invariant proti úniku cizí daně umí řádek ODMÍTNOUT (číselník nepotvrdil sazbu
     * v zemi dodavatele a OSS být nemůže). U importu to shodí doklad, protože zdroj
     * pravdy je venku a po opravě se import zopakuje. Tady zdroj pravdy JSME MY a
     * doklad musí vzniknout: chybějící číselník (neproběhlá migrace 1152) by jinak
     * zastavil zákazníkovi celou fakturaci, u KAŽDÉ šablony s nenulovou sazbou včetně
     * ryze české. Řádek proto zůstane mimo OSS, ale dostane příznak K RUČNÍMU
     * POSOUZENÍ — nejistota se uloží k položce, kde ji hromadná editace najde, místo
     * aby zmizela v logu cronu. Tohle je jediné povolené „nevím" a NENÍ to tiché
     * zařazení: bez příznaku by řádek vypadal jako rozhodnutý.
     *
     * @param  array<string,mixed> $item řádek šablony
     * @return array{oss_applicable:int, oss_consumer_country:?string, oss_rate_type:?string,
     *               oss_supply_type:?string, oss_needs_manual_review:int}
     */
    private function ossColumnsFor(
        array $item,
        int $supplierId,
        OssClientContext $client,
        float $ratePercent,
        string $taxDate,
        bool $reverseCharge,
    ): array {
        $decision = $this->ossDeriver->derive(
            $supplierId,
            $client,
            $ratePercent,
            (string) ($item['unit'] ?? ''),
            $taxDate,
            $reverseCharge,
        );

        return OssTemplateItemPolicy::generatedColumns($item, $decision);
    }

    /**
     * Neplátce DPH fakturuje bez DPH → všechny položky se přepnou na 0% osvobozenou sazbu
     * (rate_percent=0, !is_reverse_charge) platnou k datu plnění. Zrcadlí chování ruční
     * faktury (InvoiceEditor.defaultVatRateId), kde frontend vybere 0% sazbu; tady to dělá
     * autoritativně backend, takže to ochrání i šablony uložené dřív s nominální sazbou.
     * Plátce (nebo nenalezený supplier) zůstává beze změny.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function coerceNonVatPayerRates(\PDO $pdo, int $supplierId, array $items, string $referenceDate): array
    {
        // Plátcovství K ROZHODNÉMU DATU generované faktury (DUZP/issue), ne živá cache —
        // šablona generovaná zpětně do období plátcovství musí sazby zachovat a naopak.
        $payerAt = \MyInvoice\Service\Vat\VatStatusService::payerAtExpr(
            (string) $supplierId,
            $pdo->quote($referenceDate),
            's.is_vat_payer',
        );
        $stmt = $pdo->prepare("SELECT {$payerAt} FROM supplier s WHERE s.id = ?");
        $stmt->execute([$supplierId]);
        $isVatPayer = $stmt->fetchColumn();
        // false = supplier nenalezen → nech beze změny; 1 = plátce → nic neřeš.
        if ($isVatPayer === false || (int) $isVatPayer === 1) {
            return $items;
        }

        $rate = $pdo->prepare(
            'SELECT id FROM vat_rates
              WHERE rate_percent = 0 AND is_reverse_charge = 0
                AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY valid_from DESC LIMIT 1'
        );
        $rate->execute([$referenceDate, $referenceDate]);
        $zeroId = $rate->fetchColumn();
        if ($zeroId === false) {
            // Žádná platná 0% sazba k datu — nech sazby být, guard případně chybu nahlásí.
            return $items;
        }

        foreach ($items as &$item) {
            $item['vat_rate_id'] = (int) $zeroId;
        }
        unset($item);

        return $items;
    }

    /**
     * Issue draft bez odeslání — vystaví VS + snapshoty + status='issued',
     * invaliduje cached PDF, recompute stats. Vrací varsymbol.
     */
    private function issueOnlyWithoutSend(int $invoiceId, ?int $userId, string $ip, string $ua): string
    {
        $invoice = $this->invoices->find($invoiceId);
        if ($invoice === null) {
            throw new \RuntimeException("Invoice #$invoiceId not found after generation");
        }

        $supplierId = (int) $invoice['supplier_id'];
        $issueDate = new \DateTimeImmutable((string) $invoice['issue_date']);
        $varsymbol = $this->varsymbol->next(
            $supplierId,
            (string) $invoice['invoice_type'],
            $issueDate,
            (int) $invoice['client_id'],
            (int) ($invoice['revenue_category_id'] ?? 0),
        );
        $snaps = $this->snapshots->build(
            (int) $invoice['client_id'],
            (int) $invoice['currency_id'],
            $supplierId,
            isset($invoice['branding_profile_id']) ? (int) $invoice['branding_profile_id'] : null,
            (string) (($invoice['tax_date'] ?? null) ?: $invoice['issue_date']),
        );

        $issueSql = 'UPDATE invoices SET
                varsymbol         = ?,
                client_snapshot   = ?,
                supplier_snapshot = ?,
                bank_snapshot     = ?,
                status            = "issued"
             WHERE id = ? AND status = "draft"';
        $issueParams = [
            $varsymbol,
            json_encode($snaps['client'], JSON_UNESCAPED_UNICODE),
            json_encode($snaps['supplier'], JSON_UNESCAPED_UNICODE),
            $snaps['bank'] !== null ? json_encode($snaps['bank'], JSON_UNESCAPED_UNICODE) : null,
            $invoiceId,
        ];

        $stockEnabled = $this->stockIssue->isStockEnabled($supplierId);
        $pdo = $this->db->pdo();
        $ownTransaction = $stockEnabled || !$pdo->inTransaction();
        if ($stockEnabled && $ownTransaction) $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            $issue = $pdo->prepare($issueSql);
            $issue->execute($issueParams);
            if ($issue->rowCount() === 0) {
                throw new \RuntimeException('Faktura byla mezitím změněna.');
            }
            if ($stockEnabled) $this->stockIssue->issueForInvoice(
                $supplierId,
                array_merge($invoice, ['varsymbol' => $varsymbol]),
                $userId,
            );
            $this->clients->markAsCustomer((int) $invoice['client_id'], $supplierId);
            if ($ownTransaction) $pdo->commit();
        } catch (\Throwable $e) {
            // Typicky StockException insufficient_stock — rollback nechá fakturu
            // v draftu; performIssue → failIssueOnStock zapíše last_error (A15).
            if ($pdo->inTransaction()) {
                if ($ownTransaction) $pdo->rollBack();
            }
            throw $e;
        }

        $this->stats->recomputeForInvoiceId($invoiceId);
        $this->pdfRenderer->invalidate($invoiceId, 'invalidate_recurring_issue');

        $this->logger->log('invoice.issued', $userId, 'invoice', $invoiceId, [
            'varsymbol'   => $varsymbol,
            'auto_reason' => 'recurring_template',
        ], $ip, $ua);

        // Auto-post hook (A2) — stejný kontrakt jako IssueInvoiceAction: faktura vystavená
        // cronem ze šablony musí být zaúčtovaná stejně jako ta vystavená z UI. Chybu
        // DocumentAutoPoster jen zaloguje, generaci nesmí shodit.
        $this->autoPoster->maybeAutoPost($supplierId, 'invoice', $invoiceId, $userId, $ip, $ua);

        return $varsymbol;
    }

    /**
     * Spočítá DUZP (tax_date) podle režimu šablony.
     *
     *   same_as_issue           → tax_date = issue_date (původní chování)
     *   previous_month_last_day → tax_date = poslední den měsíce předcházejícího issue_date
     *                             (typický CZ scénář "fakturuji 1.6. za květen, DUZP 31.5.")
     */
    private static function computeTaxDate(string $issueDate, string $mode): string
    {
        $d = new \DateTimeImmutable($issueDate);
        return match ($mode) {
            'previous_month_last_day' => $d->modify('first day of this month')
                                           ->modify('-1 day')
                                           ->format('Y-m-d'),
            default => $issueDate, // 'same_as_issue' a unknown mode (fail-safe)
        };
    }
}
