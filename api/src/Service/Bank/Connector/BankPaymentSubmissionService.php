<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use MyInvoice\Repository\BankConnectionRepository;
use MyInvoice\Repository\BankPaymentOrderSubmissionRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Bank\AccountNumberNormalizer;

final class BankPaymentSubmissionService
{
    /** První dotaz na stav dávky čekající na podpis, pokud ho dřív nespustí návrat z banky. */
    private const FIRST_POLL_SECONDS = 15 * 60;
    /** Chyby příkazu samotného: přerušené odeslání po nich už nejde dokončit. */
    private const TERMINAL_RESUME_ERRORS = [
        'payment_order_changed', 'payment_order_no_longer_payable', 'payment_order_not_found', 'payment_order_date_expired',
        'payment_order_date_in_past', 'payment_order_items_not_payable', 'payment_order_not_domestic', 'payment_order_invalid',
        'payment_order_account_mismatch', 'payment_order_mark_paid_forbidden', 'payment_order_unsupported', 'payment_export_invalid',
        'payment_order_duplicate_items', 'payment_order_too_many_items',
    ];

    public function __construct(
        private readonly BankConnectionRepository $connections,
        private readonly BankPaymentOrderSubmissionRepository $submissions,
        private readonly BankConnectorRegistry $connectors,
        private readonly BankConnectorCallGuard $calls,
        private readonly SecretEncryption $secrets,
    ) {}

    public function find(int $supplierId, int $orderId, string $source = 'purchase_invoice'): ?array
    {
        return $this->submissions->find($supplierId, $orderId, $source);
    }

    /**
     * Předá příkaz bance. Každý příkaz má nejvýš jeden pokus o odeslání (unikátní klíč) a
     * ten se zapíše dřív, než se banka osloví; opakované volání banku znovu neosloví.
     *
     * Výjimkou je odeslání po položkách ({@see StagedBankPaymentConnector}), které zůstalo
     * ve stavu creating, protože proces skončil uprostřed (timeout, pád). Opakované volání
     * pak pod zámkem napojení pokračuje z evidence položek: založené položky se neposílají
     * znovu a položka s nejasným výsledkem odeslání zastaví k ruční kontrole.
     *
     * @return array{created:bool,submission:array<string,mixed>}
     */
    public function submit(int $supplierId, int $orderId, int $connectionId, ?int $userId, callable $prepare, string $source = 'purchase_invoice'): array
    {
        $existing = $this->submissions->find($supplierId, $orderId, $source);
        if ($existing !== null && !$this->resumable($existing, $connectionId)) {
            return ['created' => false, 'submission' => $existing];
        }

        $connection = $this->connections->findWithCredentialById($supplierId, $connectionId);
        if ($connection === null) {
            throw new BankConnectorOperationException('connection_not_found');
        }

        return $this->calls->withConnectionLock(
            $supplierId,
            (int) $connection['currency_id'],
            function () use ($supplierId, $orderId, $connectionId, $userId, $prepare, $source, $existing): array {
                // Nové odeslání chrání unikátní klíč v begin(); pokračování se pod zámkem ověří znovu.
                $resume = $existing !== null ? $this->submissions->find($supplierId, $orderId, $source) : null;
                if ($existing !== null && ($resume === null || !$this->resumable($resume, $connectionId))) {
                    return ['created' => false, 'submission' => $resume ?? $existing];
                }
                $connection = $this->connections->findWithCredentialById($supplierId, $connectionId);
                if ($connection === null) {
                    throw new BankConnectorOperationException('connection_not_found');
                }
                $connector = $this->connectors->get((string) $connection['provider']);
                if ($resume !== null && !$connector instanceof StagedBankPaymentConnector) {
                    return ['created' => false, 'submission' => $resume];
                }
                $submissionId = $resume !== null ? (int) $resume['id'] : null;
                try {
                    $this->assertConnection($connection);
                    $token = $this->decryptToken($connection);
                    $this->assertPaymentCapability($connector, $token);
                    $abo = $prepare($connection);
                    if ($connector instanceof StagedBankPaymentConnector) {
                        $this->assertStagedOrder($connector, $token, $abo);
                    }
                    if ($submissionId !== null) {
                        $state = $this->submissions->internalState($supplierId, $submissionId);
                        if ($state === null || $state['status'] !== 'creating' || !hash_equals($state['payload_hash'], hash('sha256', $abo))) {
                            throw new BankConnectorOperationException('payment_order_changed');
                        }
                    }
                } catch (BankConnectorOperationException $e) {
                    // Přerušené odeslání, které už nejde dokončit (příkaz se změnil, byl archivován,
                    // propadl), se uzavře jako nejasné; nové nevznikne. Přechodná chyba (souběh,
                    // časová pojistka, šifrovací klíč) ho nechá ve stavu creating k dalšímu pokusu.
                    if ($submissionId !== null && in_array($e->errorCode, self::TERMINAL_RESUME_ERRORS, true)) {
                        $this->submissions->fail($supplierId, $submissionId, 'unknown', $e->errorCode);
                    }
                    throw $e;
                }
                try {
                    $callResult = $this->calls->call(
                        $connector instanceof BankConnectorCredentialKeyProvider ? $connector->callGuardCredential($token) : $token,
                        function () use (
                            $supplierId,
                            $source,
                            $orderId,
                            $connectionId,
                            $connection,
                            $abo,
                            $userId,
                            $connector,
                            $token,
                            &$submissionId,
                        ): array {
                            if ($submissionId === null) {
                                $attempt = $this->submissions->begin(
                                    $supplierId,
                                    $orderId,
                                    $connectionId,
                                    (string) $connection['provider'],
                                    hash('sha256', $abo),
                                    $userId,
                                    $source,
                                );
                                if (!$attempt['created']) {
                                    return ['attempt' => $attempt, 'provider_result' => null];
                                }
                                $submissionId = (int) $attempt['submission']['id'];
                            } else {
                                $attempt = ['created' => true];
                            }
                            return [
                                'attempt' => $attempt,
                                'provider_result' => $connector instanceof StagedBankPaymentConnector
                                    ? $connector->submitStagedPaymentOrder($token, $abo,
                                        new StoredBankPaymentSubmissionJournal($this->submissions, $supplierId, $submissionId))
                                    : $connector->submitPaymentOrder($token, $abo),
                            ];
                        },
                    );
                    if (!$callResult['attempt']['created']) {
                        return $callResult['attempt'];
                    }
                    $result = (array) $callResult['provider_result'];
                    if (($result['accepted'] ?? false) !== true || trim((string) ($result['reference'] ?? '')) === '') {
                        throw new BankConnectorOperationException('provider_response_invalid');
                    }
                    $staged = $connector instanceof StagedBankPaymentConnector;
                    if ($staged && (($result['status'] ?? '') !== 'awaiting_signature' || !is_string($result['sign_hash'] ?? null))) {
                        throw new BankConnectorOperationException('provider_response_invalid');
                    }
                    $this->submissions->accept($supplierId, $submissionId, (string) $result['reference'],
                        $staged ? 'awaiting_signature' : (($result['status'] ?? '') === 'import_started' ? 'import_started' : 'accepted_awaiting_authorization'),
                        $staged ? (string) $result['sign_hash'] : null);
                    if ($staged) {
                        $this->submissions->updateSigning($supplierId, $submissionId, ['awaiting_signature'], ['next_poll_seconds' => self::FIRST_POLL_SECONDS]);
                    }
                } catch (BankConnectorException $e) {
                    if ($submissionId === null) {
                        throw new BankConnectorOperationException('submission_failed');
                    }
                    $status = $e->ambiguousPaymentOutcome || ((int) ($e->acceptedCount ?? 0)) > 0
                        ? 'unknown'
                        : 'rejected';
                    $this->submissions->fail(
                        $supplierId,
                        $submissionId,
                        $status,
                        $e->errorCode,
                        $e->acceptedCount,
                        $e->rejectedCount,
                        $e->remoteHttpStatus,
                    );
                } catch (BankConnectorOperationException $e) {
                    if ($submissionId === null) {
                        throw $e;
                    }
                    // Odeslání po položkách drží evidenci konzistentní (položka je „sending“ jen po dobu
                    // HTTP volání), takže chyba služby ve stavu creating se dá bezpečně zopakovat.
                    if ($connector instanceof StagedBankPaymentConnector
                        && ($this->submissions->internalState($supplierId, $submissionId)['status'] ?? null) === 'creating') {
                        throw $e;
                    }
                    $this->submissions->fail($supplierId, $submissionId, 'unknown', $e->errorCode);
                } catch (\Throwable) {
                    if ($submissionId === null) {
                        throw new BankConnectorOperationException('submission_failed');
                    }
                    $this->submissions->fail($supplierId, $submissionId, 'unknown', 'submission_failed');
                }

                return [
                    'created' => true,
                    'submission' => $this->submissions->find($supplierId, $orderId, $source)
                        ?? throw new BankConnectorOperationException('submission_state_unavailable'),
                ];
            },
        );
    }

    /**
     * Konektor a přístup napojení pro podpis a dotazy na stav dávky. Napojení musí projít
     * stejnými kontrolami jako při odeslání (aktivní, ověřený účet, šifrovací klíč).
     *
     * @return array{connector:SignableBankPaymentConnector,token:string,connection:array<string,mixed>}
     */
    public function signingAccess(int $supplierId, int $connectionId): array
    {
        $connection = $this->connections->findWithCredentialById($supplierId, $connectionId);
        if ($connection === null) {
            throw new BankConnectorOperationException('connection_not_found');
        }
        $this->assertConnection($connection);
        $connector = $this->connectors->get((string) $connection['provider']);
        if (!$connector instanceof SignableBankPaymentConnector) {
            throw new BankConnectorOperationException('payment_signature_unavailable');
        }
        return ['connector' => $connector, 'token' => $this->decryptToken($connection), 'connection' => $connection];
    }

    /** Pokračovat smí jen přerušené odeslání po položkách, a to přes totéž napojení. */
    private function resumable(array $submission, int $connectionId): bool
    {
        return ($submission['status'] ?? null) === 'creating' && (int) ($submission['connection_id'] ?? 0) === $connectionId;
    }

    private function assertStagedOrder(StagedBankPaymentConnector $connector, #[\SensitiveParameter] string $token, #[\SensitiveParameter] string $abo): void
    {
        try {
            $connector->assertPaymentOrder($token, $abo);
        } catch (BankConnectorException $e) {
            throw new BankConnectorOperationException($e->errorCode === BankConnectorException::INVALID_PAYMENT_ORDER
                ? 'payment_order_invalid' : $e->errorCode, $e->details);
        }
    }

    private function assertPaymentCapability(BankConnector $connector, #[\SensitiveParameter] string $token): void
    {
        if (!$connector instanceof BankPaymentCapabilityProvider) {
            return;
        }
        try {
            $available = $connector->canSubmitPaymentOrder($token);
        } catch (BankConnectorException) {
            throw new BankConnectorOperationException('submission_failed');
        }
        if (!$available) {
            throw new BankConnectorOperationException('payment_submission_unavailable');
        }
    }

    private function assertConnection(array $connection): void
    {
        if ($this->secrets->validateKey() !== null) {
            throw new BankConnectorOperationException('encryption_key_unavailable');
        }
        if (!(bool) $connection['enabled'] || !(bool) $connection['has_token']) {
            throw new BankConnectorOperationException('connection_disabled');
        }
        if (!(bool) ($connection['is_active'] ?? false)) {
            throw new BankConnectorOperationException('account_inactive');
        }
        if (
            !$this->connectors->supportsBankCode((string) $connection['provider'], (string) ($connection['bank_code'] ?? ''))
            || !in_array((string) ($connection['bank_code'] ?? ''), ['2010', '5500', '0300', '0100', '2250', '0600', '0800'], true)
            || (string) ($connection['verified_bank_code'] ?? '') !== (string) ($connection['bank_code'] ?? '')
            || strtoupper((string) ($connection['account_code'] ?? '')) !== 'CZK'
            || strtoupper((string) ($connection['verified_currency'] ?? '')) !== 'CZK'
            || !AccountNumberNormalizer::matchesAny(
                (string) ($connection['verified_account_number'] ?? ''),
                isset($connection['account_number']) ? (string) $connection['account_number'] : null,
                isset($connection['iban']) ? (string) $connection['iban'] : null,
            )
        ) {
            throw new BankConnectorOperationException('account_changed_revalidation_required');
        }
    }

    private function decryptToken(array $connection): string
    {
        $stored = (string) ($connection['token_ciphertext'] ?? '');
        if (!str_starts_with($stored, 'enc:v2:')) {
            throw new BankConnectorOperationException('credential_format_invalid');
        }
        try {
            return $this->secrets->decryptFor(
                $stored,
                sprintf(
                    'bank-connection:supplier:%d:connection:%d:type:token',
                    (int) $connection['supplier_id'],
                    (int) $connection['id'],
                ),
            );
        } catch (\Throwable) {
            throw new BankConnectorOperationException('credential_unavailable');
        }
    }
}
