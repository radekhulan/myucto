<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\Accounting\InvoiceSettlementService;
use MyInvoice\Service\Accounting\SettlementException;
use MyInvoice\Service\Accounting\VoucherLiabilityAccount;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Qr\QrPaymentGenerator;
use MyInvoice\Service\Stats\StatsRecomputer;
use MyInvoice\Support\PaymentMethods;
use PDO;
use Psr\Clock\ClockInterface;

/**
 * Přijetí platby s rozhodnutím o evidenci tržby (společný příkaz pro web i mobil).
 *
 * `accept` v jedné transakci založí platební událost, případnou účetní úhradu faktury
 * ({@see InvoicePaymentService::recordPayment()}, zdroj `manual` nebo `mark_paid` jako
 * ruční úhrada před EET, i u hotovosti), alokaci a EET záznam s číslem z řady zařízení. První pokus o odeslání
 * běží až po commitu; timeout FS tak platbu nezruší, jen nechá EET čekat ve frontě.
 *
 * QR na místě má dva kroky. `createIntent` jen připraví záměr a QR data: není to
 * inkaso ani tržba. `confirmIntent` („Zákazník zadal platbu") uloží okamžik příkazu
 * a hned vytvoří EET záznam. Účetní úhrada faktury vzniká až bankovním párováním
 * (accounting_settlement_status = pending, bank_verification_status = pending):
 * obsluha potvrzuje zadání příkazu, ne připsání peněz, a zaúčtovat úhradu podle
 * pouhého kliknutí by vedlo ke dvojí úhradě, až dorazí výpis. Spárování výpisu
 * připojí důkaz ke stejné události ({@see PaymentEventReconciliation}) a druhou
 * tržbu nezaloží. Do té doby se potvrzená, nevypořádaná částka odečítá ze
 * zbývajícího dluhu faktury, aby ji nešlo přijmout podruhé.
 *
 * Klientské `eet_required` se ignoruje; rozhoduje {@see EetRecordingPolicy}.
 *
 * Kredit: podíl platby připadající na položky hodnotového poukazu / kreditu jde do
 * `urceno_cerp_zuct` ({@see EetStoredValueCalculator}), úhrada poukazem či kreditem
 * (kanál `stored_value`) celou částkou do `cerp_zuct`. Úhrada faktury poukazem je
 * v podvojném účetnictví zápočet proti přijaté záloze, na kterou se poukaz prodal
 * ({@see InvoiceSettlementService::settleVoucherRedemption()}); jinde ruční úhrada
 * (zdroj `manual`, způsob `offset`).
 *
 * Externí evidence (`eet_context = external`, {@see acceptExternal()}): tržbu už
 * zaevidoval jiný systém (e-shop). Úhrada se zapíše běžnou účetní cestou a EET záznam
 * vznikne jen jako evidence se stavem `external`, který se nikdy neodesílá.
 */
final class EetPaymentCommand
{
    /** Kontext platby, kterou evidoval jiný systém (e-shop s vlastní EET). */
    public const EXTERNAL_CONTEXT = 'external';

    /** Výchozí způsob úhrady podle kanálu platby (sdílí ho i rychlá účtenka). */
    public const DEFAULT_METHOD_BY_CHANNEL = [
        'cash' => 'cash',
        'card_terminal' => 'card',
        'card_online' => 'card',
        'qr_in_person' => 'bank_transfer',
        'bank_transfer' => 'bank_transfer',
        'cash_on_delivery' => 'cash_on_delivery',
        'other' => 'other',
        // Uplatnění poukazu / čerpání kreditu = zápočet přijaté platby, žádný nový pohyb peněz.
        'stored_value' => 'offset',
    ];

    /** Kanály, u kterých se platba vypořádá hned (žádné bankovní ověření). */
    private const SETTLED_CHANNELS = ['cash', 'stored_value'];

    private const EXTERNAL_FIELDS = ['pok', 'eic', 'id_jednotky', 'id_pokl', 'porad_cis', 'dat_trzby', 'celk_trzba',
        'eic_poverujiciho', 'povereni_vice_popl'];

    private const PAYABLE_STATUSES = ['issued', 'sent', 'reminded', 'paid'];

    /** Zdroj účetní úhrady faktury: ruční úhrada (manual), nebo „Označit zaplaceno" (mark_paid). */
    public const INVOICE_PAYMENT_SOURCES = ['manual', 'mark_paid'];

    public function __construct(
        private readonly Connection $db,
        private readonly PaymentEventService $events,
        private readonly EetRecordingPolicy $policy,
        private readonly EetRecordFactory $records,
        private readonly EetDispatchService $dispatch,
        private readonly EetSettingsService $settings,
        private readonly InvoicePaymentService $invoicePayments,
        private readonly QrPaymentGenerator $qr,
        private readonly InvoicePdfRenderer $invoicePdf,
        private readonly StatsRecomputer $stats,
        private readonly ClockInterface $clock,
        private readonly EetCurrency $fx,
        private readonly EetStoredValueCalculator $storedValue,
        private readonly InvoiceSettlementService $settlements,
        private readonly VoucherLiabilityAccount $vouchers,
    ) {}

    /**
     * Varování k uplatnění poukazu nad zůstatek prodaných poukazů a kreditu
     * ({@see VoucherLiabilityAccount::shortfallWarning()}). Platbu neblokuje.
     *
     * @return list<array<string,mixed>>
     */
    private function voucherWarnings(int $supplierId, ?string $channel, ?string $amountCzk): array
    {
        if ($channel !== 'stored_value' || $amountCzk === null) {
            return [];
        }
        $warning = $this->vouchers->shortfallWarning($supplierId, (float) $amountCzk);
        return $warning === null ? [] : [$warning];
    }

    /**
     * Účetní úhrada faktury k přijaté platbě. Uplatnění poukazu (kanál `stored_value`) čerpá
     * přijatou zálohu, na kterou se poukaz prodal ({@see InvoiceSettlementService::settleVoucherRedemption()}:
     * zápočet 324 MD / 311 D, v daňové evidenci vyrovnání bez příjmu). Kde zápočet nejde
     * (proforma, cizí měna), i ostatní kanály: ruční úhrada se zdrojem `$source`.
     * Zdroj jako u ruční úhrady před EET (manual / mark_paid), i u hotovosti: `cash`
     * patří jen úhradě z pokladního dokladu a peněžní deník ho bere jako poznámku k PPD,
     * ne jako příjem.
     */
    private function recordInvoicePayment(int $supplierId, int $invoiceId, ?string $channel, float $amount, string $paidOn, string $source, string $note, int $userId): int
    {
        if ($channel === 'stored_value') {
            try {
                $paymentId = $this->settlements->settleVoucherRedemption($supplierId, $invoiceId, $amount, $paidOn, $note, $userId > 0 ? $userId : null);
            } catch (SettlementException $e) {
                throw EetCommandException::validation($e->getMessage(), 'amount');
            }
            if ($paymentId !== null) {
                return $paymentId;
            }
        }
        return (int) $this->invoicePayments->recordPayment($invoiceId, $amount, $paidOn, [
            'source' => $source,
            'note' => $note,
            'created_by' => $userId > 0 ? $userId : null,
        ])['payment_id'];
    }

    /** Tělo požadavku hlásí, že tržbu evidoval jiný systém. */
    public static function isExternal(array $input): bool
    {
        return ($input['eet_context'] ?? null) === self::EXTERNAL_CONTEXT;
    }

    /**
     * Náhled politiky bez zápisu. Při přijetí se politika vyhodnotí znovu.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function preview(int $supplierId, array $input): array
    {
        if (self::isExternal($input)) {
            return $this->previewExternal($supplierId, $input);
        }
        $now = $this->now();
        $in = $this->normalizePayment($input, requireChannel: false);
        $receivedAt = EetValues::clientTime($input['received_at'] ?? null, $now, 'received_at');
        $decision = $this->evaluate($supplierId, $in, $receivedAt, 'payment');
        $invoice = null;
        $invoiceRow = null;
        if ($in['invoice_id'] !== null) {
            $invoiceRow = $this->invoice($supplierId, $in['invoice_id'], false);
            $invoice = [
                'id' => (int) $invoiceRow['id'],
                'currency' => (string) $invoiceRow['currency'],
                'remaining' => EetValues::fromCents($this->remainingCents($supplierId, $invoiceRow)),
            ];
        }
        $requires = [];
        if ($decision->decision === EetPolicyDecision::UNKNOWN) {
            $requires[] = $in['channel'] === null || $in['channel'] === 'other' ? 'channel' : 'contact_context';
        }
        $fx = null;
        $fxError = null;
        try {
            $fx = $this->fx->forPayment($supplierId, $in['currency'], $in['amount'], $receivedAt);
        } catch (EetCommandException $e) {
            $fxError = ['code' => $e->errorCode, 'message' => $e->getMessage()];
        }
        $credit = $fx !== null && $decision->createsRecord()
            ? $this->creditAmounts($supplierId, $in, $fx['amount'], $invoiceRow)
            : ['credit_topup_amount' => null, 'credit_draw_amount' => null];
        return [
            'eet' => $decision->toArray(),
            'creates_record' => $decision->createsRecord(),
            'blocks_acceptance' => $decision->blocksAcceptance() || ($fxError !== null && $decision->createsRecord()),
            'requires' => $requires,
            'amount' => $in['amount'],
            'currency' => $in['currency'],
            'amount_czk' => $fx['amount'] ?? null,
            'exchange_rate' => $fx['exchange_rate'] ?? null,
            'exchange_rate_source' => $fx['exchange_rate_source'] ?? null,
            'exchange_rate_error' => $fxError,
            // Části tržby v Kč, které jdou do urceno_cerp_zuct / cerp_zuct (null = pole se neuvádí).
            'credit_topup_amount' => $credit['credit_topup_amount'],
            'credit_draw_amount' => $credit['credit_draw_amount'],
            'warnings' => $this->voucherWarnings($supplierId, $in['channel'], $fx['amount'] ?? null),
            'invoice' => $invoice,
        ];
    }

    /**
     * Přijetí platby (hotovost, karta na terminálu, převod…). Idempotentní podle klíče.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     * @throws EetCommandException|IdempotencyConflictException
     */
    public function accept(int $supplierId, int $userId, string $idempotencyKey, array $input, string $invoicePaymentSource = 'manual'): array
    {
        if (!in_array($invoicePaymentSource, self::INVOICE_PAYMENT_SOURCES, true)) {
            throw new \LogicException('Neplatný zdroj úhrady faktury: ' . $invoicePaymentSource);
        }
        if (self::isExternal($input)) {
            return $this->acceptExternal($supplierId, $userId, $idempotencyKey, $input, $invoicePaymentSource);
        }
        $key = PaymentEventService::validateKey($idempotencyKey);
        $in = $this->normalizePayment($input, requireChannel: true);
        $hashInput = $in + ['received_at' => $input['received_at'] ?? null];
        $hash = PaymentEventService::requestHash('payments.accept', $hashInput);
        $existing = $this->events->replay($supplierId, $key, $hash);
        if ($existing !== null) {
            return $this->result($supplierId, $existing, true);
        }

        $now = $this->now();
        $receivedAt = EetValues::clientTime($input['received_at'] ?? null, $now, 'received_at');
        $settings = $this->settings->get($supplierId);
        $decision = $this->evaluate($supplierId, $in, $receivedAt, 'payment', $settings);
        self::assertAcceptable($decision);
        // Částka události a tržby je v CZK; platba v cizí měně nese původní částku a kurz.
        // Kurz (případně stažení z ČNB) se řeší před otevřením transakce.
        $fx = $this->fx->forPayment($supplierId, $in['currency'], $in['amount'], $receivedAt);

        $pdo = $this->db->pdo();
        $this->assertNoOpenTransaction($pdo);
        try {
            $created = TransactionFailure::retrying($pdo, function () use ($pdo, $supplierId, $userId, $key, $hash, $in, $receivedAt, $now, $settings, $decision, $invoicePaymentSource, $fx): array {
                $pdo->beginTransaction();
                try {
                    $created = $this->persistAccepted($supplierId, $userId, $key, $hash, $in, $receivedAt, $now, $settings, $decision, $invoicePaymentSource, $fx);
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
            $replayed = $this->replayAfterRace($e, $supplierId, $key, $hash);
            return $this->result($supplierId, $replayed, true);
        }

        $this->afterCommit($created['invoice_id'], $created['record_id']);
        $event = $this->events->find($supplierId, $created['event_id']);
        $out = $this->result($supplierId, (array) $event, false);
        if ($created['warnings'] !== []) {
            $out['warnings'] = $created['warnings'];
        }
        return $out;
    }

    /**
     * Přijetí platby k faktuře UVNITŘ transakce volajícího (rychlá účtenka: doklad,
     * úhrada a tržba vznikají v jedné transakci). Pravidla jsou tatáž jako u
     * {@see accept()}: politika EET, kurz, úhrada faktury, alokace a EET záznam.
     * Transakci vlastní volající. Odeslání EET se naplánuje až na její commit
     * ({@see EetDispatchService::dispatchAfterCommit()}), přepočet statistik faktury
     * dělá volající po commitu.
     *
     * Klíč je interní (`sys:…`): idempotenci celé operace drží volající.
     *
     * @param array<string,mixed> $input stejné tělo jako u accept(), `invoice_id` povinné
     * @return array{event_id:int, record_id:?int, decision:EetPolicyDecision, warnings:list<array<string,mixed>>}
     * @throws EetCommandException
     */
    public function acceptWithinTransaction(int $supplierId, int $userId, string $internalKey, array $input): array
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException('acceptWithinTransaction() běží jen v transakci volajícího.');
        }
        if (!PaymentEventService::isInternalKey($internalKey)) {
            throw new \LogicException('acceptWithinTransaction() přijímá jen interní klíč.');
        }
        $in = $this->normalizePayment($input, requireChannel: true);
        if ($in['invoice_id'] === null) {
            throw new \LogicException('acceptWithinTransaction() je jen pro úhradu faktury.');
        }
        $hash = PaymentEventService::requestHash('payments.accept', $in + ['received_at' => $input['received_at'] ?? null]);
        $now = $this->now();
        $receivedAt = EetValues::clientTime($input['received_at'] ?? null, $now, 'received_at');
        $settings = $this->settings->get($supplierId);
        $decision = $this->evaluate($supplierId, $in, $receivedAt, 'payment', $settings);
        self::assertAcceptable($decision);
        if ($in['currency'] !== 'CZK') {
            // Kurz z ČNB se stahuje mimo transakci; tahle cesta ho nemá kde získat.
            throw new \LogicException('acceptWithinTransaction() přijímá jen platby v Kč.');
        }
        $fx = $this->fx->forPayment($supplierId, $in['currency'], $in['amount'], $receivedAt);
        $created = $this->persistAccepted($supplierId, $userId, $internalKey, $hash, $in, $receivedAt, $now, $settings, $decision, 'manual', $fx);
        if ($created['record_id'] !== null) {
            $this->dispatch->dispatchAfterCommit($created['record_id']);
        }
        return ['event_id' => $created['event_id'], 'record_id' => $created['record_id'], 'decision' => $decision, 'warnings' => $created['warnings']];
    }

    /**
     * Zápis přijaté platby v otevřené transakci: událost, úhrada faktury, alokace, EET záznam.
     *
     * @param array<string,mixed> $in
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $fx
     * @return array{event_id:int, record_id:?int, invoice_id:?int, warnings:list<array<string,mixed>>}
     */
    private function persistAccepted(
        int $supplierId,
        int $userId,
        string $key,
        string $hash,
        array $in,
        \DateTimeImmutable $receivedAt,
        \DateTimeImmutable $now,
        array $settings,
        EetPolicyDecision $decision,
        string $invoicePaymentSource,
        array $fx,
    ): array {
        $pdo = $this->db->pdo();
        $cashLike = in_array($in['channel'], self::SETTLED_CHANNELS, true);
        $eventId = $this->events->insert([
            'supplier_id' => $supplierId,
            'idempotency_key' => $key,
            'request_hash' => $hash,
            'kind' => 'payment',
            'origin' => $in['invoice_id'] !== null ? 'invoice_payment' : 'quick_sale',
            'payment_method' => $in['payment_method'],
            'channel' => $in['channel'],
            'contact_context' => $in['contact_context'],
            'business_purpose' => $in['business_purpose'],
            'amount' => $fx['amount'],
            'currency' => $fx['currency'],
            'original_currency' => $fx['original_currency'],
            'original_amount' => $fx['original_amount'],
            'exchange_rate' => $fx['exchange_rate'],
            'exchange_rate_source' => $fx['exchange_rate_source'],
            'received_at' => EetValues::dbTime($receivedAt),
            'recorded_at' => EetValues::dbTime($now),
            'paid_on' => $receivedAt->format('Y-m-d'),
            'payment_order_status' => $cashLike ? 'settled' : 'operator_confirmed',
            'bank_verification_status' => $cashLike ? 'not_applicable' : 'pending',
            'accounting_settlement_status' => $in['invoice_id'] !== null ? 'settled' : 'pending',
            'invoice_id' => $in['invoice_id'],
            'eet_decision' => $decision->decision,
            'eet_reason_code' => $decision->reasonCode,
            'policy_version' => EetRecordingPolicy::VERSION,
            'note' => $in['note'],
            'created_by' => $userId > 0 ? $userId : null,
        ]);

        // Zůstatek poukazů se posuzuje PŘED čerpáním touto platbou.
        $warnings = $this->voucherWarnings($supplierId, $in['channel'], $fx['amount']);
        $invoiceId = null;
        $credit = $this->creditAmounts($supplierId, $in, $fx['amount'], null);
        if ($in['invoice_id'] !== null) {
            $invoice = $this->invoice($supplierId, $in['invoice_id'], true);
            $this->assertInvoiceAccepts($supplierId, $invoice, $in['amount'], $in['currency']);
            // Podíl kreditu z úhrad PŘED touto platbou, pod zámkem faktury.
            $credit = $this->creditAmounts($supplierId, $in, $fx['amount'], $invoice);
            $note = $in['note'] ?? ($in['channel'] === 'stored_value'
                ? 'Uplatnění poukazu / kreditu (EET)' : 'Platba přijatá s posouzením EET');
            $paymentId = $this->recordInvoicePayment($supplierId, (int) $invoice['id'], $in['channel'], (float) $in['amount'],
                $receivedAt->format('Y-m-d'), $invoicePaymentSource, $note, $userId);
            // Alokace je v měně faktury (stejně jako úhrada), částka události v CZK.
            $this->events->allocate($supplierId, $eventId, $in['amount'], [
                'invoice_id' => (int) $invoice['id'],
                'invoice_payment_id' => $paymentId,
            ]);
            $invoiceId = (int) $invoice['id'];
        }

        $recordId = null;
        if ($decision->createsRecord()) {
            $record = $this->records->create($supplierId, $eventId, $settings, [
                'device_id' => $in['device_id'],
                'sale_at' => $receivedAt,
                'total_amount' => $fx['amount'],
                'credit_topup_amount' => $credit['credit_topup_amount'],
                'credit_draw_amount' => $credit['credit_draw_amount'],
                'policy_decision' => $decision->decision,
                'policy_reason' => $decision->reasonCode,
                'created_by' => $userId > 0 ? $userId : null,
                'now' => $now,
            ]);
            $recordId = $record['id'];
            $pdo->prepare('UPDATE payment_events SET device_id = ?, unit_id = ? WHERE id = ? AND supplier_id = ?')
                ->execute([$record['device_id'], $record['unit_id'], $eventId, $supplierId]);
        }

        return ['event_id' => $eventId, 'record_id' => $recordId, 'invoice_id' => $invoiceId, 'warnings' => $warnings];
    }

    /**
     * QR na místě: připraví platební záměr a QR data. Nevzniká úhrada ani tržba.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createIntent(int $supplierId, int $userId, ?string $idempotencyKey, array $input): array
    {
        $key = $idempotencyKey !== null && trim($idempotencyKey) !== '' ? PaymentEventService::validateKey($idempotencyKey) : null;
        $invoiceId = self::positiveInt($input['invoice_id'] ?? null, 'invoice_id', true);
        $deviceId = self::positiveInt($input['device_id'] ?? null, 'device_id', false);
        $context = self::enumValue($input['contact_context'] ?? 'in_person', EetPolicyInput::CONTACT_CONTEXTS, 'contact_context');
        $note = self::note($input['note'] ?? null);
        $requestedAmount = isset($input['amount']) && $input['amount'] !== '' ? EetValues::amount($input['amount']) : null;
        $normalized = ['invoice_id' => $invoiceId, 'device_id' => $deviceId, 'contact_context' => $context, 'note' => $note, 'amount' => $requestedAmount];
        $hash = PaymentEventService::requestHash('payment-intents.create', $normalized);
        if ($key !== null) {
            $existing = $this->events->replay($supplierId, $key, $hash);
            if ($existing !== null) {
                return $this->intentResult($supplierId, $existing, true);
            }
        }

        $now = $this->now();
        if ($deviceId !== null) {
            $this->assertDeviceOwned($supplierId, $deviceId);
        }
        $settings = $this->settings->get($supplierId);
        $pdo = $this->db->pdo();
        $this->assertNoOpenTransaction($pdo);
        try {
            $eventId = TransactionFailure::retrying($pdo, function () use ($pdo, $supplierId, $userId, $invoiceId, $deviceId, $context, $note, $requestedAmount, $key, $hash, $now, $settings): int {
                $pdo->beginTransaction();
                try {
                    $invoice = $this->invoice($supplierId, (int) $invoiceId, true);
                    // QR na místě je česká QR platba (SPAYD v CZK). Potvrzený příkaz blokuje dluh faktury
                    // a vypořádá ho bankovní párování korunového pohybu; cizoměnový QR příkaz (SEPA)
                    // tímhle tokem nejde. Fakturu v cizí měně lze přijmout hotově nebo kartou na terminálu.
                    if ((string) $invoice['currency'] !== 'CZK') {
                        throw EetCommandException::unsupported(
                            'QR platba na místě je podporovaná jen u faktur v Kč. Fakturu v ' . (string) $invoice['currency']
                            . ' přijměte v hotovosti nebo kartou na terminálu.',
                            'qr_foreign_currency',
                        );
                    }
                    // Nový QR nahrazuje dřívější nepotvrzené záměry téže faktury: obsluha mění
                    // částku nebo zařízení a zákazník skenuje jen poslední QR. Nepotvrzený záměr
                    // nemá účetní ani EET účinek; potvrzené (operator_confirmed) se nemění nikdy.
                    // Zrušení je ve stejné transakci pod zámkem faktury, takže při chybě níže
                    // zůstanou dřívější záměry beze změny.
                    $pdo->prepare(
                        "UPDATE payment_events SET payment_order_status = 'cancelled', accounting_settlement_status = 'not_applicable'
                          WHERE supplier_id = ? AND invoice_id = ? AND origin = 'payment_intent' AND payment_order_status = 'initiated'"
                    )->execute([$supplierId, (int) $invoice['id']]);

                    $remaining = $this->remainingCents($supplierId, $invoice, true);
                    $amount = $requestedAmount ?? EetValues::fromCents(max(0, $remaining));
                    if (EetValues::toCents($amount) <= 0) {
                        throw EetCommandException::validation('Faktura nemá nic k úhradě.', 'invoice_id',
                            ['remaining' => EetValues::fromCents(max(0, $remaining))]);
                    }
                    $this->assertInvoiceAccepts($supplierId, $invoice, $amount, 'CZK', true);
                    if ($this->qrPayload($invoice, $amount) === null) {
                        throw EetCommandException::validation('Faktura nemá bankovní účet, ze kterého by šel sestavit QR kód.', 'invoice_id');
                    }
                    $decision = $this->policy->evaluate(new EetPolicyInput($settings, $now, 'qr_in_person', $context, 'sale', 'CZK', $amount, 'payment_intent', 'intent'));
                    self::assertAcceptable($decision);

                    $id = $this->events->insert([
                        'supplier_id' => $supplierId,
                        'idempotency_key' => $key,
                        'request_hash' => $key !== null ? $hash : null,
                        'kind' => 'intent',
                        'origin' => 'payment_intent',
                        'payment_method' => 'bank_transfer',
                        'channel' => 'qr_in_person',
                        'contact_context' => $context,
                        'business_purpose' => 'sale',
                        'amount' => $amount,
                        'currency' => 'CZK',
                        'recorded_at' => EetValues::dbTime($now),
                        'payment_order_status' => 'initiated',
                        'bank_verification_status' => 'not_applicable',
                        'accounting_settlement_status' => 'pending',
                        'invoice_id' => (int) $invoice['id'],
                        'device_id' => $deviceId,
                        'eet_decision' => $decision->decision,
                        'eet_reason_code' => $decision->reasonCode,
                        'policy_version' => EetRecordingPolicy::VERSION,
                        'note' => $note,
                        'created_by' => $userId > 0 ? $userId : null,
                    ]);
                    $pdo->commit();
                    return $id;
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
            });
        } catch (\PDOException $e) {
            if ($key === null) {
                throw $e;
            }
            // Souběžný požadavek se stejným klíčem doběhl dřív; zrušení výše se vrátilo s rollbackem.
            return $this->intentResult($supplierId, $this->replayAfterRace($e, $supplierId, $key, $hash), true);
        }
        return $this->intentResult($supplierId, (array) $this->events->find($supplierId, $eventId), false);
    }

    /**
     * „Zákazník zadal platbu": okamžik příkazu, EET záznam, bez účetní úhrady.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function confirmIntent(int $supplierId, int $userId, string $publicId, string $idempotencyKey, array $input): array
    {
        $key = PaymentEventService::validateKey($idempotencyKey);
        $normalized = ['ordered_at' => $input['ordered_at'] ?? null, 'note' => self::note($input['note'] ?? null)];
        $hash = PaymentEventService::requestHash('payment-intents.confirm:' . $publicId, $normalized);
        $previous = $this->events->findByConfirmKey($supplierId, $key);
        if ($previous !== null) {
            if ((string) $previous['public_id'] !== $publicId || !hash_equals((string) $previous['confirm_request_hash'], $hash)) {
                throw new IdempotencyConflictException();
            }
            return $this->result($supplierId, $previous, true);
        }

        $now = $this->now();
        $orderedAt = EetValues::clientTime($input['ordered_at'] ?? null, $now, 'ordered_at');
        $settings = $this->settings->get($supplierId);
        $pdo = $this->db->pdo();
        $this->assertNoOpenTransaction($pdo);
        try {
            $outcome = TransactionFailure::retrying($pdo, function () use ($pdo, $supplierId, $userId, $publicId, $key, $hash, $orderedAt, $now, $settings, $normalized): array {
                $pdo->beginTransaction();
                try {
                    $intent = $this->events->findByPublicId($supplierId, $publicId, true);
                    if ($intent === null || (string) $intent['origin'] !== 'payment_intent') {
                        throw EetCommandException::notFound('Platební záměr nenalezen.');
                    }
                    $status = (string) $intent['payment_order_status'];
                    if ($status !== 'initiated') {
                        throw EetCommandException::stateConflict(
                            $status === 'cancelled' ? 'Platební záměr byl zrušen.' : 'Platební záměr už byl potvrzen.',
                            ['payment_order_status' => $status],
                        );
                    }
                    $invoice = null;
                    if ($intent['invoice_id'] !== null) {
                        // Pod zámkem faktury znovu ověřit dluh: mezitím mohl být potvrzen jiný
                        // záměr nebo přijata hotovost a dvě tržby na jedny peníze nesmí vzniknout.
                        $invoice = $this->invoice($supplierId, (int) $intent['invoice_id'], true);
                        $this->assertInvoiceAccepts($supplierId, $invoice, (string) $intent['amount'], (string) $intent['currency']);
                    }
                    // Kredit z úhrad před potvrzením (zaplaceno + jiné potvrzené QR příkazy).
                    $credit = $this->creditAmounts($supplierId, [
                        'amount' => EetValues::amount((string) $intent['amount']), 'channel' => 'qr_in_person', 'business_purpose' => 'sale',
                    ], EetValues::amount((string) $intent['amount']), $invoice);
                    $delegation = $this->deviceDelegation($supplierId, $intent['device_id'] !== null ? (int) $intent['device_id'] : null, $settings);
                    $decision = $this->policy->evaluate(new EetPolicyInput(
                        $settings, $orderedAt, 'qr_in_person', (string) $intent['contact_context'], 'sale',
                        (string) $intent['currency'], (string) $intent['amount'], 'payment_intent', 'payment',
                        $delegation,
                    ));
                    self::assertAcceptable($decision);

                    $pdo->prepare(
                        "UPDATE payment_events
                            SET kind = 'payment', payment_order_status = 'operator_confirmed', ordered_at = ?,
                                bank_verification_status = 'pending', accounting_settlement_status = 'pending',
                                eet_decision = ?, eet_reason_code = ?, policy_version = ?,
                                confirm_idempotency_key = ?, confirm_request_hash = ?, note = COALESCE(?, note)
                          WHERE id = ? AND supplier_id = ?"
                    )->execute([
                        EetValues::dbTime($orderedAt), $decision->decision, $decision->reasonCode, EetRecordingPolicy::VERSION,
                        $key, $hash, $normalized['note'], (int) $intent['id'], $supplierId,
                    ]);
                    if ($intent['invoice_id'] !== null) {
                        // Zamýšlená alokace bez účetní úhrady; úhradu doplní bankovní párování.
                        $this->events->allocate($supplierId, (int) $intent['id'], (string) $intent['amount'], [
                            'invoice_id' => (int) $intent['invoice_id'],
                        ]);
                    }

                    $recordId = null;
                    if ($decision->createsRecord()) {
                        $record = $this->records->create($supplierId, (int) $intent['id'], $settings, [
                            'device_id' => $intent['device_id'] !== null ? (int) $intent['device_id'] : null,
                            'sale_at' => $orderedAt,
                            'total_amount' => (string) $intent['amount'],
                            'credit_topup_amount' => $credit['credit_topup_amount'],
                            'policy_decision' => $decision->decision,
                            'policy_reason' => $decision->reasonCode,
                            'created_by' => $userId > 0 ? $userId : null,
                            'now' => $now,
                        ]);
                        $recordId = $record['id'];
                        $pdo->prepare('UPDATE payment_events SET device_id = ?, unit_id = ? WHERE id = ? AND supplier_id = ?')
                            ->execute([$record['device_id'], $record['unit_id'], (int) $intent['id'], $supplierId]);
                    }
                    $pdo->commit();
                    return ['event_id' => (int) $intent['id'], 'record_id' => $recordId];
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
            });
        } catch (EetCommandException $e) {
            // Souběžné potvrzení se stejným klíčem doběhlo dřív: vrátit jeho výsledek.
            $raced = $this->events->findByConfirmKey($supplierId, $key);
            if ($e->httpStatus === 409 && $raced !== null && (string) $raced['public_id'] === $publicId
                && hash_equals((string) $raced['confirm_request_hash'], $hash)) {
                return $this->result($supplierId, $raced, true);
            }
            throw $e;
        } catch (\PDOException $e) {
            if (!PaymentEventService::isDuplicateKey($e)) {
                throw $e;
            }
            throw new IdempotencyConflictException();
        }

        $this->afterCommit(null, $outcome['record_id']);
        return $this->result($supplierId, (array) $this->events->find($supplierId, $outcome['event_id']), false);
    }

    /**
     * Zrušení neprovedeného záměru. Po potvrzení obsluhou už zrušit nejde: tržba
     * vznikla a neprovedený příkaz řeší záporná oprava EET (důvod failed_payment).
     *
     * @return array<string,mixed>
     */
    public function cancelIntent(int $supplierId, int $userId, string $publicId): array
    {
        $pdo = $this->db->pdo();
        $this->assertNoOpenTransaction($pdo);
        $eventId = TransactionFailure::retrying($pdo, function () use ($pdo, $supplierId, $publicId): int {
            $pdo->beginTransaction();
            try {
                $intent = $this->events->findByPublicId($supplierId, $publicId, true);
                if ($intent === null || (string) $intent['origin'] !== 'payment_intent') {
                    throw EetCommandException::notFound('Platební záměr nenalezen.');
                }
                $status = (string) $intent['payment_order_status'];
                if ($status === 'initiated') {
                    $pdo->prepare(
                        "UPDATE payment_events SET payment_order_status = 'cancelled', accounting_settlement_status = 'not_applicable'
                          WHERE id = ? AND supplier_id = ?"
                    )->execute([(int) $intent['id'], $supplierId]);
                } elseif ($status !== 'cancelled') {
                    throw EetCommandException::stateConflict(
                        'Potvrzenou platbu nelze zrušit. Pokud zákazník platbu neprovedl, vytvořte opravu EET s důvodem „neprovedená platba".',
                        ['payment_order_status' => $status, 'correction' => 'failed_payment'],
                    );
                }
                $pdo->commit();
                return (int) $intent['id'];
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        });
        return $this->result($supplierId, (array) $this->events->find($supplierId, $eventId), false);
    }

    /**
     * Platba, jejíž tržbu evidoval jiný systém (e-shop s vlastní EET). Úhrada faktury
     * jde běžnou účetní cestou, platební událost nese rozhodnutí `external` a EET záznam
     * vznikne se stavem `external`: je v evidenci, detailu, exportu i v souhrnu faktury,
     * ale nikdy se neodesílá a nepatří do fronty ani do „vyžaduje zásah". Vratku takové
     * tržby přes MyÚčto (oprava, dobropis) eviduje MyÚčto jako běžnou zápornou tržbu.
     *
     * Údaje externí evidence (`external_eet`) jsou volitelné a ověří se podle XSD EET.
     * Trojice EIČ + id_pokl + porad_cis se u firmy převezme jen jednou: stejný obsah vrátí
     * původní výsledek, jiný obsah 409 `eet_external_duplicate`. `Idempotency-Key` je
     * volitelný (e-shop ho posílat nemusí), pokud přijde, platí jako jinde.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     * @throws EetCommandException|IdempotencyConflictException
     */
    public function acceptExternal(int $supplierId, int $userId, ?string $idempotencyKey, array $input, string $invoicePaymentSource = 'manual'): array
    {
        if (!in_array($invoicePaymentSource, self::INVOICE_PAYMENT_SOURCES, true)) {
            throw new \LogicException('Neplatný zdroj úhrady faktury: ' . $invoicePaymentSource);
        }
        $key = $idempotencyKey !== null && trim($idempotencyKey) !== '' ? PaymentEventService::validateKey($idempotencyKey) : null;
        $now = $this->now();
        $settings = $this->settings->get($supplierId);
        $in = $this->normalizeExternal($input, $settings, $now);
        if (!$this->policy->activeAt($settings, $in['sale_at'])) {
            throw EetCommandException::notConfigured('Firma k času tržby neeviduje tržby (EET); externí evidenci nelze zaznamenat.');
        }
        $hash = PaymentEventService::requestHash('payments.external', ['sale_at' => $in['sale_at']->format(DATE_ATOM)] + $in);
        if ($key !== null) {
            $existing = $this->events->replay($supplierId, $key, $hash);
            if ($existing !== null) {
                return $this->result($supplierId, $existing, true);
            }
        }
        if ($in['external_key'] !== null && ($existing = $this->externalDuplicate($supplierId, $in['external_key'], $hash)) !== null) {
            return $this->result($supplierId, $existing, true);
        }
        $fx = $this->fx->forPayment($supplierId, $in['currency'], $in['amount'], $in['sale_at']);

        $pdo = $this->db->pdo();
        $this->assertNoOpenTransaction($pdo);
        try {
            $created = TransactionFailure::retrying($pdo, function () use ($pdo, $supplierId, $userId, $key, $hash, $in, $now, $settings, $invoicePaymentSource, $fx): array {
                $pdo->beginTransaction();
                try {
                    $eventId = $this->events->insert([
                        'supplier_id' => $supplierId,
                        'idempotency_key' => $key,
                        'request_hash' => $hash,
                        'kind' => 'payment',
                        'origin' => $in['invoice_id'] !== null ? 'invoice_payment' : 'quick_sale',
                        'payment_method' => $in['payment_method'],
                        'channel' => $in['channel'],
                        'contact_context' => $in['contact_context'],
                        'business_purpose' => 'sale',
                        'amount' => $fx['amount'],
                        'currency' => $fx['currency'],
                        'original_currency' => $fx['original_currency'],
                        'original_amount' => $fx['original_amount'],
                        'exchange_rate' => $fx['exchange_rate'],
                        'exchange_rate_source' => $fx['exchange_rate_source'],
                        'received_at' => EetValues::dbTime($in['sale_at']),
                        'recorded_at' => EetValues::dbTime($now),
                        'paid_on' => $in['paid_on'],
                        'payment_order_status' => 'settled',
                        'bank_verification_status' => 'not_applicable',
                        'accounting_settlement_status' => $in['invoice_id'] !== null ? 'settled' : 'pending',
                        'invoice_id' => $in['invoice_id'],
                        'eet_decision' => EetPolicyDecision::EXTERNAL,
                        'eet_reason_code' => 'external_system',
                        'policy_version' => EetRecordingPolicy::VERSION,
                        'note' => $in['note'],
                        'created_by' => $userId > 0 ? $userId : null,
                    ]);
                    $invoiceId = null;
                    if ($in['invoice_id'] !== null) {
                        $invoice = $this->invoice($supplierId, $in['invoice_id'], true);
                        $this->assertInvoiceAccepts($supplierId, $invoice, $in['amount'], $in['currency']);
                        $paymentId = $this->recordInvoicePayment($supplierId, (int) $invoice['id'], $in['channel'], (float) $in['amount'],
                            $in['paid_on'], $invoicePaymentSource, $in['note'] ?? 'Platba evidovaná v EET externím systémem', $userId);
                        $this->events->allocate($supplierId, $eventId, $in['amount'], [
                            'invoice_id' => (int) $invoice['id'],
                            'invoice_payment_id' => $paymentId,
                        ]);
                        $invoiceId = (int) $invoice['id'];
                    }
                    $this->records->createExternal($supplierId, $eventId, $settings, [
                        'sale_at' => $in['sale_at'],
                        'total_amount' => $in['external']['celk_trzba'] ?? $fx['amount'],
                        'eic' => $in['external']['eic'] ?? null,
                        'unit_number' => $in['external']['id_jednotky'] ?? null,
                        'device_code' => $in['external']['id_pokl'] ?? null,
                        'sequence_number' => $in['external']['porad_cis'] ?? null,
                        'pok' => $in['external']['pok'] ?? null,
                        'delegation' => EetDelegation::fromItems(
                            $in['external']['eic_poverujiciho'] ?? null,
                            ($in['external']['povereni_vice_popl'] ?? false) === true,
                        ),
                        'external_key' => $in['external_key'],
                        'created_by' => $userId > 0 ? $userId : null,
                    ]);
                    $pdo->commit();
                    return ['event_id' => $eventId, 'invoice_id' => $invoiceId];
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
            });
        } catch (\PDOException $e) {
            if (!PaymentEventService::isDuplicateKey($e)) {
                throw $e;
            }
            // Souběh: stejný klíč nebo stejná externí tržba doběhla dřív.
            $raced = $key !== null ? $this->events->replay($supplierId, $key, $hash) : null;
            $raced ??= $in['external_key'] !== null ? $this->externalDuplicate($supplierId, $in['external_key'], $hash) : null;
            if ($raced === null) {
                throw $e;
            }
            return $this->result($supplierId, $raced, true);
        }
        $this->afterCommit($created['invoice_id'], null);
        return $this->result($supplierId, (array) $this->events->find($supplierId, $created['event_id']), false);
    }

    /**
     * Náhled externí evidence: ověří vstup, nic nezapisuje.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function previewExternal(int $supplierId, array $input): array
    {
        $settings = $this->settings->get($supplierId);
        $in = $this->normalizeExternal($input, $settings, $this->now());
        $active = $this->policy->activeAt($settings, $in['sale_at']);
        $decision = $active
            ? new EetPolicyDecision(EetPolicyDecision::EXTERNAL, 'external_system', 'Tržbu evidoval externí systém; MyÚčto ji jen zaznamená a neodesílá.')
            : new EetPolicyDecision(EetPolicyDecision::INACTIVE, 'eet_inactive', 'Firma k času tržby neeviduje tržby (EET).');
        return [
            'eet' => $decision->toArray(),
            'creates_record' => false,
            'blocks_acceptance' => !$active,
            'requires' => [],
            'amount' => $in['amount'],
            'currency' => $in['currency'],
            'amount_czk' => null,
            'exchange_rate' => null,
            'exchange_rate_source' => null,
            'exchange_rate_error' => null,
            'credit_topup_amount' => null,
            'credit_draw_amount' => null,
            'invoice' => null,
        ];
    }

    /**
     * Už převzatá externí tržba se stejnou trojicí EIČ + id_pokl + porad_cis: stejný obsah
     * → její platební událost (idempotence), jiný obsah → 409.
     *
     * @return array<string,mixed>|null
     * @throws EetCommandException
     */
    private function externalDuplicate(int $supplierId, string $externalKey, string $hash): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT payment_event_id FROM eet_records WHERE supplier_id = ? AND external_key = ?');
        $stmt->execute([$supplierId, $externalKey]);
        $eventId = $stmt->fetchColumn();
        if ($eventId === false || $eventId === null) {
            return null;
        }
        $event = $this->events->find($supplierId, (int) $eventId);
        if ($event === null) {
            return null;
        }
        if (!hash_equals((string) $event['request_hash'], $hash)) {
            throw new EetCommandException('eet_external_duplicate',
                'Tržba se stejným EIČ, označením pokladního zařízení a pořadovým číslem už je v MyÚčtu zaznamenaná s jinými údaji.',
                409, ['payment_event_id' => (string) $event['public_id']]);
        }
        return $event;
    }

    /**
     * Části tržby pro `urceno_cerp_zuct` a `cerp_zuct` v Kč. Úhrada faktury nese podíl
     * položek hodnotového poukazu / kreditu z úhrad před touto platbou (zaplaceno a potvrzené
     * QR příkazy, {@see EetStoredValueCalculator}); prodej bez faktury s účelem
     * `credit_topup` je kredit celý. Úhrada kanálem `stored_value` je čerpání celou částkou.
     *
     * @param array{amount:string, channel:?string, business_purpose:string} $in
     * @param array<string,mixed>|null $invoice řádek faktury (u úhrady pod zámkem)
     * @return array{credit_topup_amount:?string, credit_draw_amount:?string}
     */
    private function creditAmounts(int $supplierId, array $in, string $amountCzk, ?array $invoice): array
    {
        $topUp = null;
        if ($invoice !== null) {
            $paidBefore = EetValues::toCents(EetValues::amount((string) $invoice['paid_total']))
                + $this->events->openConfirmedIntentCents($supplierId, (int) $invoice['id']);
            $cents = $this->storedValue->forInvoicePayment($supplierId, (int) $invoice['id'], $paidBefore,
                EetValues::toCents($in['amount']), EetValues::toCents($amountCzk));
            $topUp = $cents !== null ? EetValues::fromCents($cents) : null;
        } elseif ($in['business_purpose'] === 'credit_topup') {
            $topUp = $amountCzk;
        }
        return [
            'credit_topup_amount' => $topUp,
            'credit_draw_amount' => $in['channel'] === 'stored_value' ? $amountCzk : null,
        ];
    }

    /**
     * Vstup externí evidence. Formáty údajů podle XSD EET ({@see EetIdentifiers}).
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $settings
     * @return array{amount:string, currency:string, channel:?string, payment_method:?string, contact_context:string,
     *               invoice_id:?int, note:?string, paid_on:string, sale_at:\DateTimeImmutable,
     *               external: array<string,mixed>, external_key:?string}
     * @throws EetCommandException
     */
    private function normalizeExternal(array $input, array $settings, \DateTimeImmutable $now): array
    {
        $amount = EetValues::amount($input['amount'] ?? null);
        if (EetValues::toCents($amount) <= 0 || EetValues::toCents($amount) > EetValues::MAX_ABS_CENTS) {
            throw EetCommandException::validation('Částka platby musí být kladná a pod 100 milionů.', 'amount');
        }
        $currency = strtoupper(trim((string) ($input['currency'] ?? 'CZK')));
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw EetCommandException::validation('Neplatná měna.', 'currency');
        }
        $channel = $input['channel'] ?? null;
        $channel = $channel === null || $channel === '' ? null : self::enumValue($channel, EetPolicyInput::CHANNELS, 'channel');
        $method = $input['payment_method'] ?? null;
        if ($method !== null && $method !== '') {
            if (!PaymentMethods::isValid($method)) {
                throw EetCommandException::validation('Neplatný způsob úhrady.', 'payment_method');
            }
        } else {
            $method = $channel !== null ? self::DEFAULT_METHOD_BY_CHANNEL[$channel] : null;
        }

        $raw = $input['external_eet'] ?? null;
        if ($raw !== null && !is_array($raw)) {
            throw EetCommandException::validation('Pole external_eet musí být objekt.', 'external_eet');
        }
        $external = [];
        foreach (self::EXTERNAL_FIELDS as $field) {
            $value = $raw[$field] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $path = 'external_eet.' . $field;
            if ($field === 'povereni_vice_popl') {
                $flag = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                if ($flag === null) {
                    throw EetCommandException::validation('Příznak pověření více poplatníky musí být true/false.', $path);
                }
                // Položka je relevantní jen jako true (metodika, kap. 3, situace 5).
                if ($flag) {
                    $external[$field] = true;
                }
                continue;
            }
            $external[$field] = match ($field) {
                'eic', 'eic_poverujiciho' => self::externalString($value, $path, static fn (string $v): bool => EetIdentifiers::isEic($v),
                    'EIČ musí mít tvar CZ a 8 až 10 číslic.', true),
                'id_pokl' => self::externalString($value, $path, static fn (string $v): bool => EetIdentifiers::isDeviceCode($v),
                    'Označení pokladního zařízení smí mít 1 až 20 znaků (písmena bez diakritiky, číslice, mezera a . , : ; / # - _).'),
                'porad_cis' => self::externalString($value, $path, static fn (string $v): bool => EetIdentifiers::isSequenceNumber($v),
                    'Pořadové číslo tržby smí mít 1 až 25 znaků (písmena bez diakritiky, číslice, mezera a . , : ; / # - _).'),
                'pok' => self::externalString($value, $path, static fn (string $v): bool => EetIdentifiers::isPok($v),
                    'Potvrzovací kód (pok) musí mít tvar podle XSD EET (39 znaků).'),
                'dat_trzby' => self::externalString($value, $path, static fn (string $v): bool => EetIdentifiers::isDateTime($v),
                    'Čas tržby musí mít tvar RRRR-MM-DDThh:mm:ss s časovou zónou (např. +01:00).'),
                'id_jednotky' => self::externalUnit($value, $path),
                'celk_trzba' => EetValues::amount($value, $path),
            };
        }
        $firmEic = strtoupper(trim((string) ($settings['eic'] ?? '')));
        if (isset($external['eic']) && $firmEic !== '' && $external['eic'] !== $firmEic) {
            throw EetCommandException::validation('EIČ externí evidence se liší od EIČ firmy v nastavení EET.', 'external_eet.eic');
        }
        if (isset($external['id_pokl']) !== isset($external['porad_cis'])) {
            throw EetCommandException::validation('Označení pokladního zařízení (id_pokl) a pořadové číslo (porad_cis) uveďte obě, nebo žádné.',
                isset($external['id_pokl']) ? 'external_eet.porad_cis' : 'external_eet.id_pokl');
        }
        if (isset($external['celk_trzba']) && EetValues::toCents($external['celk_trzba']) <= 0) {
            throw EetCommandException::validation('Celková částka externí tržby musí být kladná.', 'external_eet.celk_trzba');
        }
        if (isset($external['dat_trzby'])) {
            $saleAt = EetValues::local(new \DateTimeImmutable($external['dat_trzby']));
            if ($saleAt > $now->modify('+5 minutes')) {
                throw EetCommandException::validation('Čas tržby nesmí být v budoucnosti.', 'external_eet.dat_trzby');
            }
        } else {
            $saleAt = EetValues::clientTime($input['received_at'] ?? null, $now, 'received_at');
        }
        $paidOn = $input['paid_on'] ?? null;
        if ($paidOn === null || $paidOn === '') {
            $paidOn = $saleAt->format('Y-m-d');
        } elseif (!is_string($paidOn) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $paidOn, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw EetCommandException::validation('Datum úhrady musí být ve formátu RRRR-MM-DD.', 'paid_on');
        }
        $eic = $external['eic'] ?? $firmEic;
        // Pověření externí evidence se převezme do záznamu, aby oprava tržby (vratka přes
        // MyÚčto) nesla stejné pověření jako tržba, kterou opravuje.
        $violation = EetDelegation::fromItems($external['eic_poverujiciho'] ?? null, ($external['povereni_vice_popl'] ?? false) === true)
            ->violation($eic !== '' ? $eic : null);
        if ($violation !== null) {
            throw EetCommandException::validation($violation['message'], 'external_eet.eic_poverujiciho');
        }
        return [
            'amount' => $amount,
            'currency' => $currency,
            'channel' => $channel,
            'payment_method' => $method !== null ? (string) $method : null,
            'contact_context' => self::enumValue($input['contact_context'] ?? 'unknown', EetPolicyInput::CONTACT_CONTEXTS, 'contact_context'),
            'invoice_id' => self::positiveInt($input['invoice_id'] ?? null, 'invoice_id', false),
            'note' => self::note($input['note'] ?? null),
            'paid_on' => (string) $paidOn,
            'sale_at' => $saleAt,
            'external' => $external,
            'external_key' => isset($external['id_pokl']) && $eic !== '' ? $eic . '|' . $external['id_pokl'] . '|' . $external['porad_cis'] : null,
        ];
    }

    /** @param \Closure(string): bool $valid */
    private static function externalString(mixed $value, string $field, \Closure $valid, string $message, bool $eic = false): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw EetCommandException::validation($message, $field);
        }
        $value = $eic ? EetIdentifiers::normalizeEic((string) $value) : trim((string) $value);
        if (!$valid($value)) {
            throw EetCommandException::validation($message, $field);
        }
        return $value;
    }

    private static function externalUnit(mixed $value, string $field): int
    {
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,8}$/D', trim($value)) === 1) {
            $value = (int) trim($value);
        }
        if (!is_int($value) || $value < 1 || $value > EetIdentifiers::UNIT_NUMBER_MAX) {
            throw EetCommandException::validation('Označení evidenční jednotky musí být celé číslo 1 až 999 999 999.', $field);
        }
        return $value;
    }

    /** @return array<string,mixed> */
    public function detail(int $supplierId, string $publicId): array
    {
        $event = $this->events->findByPublicId($supplierId, $publicId);
        if ($event === null) {
            throw EetCommandException::notFound('Platební událost nenalezena.');
        }
        return $this->result($supplierId, $event, false);
    }

    /**
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    private function result(int $supplierId, array $event, bool $replayed): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM eet_records WHERE supplier_id = ? AND payment_event_id = ? ORDER BY id LIMIT 1');
        $stmt->execute([$supplierId, (int) $event['id']]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $out = EetRecordPresenter::event($event, $record, $this->events->allocations($supplierId, (int) $event['id']));
        $out['idempotent_replay'] = $replayed;
        if ($record !== null && in_array($record['status'], ['pending', 'sending', 'retry_wait'], true)) {
            $out['poll_after_seconds'] = 5;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    private function intentResult(int $supplierId, array $event, bool $replayed): array
    {
        $out = $this->result($supplierId, $event, $replayed);
        if ((string) $event['payment_order_status'] === 'initiated' && $event['invoice_id'] !== null) {
            $invoice = $this->invoice($supplierId, (int) $event['invoice_id'], false);
            $payload = $this->qrPayload($invoice, (string) $event['amount']);
            $bank = $this->bankSnapshot($invoice);
            $out['qr'] = $payload === null ? null : [
                'spd' => $payload,
                'image_data_uri' => $this->qr->generate('CZK', (float) $event['amount'], (string) ($invoice['varsymbol'] ?? ''),
                    $bank, '', null, 'Faktura ' . (string) ($invoice['varsymbol'] ?? '')),
            ] + self::manualTransferDetails($bank, (string) ($invoice['varsymbol'] ?? ''), (string) $event['amount']);
        }
        return $out;
    }

    /**
     * Údaje pro ruční přepis příkazu ze stejného účtu a VS, ze kterých se skládá QR
     * (QrPaymentGenerator: tuzemský účet má přednost, jinak IBAN; VS jen číslice).
     *
     * @param array<string,mixed> $bank
     * @return array{account_number:?string, bank_code:?string, iban:?string, variable_symbol:?string, amount:string}
     */
    private static function manualTransferDetails(array $bank, string $varsymbol, string $amount): array
    {
        $accountNumber = trim((string) ($bank['account_number'] ?? ''));
        $bankCode = trim((string) ($bank['bank_code'] ?? ''));
        $domestic = $accountNumber !== '' && $bankCode !== '';
        $iban = trim(str_replace(' ', '', (string) ($bank['iban'] ?? '')));
        $vs = VariableSymbolNormalizer::forPayment($varsymbol);
        return [
            'account_number' => $domestic ? $accountNumber : null,
            'bank_code' => $domestic ? $bankCode : null,
            'iban' => $iban !== '' ? $iban : null,
            'variable_symbol' => $vs !== '' ? $vs : null,
            'amount' => EetValues::amount($amount),
        ];
    }

    /** @param array<string,mixed> $invoice */
    private function qrPayload(array $invoice, string $amount): ?string
    {
        return $this->qr->buildPayload('CZK', (float) $amount, (string) ($invoice['varsymbol'] ?? ''), $this->bankSnapshot($invoice),
            '', null, 'Faktura ' . (string) ($invoice['varsymbol'] ?? ''));
    }

    /** @param array<string,mixed> $invoice @return array<string,mixed> */
    private function bankSnapshot(array $invoice): array
    {
        // Stejný účet jako QR na PDF faktury: snapshot dokladu doplněný aktuálním účtem měny.
        return $this->invoicePdf->resolveBank($invoice) ?? [];
    }

    private function afterCommit(?int $invoiceId, ?int $recordId): void
    {
        if ($invoiceId !== null) {
            try {
                $this->stats->recomputeAfterCommit($invoiceId);
            } catch (\Throwable $e) {
                error_log('Přepočet statistik po platbě EET faktury #' . $invoiceId . ' selhal: ' . $e->getMessage());
            }
        }
        if ($recordId !== null) {
            try {
                $this->dispatch->dispatchNow($recordId);
            } catch (\Throwable $e) {
                // Záznam je uložený ve frontě; worker ho odešle. Odpověď příkazu se nemění.
                error_log('Okamžité odeslání EET záznamu #' . $recordId . ' selhalo: ' . $e->getMessage());
            }
        }
    }

    /** @return array<string,mixed> */
    private function replayAfterRace(\PDOException $e, int $supplierId, string $key, string $hash): array
    {
        if (!PaymentEventService::isDuplicateKey($e)) {
            throw $e;
        }
        $existing = $this->events->replay($supplierId, $key, $hash);
        if ($existing === null) {
            throw $e;
        }
        return $existing;
    }

    /**
     * @param array{channel:?string, contact_context:string, business_purpose:string, currency:string, amount:string, invoice_id:?int} $in
     * @param array<string,mixed>|null $settings
     */
    private function evaluate(int $supplierId, array $in, \DateTimeImmutable $at, string $kind, ?array $settings = null): EetPolicyDecision
    {
        $settings ??= $this->settings->get($supplierId);
        $delegation = $this->deviceDelegation($supplierId, $in['device_id'] ?? null, $settings);
        return $this->policy->evaluate(new EetPolicyInput(
            $settings,
            $at,
            $in['channel'],
            $in['contact_context'],
            $in['business_purpose'],
            $in['currency'],
            $in['amount'],
            $in['invoice_id'] !== null ? 'invoice_payment' : 'quick_sale',
            $kind,
            $delegation,
        ));
    }

    /**
     * Pověření zařízení, přes které tržba půjde (zvolené, jinak výchozí). Neexistující
     * zařízení = bez pověření; chybu zařízení ohlásí až vznik záznamu.
     *
     * @param array<string,mixed> $settings
     */
    private function deviceDelegation(int $supplierId, ?int $deviceId, array $settings): EetDelegation
    {
        $deviceId = ($deviceId ?? 0) ?: (int) ($settings['default_device_id'] ?? 0);
        if ($deviceId <= 0) {
            return EetDelegation::none();
        }
        $stmt = $this->db->pdo()->prepare('SELECT delegation, delegating_eic FROM eet_devices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$deviceId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? EetDelegation::fromDevice($row) : EetDelegation::none();
    }

    /** @throws EetCommandException */
    private static function assertAcceptable(EetPolicyDecision $decision): void
    {
        if ($decision->decision === EetPolicyDecision::UNKNOWN) {
            throw EetCommandException::validation($decision->messageCs, null, ['reason_code' => $decision->reasonCode]);
        }
        if ($decision->decision === EetPolicyDecision::UNSUPPORTED) {
            throw EetCommandException::unsupported($decision->messageCs, $decision->reasonCode);
        }
    }

    /**
     * Validace a normalizace vstupu platby. Otisk idempotence se počítá z výsledku.
     *
     * @param array<string,mixed> $input
     * @return array{amount:string, currency:string, channel:?string, payment_method:?string, contact_context:string,
     *               business_purpose:string, invoice_id:?int, device_id:?int, note:?string, delegating_eic:?string}
     */
    private function normalizePayment(array $input, bool $requireChannel): array
    {
        $amount = EetValues::amount($input['amount'] ?? null);
        if (EetValues::toCents($amount) <= 0) {
            throw EetCommandException::validation('Částka platby musí být kladná. Vrácení peněz zadejte jako opravu EET.', 'amount');
        }
        if (EetValues::toCents($amount) > EetValues::MAX_ABS_CENTS) {
            throw EetCommandException::validation('Částka platby musí být pod 100 milionů.', 'amount');
        }
        $currency = strtoupper(trim((string) ($input['currency'] ?? 'CZK')));
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw EetCommandException::validation('Neplatná měna.', 'currency');
        }
        $channel = $input['channel'] ?? null;
        if ($channel === null || $channel === '') {
            if ($requireChannel) {
                throw EetCommandException::validation('Uveďte způsob platby (channel).', 'channel');
            }
            $channel = null;
        } else {
            $channel = self::enumValue($channel, EetPolicyInput::CHANNELS, 'channel');
        }
        $method = $input['payment_method'] ?? null;
        if ($method !== null && $method !== '') {
            if (!PaymentMethods::isValid($method)) {
                throw EetCommandException::validation('Neplatný způsob úhrady.', 'payment_method');
            }
            $method = (string) $method;
        } else {
            $method = $channel !== null ? self::DEFAULT_METHOD_BY_CHANNEL[$channel] : null;
        }
        // Pověření nese pokladní zařízení (situace 4 a 5 metodiky), ne jednotlivá platba.
        $delegating = $input['delegating_eic'] ?? null;
        if ($delegating !== null && $delegating !== '') {
            throw EetCommandException::validation(
                'Pověření jiného poplatníka se nastavuje u pokladního zařízení EET. Zvolte zařízení s pověřením (device_id).',
                'delegating_eic',
                ['reason_code' => 'delegation_requires_device'],
            );
        }
        $invoiceId = self::positiveInt($input['invoice_id'] ?? null, 'invoice_id', false);
        $purpose = self::enumValue($input['business_purpose'] ?? 'sale', EetPolicyInput::BUSINESS_PURPOSES, 'business_purpose');
        // Úhrada poukazem / kreditem je vždy čerpání kreditu a čerpání jde jen tímto kanálem.
        if ($channel === 'stored_value') {
            if (!in_array($purpose, ['sale', 'credit_draw'], true)) {
                throw EetCommandException::validation('Úhrada poukazem nebo kreditem je čerpání kreditu; jiný účel platby s ní zadat nejde.', 'business_purpose');
            }
            $purpose = 'credit_draw';
        } elseif ($purpose === 'credit_draw') {
            throw EetCommandException::validation('Čerpání kreditu zadejte způsobem platby „Poukaz / kredit" (channel = stored_value).', 'channel');
        }
        if ($purpose === 'credit_topup' && $invoiceId !== null) {
            throw EetCommandException::validation(
                'U faktury se dobití kreditu určuje podle položek (druh zboží pro EET). Účel credit_topup zadejte jen u prodeje bez faktury.',
                'business_purpose',
            );
        }
        return [
            'amount' => $amount,
            'currency' => $currency,
            'channel' => $channel,
            'payment_method' => $method,
            'contact_context' => self::enumValue($input['contact_context'] ?? 'unknown', EetPolicyInput::CONTACT_CONTEXTS, 'contact_context'),
            'business_purpose' => $purpose,
            'invoice_id' => $invoiceId,
            'device_id' => self::positiveInt($input['device_id'] ?? null, 'device_id', false),
            'note' => self::note($input['note'] ?? null),
            // Klíč zůstává kvůli stabilnímu otisku idempotence dřívějších požadavků.
            'delegating_eic' => null,
        ];
    }

    /**
     * Faktura firmy (volitelně zamčená). Cizí nebo neexistující = validační chyba.
     *
     * @return array<string,mixed>
     */
    private function invoice(int $supplierId, int $invoiceId, bool $forUpdate): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT i.id, i.invoice_type, i.status, i.amount_to_pay, i.paid_total, i.varsymbol, i.bank_snapshot, i.currency_id,
                    cur.code AS currency
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.id = ? AND i.supplier_id = ?' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$invoiceId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw EetCommandException::validation('Faktura nenalezena.', 'invoice_id');
        }
        return $row;
    }

    /** @param array<string,mixed> $invoice */
    private function assertInvoiceAccepts(int $supplierId, array $invoice, string $amount, string $currency, bool $includeOpenIntents = false): void
    {
        if (!in_array((string) $invoice['invoice_type'], ['invoice', 'proforma'], true)) {
            throw EetCommandException::validation('Platbu lze přijmout jen k faktuře nebo zálohové faktuře.', 'invoice_id');
        }
        if (!in_array((string) $invoice['status'], self::PAYABLE_STATUSES, true)) {
            throw EetCommandException::validation('Platbu lze přijmout jen k vystavenému dokladu.', 'invoice_id');
        }
        if ((string) $invoice['currency'] !== $currency) {
            throw EetCommandException::validation('Měna platby se liší od měny faktury.', 'currency');
        }
        $remaining = $this->remainingCents($supplierId, $invoice, $includeOpenIntents);
        $tolerance = (int) round(InvoicePaymentService::TOLERANCE * 100);
        if (EetValues::toCents($amount) > $remaining + $tolerance) {
            throw EetCommandException::validation(
                'Částka převyšuje zbývající dluh faktury (' . EetValues::fromCents(max(0, $remaining)) . ').',
                'amount',
                ['remaining' => EetValues::fromCents(max(0, $remaining))],
            );
        }
    }

    /**
     * Zbývá k úhradě: částka k úhradě − zaevidované úhrady − potvrzené QR příkazy,
     * které ještě nevypořádalo bankovní párování.
     *
     * @param array<string,mixed> $invoice
     */
    private function remainingCents(int $supplierId, array $invoice, bool $includeOpenIntents = false): int
    {
        $open = 0;
        if ($includeOpenIntents) {
            // Nový QR záměr nesmí s otevřenými záměry téže faktury přesáhnout dluh: obě
            // potvrzení by jinak založila dvě tržby na jedny peníze. Opuštěný záměr se zruší.
            $stmt = $this->db->pdo()->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM payment_events
                  WHERE supplier_id = ? AND invoice_id = ? AND origin = 'payment_intent' AND payment_order_status = 'initiated'"
            );
            $stmt->execute([$supplierId, (int) $invoice['id']]);
            $open = EetValues::toCents(EetValues::amount((string) $stmt->fetchColumn()));
        }
        return $this->settledRemainingCents($supplierId, $invoice) - $open;
    }

    /** @param array<string,mixed> $invoice */
    private function settledRemainingCents(int $supplierId, array $invoice): int
    {
        $pending = $this->events->openConfirmedIntentCents($supplierId, (int) $invoice['id']);
        return EetValues::toCents(EetValues::amount((string) ($invoice['amount_to_pay'] ?? '0')))
            - EetValues::toCents(EetValues::amount((string) $invoice['paid_total']))
            - $pending;
    }

    private function assertDeviceOwned(int $supplierId, int $deviceId): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM eet_devices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$deviceId, $supplierId]);
        if ($stmt->fetchColumn() === false) {
            throw EetCommandException::validation('Pokladní zařízení EET nenalezeno.', 'device_id');
        }
    }

    private function assertNoOpenTransaction(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            throw new \LogicException('Platební příkaz EET řídí vlastní transakci a odesílá až po commitu.');
        }
    }

    private function now(): \DateTimeImmutable
    {
        return EetValues::local($this->clock->now());
    }

    /** @param list<string> $allowed */
    private static function enumValue(mixed $value, array $allowed, string $field): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw EetCommandException::validation('Neplatná hodnota pole ' . $field . '.', $field, ['allowed' => $allowed]);
        }
        return $value;
    }

    private static function positiveInt(mixed $value, string $field, bool $required): ?int
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw EetCommandException::validation('Chybí pole ' . $field . '.', $field);
            }
            return null;
        }
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value <= 0) {
            throw EetCommandException::validation('Neplatné pole ' . $field . '.', $field);
        }
        return $value;
    }

    private static function note(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : mb_substr($value, 0, 255);
    }
}
