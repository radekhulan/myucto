<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

use MyInvoice\Infrastructure\Database\Connection;
use Psr\Clock\ClockInterface;

/**
 * Rozcestí ruční úhrady faktury (`POST /api/invoices/{id}/payments`, `mark-paid`) mezi
 * původní cestou a společným platebním příkazem EET (EET-05).
 *
 * Firma bez aktivní evidence: původní cesta beze změny, EET pole v těle se ignorují.
 * Firma s aktivní evidencí musí říct, jak platba proběhla (`channel`,
 * `contact_context`), jinak 422 `eet_context_required`: starší klient by evidenci
 * jinak tiše podhodnotil. Zkratka `eet_context = remote_transfer` je převod z domova
 * (vzdálená bezhotovostní platba). O povinnosti rozhoduje {@see EetRecordingPolicy}:
 *  - tržba (required/voluntary) → {@see EetPaymentCommand::accept()} s `Idempotency-Key`,
 *    jedna platební událost, jedna účetní úhrada, jeden EET záznam;
 *  - mimo evidenci (not_subject) → původní cesta (datum úhrady zůstává volné);
 *  - neúplný kontext nebo scénář mimo MVP → 422 bez zápisu.
 *
 * Zkratka `eet_context = external`: tržbu už evidoval jiný systém (e-shop s vlastní
 * EET). Úhrada jde přes {@see EetPaymentCommand::acceptExternal()}: běžný účetní zápis
 * a EET záznam `external`, který se neodesílá (žádné dvojí hlášení). API token
 * s volbou `eet_external_by_default` posílá takové úhrady bez kontextu; platí jen pro
 * bearer požadavek bez `eet_context` i bez `channel`, webová relace ji nedědí.
 */
final class EetInvoicePaymentGate
{
    public const REMOTE_TRANSFER = 'remote_transfer';
    public const EXTERNAL = EetPaymentCommand::EXTERNAL_CONTEXT;
    public const CONTEXTS = [self::REMOTE_TRANSFER, self::EXTERNAL];

    public function __construct(
        private readonly Connection $db,
        private readonly EetSettingsService $settings,
        private readonly EetRecordingPolicy $policy,
        private readonly EetPaymentCommand $command,
        private readonly PaymentEventService $events,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @param array<string,mixed> $body tělo požadavku akce
     * @param string $invoicePaymentSource zdroj úhrady, jaký by akce zapsala bez EET (manual / mark_paid)
     * @return array{mode:'legacy'|'eet', decision:?array<string,mixed>, result:?array<string,mixed>}
     * @throws EetCommandException|IdempotencyConflictException
     */
    public function route(int $supplierId, int $userId, int $invoiceId, string $amount, array $body, string $idempotencyKey,
        string $invoicePaymentSource = 'manual', bool $externalByDefault = false): array
    {
        $now = EetValues::local($this->clock->now());
        $settings = $this->settings->get($supplierId);
        if (!$this->policy->activeAt($settings, $now)) {
            return ['mode' => 'legacy', 'decision' => null, 'result' => null];
        }

        $shortcut = $body['eet_context'] ?? null;
        if ($shortcut !== null && $shortcut !== '' && !in_array($shortcut, self::CONTEXTS, true)) {
            throw EetCommandException::validation('Neplatná hodnota pole eet_context.', 'eet_context', ['allowed' => self::CONTEXTS]);
        }
        if (($shortcut === null || $shortcut === '') && $externalByDefault && in_array($body['channel'] ?? null, [null, ''], true)) {
            $shortcut = self::EXTERNAL;
        }
        if ($shortcut === self::EXTERNAL) {
            return $this->routeExternal($supplierId, $userId, $invoiceId, $amount, $body, $idempotencyKey, $invoicePaymentSource);
        }
        if ($shortcut === self::REMOTE_TRANSFER) {
            $channel = 'bank_transfer';
            $context = 'remote';
        } else {
            $channel = $body['channel'] ?? null;
            if ($channel === null || $channel === '') {
                throw self::contextRequired('channel_missing', 'Firma eviduje tržby (EET). Uveďte, jak zákazník platil.');
            }
            $context = $body['contact_context'] ?? 'unknown';
        }

        $invoice = $this->invoice($supplierId, $invoiceId);
        $input = [
            'invoice_id' => $invoiceId,
            'amount' => $amount,
            'currency' => $invoice['currency'],
            'channel' => $channel,
            'contact_context' => $context,
            // Úhrada poukazem / kreditem je čerpání kreditu (EetRecordingPolicy).
            'business_purpose' => $body['business_purpose'] ?? ($channel === 'stored_value' ? 'credit_draw'
                : ($invoice['invoice_type'] === 'proforma' ? 'order_deposit' : 'sale')),
            'payment_method' => $body['payment_method'] ?? null,
            'device_id' => $body['device_id'] ?? null,
            'received_at' => $body['received_at'] ?? null,
            'note' => $body['note'] ?? null,
        ];
        foreach (['channel' => EetPolicyInput::CHANNELS, 'contact_context' => EetPolicyInput::CONTACT_CONTEXTS,
            'business_purpose' => EetPolicyInput::BUSINESS_PURPOSES] as $field => $allowed) {
            if (!is_string($input[$field]) || !in_array($input[$field], $allowed, true)) {
                throw EetCommandException::validation('Neplatná hodnota pole ' . $field . '.', $field, ['allowed' => $allowed]);
            }
        }
        $receivedAt = EetValues::clientTime($input['received_at'], $now, 'received_at');
        $decision = $this->policy->evaluate(new EetPolicyInput(
            $settings, $receivedAt, $input['channel'], $input['contact_context'], $input['business_purpose'],
            (string) $input['currency'], EetValues::amount($amount), 'invoice_payment', 'payment',
        ));

        if ($decision->decision === EetPolicyDecision::UNKNOWN) {
            throw self::contextRequired($decision->reasonCode, $decision->messageCs);
        }
        if ($decision->decision === EetPolicyDecision::UNSUPPORTED) {
            throw EetCommandException::unsupported($decision->messageCs, $decision->reasonCode);
        }
        if (!$decision->createsRecord()) {
            // Úhrada mimo evidenci (převod z domova) nesmí krýt dluh, který drží potvrzený
            // QR příkaz čekající na banku: ten už tržbu má a vypořádá ho bankovní párování.
            $this->events->assertNoQrOverlap($supplierId, $invoiceId,
                EetValues::toCents(EetValues::amount((string) $invoice['amount_to_pay'])),
                EetValues::toCents(EetValues::amount((string) $invoice['paid_total'])),
                EetValues::toCents(EetValues::amount($amount)),
                (int) round(\MyInvoice\Service\Invoice\InvoicePaymentService::TOLERANCE * 100));
            return ['mode' => 'legacy', 'decision' => $decision->toArray(), 'result' => null];
        }

        $key = PaymentEventService::validateKey($idempotencyKey);
        $paidOn = $body['paid_on'] ?? $body['paid_at'] ?? null;
        if (is_string($paidOn) && $paidOn !== '' && $paidOn !== $receivedAt->format('Y-m-d')) {
            throw EetCommandException::validation(
                'U evidované tržby se datum úhrady řídí okamžikem přijetí platby (received_at), ne zadaným datem.',
                'received_at',
            );
        }
        $result = $this->command->accept($supplierId, $userId, $key, $input, $invoicePaymentSource);
        return ['mode' => 'eet', 'decision' => $decision->toArray(), 'result' => $result];
    }

    /**
     * Úhrada, jejíž tržbu evidoval jiný systém: běžný účetní zápis a záznam `external`.
     *
     * @param array<string,mixed> $body
     * @return array{mode:'eet', decision:array<string,mixed>, result:array<string,mixed>}
     */
    private function routeExternal(int $supplierId, int $userId, int $invoiceId, string $amount, array $body, string $idempotencyKey,
        string $invoicePaymentSource): array
    {
        $invoice = $this->invoice($supplierId, $invoiceId);
        $result = $this->command->acceptExternal($supplierId, $userId, trim($idempotencyKey) !== '' ? $idempotencyKey : null, [
            'invoice_id' => $invoiceId,
            'amount' => $amount,
            'currency' => $invoice['currency'],
            'channel' => $body['channel'] ?? null,
            'contact_context' => $body['contact_context'] ?? 'unknown',
            'payment_method' => $body['payment_method'] ?? null,
            'received_at' => $body['received_at'] ?? null,
            'paid_on' => $body['paid_on'] ?? $body['paid_at'] ?? null,
            'note' => $body['note'] ?? null,
            'external_eet' => $body['external_eet'] ?? null,
        ], $invoicePaymentSource);
        $decision = new EetPolicyDecision(EetPolicyDecision::EXTERNAL, 'external_system',
            'Tržbu evidoval externí systém; MyÚčto ji jen zaznamená a neodesílá.');
        return ['mode' => 'eet', 'decision' => $decision->toArray(), 'result' => $result];
    }

    /**
     * Má úhrada přijatá API tokenem bez kontextu EET platit jako externí evidence?
     * Jen bearer token s volbou `eet_external_by_default`; webová relace nikdy.
     *
     * @param array<string,mixed>|null $apiToken řádek tokenu z AuthMiddleware
     */
    public static function tokenDefaultsToExternal(?array $apiToken, bool $isBearer): bool
    {
        return $isBearer && $apiToken !== null && !empty($apiToken['eet_external_by_default']);
    }

    private static function contextRequired(string $reasonCode, string $message): EetCommandException
    {
        return new EetCommandException('eet_context_required', $message, 422, [
            'reason_code' => $reasonCode,
            'required' => ['channel', 'contact_context'],
            'shortcut' => ['eet_context' => self::REMOTE_TRANSFER],
            'external_shortcut' => ['eet_context' => self::EXTERNAL],
            'hint' => 'Pošlete channel (cash, card_terminal, qr_in_person, bank_transfer, stored_value…) a contact_context '
                . '(in_person, on_premises, in_vehicle, remote) s hlavičkou Idempotency-Key; převod z domova '
                . 'stačí označit eet_context = remote_transfer, tržbu evidovanou e-shopem eet_context = external.',
        ]);
    }

    /** @return array{currency:string, invoice_type:string, amount_to_pay:string, paid_total:string} */
    private function invoice(int $supplierId, int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT i.invoice_type, i.amount_to_pay, i.paid_total, cur.code AS currency
               FROM invoices i JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.id = ? AND i.supplier_id = ?'
        );
        $stmt->execute([$invoiceId, $supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw EetCommandException::notFound('Faktura nenalezena.');
        }
        return ['currency' => (string) $row['currency'], 'invoice_type' => (string) $row['invoice_type'],
            'amount_to_pay' => (string) ($row['amount_to_pay'] ?? '0'), 'paid_total' => (string) ($row['paid_total'] ?? '0')];
    }
}
