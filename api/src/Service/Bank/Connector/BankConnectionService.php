<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use MyInvoice\Repository\BankConnectionRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Bank\GpcParser;
use MyInvoice\Service\Bank\StatementReconciliationException;
use MyInvoice\Service\Bank\StatementImporter;

final class BankConnectionService
{
    private const INITIAL_DAYS = 14;
    private const OVERLAP_DAYS = 3;
    private const MAX_AUTOMATIC_DAYS = 89;
    private const MAX_MANUAL_DAYS = 31;

    public function __construct(
        private readonly BankConnectionRepository $connections,
        private readonly BankConnectorRegistry $connectors,
        private readonly BankConnectorCallGuard $calls,
        private readonly SecretEncryption $secrets,
        private readonly GpcParser $parser,
        private readonly StatementImporter $importer,
    ) {}

    public function overview(int $supplierId): array
    {
        return [
            'providers' => $this->connectors->catalog(),
            'connections' => $this->connections->listPublic($supplierId),
        ];
    }

    /** @param array<string,mixed> $input */
    public function configure(int $supplierId, int $currencyId, #[\SensitiveParameter] array $input): array
    {
        $provider = strtolower(trim((string) ($input['provider'] ?? '')));
        if ($provider === '') {
            throw new BankConnectorOperationException('provider_required');
        }
        $enabled = array_key_exists('enabled', $input) ? (bool) $input['enabled'] : true;
        $hasNewToken = array_key_exists('token', $input) || array_key_exists('credentials', $input);
        $newToken = array_key_exists('token', $input) ? trim((string) $input['token']) : null;
        if (array_key_exists('token', $input) && ($newToken === '' || strlen($newToken) > 512)) {
            throw new BankConnectorOperationException('token_invalid');
        }

        return $this->calls->withConnectionLock($supplierId, $currencyId, function () use (
            $supplierId, $currencyId, $provider, $enabled, $hasNewToken, $newToken, $input,
        ): array {
            $connectionId = $this->connections->ensure($supplierId, $currencyId, $provider);
            $connection = $this->connections->findWithCredentialByCurrency($supplierId, $currencyId);
            if ($connection === null) {
                throw new BankConnectorOperationException('connection_not_found');
            }
            if (!$enabled && !$hasNewToken) {
                $this->connections->setEnabled($supplierId, $connectionId, false);
                return $this->connections->findPublicByCurrency($supplierId, $currencyId)
                    ?? throw new BankConnectorOperationException('connection_not_found');
            }
            $this->assertEncryptionKey();
            if (!$this->connectors->supportsBankCode($provider, (string) ($connection['bank_code'] ?? ''))) {
                throw new BankConnectorOperationException('provider_account_mismatch');
            }
            if (!(bool) ($connection['is_active'] ?? false)) {
                throw new BankConnectorOperationException('account_inactive');
            }
            $currency = strtoupper((string) ($connection['account_code'] ?? ''));
            if ($currency === '') {
                throw new BankConnectorOperationException('account_currency_missing');
            }

            $connector = $this->connectors->get($provider);
            $token = $newToken;
            if (!$hasNewToken) {
                if ((string) $connection['provider'] !== $provider) {
                    throw new BankConnectorOperationException('token_required');
                }
                $token = $this->decryptToken($connection);
            }
            if ($connector instanceof StructuredBankConnector) {
                $token = $connector->credentials((array) ($input['credentials'] ?? []), $connection, $token);
            }
            if ($token === null || $token === '') {
                throw new BankConnectorOperationException('token_required');
            }

            $today = date('Y-m-d');
            try {
                $parsed = $this->calls->call(
                    $connector instanceof BankConnectorCredentialKeyProvider ? $connector->callGuardCredential($token) : $token,
                    fn (): array => $connector instanceof MultiFileBankConnector
                        ? ['header' => $connector->verifyAccount($token)]
                        : $this->parseStatement($connector->downloadStatement($token, $today, $today), $connector),
                );
            } catch (BankConnectorException $e) {
                throw new BankConnectorOperationException($e->errorCode);
            }
            $statementAccount = (string) ($parsed['header']['account_number'] ?? '');
            if (!$this->matchesConfiguredAccount($statementAccount, $connection)) {
                throw new BankConnectorOperationException('statement_account_mismatch');
            }

            if (!$hasNewToken && $connector instanceof BankConnectorCredentialKeyProvider) {
                $current = $this->connections->findWithCredentialById($supplierId, $connectionId);
                $ciphertext = (string) ($current['token_ciphertext'] ?? '');
            } else {
                $ciphertext = $this->secrets->encryptFor(
                    $token,
                    $this->secretContext($supplierId, $connectionId),
                );
            }
            if (!str_starts_with($ciphertext, 'enc:v2:')) {
                throw new BankConnectorOperationException('encryption_failed');
            }
            $this->connections->saveValidated(
                $supplierId,
                $connectionId,
                $provider,
                $ciphertext,
                $enabled,
                $statementAccount,
                (string) $connection['bank_code'],
                $currency,
            );

            return $this->connections->findPublicByCurrency($supplierId, $currencyId)
                ?? throw new BankConnectorOperationException('connection_not_found');
        });
    }

    public function disconnect(int $supplierId, int $currencyId): bool
    {
        return $this->calls->withConnectionLock(
            $supplierId,
            $currencyId,
            fn (): bool => $this->connections->disconnect($supplierId, $currencyId),
        );
    }

    public function sync(
        int $supplierId,
        int $currencyId,
        ?string $from = null,
        ?string $to = null,
        ?int $userId = null,
        array $reconciliationConfirmations = [],
    ): array {
        return $this->calls->withConnectionLock($supplierId, $currencyId, function () use (
            $supplierId, $currencyId, $from, $to, $userId, $reconciliationConfirmations,
        ): array {
            $connection = $this->connections->findWithCredentialByCurrency($supplierId, $currencyId);
            if ($connection === null) {
                throw new BankConnectorOperationException('connection_not_found');
            }
            $connectionId = (int) $connection['id'];
            try {
                $this->assertEncryptionKey();
                $this->assertConnectionUsable($connection);
                [$periodFrom, $periodTo] = $this->period($connection, $from, $to);
                $watermarkTo = $this->watermarkAfterSuccessfulPeriod(
                    $connection,
                    $periodFrom,
                    $periodTo,
                    $from === null,
                );
                $token = $this->decryptToken($connection);
                $connector = $this->connectors->get((string) $connection['provider']);
                try {
                    $content = $this->calls->call(
                        $connector instanceof BankConnectorCredentialKeyProvider ? $connector->callGuardCredential($token) : $token,
                        static fn (): string => $connector->downloadStatement($token, $periodFrom, $periodTo),
                    );
                } catch (BankConnectorException $e) {
                    throw new BankConnectorOperationException($e->errorCode);
                }
                $parsed = $this->parseStatement($content, $connector);
                $statementAccount = (string) ($parsed['header']['account_number'] ?? '');
                if (!AccountNumberNormalizer::equals(
                    $statementAccount,
                    (string) $connection['verified_account_number'],
                )) {
                    throw new BankConnectorOperationException('statement_account_mismatch');
                }

                $fileName = sprintf('%s-%d-%s-%s.%s', $connector->provider(), $connectionId, $periodFrom, $periodTo,
                    $connector instanceof StructuredBankConnector ? $connector->statementFormat() : 'gpc');
                if ($connector instanceof MultiFileBankConnector) {
                    $result = ['statement_id' => null, 'statement_ids' => [], 'transactions' => 0, 'matched' => 0, 'skipped_duplicates' => 0];
                    foreach ($connector->statementFiles($content) as $file) {
                        $imported = $this->importer->importConnected(
                            $file['content'],
                            $file['filename'],
                            $userId,
                            $currencyId,
                            $supplierId,
                            $reconciliationConfirmations,
                        );
                        $result['statement_id'] = $imported['statement_id'];
                        $result['statement_ids'][] = $imported['statement_id'];
                        foreach (['transactions', 'matched', 'skipped_duplicates'] as $key) $result[$key] += (int) ($imported[$key] ?? 0);
                    }
                } else {
                    $result = $connector instanceof StructuredBankConnector
                        ? $this->importer->importConnectedParsed(
                            $parsed,
                            $content,
                            $fileName,
                            $userId,
                            $currencyId,
                            $supplierId,
                            'bank_api',
                            $reconciliationConfirmations,
                        )
                        : $this->importer->importConnected(
                            $content,
                            $fileName,
                            $userId,
                            $currencyId,
                            $supplierId,
                            $reconciliationConfirmations,
                        );
                }
                $this->connections->recordSyncSuccess($supplierId, $connectionId, $watermarkTo);

                return [
                    'status' => 'success',
                    'imported_statement_id' => $result['statement_id'] === null ? null : (int) $result['statement_id'],
                    'import_result' => $result,
                    'period' => ['from' => $periodFrom, 'to' => $periodTo],
                ];
            } catch (StatementReconciliationException $e) {
                $this->connections->recordSyncError(
                    $supplierId,
                    $connectionId,
                    StatementReconciliationException::ERROR_CODE,
                );
                throw new BankConnectorOperationException(
                    StatementReconciliationException::ERROR_CODE,
                    ['reconciliation_candidates' => $e->candidates],
                );
            } catch (BankConnectorOperationException $e) {
                // Omezení četnosti banky (429) i vlastní odstup volání nejsou vada
                // spojení: pokus se jen opakuje později, stav spojení se nemění.
                if (!self::isRateLimit($e->errorCode)) {
                    $this->connections->recordSyncError($supplierId, $connectionId, $e->errorCode);
                }
                throw $e;
            } catch (\Throwable $e) {
                $this->connections->recordSyncError($supplierId, $connectionId, 'bank_sync_failed');
                throw new BankConnectorOperationException('bank_sync_failed');
            }
        });
    }

    public function syncAll(): array
    {
        $summary = ['processed' => 0, 'succeeded' => 0, 'skipped' => 0, 'errors' => 0, 'details' => []];
        foreach ($this->connections->enabledWithCredentials() as $connection) {
            $summary['processed']++;
            if ($this->pacedOut($connection)) {
                $summary['skipped']++;
                $summary['details'][] = [
                    'connection_id' => (int) $connection['id'],
                    'skipped' => 'minimum_sync_interval',
                ];
                continue;
            }
            try {
                $result = $this->sync(
                    (int) $connection['supplier_id'],
                    (int) $connection['currency_id'],
                );
                $summary['succeeded']++;
                $summary['details'][] = [
                    'connection_id' => (int) $connection['id'],
                    'statement_id' => (int) $result['imported_statement_id'],
                    'transactions' => (int) ($result['import_result']['transactions'] ?? 0),
                ];
            } catch (BankConnectorOperationException $e) {
                if (self::isRateLimit($e->errorCode)) {
                    $summary['skipped']++;
                    $summary['details'][] = [
                        'connection_id' => (int) $connection['id'],
                        'skipped' => $e->errorCode,
                    ];
                    continue;
                }
                $summary['errors']++;
                $summary['details'][] = [
                    'connection_id' => (int) $connection['id'],
                    'error_code' => $e->errorCode,
                ];
            }
        }
        return $summary;
    }

    private static function isRateLimit(string $errorCode): bool
    {
        return in_array($errorCode, [BankConnectorException::RATE_LIMITED, 'bank_rate_limited'], true);
    }

    /**
     * Banka s omezenou četností stahování (BankConnectorSyncPacing) se
     * automaticky nevolá dřív, než od posledního pokusu uplyne její odstup.
     * Neznámý konektor ani spojení bez předchozího pokusu se nepřeskakuje.
     *
     * @param array<string,mixed> $connection
     */
    private function pacedOut(array $connection): bool
    {
        $elapsed = $connection['seconds_since_last_sync'] ?? null;
        if (!is_int($elapsed) || $elapsed < 0) {
            return false;
        }
        try {
            $connector = $this->connectors->get((string) $connection['provider']);
        } catch (\Throwable) {
            return false;
        }

        return $connector instanceof BankConnectorSyncPacing
            && $elapsed < $connector->minimumAutomaticSyncIntervalSeconds();
    }

    /** @return array{0:string,1:string} */
    private function period(array $connection, ?string $from, ?string $to): array
    {
        if (($from === null) !== ($to === null)) {
            throw new BankConnectorOperationException('period_incomplete');
        }
        $today = new \DateTimeImmutable('today');
        if ($from !== null && $to !== null) {
            $start = $this->date($from);
            $end = $this->date($to);
            $days = (int) $start->diff($end)->format('%r%a') + 1;
            if ($days < 1 || $days > self::MAX_MANUAL_DAYS || $end > $today) {
                throw new BankConnectorOperationException('period_invalid');
            }
            return [$start->format('Y-m-d'), $end->format('Y-m-d')];
        }

        $end = $today;
        $watermark = trim((string) ($connection['sync_watermark_date'] ?? ''));
        $start = $watermark !== ''
            ? $this->date($watermark)->modify('-' . self::OVERLAP_DAYS . ' days')
            : $end->modify('-' . (self::INITIAL_DAYS - 1) . ' days');
        $oldest = $end->modify('-' . (self::MAX_AUTOMATIC_DAYS - 1) . ' days');
        if ($start < $oldest) {
            throw new BankConnectorOperationException('history_gap');
        }
        if ($start > $end) {
            $start = $end;
        }
        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }

    private function watermarkAfterSuccessfulPeriod(
        array $connection,
        string $from,
        string $to,
        bool $automatic,
    ): ?string {
        if ($automatic) {
            return $to;
        }
        $start = $this->date($from);
        $end = $this->date($to);
        $watermark = trim((string) ($connection['sync_watermark_date'] ?? ''));
        if ($watermark !== '') {
            $current = $this->date($watermark);
            return $start <= $current->modify('+1 day') && $end > $current ? $to : null;
        }
        $requiredStart = (new \DateTimeImmutable('today'))->modify('-' . (self::INITIAL_DAYS - 1) . ' days');
        return $start <= $requiredStart && $end >= new \DateTimeImmutable('today') ? $to : null;
    }

    private function assertConnectionUsable(array $connection): void
    {
        if (!(bool) $connection['enabled'] || !(bool) $connection['has_token']) {
            throw new BankConnectorOperationException('connection_disabled');
        }
        if (!(bool) ($connection['is_active'] ?? false)) {
            throw new BankConnectorOperationException('account_inactive');
        }
        $provider = (string) $connection['provider'];
        if (!$this->connectors->supportsBankCode($provider, (string) ($connection['bank_code'] ?? ''))) {
            throw new BankConnectorOperationException('provider_account_mismatch');
        }
        if (
            strtoupper((string) ($connection['account_code'] ?? '')) !== strtoupper((string) ($connection['verified_currency'] ?? ''))
            || (string) ($connection['bank_code'] ?? '') !== (string) ($connection['verified_bank_code'] ?? '')
            || !$this->matchesConfiguredAccount((string) ($connection['verified_account_number'] ?? ''), $connection)
        ) {
            throw new BankConnectorOperationException('account_changed_revalidation_required');
        }
    }

    private function matchesConfiguredAccount(string $statementAccount, array $connection): bool
    {
        return $statementAccount !== '' && AccountNumberNormalizer::matchesAny(
            $statementAccount,
            isset($connection['account_number']) ? (string) $connection['account_number'] : null,
            isset($connection['iban']) ? (string) $connection['iban'] : null,
        );
    }

    private function decryptToken(array $connection): string
    {
        $stored = (string) ($connection['token_ciphertext'] ?? '');
        if ($stored === '' || !str_starts_with($stored, 'enc:v2:')) {
            throw new BankConnectorOperationException('credential_format_invalid');
        }
        try {
            return $this->secrets->decryptFor(
                $stored,
                $this->secretContext((int) $connection['supplier_id'], (int) $connection['id']),
            );
        } catch (\Throwable) {
            throw new BankConnectorOperationException('credential_unavailable');
        }
    }

    private function assertEncryptionKey(): void
    {
        if ($this->secrets->validateKey() !== null) {
            throw new BankConnectorOperationException('encryption_key_unavailable');
        }
    }

    private function secretContext(int $supplierId, int $connectionId): string
    {
        return sprintf('bank-connection:supplier:%d:connection:%d:type:token', $supplierId, $connectionId);
    }

    private function parseStatement(string $content, BankConnector $connector): array
    {
        try {
            return $connector instanceof StructuredBankConnector ? $connector->parseStatement($content) : $this->parser->parse($content);
        } catch (\Throwable) {
            throw new BankConnectorOperationException('statement_invalid');
        }
    }

    private function date(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new BankConnectorOperationException('period_invalid');
        }
        return $date;
    }
}
