<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mobile;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Accounting\Cash\CashSettlementService;
use MyInvoice\Service\Accounting\DocumentAutoPoster;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Eet\EetCommandException;
use MyInvoice\Service\Eet\EetPaymentCommand;
use MyInvoice\Service\Eet\EetPolicyInput;
use MyInvoice\Service\Eet\EetRecordingPolicy;
use MyInvoice\Service\Eet\EetSettingsService;
use MyInvoice\Service\Eet\EetValues;
use MyInvoice\Service\Eet\IdempotencyConflictException;
use MyInvoice\Service\Eet\PaymentEventService;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Invoice\InvoiceDefaults;
use MyInvoice\Service\Invoice\InvoiceIssuer;
use MyInvoice\Service\Invoice\InvoiceIssueTaxGate;
use MyInvoice\Service\Invoice\InvoiceItemKind;
use MyInvoice\Service\Invoice\InvoicePublicLinkFeature;
use MyInvoice\Service\Invoice\NewInvoiceVatClassification;
use MyInvoice\Service\Invoice\ReceiptLinkService;
use MyInvoice\Service\Invoice\SnapshotBuilder;
use MyInvoice\Service\Invoice\UnitPricePrecision;
use MyInvoice\Service\Invoice\VarsymbolGenerator;
use MyInvoice\Service\Oss\OssItemPlanner;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Stats\StatsRecomputer;
use MyInvoice\Service\Stock\StockIssueService;
use MyInvoice\Service\Validation\InvoiceAmountPolicy;
use MyInvoice\Service\Validation\InvoiceValidation;
use PDO;
use Psr\Clock\ClockInterface;

/**
 * Rychlá účtenka (`POST /api/mobile/receipts`): prodej bez předchozí faktury.
 *
 * V JEDNÉ transakci vznikne vystavený daňový doklad s položkami (bez odběratele
 * zjednodušený daňový doklad § 30 na systémového koncového zákazníka), jeho okamžitá
 * úhrada a případná tržba EET. Nic z toho nepočítá ani nečísluje samo:
 *
 *  - sazby a OSS položek: {@see OssItemPlanner::planIssuedItems()} (jako ostatní kanály),
 *  - klasifikace DPH: {@see NewInvoiceVatClassification} (jako editor),
 *  - DPH a součty z řádků: {@see InvoiceCalculator} (režim cen s DPH i bez),
 *  - daňové brány vystavení (§ 29, § 30, § 108): {@see InvoiceIssueTaxGate},
 *  - číslo z řady dokladů (vlastní řada zjednodušených dokladů, je-li nastavená):
 *    {@see VarsymbolGenerator},
 *  - hotovost s pokladnou: příjmový pokladní doklad k faktuře přes
 *    {@see CashSettlementService::syncInvoice()}; úhradu i tržbu zakládá zaúčtování
 *    PPD ({@see \MyInvoice\Service\Eet\EetCashDocumentRecorder}), takže je jedna
 *    tržba a jedna úhrada se sdílenou platební identitou,
 *  - ostatní kanály (karta na terminálu, poukaz / kredit, hotovost bez pokladny):
 *    {@see EetPaymentCommand::acceptWithinTransaction()}.
 *
 * Zápis vystavení (číslo, snapshoty, stav) je tentýž krok jako ve
 * {@see \MyInvoice\Action\Invoice\IssueInvoiceAction} a v generátoru opakovaných
 * faktur; tady běží uvnitř transakce účtenky, takže rollback vrátí číslo do řady.
 *
 * Odeslání EET, zaúčtování faktury (auto-post), statistiky a PDF jdou až po commitu;
 * jejich chyba výsledek účtenky nemění. Opakování se stejným Idempotency-Key vrátí
 * původní účtenku, jiné tělo se stejným klíčem skončí 409.
 */
final class QuickReceiptService
{
    /** Kanály okamžité úhrady. QR na místě má vlastní dvoukrokový tok (payment-intents). */
    public const CHANNELS = ['cash', 'card_terminal', 'stored_value'];

    private const MAX_ITEMS = 200;

    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceRepository $invoices,
        private readonly ClientRepository $clients,
        private readonly InvoiceDefaults $defaults,
        private readonly InvoiceCalculator $calculator,
        private readonly NewInvoiceVatClassification $vatClassification,
        private readonly OssItemPlanner $planner,
        private readonly InvoiceIssueTaxGate $taxGate,
        private readonly VarsymbolGenerator $varsymbol,
        private readonly SnapshotBuilder $snapshots,
        private readonly StockIssueService $stockIssue,
        private readonly CashSettlementService $cashSettlement,
        private readonly EetPaymentCommand $payments,
        private readonly EetSettingsService $eetSettings,
        private readonly EetRecordingPolicy $eetPolicy,
        private readonly ReceiptLinkService $receiptLinks,
        private readonly InvoicePublicLinkFeature $publicLinks,
        private readonly InvoicePdfRenderer $pdf,
        private readonly DocumentAutoPoster $autoPoster,
        private readonly StatsRecomputer $stats,
        private readonly ActivityLogger $logger,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @param array<string,mixed> $input
     * @param bool $mayRecordEet má uživatel právo přijmout platbu s evidencí (`eet.accept_payment`)?
     * @return array<string,mixed>
     * @throws QuickReceiptException|EetCommandException|IdempotencyConflictException
     */
    public function create(int $supplierId, int $userId, string $idempotencyKey, array $input, bool $mayRecordEet, ?string $ip = null, ?string $userAgent = null): array
    {
        $key = PaymentEventService::validateKey($idempotencyKey);
        $in = $this->normalize($input);
        $hash = PaymentEventService::requestHash('mobile.receipts', $in);

        $existing = $this->findByKey($supplierId, $key);
        if ($existing !== null) {
            return $this->replay($supplierId, $userId, $existing, $hash, $in['public_link']);
        }

        $now = EetValues::local($this->clock->now());
        $settings = $this->eetSettings->get($supplierId);
        if (!$mayRecordEet && $this->eetPolicy->activeAt($settings, $now)) {
            throw new QuickReceiptException('forbidden',
                'Firma eviduje tržby (EET) a k přijetí platby s evidencí chybí oprávnění „Přijímat platby s EET".', 403);
        }
        $device = $this->device($supplierId, $in['device_id'], $settings);
        $registerId = $in['channel'] === 'cash' ? ($in['cash_register_id'] ?? $device['cash_register_id'] ?? null) : null;
        if ($registerId !== null) {
            $this->assertRegister($supplierId, $registerId);
        }
        $client = $this->client($supplierId, $in['client_id']);
        $currencyId = $this->czkCurrencyId($supplierId);

        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Rychlá účtenka řídí vlastní transakci.');
        }
        try {
            $created = TransactionFailure::retrying($pdo, function () use ($pdo, $supplierId, $userId, $key, $hash, $in, $now, $device, $registerId, $client, $currencyId): array {
                // Číslo z řady se přiděluje v této transakci; pod REPEATABLE READ by zámek
                // počítadla po souběžném vystavení skončil chybou 1020 (NumberSeriesGapGuard).
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                $pdo->beginTransaction();
                try {
                    $created = $this->createInTransaction($supplierId, $userId, $key, $hash, $in, $now, $device, $registerId, $client, $currencyId);
                    $pdo->commit();
                    return $created;
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
            });
        } catch (\PDOException $e) {
            // Souběžný požadavek se stejným klíčem doběhl dřív.
            if (!PaymentEventService::isDuplicateKey($e) || ($raced = $this->findByKey($supplierId, $key)) === null) {
                throw $e;
            }
            return $this->replay($supplierId, $userId, $raced, $hash, $in['public_link']);
        }

        $invoiceId = $created['invoice_id'];
        $this->pdf->invalidate($invoiceId, 'invalidate_issue');
        $this->logger->log('invoice.issued', $userId > 0 ? $userId : null, 'invoice', $invoiceId, [
            'varsymbol' => $created['varsymbol'],
            'auto_reason' => 'quick_receipt',
        ], $ip, $userAgent, $supplierId);
        $this->logger->log('mobile.quick_receipt_created', $userId > 0 ? $userId : null, 'invoice', $invoiceId, [
            'quick_receipt_id' => $created['id'],
            'channel' => $in['channel'],
            'amount' => $created['amount'],
            'cash_document_id' => $created['cash_document_id'],
        ], $ip, $userAgent, $supplierId);
        // Zaúčtování faktury a statistiky jako po vystavení z editoru; jejich chyba
        // vystavenou a zaplacenou účtenku nezmění.
        $this->autoPoster->maybeAutoPost($supplierId, 'invoice', $invoiceId, $userId > 0 ? $userId : null, $ip, $userAgent);
        try {
            $this->stats->recomputeAfterCommit($invoiceId);
        } catch (\Throwable $e) {
            error_log('Přepočet statistik po rychlé účtence #' . $invoiceId . ' selhal: ' . $e->getMessage());
        }

        $out = $this->present($supplierId, $userId, $this->findById($supplierId, $created['id']) ?? [], false, $in['public_link']);
        // Uplatnění poukazu nad zůstatek prodaných poukazů: účtenka vznikla, jen se upozorní.
        if ($created['warnings'] !== []) {
            $out['warnings'] = $created['warnings'];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $in
     * @param array{id:int, cash_register_id:?int}|null $device
     * @param array<string,mixed>|null $client
     * @return array{id:int, invoice_id:int, varsymbol:string, amount:string, cash_document_id:?int, warnings:list<array<string,mixed>>}
     */
    private function createInTransaction(
        int $supplierId,
        int $userId,
        string $key,
        string $hash,
        array $in,
        \DateTimeImmutable $now,
        ?array $device,
        ?int $registerId,
        ?array $client,
        int $currencyId,
    ): array {
        $pdo = $this->db->pdo();
        // Prvním zápisem je idempotence: souběžný požadavek se stejným klíčem tu narazí.
        $pdo->prepare(
            'INSERT INTO quick_receipts (supplier_id, idempotency_key, request_hash, device_id, channel, received_amount, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$supplierId, $key, $hash, $device['id'] ?? null, $in['channel'], $in['received_amount'], $in['note'], $userId > 0 ? $userId : null]);
        $receiptId = (int) $pdo->lastInsertId();

        $today = $now->format('Y-m-d');
        $walkIn = $client === null;
        $clientId = $walkIn ? $this->clients->findOrCreateWalkInCustomer($supplierId, $currencyId) : (int) $client['id'];

        $lines = [];
        foreach ($in['items'] as $item) {
            $lines[] = [
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit' => $item['unit'],
                'unit_price_without_vat' => $item['unit_price'],
                'vat_rate' => $item['vat_rate'],
                'stock_item_id' => $item['stock_item_id'],
            ];
        }
        try {
            $items = $this->planner->planIssuedItems($supplierId, $clientId, $today, false, $lines);
        } catch (\RuntimeException $e) {
            // Odmítnutý řádek je chyba vstupu; chyba databáze (i odvolaná transakce) letí dál.
            if ($e instanceof \PDOException) {
                throw $e;
            }
            throw QuickReceiptException::validation($e->getMessage(), 'items');
        }

        $method = EetPaymentCommand::DEFAULT_METHOD_BY_CHANNEL[$in['channel']];
        $body = [
            'client_id' => $clientId,
            'invoice_type' => 'invoice',
            'issue_date' => $today,
            'tax_date' => $today,
            'due_date' => $today,
            'currency_id' => $currencyId,
            'prices_include_vat' => $in['prices_include_vat'],
            'reverse_charge' => false,
            'payment_method' => $method,
            // Bez odběratele zjednodušený doklad (§ 30); brány níže ho odmítnou nad limit.
            'is_simplified' => $walkIn,
            // Účtenka je zaplacená hned, upomínky nemají smysl.
            'auto_send_reminders' => 0,
            'items' => $items,
        ];
        try {
            $body = $this->defaults->resolve($body, forNewInvoice: true);
        } catch (\InvalidArgumentException $e) {
            throw QuickReceiptException::validation($e->getMessage());
        }
        $errors = InvoiceValidation::invoice(
            $body,
            $this->invoices->vatRateMap(),
            $this->invoices->vatRateCountryMap(),
            $this->planner->domesticCountry($supplierId),
            false,
            null,
        );
        if ($errors !== []) {
            throw new QuickReceiptException('validation_failed', 'Validace selhala', 422, ['fields' => $errors]);
        }
        $this->vatClassification->apply($body, $supplierId);

        try {
            $invoiceId = $this->invoices->createDraft($body, $userId);
            $this->invoices->replaceItems($invoiceId, (array) $body['items']);
        } catch (\InvalidArgumentException $e) {
            throw QuickReceiptException::validation($e->getMessage(), 'items');
        }
        if ($registerId !== null) {
            $this->invoices->setCashRegisterId($invoiceId, $supplierId, $registerId);
        }
        $this->calculator->recompute($invoiceId);
        $invoice = $this->invoices->find($invoiceId) ?? throw new \LogicException('Doklad účtenky nevznikl.');

        $this->assertIssuable($invoice, $walkIn);
        if ($this->stockIssue->isStockEnabled($supplierId)) {
            $this->stockIssue->assertAvailableForInvoice($supplierId, $invoice);
        }

        $issueDate = new \DateTimeImmutable($today);
        [$varsymbol, $usedSeriesId] = $this->varsymbol->nextForInvoice(
            $supplierId,
            ['client_id' => $clientId] + $invoice,
            $issueDate,
        );
        $snapshots = $this->snapshots->build(
            $clientId,
            $currencyId,
            $supplierId,
            isset($invoice['branding_profile_id']) ? (int) $invoice['branding_profile_id'] : null,
            InvoiceIssueTaxGate::vatStatusDate($invoice),
        );
        $issue = $pdo->prepare(
            'UPDATE invoices SET varsymbol = ?, client_snapshot = ?, supplier_snapshot = ?, bank_snapshot = ?, status = "issued"
              WHERE id = ? AND supplier_id = ? AND status = "draft"'
        );
        $issue->execute([
            $varsymbol,
            json_encode($snapshots['client'], JSON_UNESCAPED_UNICODE),
            json_encode($snapshots['supplier'], JSON_UNESCAPED_UNICODE),
            $snapshots['bank'] !== null ? json_encode($snapshots['bank'], JSON_UNESCAPED_UNICODE) : null,
            $invoiceId,
            $supplierId,
        ]);
        $this->varsymbol->recordSeries($invoiceId, $invoice, $usedSeriesId);
        if ($issue->rowCount() === 0) {
            throw new \RuntimeException('Doklad účtenky byl mezitím změněn.');
        }
        InvoiceIssuer::record($pdo, $invoiceId, $userId > 0 ? $userId : null);
        if ($this->stockIssue->isStockEnabled($supplierId)) {
            $this->stockIssue->issueForInvoice($supplierId, array_merge($invoice, ['varsymbol' => $varsymbol]), $userId > 0 ? $userId : null);
        }
        if (!$walkIn) {
            $this->clients->markAsCustomer($clientId, $supplierId);
        }

        $amount = EetValues::amount((string) $invoice['amount_to_pay']);
        if ($in['received_amount'] !== null && EetValues::toCents($in['received_amount']) < EetValues::toCents($amount)) {
            throw QuickReceiptException::validation('Přijatá hotovost je nižší než částka k úhradě (' . $amount . ').', 'received_amount', ['amount' => $amount]);
        }

        $cashDocumentId = null;
        $eventId = null;
        $warnings = [];
        if ($registerId !== null) {
            $settlement = $this->cashSettlement->syncInvoice($supplierId, $invoiceId, $userId > 0 ? $userId : null, $device['id'] ?? null);
            if ($settlement['status'] !== CashSettlementService::CREATED) {
                throw new QuickReceiptException('cash_settlement_failed',
                    'Hotovost se nepodařilo přijmout do pokladny (' . (string) ($settlement['reason'] ?? 'neznámý důvod') . ').',
                    422, ['reason' => $settlement['reason'] ?? null]);
            }
            $cashDocumentId = (int) $settlement['cash_document_id'];
            $eventId = $this->eventForCashDocument($supplierId, $cashDocumentId);
        } else {
            $accepted = $this->payments->acceptWithinTransaction($supplierId, $userId,
                PaymentEventService::internalKey('quick-receipt', $receiptId), [
                    'amount' => $amount,
                    'currency' => 'CZK',
                    'channel' => $in['channel'],
                    'contact_context' => $in['contact_context'],
                    'device_id' => $device['id'] ?? null,
                    'invoice_id' => $invoiceId,
                    'note' => $in['note'],
                ]);
            $eventId = $accepted['event_id'];
            $warnings = $accepted['warnings'];
        }

        $pdo->prepare('UPDATE quick_receipts SET invoice_id = ?, payment_event_id = ?, cash_document_id = ? WHERE id = ? AND supplier_id = ?')
            ->execute([$invoiceId, $eventId, $cashDocumentId, $receiptId, $supplierId]);

        return ['id' => $receiptId, 'invoice_id' => $invoiceId, 'varsymbol' => $varsymbol, 'amount' => $amount, 'cash_document_id' => $cashDocumentId, 'warnings' => $warnings];
    }

    /**
     * Daňové brány vystavení — stejné jako u vystavení z editoru.
     *
     * @param array<string,mixed> $invoice
     */
    private function assertIssuable(array $invoice, bool $walkIn): void
    {
        if (!InvoiceItemKind::hasAmountLine((array) $invoice['items'])) {
            throw QuickReceiptException::validation('Účtenka musí obsahovat alespoň jednu položku.', 'items');
        }
        if (!InvoiceAmountPolicy::hasPositiveAmountToPay($invoice)) {
            throw QuickReceiptException::validation('Částka účtenky musí být kladná.', 'items');
        }
        foreach (['reverseChargeRejection', 'walkInRejection', 'simplifiedRejection', 'vatPayerRejection'] as $gate) {
            $rejected = $this->taxGate->{$gate}($invoice);
            if ($rejected === null) {
                continue;
            }
            if ($walkIn && $rejected['code'] === 'simplified_document_not_allowed') {
                // Nad limit zjednodušeného dokladu musí účtenka nést odběratele (§ 29).
                throw new QuickReceiptException('client_required',
                    $rejected['message'] . ' Vyberte odběratele, vystaví se běžný daňový doklad.', 422, ['field' => 'client_id']);
            }
            throw new QuickReceiptException($rejected['code'], $rejected['message'], $rejected['status']);
        }
    }

    /**
     * Platební událost tržby z pokladního dokladu (zakládá ji zaúčtování PPD), nebo null,
     * když firma k času tržby neeviduje.
     */
    private function eventForCashDocument(int $supplierId, int $cashDocumentId): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.payment_event_id
               FROM payment_event_allocations a
               JOIN payment_events e ON e.id = a.payment_event_id AND e.supplier_id = a.supplier_id AND e.kind = 'payment'
              WHERE a.supplier_id = ? AND a.cash_document_id = ?
              ORDER BY a.id LIMIT 1"
        );
        $stmt->execute([$supplierId, $cashDocumentId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function replay(int $supplierId, int $userId, array $row, string $hash, bool $publicLink): array
    {
        if (!hash_equals((string) $row['request_hash'], $hash)) {
            throw new IdempotencyConflictException();
        }
        if ($row['invoice_id'] === null) {
            // Řádek bez dokladu by vznikl jen v nedokončené transakci; ta se vrátila celá.
            throw new QuickReceiptException('receipt_incomplete', 'Účtenka se stejným klíčem se ještě zpracovává.', 409);
        }
        return $this->present($supplierId, $userId, $row, true, $publicLink);
    }

    /**
     * Odpověď: doklad, úhrada, pokladní doklad a oddělené stavy peněz, dokladu a EET.
     *
     * @param array<string,mixed> $row řádek quick_receipts
     * @return array<string,mixed>
     */
    private function present(int $supplierId, int $userId, array $row, bool $replayed, bool $publicLink): array
    {
        $invoiceId = (int) $row['invoice_id'];
        $invoice = $this->invoices->find($invoiceId) ?? [];
        $total = EetValues::amount((string) ($invoice['total_with_vat'] ?? '0'));
        $received = $row['received_amount'] !== null ? EetValues::amount((string) $row['received_amount']) : null;

        $event = null;
        if ($row['payment_event_id'] !== null) {
            $publicId = $this->db->pdo()->prepare('SELECT public_id FROM payment_events WHERE id = ? AND supplier_id = ?');
            $publicId->execute([(int) $row['payment_event_id'], $supplierId]);
            $pid = $publicId->fetchColumn();
            if ($pid !== false) {
                $event = $this->payments->detail($supplierId, (string) $pid);
            }
        }
        $cashDocument = null;
        if ($row['cash_document_id'] !== null) {
            $doc = $this->db->pdo()->prepare('SELECT id, doc_number, register_id, status FROM cash_documents WHERE id = ? AND supplier_id = ?');
            $doc->execute([(int) $row['cash_document_id'], $supplierId]);
            $d = $doc->fetch(PDO::FETCH_ASSOC);
            if ($d !== false) {
                $cashDocument = ['id' => (int) $d['id'], 'doc_number' => $d['doc_number'], 'register_id' => (int) $d['register_id'], 'status' => (string) $d['status']];
            }
        }
        $eet = $event['eet'] ?? null;
        $decision = $event['eet_decision']['decision'] ?? 'inactive';

        $link = null;
        if ($publicLink && $this->publicLinks->isEnabled() && ReceiptLinkService::ineligibility($invoice) === null) {
            try {
                $link = $this->receiptLinks->create($supplierId, $invoiceId, $userId > 0 ? $userId : null);
            } catch (\Throwable $e) {
                // Účtenka je vystavená a zaplacená; odkaz jde vytvořit i dodatečně.
                error_log('Veřejný odkaz rychlé účtenky #' . $invoiceId . ' se nepodařilo vytvořit: ' . $e->getMessage());
            }
        }

        return [
            'receipt_id' => (int) $row['id'],
            'idempotent_replay' => $replayed,
            'invoice' => [
                'id' => $invoiceId,
                'number' => $invoice['varsymbol'] ?? null,
                'is_simplified' => !empty($invoice['is_simplified']),
                'issue_date' => $invoice['issue_date'] ?? null,
                'status' => $invoice['status'] ?? null,
                'currency' => $invoice['currency'] ?? 'CZK',
                'total_without_vat' => EetValues::amount((string) ($invoice['total_without_vat'] ?? '0')),
                'total_vat' => EetValues::amount((string) ($invoice['total_vat'] ?? '0')),
                'total_with_vat' => $total,
                'vat_breakdown' => $invoice['vat_breakdown'] ?? [],
                'pdf_url' => '/api/invoices/' . $invoiceId . '/pdf',
            ],
            'payment' => [
                'channel' => (string) $row['channel'],
                'payment_method' => $invoice['payment_method'] ?? null,
                'amount' => EetValues::amount((string) ($invoice['amount_to_pay'] ?? '0')),
                'received_amount' => $received,
                'change_amount' => $received !== null
                    ? EetValues::fromCents(max(0, EetValues::toCents($received) - EetValues::toCents(EetValues::amount((string) ($invoice['amount_to_pay'] ?? '0')))))
                    : null,
                'event' => $event,
            ],
            'cash_document' => $cashDocument,
            'states' => [
                'money' => $event['payment_order_status'] ?? 'settled',
                'document' => $invoice['status'] ?? null,
                'eet' => $eet['status'] ?? $decision,
            ],
            'eet' => [
                'decision' => $decision,
                'record' => $eet,
            ],
            'public_receipt_url' => $link['url'] ?? null,
            'public_receipt_expires_at' => $link['expires_at'] ?? null,
        ];
    }

    /**
     * Validace a normalizace těla. Otisk idempotence se počítá z výsledku.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function normalize(array $input): array
    {
        $channel = $input['channel'] ?? null;
        if (!is_string($channel) || !in_array($channel, self::CHANNELS, true)) {
            throw QuickReceiptException::validation('Uveďte způsob platby: hotově (cash), kartou na terminálu (card_terminal), nebo poukazem (stored_value).',
                'channel', ['allowed' => self::CHANNELS]);
        }
        $context = $input['contact_context'] ?? 'in_person';
        if (!is_string($context) || !in_array($context, EetPolicyInput::CONTACT_CONTEXTS, true)) {
            throw QuickReceiptException::validation('Neplatná hodnota pole contact_context.', 'contact_context', ['allowed' => EetPolicyInput::CONTACT_CONTEXTS]);
        }
        $currency = strtoupper(trim((string) ($input['currency'] ?? 'CZK')));
        if ($currency !== 'CZK') {
            // Hotovost jde do korunové pokladny a QR / EET účtenky jsou v Kč; cizoměnový
            // prodej se vystavuje fakturou v editoru.
            throw new QuickReceiptException('currency_unsupported', 'Rychlá účtenka se vystavuje jen v Kč.', 422, ['field' => 'currency']);
        }
        $rawItems = $input['items'] ?? null;
        if (!is_array($rawItems) || $rawItems === [] || count($rawItems) > self::MAX_ITEMS || !array_is_list($rawItems)) {
            throw QuickReceiptException::validation('Účtenka musí mít 1 až ' . self::MAX_ITEMS . ' položek.', 'items');
        }
        $items = [];
        $gross = null;
        foreach ($rawItems as $i => $raw) {
            if (!is_array($raw)) {
                throw QuickReceiptException::validation('Položka č. ' . ($i + 1) . ' není objekt.', 'items.' . $i);
            }
            $stockItemId = self::positiveInt($raw['stock_item_id'] ?? null, 'items.' . $i . '.stock_item_id');
            $description = is_string($raw['description'] ?? null) ? trim($raw['description']) : '';
            $unit = is_string($raw['unit'] ?? null) ? mb_substr(trim($raw['unit']), 0, 20) : '';
            $quantity = self::decimal($raw['quantity'] ?? 1, 'items.' . $i . '.quantity', 3);
            if ((float) $quantity <= 0 || (float) $quantity > 1000000) {
                throw QuickReceiptException::validation('Množství položky č. ' . ($i + 1) . ' musí být kladné.', 'items.' . $i . '.quantity');
            }
            $price = self::decimal($raw['unit_price'] ?? null, 'items.' . $i . '.unit_price', UnitPricePrecision::DECIMALS);
            if ((float) $price < 0 || (float) $price >= 100000000) {
                throw QuickReceiptException::validation('Cena položky č. ' . ($i + 1) . ' musí být nezáporná.', 'items.' . $i . '.unit_price');
            }
            $rate = self::decimal($raw['vat_rate'] ?? 0, 'items.' . $i . '.vat_rate', 2);
            $includesVat = filter_var($raw['prices_include_vat'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($includesVat === null) {
                throw QuickReceiptException::validation('Neplatná hodnota prices_include_vat.', 'items.' . $i . '.prices_include_vat');
            }
            // Režim cen je vlastnost dokladu, ne řádku: DPH se počítá shora nebo zdola pro celý doklad.
            if ($gross !== null && $gross !== $includesVat) {
                throw QuickReceiptException::validation('Všechny položky účtenky musí mít ceny buď s DPH, nebo bez DPH.', 'items.' . $i . '.prices_include_vat');
            }
            $gross = $includesVat;
            if ($stockItemId !== null) {
                $stock = $this->stockItem($stockItemId);
                $description = $description !== '' ? $description : (string) $stock['name'];
                $unit = $unit !== '' ? $unit : (string) ($stock['default_sale_unit'] ?? $stock['unit'] ?? '');
            }
            if ($description === '') {
                throw QuickReceiptException::validation('Položka č. ' . ($i + 1) . ' musí mít popis nebo skladovou kartu.', 'items.' . $i . '.description');
            }
            $items[] = [
                'stock_item_id' => $stockItemId,
                'description' => mb_substr($description, 0, 500),
                'quantity' => $quantity,
                'unit' => $unit,
                'unit_price' => $price,
                'vat_rate' => (float) $rate,
            ];
        }
        $received = $input['received_amount'] ?? null;
        $received = $received === null || $received === '' ? null : EetValues::amount($received, 'received_amount');
        if ($received !== null && $channel !== 'cash') {
            throw QuickReceiptException::validation('Přijatou částku lze zadat jen u hotovosti.', 'received_amount');
        }
        $note = is_string($input['note'] ?? null) ? trim($input['note']) : '';

        return [
            'channel' => $channel,
            'contact_context' => $context,
            'currency' => $currency,
            'device_id' => self::positiveInt($input['device_id'] ?? null, 'device_id'),
            'cash_register_id' => self::positiveInt($input['cash_register_id'] ?? null, 'cash_register_id'),
            'client_id' => self::positiveInt($input['client_id'] ?? null, 'client_id'),
            'prices_include_vat' => (bool) $gross,
            'items' => $items,
            'received_amount' => $received,
            'note' => $note === '' ? null : mb_substr($note, 0, 255),
            'public_link' => filter_var($input['public_link'] ?? true, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * Zařízení EET: zadané (musí patřit firmě), jinak výchozí aktivní zařízení firmy bez pověření.
     *
     * @param array<string,mixed> $settings
     * @return array{id:int, cash_register_id:?int}|null
     */
    private function device(int $supplierId, ?int $deviceId, array $settings): ?array
    {
        $explicit = $deviceId !== null;
        $deviceId ??= (int) ($settings['default_device_id'] ?? 0) ?: null;
        if ($deviceId === null) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare('SELECT id, cash_register_id, active, delegation FROM eet_devices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$deviceId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        // Výchozí zařízení v pověření se nepoužije: tržba v pověření jen výslovnou volbou.
        if ($row === false || (!$explicit && ((int) $row['active'] !== 1 || (string) $row['delegation'] !== 'none'))) {
            if ($explicit) {
                throw new QuickReceiptException('device_not_found', 'Pokladní zařízení nenalezeno.', 404, ['field' => 'device_id']);
            }
            return null;
        }
        if ((int) $row['active'] !== 1) {
            throw QuickReceiptException::validation('Pokladní zařízení není aktivní.', 'device_id');
        }
        return ['id' => (int) $row['id'], 'cash_register_id' => $row['cash_register_id'] !== null ? (int) $row['cash_register_id'] : null];
    }

    private function assertRegister(int $supplierId, int $registerId): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT is_active FROM cash_registers WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$registerId, $supplierId]);
        $active = $stmt->fetchColumn();
        if ($active === false) {
            throw new QuickReceiptException('cash_register_not_found', 'Pokladna nenalezena.', 404, ['field' => 'cash_register_id']);
        }
        if ((int) $active !== 1) {
            throw QuickReceiptException::validation('Pokladna není aktivní.', 'cash_register_id');
        }
    }

    /** @return array<string,mixed>|null */
    private function client(int $supplierId, ?int $clientId): ?array
    {
        if ($clientId === null) {
            return null;
        }
        $client = $this->clients->find($clientId);
        if ($client === null || (int) $client['supplier_id'] !== $supplierId) {
            throw new QuickReceiptException('client_not_found', 'Odběratel nenalezen.', 404, ['field' => 'client_id']);
        }
        return $client;
    }

    private function czkCurrencyId(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT id FROM currencies WHERE supplier_id = ? AND code = 'CZK' ORDER BY is_default DESC, id LIMIT 1");
        $stmt->execute([$supplierId]);
        $id = (int) $stmt->fetchColumn();
        if ($id <= 0) {
            throw QuickReceiptException::validation('Firma nemá měnu Kč.', 'currency');
        }
        return $id;
    }

    /** @return array<string,mixed> */
    private function stockItem(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, supplier_id, name, unit, default_sale_unit FROM stock_items WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw QuickReceiptException::validation('Skladová karta nenalezena.', 'stock_item_id');
        }
        // Firmu karty ověří replaceItems() (TenantReferenceGuard) v rámci transakce.
        return $row;
    }

    /** @return array<string,mixed>|null */
    private function findByKey(int $supplierId, string $key): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM quick_receipts WHERE supplier_id = ? AND idempotency_key = ?');
        $stmt->execute([$supplierId, $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    private function findById(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM quick_receipts WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private static function positiveInt(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value <= 0) {
            throw QuickReceiptException::validation('Neplatné pole ' . $field . '.', $field);
        }
        return $value;
    }

    private static function decimal(mixed $value, string $field, int $scale): string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || preg_match('/^-?\d{1,12}(\.\d{1,' . $scale . '})?$/D', trim($value)) !== 1) {
            throw QuickReceiptException::validation('Pole ' . $field . ' musí být číslo s nejvýše ' . $scale . ' desetinnými místy.', $field);
        }
        return trim($value);
    }
}
