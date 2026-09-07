<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use MyInvoice\Repository\BankConnectionRepository;
use MyInvoice\Repository\BankPaymentOrderSubmissionRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Bank\AccountNumberNormalizer;

final class BankPaymentSubmissionService
{
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

    /** @return array{created:bool,submission:array<string,mixed>} */
    public function submit(int $supplierId, int $orderId, int $connectionId, ?int $userId, callable $prepare, string $source = 'purchase_invoice'): array
    {
        $existing = $this->submissions->find($supplierId, $orderId, $source);
        if ($existing !== null) {
            return ['created' => false, 'submission' => $existing];
        }

        $connection = $this->connections->findWithCredentialById($supplierId, $connectionId);
        if ($connection === null) {
            throw new BankConnectorOperationException('connection_not_found');
        }

        return $this->calls->withConnectionLock(
            $supplierId,
            (int) $connection['currency_id'],
            function () use ($supplierId, $orderId, $connectionId, $userId, $prepare, $source): array {
                $connection = $this->connections->findWithCredentialById($supplierId, $connectionId);
                if ($connection === null) {
                    throw new BankConnectorOperationException('connection_not_found');
                }
                $this->assertConnection($connection);

                $abo = $prepare($connection);
                $token = $this->decryptToken($connection);
                $connector = $this->connectors->get((string) $connection['provider']);
                $submissionId = null;
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
                            return [
                                'attempt' => $attempt,
                                'provider_result' => $connector->submitPaymentOrder($token, $abo),
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
                    $this->submissions->accept($supplierId, $submissionId, (string) $result['reference'],
                        ($result['status'] ?? '') === 'import_started' ? 'import_started' : 'accepted_awaiting_authorization');
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
            || !in_array((string) ($connection['bank_code'] ?? ''), ['2010', '5500', '0300', '0100', '2250'], true)
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
