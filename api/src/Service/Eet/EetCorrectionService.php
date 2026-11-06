<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\Currency\PaymentExchangeRate;
use PDO;
use Psr\Clock\ClockInterface;

/**
 * Záporná oprava evidované tržby (vratka, neprovedený QR příkaz, chybná data, duplicita).
 *
 * EET neumí původní zprávu zrušit ani přepsat. Oprava je nová záporná tržba se
 * svým číslem z řady zařízení, aktuálním časem a novým UUID; vazbu na původní
 * záznam a důvod drží MyÚčto interně. Každá oprava má vlastní platební událost
 * (kind = refund), takže UNIQUE(payment_event_id, kind) platí i pro opravy.
 *
 * Součet oprav nesmí překročit dosud neopravenou část původní tržby. Souběžné
 * opravy zamykají původní záznam (FOR UPDATE), opakované odeslání stejného
 * `Idempotency-Key` vrátí původní opravu.
 *
 * Tržba přijatá v cizí měně: oprava se zadává v Kč (`amount`), nebo v původní měně
 * (`original_amount`). Částka v původní měně se přepočte kurzem PŮVODNÍ tržby, ne
 * kurzem dne vratky: oprava vrací část téže evidované částky, takže vratka celé
 * tržby musí dát přesně celou CZK částku a součet částečných vratek nesmí utéct
 * mimo ni ({@see resolveAmount()}).
 *
 * Oprava jde do stejného prostředí a pod stejné EIČ jako původní záznam bez ohledu
 * na pozdější změnu nastavení. Tržbu evidovanou externím systémem (stav `external`)
 * opravuje MyÚčto běžnou zápornou tržbou ze svého zařízení: peníze vrací pokladna MyÚčta.
 *
 * Kredit ({@see creditParts()}): oprava tržby s `urceno_cerp_zuct` (vrácení peněz za
 * nevyčerpaný kredit) snižuje i tuto část úměrně opravené částce. Oprava tržby
 * uhrazené kreditem (`cerp_zuct`) s volbou `refund_to_stored_value` vrací peníze zpět
 * na kartu / kredit, takže nese záporné `cerp_zuct` (metodika FS „Popis položek datové zprávy“ v1.0, kap. 1 bod 14, příklad 3;
 * zdroj v api/resources/eet/README.md);
 * vratka v hotovosti kreditová pole nemá. Ostatní důvody (chybná data, duplicita…)
 * ruší původní zprávu, proto čerpání snižují úměrně.
 *
 * Účetní dopad: oprava je jen fiskální záznam a platební událost. Skutečné vrácení
 * peněz (důvod `refund`) se dál účtuje existujícími cestami (dobropis, výdajový
 * pokladní doklad, odchozí platba); událost proto nese
 * `accounting_settlement_status = pending`. Ostatní důvody peníze nehýbou
 * (`not_applicable`) a fiktivní pokladní ani bankovní výdej nevzniká.
 */
final class EetCorrectionService
{
    public const REASONS = ['refund', 'failed_payment', 'wrong_data', 'duplicate', 'other'];

    public function __construct(
        private readonly Connection $db,
        private readonly PaymentEventService $events,
        private readonly EetRecordFactory $records,
        private readonly EetDispatchService $dispatch,
        private readonly EetSettingsService $settings,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function preview(int $supplierId, int $recordId, array $input): array
    {
        $original = $this->original($supplierId, $recordId, false);
        $remaining = $this->remainingCents($supplierId, $original);
        $state = $this->foreignState($supplierId, $original);
        $reason = isset($input['reason']) && $input['reason'] !== '' ? self::reason($input['reason']) : null;
        [$czkInput, $foreignInput] = self::amountInput($input, false);
        $error = null;
        $foreignCents = null;
        try {
            [$cents, $foreignCents] = $this->resolveAmount($original, $state, $remaining, $czkInput ?? ($foreignInput === null ? $remaining : null), $foreignInput);
        } catch (EetCommandException $e) {
            $cents = $czkInput ?? 0;
            $error = $e->getMessage();
        }
        $now = EetValues::local($this->clock->now());
        try {
            $device = $this->correctionDevice($supplierId, $original, (int) ($input['device_id'] ?? 0) ?: null, $this->settings->get($supplierId), $now);
        } catch (EetCommandException) {
            $device = null;
        }
        $toStoredValue = self::refundToStoredValue($input);
        $credit = ['credit_topup_amount' => null, 'credit_draw_amount' => null];
        if ($error === null && $cents > 0) {
            try {
                $credit = $this->creditParts($supplierId, $original, $cents, $remaining, $reason ?? 'refund', $toStoredValue);
            } catch (EetCommandException $e) {
                $error = $e->getMessage();
            }
        }
        return [
            'original' => EetRecordPresenter::record($original),
            'corrected_total' => EetValues::fromCents(EetValues::toCents((string) $original['total_amount']) - $remaining),
            'remaining' => EetValues::fromCents($remaining),
            'foreign' => self::foreignSummary($state),
            // Tržba uhrazená poukazem / kreditem: nabídnout vrácení zpět na kartu / kredit.
            'stored_value_payment' => ($original['credit_draw_amount'] ?? null) !== null,
            'proposed' => [
                'total_amount' => EetValues::fromCents(-$cents),
                'original_amount' => $foreignCents !== null ? EetValues::fromCents(-$foreignCents) : null,
                'credit_topup_amount' => $credit['credit_topup_amount'],
                'credit_draw_amount' => $credit['credit_draw_amount'],
                'refund_to_stored_value' => $toStoredValue,
                'reason' => $reason,
                'device_id' => $device,
                'environment' => (string) $original['environment'],
                'sale_at' => $now->format(DATE_ATOM),
            ],
            'allowed' => $error === null && $cents > 0 && $cents <= $remaining && $device !== null,
            'error' => $error,
            'accounting' => $reason === 'refund'
                ? 'Vrácení peněz zaúčtujte dobropisem, výdajovým pokladním dokladem nebo odchozí platbou.'
                : 'Oprava nemění pohyb peněz ani účetnictví.',
        ];
    }

    /**
     * @param array<string,mixed> $input {amount | original_amount, reason, note?, device_id?}
     * @return array<string,mixed>
     */
    public function create(int $supplierId, int $userId, int $recordId, string $idempotencyKey, array $input): array
    {
        $key = PaymentEventService::validateKey($idempotencyKey);
        [$czkInput, $foreignInput] = self::amountInput($input, true);
        $reason = self::reason($input['reason'] ?? null);
        $note = isset($input['note']) && is_string($input['note']) && trim($input['note']) !== '' ? mb_substr(trim($input['note']), 0, 255) : null;
        $deviceId = isset($input['device_id']) && (int) $input['device_id'] > 0 ? (int) $input['device_id'] : null;
        $toStoredValue = self::refundToStoredValue($input);
        $hash = PaymentEventService::requestHash('records.corrections:' . $recordId, [
            'amount' => $czkInput !== null ? EetValues::fromCents($czkInput) : null,
            'original_amount' => $foreignInput !== null ? EetValues::fromCents($foreignInput) : null,
            'reason' => $reason, 'note' => $note, 'device_id' => $deviceId,
            'refund_to_stored_value' => $toStoredValue ? true : null,
        ]);
        $existing = $this->events->replay($supplierId, $key, $hash);
        if ($existing !== null) {
            return $this->result($supplierId, $existing, true);
        }

        $now = EetValues::local($this->clock->now());
        $settings = $this->settings->get($supplierId);
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Oprava EET řídí vlastní transakci a odesílá až po commitu.');
        }
        try {
            $created = TransactionFailure::retrying($pdo, function () use ($pdo, $supplierId, $userId, $recordId, $key, $hash, $czkInput, $foreignInput, $reason, $note, $deviceId, $now, $settings, $toStoredValue): array {
                $pdo->beginTransaction();
                try {
                    $original = $this->original($supplierId, $recordId, true);
                    $created = $this->insertLocked($supplierId, $userId, $original, $key, $hash, $czkInput, $foreignInput, $reason, $note, $deviceId, $now, $settings, null, $toStoredValue);
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
            if (!PaymentEventService::isDuplicateKey($e)) {
                throw $e;
            }
            $existing = $this->events->replay($supplierId, $key, $hash);
            if ($existing === null) {
                throw $e;
            }
            return $this->result($supplierId, $existing, true);
        }

        try {
            $this->dispatch->dispatchNow($created['record_id']);
        } catch (\Throwable $e) {
            error_log('Okamžité odeslání opravy EET #' . $created['record_id'] . ' selhalo: ' . $e->getMessage());
        }
        return $this->result($supplierId, (array) $this->events->find($supplierId, $created['event_id']), false);
    }

    /**
     * Oprava v transakci volajícího (storno pokladního dokladu): celá dosud neopravená
     * část tržby, odeslání až po commitu. Idempotentní podle klíče; když už je tržba
     * opravená celá, nevznikne nic a vrací null.
     *
     * @return array{event_id:int, record_id:int}|null
     */
    public function createWithinTransaction(int $supplierId, ?int $userId, int $recordId, string $idempotencyKey, string $reason,
        ?string $note = null, ?int $deviceId = null): ?array
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException('Oprava EET v transakci volajícího vyžaduje otevřenou transakci.');
        }
        if (!PaymentEventService::isInternalKey($idempotencyKey)) {
            throw new \LogicException('Oprava v transakci volajícího nese interní klíč (PaymentEventService::internalKey).');
        }
        $key = $idempotencyKey;
        $reason = self::reason($reason);
        $hash = PaymentEventService::requestHash('records.corrections:' . $recordId, ['reason' => $reason, 'scope' => 'remaining']);
        $existing = $this->events->replay($supplierId, $key, $hash);
        if ($existing !== null) {
            $stmt = $pdo->prepare("SELECT id FROM eet_records WHERE supplier_id = ? AND payment_event_id = ? AND kind = 'correction'");
            $stmt->execute([$supplierId, (int) $existing['id']]);
            return ['event_id' => (int) $existing['id'], 'record_id' => (int) $stmt->fetchColumn()];
        }
        $original = $this->original($supplierId, $recordId, true);
        $remaining = $this->remainingCents($supplierId, $original);
        if ($remaining <= 0) {
            return null;
        }
        $created = $this->insertLocked(
            $supplierId, $userId ?? 0, $original, $key, $hash, $remaining, null, $reason, $note, $deviceId,
            EetValues::local($this->clock->now()), $this->settings->get($supplierId),
        );
        $this->dispatch->dispatchAfterCommit($created['record_id']);
        return ['event_id' => $created['event_id'], 'record_id' => $created['record_id']];
    }

    /**
     * Skutečné vrácení peněz k dobropisu (EET-08): záporné opravy evidovaných tržeb
     * opravované faktury, v transakci volajícího (zaúčtování výdajového pokladního
     * dokladu, označení dobropisu jako vráceného). Odeslání až po commitu.
     *
     * Vrácená částka je v měně dobropisu (= měna faktury). Rozpouští se postupně od
     * nejnovější tržby: peníze se vrací z posledních přijatých plateb a starší tržby
     * (typicky už v uzavřeném období) zůstávají nedotčené; pořadí je deterministické,
     * takže opakování dává stejný výsledek. Každá tržba se sníží nejvýš o svou
     * neopravenou část. Vrácení nad evidovanou část se do EET nezaeviduje
     * (vrácení neevidované platby se nehlásí) a výsledek nese varování. Faktura bez
     * evidované tržby (zaplacená převodem z domova) nezaloží nic.
     *
     * Idempotence: klíč každé opravy je `sys:{scope}:{id tržby}`. Když pro `$keyScope`
     * už nějaká oprava existuje, nevznikne nic nového (opakované zaúčtování dokladu,
     * dvojklik) a vrátí se původní opravy.
     *
     * @param array{invoice_id?:?int, cash_document_id?:?int} $allocation vazba opravy na zdroj vrácení (dobropis, doklad)
     * @return array{corrections: list<array{event_id:int, record_id:int, total_amount:string, original_amount:?string}>,
     *               requested:string, refunded:string, refunded_czk:string, not_recorded:string, warning:?string, replayed:bool}
     */
    public function refundInvoiceSales(int $supplierId, ?int $userId, int $invoiceId, string $currency, int $amountCents,
        string $keyScope, ?int $deviceId, ?string $note, array $allocation, ?\DateTimeImmutable $refundedAt = null): array
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException('Vratka k dobropisu vzniká v transakci volajícího.');
        }
        $currency = strtoupper($currency);
        $existing = $this->refundsForScope($supplierId, $keyScope);
        if ($existing !== []) {
            return self::refundSummary($existing, $amountCents, null, true);
        }
        $stmt = $pdo->prepare(
            "SELECT r.*
               FROM payment_events e
               JOIN eet_records r ON r.payment_event_id = e.id AND r.supplier_id = e.supplier_id AND r.kind = 'sale'
              WHERE e.supplier_id = ? AND e.invoice_id = ?
              ORDER BY r.sale_at DESC, r.id DESC
              FOR UPDATE"
        );
        $stmt->execute([$supplierId, $invoiceId]);
        $sales = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($sales === [] || $amountCents <= 0) {
            return self::refundSummary([], $amountCents, null, false);
        }

        $now = EetValues::local($this->clock->now());
        $at = $refundedAt !== null ? min(EetValues::local($refundedAt), $now) : $now;
        $settings = $this->settings->get($supplierId);
        $rest = $amountCents;
        $created = [];
        $skipped = false;
        foreach ($sales as $sale) {
            if ($rest <= 0) {
                break;
            }
            $remaining = $this->remainingCents($supplierId, $sale);
            if ($remaining <= 0) {
                continue;
            }
            $state = $this->foreignState($supplierId, $sale);
            if ($currency === 'CZK') {
                if ($state !== null) {
                    $skipped = true;
                    continue;
                }
                $take = min($rest, $remaining);
                $czk = $take;
                $foreign = null;
            } else {
                if ($state === null || $state['currency'] !== $currency || !$state['consistent']) {
                    $skipped = true;
                    continue;
                }
                $take = min($rest, $state['total'] - $state['corrected']);
                if ($take <= 0) {
                    continue;
                }
                $czk = null;
                $foreign = $take;
            }
            $saleId = (int) $sale['id'];
            $key = PaymentEventService::internalKey($keyScope, $saleId);
            $hash = PaymentEventService::requestHash('records.corrections:' . $saleId,
                ['reason' => 'refund', 'scope' => $keyScope, 'amount' => EetValues::fromCents($take), 'currency' => $currency]);
            $result = $this->insertLocked($supplierId, $userId ?? 0, $sale, $key, $hash, $czk, $foreign, 'refund', $note, $deviceId, $at, $settings, $now);
            // Vrácení peněz účtuje sám zdroj (výdajový doklad, vrácení k dobropisu).
            $pdo->prepare("UPDATE payment_events SET accounting_settlement_status = 'settled' WHERE id = ? AND supplier_id = ?")
                ->execute([$result['event_id'], $supplierId]);
            $this->events->allocate($supplierId, $result['event_id'], EetValues::fromCents(-$take), [
                'invoice_id' => $allocation['invoice_id'] ?? null,
                'cash_document_id' => $allocation['cash_document_id'] ?? null,
            ]);
            $this->dispatch->dispatchAfterCommit($result['record_id']);
            $created[] = [
                'event_id' => $result['event_id'],
                'record_id' => $result['record_id'],
                'total_amount' => EetValues::fromCents(-$result['cents']),
                'original_amount' => $foreign !== null ? EetValues::fromCents(-$foreign) : null,
                'take' => $take,
            ];
            $rest -= $take;
        }
        $warning = null;
        if ($rest > 0) {
            $refunded = $amountCents - $rest;
            $warning = $refunded > 0
                ? 'Vrácená částka ' . EetValues::fromCents($amountCents) . ' ' . $currency . ' převyšuje dosud neopravenou evidovanou část tržeb '
                    . 'původní faktury; do EET se jako záporná tržba zaevidovalo jen ' . EetValues::fromCents($refunded) . ' ' . $currency
                    . '. Vrácení neevidované platby se neeviduje.'
                : 'Tržby původní faktury už jsou v EET opravené celé' . ($skipped ? ' nebo je nelze opravit v měně dobropisu' : '')
                    . '; vrácení se do EET nezaevidovalo.';
        }
        return self::refundSummary($created, $amountCents, $warning, false);
    }

    /**
     * Kolik z evidovaných tržeb faktury zbývá opravit (podklad pro náhled vrácení k dobropisu).
     *
     * @return array{records:int, remaining_czk:string, remaining:?string, currency:?string}
     */
    public function refundableForInvoice(int $supplierId, int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT r.*
               FROM payment_events e
               JOIN eet_records r ON r.payment_event_id = e.id AND r.supplier_id = e.supplier_id AND r.kind = 'sale'
              WHERE e.supplier_id = ? AND e.invoice_id = ?"
        );
        $stmt->execute([$supplierId, $invoiceId]);
        $count = 0;
        $czk = 0;
        $foreign = 0;
        $currency = null;
        $foreignOk = true;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $sale) {
            $count++;
            $remaining = $this->remainingCents($supplierId, $sale);
            if ($remaining <= 0) {
                continue;
            }
            $czk += $remaining;
            $state = $this->foreignState($supplierId, $sale);
            if ($state === null || !$state['consistent'] || ($currency !== null && $currency !== $state['currency'])) {
                $foreignOk = false;
                continue;
            }
            $currency = $state['currency'];
            $foreign += $state['total'] - $state['corrected'];
        }
        return [
            'records' => $count,
            'remaining_czk' => EetValues::fromCents($czk),
            'remaining' => $currency !== null && $foreignOk ? EetValues::fromCents($foreign) : null,
            'currency' => $currency,
        ];
    }

    /**
     * Opravy vzniklé pro daný zdroj vrácení (klíč `sys:{scope}:…`).
     *
     * @return list<array{event_id:int, record_id:int, total_amount:string, original_amount:?string, take:int}>
     */
    private function refundsForScope(int $supplierId, string $keyScope): array
    {
        $prefix = PaymentEventService::INTERNAL_KEY_PREFIX . $keyScope . ':';
        $stmt = $this->db->pdo()->prepare(
            "SELECT e.id AS event_id, r.id AS record_id, r.total_amount, e.original_amount, a.amount AS allocated
               FROM payment_events e
               JOIN eet_records r ON r.payment_event_id = e.id AND r.supplier_id = e.supplier_id AND r.kind = 'correction'
               LEFT JOIN payment_event_allocations a ON a.payment_event_id = e.id AND a.supplier_id = e.supplier_id
              WHERE e.supplier_id = ? AND e.idempotency_key LIKE ?
              ORDER BY e.id"
        );
        $stmt->execute([$supplierId, addcslashes($prefix, '%_\\') . '%']);
        return array_map(static fn (array $r): array => [
            'event_id' => (int) $r['event_id'],
            'record_id' => (int) $r['record_id'],
            'total_amount' => EetValues::amount((string) $r['total_amount']),
            'original_amount' => $r['original_amount'] !== null ? EetValues::amount((string) $r['original_amount']) : null,
            'take' => -EetValues::toCents(EetValues::amount((string) ($r['allocated'] ?? $r['original_amount'] ?? $r['total_amount']))),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @param list<array{event_id:int, record_id:int, total_amount:string, original_amount:?string, take:int}> $created
     * @return array{corrections: list<array{event_id:int, record_id:int, total_amount:string, original_amount:?string}>,
     *               requested:string, refunded:string, refunded_czk:string, not_recorded:string, warning:?string, replayed:bool}
     */
    private static function refundSummary(array $created, int $requestedCents, ?string $warning, bool $replayed): array
    {
        $refunded = array_sum(array_column($created, 'take'));
        $czk = array_sum(array_map(static fn (array $c): int => -EetValues::toCents($c['total_amount']), $created));
        return [
            'corrections' => array_map(static fn (array $c): array => [
                'event_id' => $c['event_id'], 'record_id' => $c['record_id'],
                'total_amount' => $c['total_amount'], 'original_amount' => $c['original_amount'],
            ], $created),
            'requested' => EetValues::fromCents($requestedCents),
            'refunded' => EetValues::fromCents($refunded),
            'refunded_czk' => EetValues::fromCents($czk),
            'not_recorded' => EetValues::fromCents(max(0, $requestedCents - $refunded)),
            'warning' => $warning,
            'replayed' => $replayed,
        ];
    }

    /**
     * Zápis opravy nad zamčeným původním záznamem. Částka v Kč (`$czkCents`), nebo
     * v původní měně tržby (`$foreignCents`), viz {@see resolveAmount()}.
     *
     * @param array<string,mixed> $original
     * @param array<string,mixed> $settings
     * @return array{event_id:int, record_id:int, cents:int}
     */
    private function insertLocked(int $supplierId, int $userId, array $original, string $key, string $hash, ?int $czkCents, ?int $foreignCents,
        string $reason, ?string $note, ?int $deviceId, \DateTimeImmutable $at, array $settings, ?\DateTimeImmutable $now = null,
        bool $refundToStoredValue = false): array
    {
        $now ??= $at;
        $remaining = $this->remainingCents($supplierId, $original);
        $state = $this->foreignState($supplierId, $original);
        [$cents, $foreignCents] = $this->resolveAmount($original, $state, $remaining, $czkCents, $foreignCents);
        if ($cents > $remaining) {
            throw EetCommandException::validation(
                'Oprava překračuje dosud neopravenou část tržby (' . EetValues::fromCents($remaining) . ').',
                'amount',
                ['remaining' => EetValues::fromCents($remaining)],
            );
        }
        $credit = $this->creditParts($supplierId, $original, $cents, $remaining, $reason, $refundToStoredValue);
        $originalEvent = $original['payment_event_id'] !== null
            ? $this->events->find($supplierId, (int) $original['payment_event_id'], true)
            : null;
        $deviceId = $this->correctionDevice($supplierId, $original, $deviceId, $settings, $at);
        $foreign = $state !== null && $foreignCents !== null;

        $eventId = $this->events->insert([
            'supplier_id' => $supplierId,
            'idempotency_key' => $key,
            'request_hash' => $hash,
            'kind' => 'refund',
            'origin' => 'correction',
            'payment_method' => $originalEvent['payment_method'] ?? null,
            'channel' => $originalEvent['channel'] ?? null,
            'contact_context' => $originalEvent['contact_context'] ?? 'unknown',
            'business_purpose' => $originalEvent['business_purpose'] ?? 'sale',
            'amount' => EetValues::fromCents(-$cents),
            'currency' => 'CZK',
            'original_currency' => $foreign ? $state['currency'] : null,
            'original_amount' => $foreign ? EetValues::fromCents(-$foreignCents) : null,
            'exchange_rate' => $foreign ? $state['rate_string'] : null,
            'exchange_rate_source' => $foreign ? $state['source'] : null,
            'received_at' => $reason === 'refund' ? EetValues::dbTime($at) : null,
            'recorded_at' => EetValues::dbTime($now),
            'paid_on' => $reason === 'refund' ? $at->format('Y-m-d') : null,
            'payment_order_status' => match ($reason) {
                'refund' => 'settled',
                'failed_payment' => 'failed',
                default => 'cancelled',
            },
            'bank_verification_status' => 'not_applicable',
            'accounting_settlement_status' => $reason === 'refund' ? 'pending' : 'not_applicable',
            'invoice_id' => $originalEvent['invoice_id'] ?? null,
            'eet_decision' => EetPolicyDecision::REQUIRED,
            'eet_reason_code' => 'correction_' . $reason,
            'policy_version' => EetRecordingPolicy::VERSION,
            'corrects_event_id' => $originalEvent !== null ? (int) $originalEvent['id'] : null,
            'note' => $note,
            'created_by' => $userId > 0 ? $userId : null,
        ]);

        $record = $this->records->create($supplierId, $eventId, [
            'eic' => (string) $original['eic'],
            'environment' => (string) $original['environment'],
        ] + $settings, [
            'device_id' => $deviceId,
            'sale_at' => $at,
            'total_amount' => EetValues::fromCents(-$cents),
            'credit_topup_amount' => $credit['credit_topup_amount'],
            'credit_draw_amount' => $credit['credit_draw_amount'],
            'kind' => 'correction',
            'corrects_record_id' => (int) $original['id'],
            'correction_reason' => $reason,
            // Záporná tržba patří stejnému pověření jako opravovaná tržba.
            'delegation' => EetDelegation::fromRecord($original),
            'policy_decision' => EetPolicyDecision::REQUIRED,
            'policy_reason' => 'correction_' . $reason,
            'created_by' => $userId > 0 ? $userId : null,
            'now' => $now,
        ]);
        $this->db->pdo()->prepare('UPDATE payment_events SET device_id = ?, unit_id = ? WHERE id = ? AND supplier_id = ?')
            ->execute([$record['device_id'], $record['unit_id'], $eventId, $supplierId]);

        if ($originalEvent !== null && $cents === $remaining && $reason !== 'refund') {
            $this->releaseZeroedOrder($supplierId, (int) $originalEvent['id'], $reason);
        }
        return ['event_id' => $eventId, 'record_id' => $record['id'], 'cents' => $cents];
    }

    /**
     * Částka opravy v haléřích CZK a (u cizoměnové tržby) v haléřích původní měny.
     *
     * Částka v původní měně se přepočte kurzem původní tržby KUMULATIVNĚ: CZK opravené
     * po této opravě = přepočet celkem opravené cizí částky, a oprava je rozdíl proti
     * dosud opraveným Kč. Zaokrouhlení jednotlivých částečných vratek se tak nesčítá,
     * součet oprav nikdy nepřesáhne CZK tržbu a vratka celé zbývající cizí částky dá
     * přesně celou zbývající CZK částku (i když CZK tržba vznikla součtem přepočtených
     * řádků DPH valutového dokladu a od prostého přepočtu se o haléř liší).
     *
     * Oprava zadaná v Kč na celou zbývající část cizoměnové tržby dostane i zbývající
     * cizí částku; částečná oprava v Kč cizí částku nemá a další opravy v původní měně
     * už nejdou (jejich přepočet by se nedal navázat).
     *
     * @param array<string,mixed> $original
     * @param array<string,mixed>|null $state {@see foreignState()}
     * @return array{0:int, 1:?int}
     * @throws EetCommandException
     */
    private function resolveAmount(array $original, ?array $state, int $remaining, ?int $czkCents, ?int $foreignCents): array
    {
        if ($foreignCents !== null) {
            if ($state === null) {
                throw EetCommandException::validation('Tržba byla přijatá v Kč; částku opravy zadejte v Kč.', 'original_amount');
            }
            if (!$state['consistent']) {
                throw EetCommandException::validation('Tržbu už snížila oprava zadaná v Kč; další opravu zadejte také v Kč.', 'original_amount');
            }
            if ($foreignCents <= 0) {
                throw EetCommandException::validation('Částka opravy se zadává kladně (o kolik se tržba snižuje).', 'original_amount');
            }
            $remainingForeign = $state['total'] - $state['corrected'];
            if ($foreignCents > $remainingForeign) {
                throw EetCommandException::validation(
                    'Oprava překračuje dosud neopravenou část tržby (' . EetValues::fromCents($remainingForeign) . ' ' . $state['currency'] . ').',
                    'original_amount',
                    ['remaining_original' => EetValues::fromCents($remainingForeign), 'currency' => $state['currency']],
                );
            }
            $totalCzk = EetValues::toCents((string) $original['total_amount']);
            $after = $state['corrected'] + $foreignCents;
            $czkAfter = $after === $state['total'] ? $totalCzk : min($totalCzk, PaymentExchangeRate::toCzkCents($after, $state['rate']));
            $cents = $czkAfter - ($totalCzk - $remaining);
            if ($cents <= 0) {
                throw EetCommandException::validation('Částka opravy je po přepočtu na Kč nulová.', 'original_amount');
            }
            return [$cents, $foreignCents];
        }
        $cents = (int) $czkCents;
        if ($cents <= 0) {
            throw EetCommandException::validation('Částka opravy se zadává kladně (o kolik se tržba snižuje).', 'amount');
        }
        if ($state !== null && $state['consistent'] && $cents === $remaining) {
            return [$cents, $state['total'] - $state['corrected']];
        }
        return [$cents, null];
    }

    /**
     * Cizoměnová stopa tržby: měna, kurz a zdroj původní platby, celková a dosud
     * opravená cizí částka. `consistent` = všechny dosavadní opravy nesou cizí částku.
     * Null u tržby přijaté v Kč.
     *
     * @param array<string,mixed> $original
     * @return array{currency:string, total:int, corrected:int, rate:float, rate_string:string, source:?string, consistent:bool}|null
     */
    private function foreignState(int $supplierId, array $original): ?array
    {
        if (($original['payment_event_id'] ?? null) === null) {
            return null;
        }
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT original_currency, original_amount, exchange_rate, exchange_rate_source FROM payment_events WHERE id = ? AND supplier_id = ?');
        $stmt->execute([(int) $original['payment_event_id'], $supplierId]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($event === false || $event['original_currency'] === null || $event['original_amount'] === null
            || $event['exchange_rate'] === null || (float) $event['exchange_rate'] <= 0) {
            return null;
        }
        $corrections = $pdo->prepare(
            "SELECT e.original_currency, e.original_amount
               FROM eet_records c
               JOIN payment_events e ON e.id = c.payment_event_id AND e.supplier_id = c.supplier_id
              WHERE c.supplier_id = ? AND c.corrects_record_id = ? AND c.kind = 'correction'"
        );
        $corrections->execute([$supplierId, (int) $original['id']]);
        $corrected = 0;
        $consistent = true;
        foreach ($corrections->fetchAll(PDO::FETCH_ASSOC) ?: [] as $c) {
            if ($c['original_amount'] === null || $c['original_currency'] !== $event['original_currency']) {
                $consistent = false;
                continue;
            }
            $corrected -= EetValues::toCents(EetValues::amount((string) $c['original_amount']));
        }
        return [
            'currency' => (string) $event['original_currency'],
            'total' => EetValues::toCents(EetValues::amount((string) $event['original_amount'])),
            'corrected' => $corrected,
            'rate' => (float) $event['exchange_rate'],
            'rate_string' => EetCurrency::rate((float) $event['exchange_rate']),
            'source' => $event['exchange_rate_source'] !== null ? (string) $event['exchange_rate_source'] : null,
            'consistent' => $consistent,
        ];
    }

    /**
     * @param array<string,mixed>|null $state
     * @return array<string,mixed>|null
     */
    private static function foreignSummary(?array $state): ?array
    {
        if ($state === null) {
            return null;
        }
        return [
            'currency' => $state['currency'],
            'original_amount' => EetValues::fromCents($state['total']),
            'remaining' => $state['consistent'] ? EetValues::fromCents($state['total'] - $state['corrected']) : null,
            'exchange_rate' => $state['rate_string'],
            'exchange_rate_source' => $state['source'],
        ];
    }

    /**
     * Částka opravy z těla: `amount` (Kč), nebo `original_amount` (původní měna tržby).
     *
     * @param array<string,mixed> $input
     * @return array{0:?int, 1:?int}
     * @throws EetCommandException
     */
    private static function amountInput(array $input, bool $required): array
    {
        $czk = isset($input['amount']) && $input['amount'] !== '' ? EetValues::toCents(EetValues::amount($input['amount'])) : null;
        $foreign = isset($input['original_amount']) && $input['original_amount'] !== ''
            ? EetValues::toCents(EetValues::amount($input['original_amount'], 'original_amount')) : null;
        if ($czk !== null && $foreign !== null) {
            throw EetCommandException::validation('Zadejte částku opravy buď v Kč (amount), nebo v původní měně tržby (original_amount).', 'amount');
        }
        if ($required && $czk === null && $foreign === null) {
            throw EetCommandException::validation('Částka opravy se zadává kladně (o kolik se tržba snižuje).', 'amount');
        }
        if ($required && $czk !== null && $czk <= 0) {
            throw EetCommandException::validation('Částka opravy se zadává kladně (o kolik se tržba snižuje).', 'amount');
        }
        return [$czk, $foreign];
    }

    /**
     * Tržba je opravou vynulovaná a peníze se nevracely (neprovedený QR příkaz, duplicita,
     * chybná data): potvrzený příkaz, který čekal na banku, už nečeká. Přestane blokovat
     * dluh faktury a bankovní párování k němu úhradu nepřiváže
     * ({@see PaymentEventService::openConfirmedIntents()}). Vrácení peněz (`refund`)
     * stav příkazu nemění: peníze přišly a odešly samostatnou cestou. Ověřený příkaz
     * ověřeným zůstává. Částečná oprava stav nemění, blokovanou částku sníží sama.
     */
    private function releaseZeroedOrder(int $supplierId, int $eventId, string $reason): void
    {
        $this->db->pdo()->prepare(
            "UPDATE payment_events
                SET payment_order_status = ?,
                    accounting_settlement_status = IF(accounting_settlement_status = 'pending', 'not_applicable', accounting_settlement_status),
                    bank_verification_status = IF(bank_verification_status = 'verified', 'verified', 'not_applicable')
              WHERE id = ? AND supplier_id = ? AND payment_order_status = 'operator_confirmed'"
        )->execute([$reason === 'failed_payment' ? 'failed' : 'cancelled', $eventId, $supplierId]);
    }

    /**
     * Zařízení opravy. Výslovně zvolené zařízení ověří továrna záznamů. Jinak původní
     * zařízení, pokud je aktivní a jeho provozovna k času opravy platí; pak výchozí
     * zařízení firmy; pak jiné aktivní zařízení téže provozovny. Deaktivované zařízení
     * ani ukončená provozovna tak nezablokují opravu natrvalo.
     *
     * @param array<string,mixed> $original
     * @param array<string,mixed> $settings
     * @throws EetCommandException
     */
    private function correctionDevice(int $supplierId, array $original, ?int $requested, array $settings, \DateTimeImmutable $now): int
    {
        if ($requested !== null && $requested > 0) {
            return $requested;
        }
        $day = EetValues::local($now)->format('Y-m-d');
        $stmt = $this->db->pdo()->prepare(
            'SELECT d.id, d.unit_id
               FROM eet_devices d
               JOIN eet_units u ON u.id = d.unit_id AND u.supplier_id = d.supplier_id
              WHERE d.supplier_id = ? AND d.active = 1 AND u.valid_from <= ? AND (u.valid_to IS NULL OR u.valid_to >= ?)
              ORDER BY d.id'
        );
        $stmt->execute([$supplierId, $day, $day]);
        $usable = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $usable[(int) $row['id']] = (int) $row['unit_id'];
        }
        $originalDevice = (int) $original['device_id'];
        $default = (int) ($settings['default_device_id'] ?? 0);
        if (isset($usable[$originalDevice])) {
            return $originalDevice;
        }
        if ($default > 0 && isset($usable[$default])) {
            return $default;
        }
        $sameUnit = array_search((int) $original['unit_id'], $usable, true);
        if ($sameUnit !== false) {
            return (int) $sameUnit;
        }
        throw EetCommandException::validation(
            'Opravu EET nelze vytvořit: původní pokladní zařízení není aktivní nebo jeho provozovna skončila a firma nemá jiné '
            . 'aktivní zařízení s platnou provozovnou. Aktivujte zařízení v Nastavení EET, případně ho zvolte výslovně.',
            'device_id',
            ['reason_code' => 'no_active_device'],
        );
    }

    /**
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    private function result(int $supplierId, array $event, bool $replayed): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT * FROM eet_records WHERE supplier_id = ? AND payment_event_id = ? AND kind = 'correction'");
        $stmt->execute([$supplierId, (int) $event['id']]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $out = EetRecordPresenter::event($event, $record);
        $out['correction'] = $record !== null ? EetRecordPresenter::record($record) : null;
        if ($record !== null && $record['corrects_record_id'] !== null) {
            $original = $this->original($supplierId, (int) $record['corrects_record_id'], false);
            $out['original_remaining'] = EetValues::fromCents($this->remainingCents($supplierId, $original));
            $state = $this->foreignState($supplierId, $original);
            if ($state !== null && $state['consistent']) {
                $out['original_remaining_foreign'] = EetValues::fromCents($state['total'] - $state['corrected']);
            }
        }
        $out['idempotent_replay'] = $replayed;
        return $out;
    }

    /** @return array<string,mixed> */
    private function original(int $supplierId, int $recordId, bool $forUpdate): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM eet_records WHERE id = ? AND supplier_id = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
        $stmt->execute([$recordId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw EetCommandException::notFound('Záznam EET nenalezen.');
        }
        if ((string) $row['kind'] !== 'sale') {
            throw EetCommandException::validation('Opravit lze jen původní tržbu, ne opravu ani diagnostiku.', 'record_id');
        }
        return $row;
    }

    /** @param array<string,mixed> $original */
    private function remainingCents(int $supplierId, array $original): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(total_amount), 0) FROM eet_records
              WHERE supplier_id = ? AND corrects_record_id = ? AND kind = 'correction'"
        );
        $stmt->execute([$supplierId, (int) $original['id']]);
        $corrected = EetValues::toCents(EetValues::amount((string) $stmt->fetchColumn()));
        return EetValues::toCents((string) $original['total_amount']) + $corrected;
    }

    /**
     * Kreditové části opravy (záporné, v Kč) k opravě o `$cents` haléřů.
     *
     * `urceno_cerp_zuct` se snižuje úměrně a KUMULATIVNĚ jako cizí měna: opraveno po
     * této opravě = podíl celkem opravené částky, oprava = rozdíl proti dosud opravenému
     * kreditu; oprava celého zbytku dorovná kredit přesně. `cerp_zuct` jen při vrácení
     * na kartu / kredit nebo u důvodu, který ruší původní zprávu; vratka v hotovosti ho
     * nemá. Součet oprav nikdy nepřesáhne kredit původní tržby.
     *
     * @param array<string,mixed> $original
     * @return array{credit_topup_amount:?string, credit_draw_amount:?string}
     * @throws EetCommandException
     */
    private function creditParts(int $supplierId, array $original, int $cents, int $remaining, string $reason, bool $refundToStoredValue): array
    {
        $total = EetValues::toCents((string) $original['total_amount']);
        $topUp = $original['credit_topup_amount'] !== null ? EetValues::toCents((string) $original['credit_topup_amount']) : 0;
        $draw = $original['credit_draw_amount'] !== null ? EetValues::toCents((string) $original['credit_draw_amount']) : 0;
        if ($refundToStoredValue && ($draw <= 0 || $reason !== 'refund')) {
            throw EetCommandException::validation(
                'Vrátit peníze na kartu nebo kredit lze jen u vratky (důvod „vrácení peněz") tržby uhrazené poukazem nebo kreditem.',
                'refund_to_stored_value',
            );
        }
        if (($topUp <= 0 && $draw <= 0) || $total <= 0) {
            return ['credit_topup_amount' => null, 'credit_draw_amount' => null];
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(credit_topup_amount), 0), COALESCE(SUM(credit_draw_amount), 0) FROM eet_records
              WHERE supplier_id = ? AND corrects_record_id = ? AND kind = 'correction'"
        );
        $stmt->execute([$supplierId, (int) $original['id']]);
        [$topUpDone, $drawDone] = $stmt->fetch(PDO::FETCH_NUM) ?: ['0', '0'];
        $topUpDone = -EetValues::toCents(EetValues::amount((string) $topUpDone));
        $drawDone = -EetValues::toCents(EetValues::amount((string) $drawDone));

        $topUpPart = 0;
        if ($topUp > 0) {
            $correctedAfter = $total - $remaining + $cents;
            $topUpAfter = $correctedAfter >= $total ? $topUp : EetStoredValueCalculator::proportion($correctedAfter, $topUp, $total);
            $topUpPart = max(0, min($topUpAfter - $topUpDone, $topUp - $topUpDone));
        }
        $drawPart = 0;
        if ($draw > 0 && ($reason !== 'refund' || $refundToStoredValue)) {
            $drawPart = max(0, min(EetStoredValueCalculator::proportion($cents, $draw, $total), $draw - $drawDone));
        }
        return [
            'credit_topup_amount' => $topUpPart > 0 ? EetValues::fromCents(-$topUpPart) : null,
            'credit_draw_amount' => $drawPart > 0 ? EetValues::fromCents(-$drawPart) : null,
        ];
    }

    /** @param array<string,mixed> $input */
    private static function refundToStoredValue(array $input): bool
    {
        $value = $input['refund_to_stored_value'] ?? false;
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    private static function reason(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::REASONS, true)) {
            throw EetCommandException::validation('Uveďte důvod opravy.', 'reason', ['allowed' => self::REASONS]);
        }
        return $value;
    }
}
