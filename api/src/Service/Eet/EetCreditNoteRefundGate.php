<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use PDO;
use Psr\Clock\ClockInterface;

/**
 * Rozcestí „Označit vráceno" u dobropisu (`POST /api/invoices/{id}/mark-paid`) mezi
 * původní cestou a zápornou tržbou EET (EET-08).
 *
 * Původní cesta beze změny, když firma k okamžiku vrácení neeviduje, dobropis nemá
 * opravovanou fakturu, nebo opravovaná faktura nemá žádnou dosud neopravenou
 * evidovanou tržbu (zaplaceno převodem z domova: vrácení neevidované platby se
 * nehlásí). Jinak musí klient říct, jak peníze vrátil (`channel`, `contact_context`,
 * zkratka `eet_context = remote_transfer`), jinak 422 `eet_context_required`.
 * Kontaktní vrácení (hotově, kartou na terminálu na místě) rozhoduje
 * {@see EetRecordingPolicy::refundDecision()}: v jedné transakci označí dobropis
 * a vytvoří záporné opravy ({@see EetCorrectionService::refundInvoiceSales()}),
 * vrácení převodem na účet jde původní cestou.
 *
 * Datum vrácení evidovaného vrácení se řídí okamžikem vrácení (dnes), jiné zadané
 * datum se odmítne stejně jako u přijetí platby.
 */
final class EetCreditNoteRefundGate
{
    public function __construct(
        private readonly Connection $db,
        private readonly EetSettingsService $settings,
        private readonly EetRecordingPolicy $policy,
        private readonly EetCorrectionService $corrections,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @param array<string,mixed> $body tělo požadavku akce
     * @param callable(string):bool $markRefunded původní označení vrácení (InvoicePaymentService::markRefunded)
     * @return array{mode:'legacy'|'eet', paid_on:string, decision:?array<string,mixed>, eet:?array<string,mixed>}
     * @throws EetCommandException
     */
    public function route(int $supplierId, int $userId, int $creditNoteId, array $body, string $paidOn, callable $markRefunded): array
    {
        $legacy = static function (?array $decision = null) use ($markRefunded, $paidOn): array {
            $markRefunded($paidOn);
            return ['mode' => 'legacy', 'paid_on' => $paidOn, 'decision' => $decision, 'eet' => null];
        };
        $creditNote = $this->creditNote($supplierId, $creditNoteId);
        if ($creditNote === null) {
            return $legacy();
        }
        $now = EetValues::local($this->clock->now());
        $settings = $this->settings->get($supplierId);
        if (!$this->policy->activeAt($settings, $now)) {
            return $legacy();
        }
        $refundable = $this->corrections->refundableForInvoice($supplierId, $creditNote['parent_invoice_id']);
        if (EetValues::toCents($refundable['remaining_czk']) <= 0) {
            return $legacy();
        }

        $shortcut = $body['eet_context'] ?? null;
        if ($shortcut !== null && $shortcut !== '' && $shortcut !== EetInvoicePaymentGate::REMOTE_TRANSFER) {
            throw EetCommandException::validation('Neplatná hodnota pole eet_context.', 'eet_context', ['allowed' => [EetInvoicePaymentGate::REMOTE_TRANSFER]]);
        }
        if ($shortcut === EetInvoicePaymentGate::REMOTE_TRANSFER) {
            $channel = 'bank_transfer';
            $context = 'remote';
        } else {
            $channel = $body['channel'] ?? null;
            $context = $body['contact_context'] ?? 'unknown';
            if ($channel === null || $channel === '') {
                throw self::contextRequired('channel_missing',
                    'Původní faktura má evidované tržby (EET). Uveďte, jak jste zákazníkovi peníze vrátili.');
            }
            if (!is_string($channel) || !in_array($channel, EetPolicyInput::CHANNELS, true)) {
                throw EetCommandException::validation('Neplatná hodnota pole channel.', 'channel', ['allowed' => EetPolicyInput::CHANNELS]);
            }
            if (!is_string($context) || !in_array($context, EetPolicyInput::CONTACT_CONTEXTS, true)) {
                throw EetCommandException::validation('Neplatná hodnota pole contact_context.', 'contact_context', ['allowed' => EetPolicyInput::CONTACT_CONTEXTS]);
            }
        }
        $decision = $this->policy->refundDecision($settings, $now, $channel, $context);
        if ($decision->decision === EetPolicyDecision::UNKNOWN) {
            throw self::contextRequired($decision->reasonCode, $decision->messageCs);
        }
        if (!$decision->createsRecord()) {
            return $legacy($decision->toArray());
        }

        $today = $now->format('Y-m-d');
        if (isset($body['paid_at']) && is_string($body['paid_at']) && $body['paid_at'] !== '' && $body['paid_at'] !== $today) {
            throw EetCommandException::validation(
                'U vrácení evidovaného v EET se datum řídí okamžikem vrácení (dnes), ne zadaným datem.', 'paid_at');
        }
        $deviceId = $this->device($supplierId, $body['eet_device_id'] ?? $body['device_id'] ?? null, $settings);
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Vrácení k dobropisu s EET řídí vlastní transakci.');
        }
        $summary = TransactionFailure::retrying($pdo, function () use ($pdo, $supplierId, $userId, $creditNoteId, $creditNote, $markRefunded, $today, $deviceId, $now): ?array {
            $pdo->beginTransaction();
            try {
                $changed = $markRefunded($today);
                $summary = $changed
                    ? $this->corrections->refundInvoiceSales(
                        $supplierId, $userId, $creditNote['parent_invoice_id'], $creditNote['currency'], $creditNote['refund_cents'],
                        'refund-credit-note:' . $creditNoteId, $deviceId, 'Vrácení k dobropisu ' . $creditNote['number'],
                        ['invoice_id' => $creditNoteId], $now,
                    )
                    : null;
                $pdo->commit();
                return $summary;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        });
        return ['mode' => 'eet', 'paid_on' => $today, 'decision' => $decision->toArray(), 'eet' => $summary];
    }

    private static function contextRequired(string $reasonCode, string $message): EetCommandException
    {
        return new EetCommandException('eet_context_required', $message, 422, [
            'reason_code' => $reasonCode,
            'required' => ['channel', 'contact_context'],
            'shortcut' => ['eet_context' => EetInvoicePaymentGate::REMOTE_TRANSFER],
            'hint' => 'Pošlete channel (cash, card_terminal…) a contact_context (in_person, on_premises, in_vehicle, remote); '
                . 'vrácení převodem na účet stačí označit eet_context = remote_transfer.',
        ]);
    }

    /**
     * @param array<string,mixed> $settings
     * @throws EetCommandException
     */
    private function device(int $supplierId, mixed $requested, array $settings): ?int
    {
        if (is_numeric($requested) && (int) $requested > 0) {
            $stmt = $this->db->pdo()->prepare('SELECT id FROM eet_devices WHERE id = ? AND supplier_id = ?');
            $stmt->execute([(int) $requested, $supplierId]);
            if ($stmt->fetchColumn() === false) {
                throw EetCommandException::validation('Pokladní zařízení EET nenalezeno.', 'eet_device_id');
            }
            return (int) $requested;
        }
        $default = (int) ($settings['default_device_id'] ?? 0);
        if ($default <= 0) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare('SELECT id FROM eet_devices WHERE id = ? AND supplier_id = ? AND active = 1');
        $stmt->execute([$default, $supplierId]);
        return $stmt->fetchColumn() === false ? null : $default;
    }

    /** @return array{parent_invoice_id:int, currency:string, refund_cents:int, number:string}|null */
    private function creditNote(int $supplierId, int $creditNoteId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT i.invoice_type, i.parent_invoice_id, i.amount_to_pay, i.varsymbol, cur.code AS currency
               FROM invoices i JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.id = ? AND i.supplier_id = ?'
        );
        $stmt->execute([$creditNoteId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || (string) $row['invoice_type'] !== 'credit_note' || (int) ($row['parent_invoice_id'] ?? 0) <= 0) {
            return null;
        }
        $refund = -EetValues::toCents(EetValues::amount((string) ($row['amount_to_pay'] ?? '0')));
        if ($refund <= 0) {
            return null;
        }
        return [
            'parent_invoice_id' => (int) $row['parent_invoice_id'],
            'currency' => (string) $row['currency'],
            'refund_cents' => $refund,
            'number' => (string) ($row['varsymbol'] ?? ''),
        ];
    }
}
