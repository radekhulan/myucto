<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

use MyInvoice\Repository\BankConnectionRepository;
use MyInvoice\Repository\Payroll\PayrollBankSubmissionSourceRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Bank\Connector\BankConnectorRegistry;
use MyInvoice\Service\Bank\Connector\BankConnectorOperationException;
use MyInvoice\Service\Bank\Connector\BankPaymentSubmissionService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final class PayrollBankSubmissionService
{
    public function __construct(
        private readonly PayrollBankSubmissionSourceRepository $sources,
        private readonly BankPaymentSubmissionService $submissions,
        private readonly BankConnectionRepository $connections,
        private readonly BankConnectorRegistry $connectors,
        private readonly SecretEncryption $secrets,
        private readonly PayrollPaymentExportService $exports,
        private readonly PayrollPaymentExportStorage $storage,
    ) {}

    public function overview(int $supplierId, int $batchId): array
    {
        $batch = $this->batch($supplierId, $batchId);
        $submission = $this->submissions->find($supplierId, $batchId, 'payroll');
        $available = [];
        $blocked = null;
        try {
            $this->assertPayable($supplierId, $batch);
            $payer = $this->payer($supplierId, $batch);
            foreach ($this->connections->listPublic($supplierId) as $connection) {
                $account = $connection['account'];
                if (!$connection['enabled'] || !$connection['has_token'] || !$connection['validated_at']
                    || !$this->matches($payer, $account) || !$this->supportsPayments($connection['provider'])) {
                    continue;
                }
                $available[] = ['id' => $connection['id'], 'provider' => $connection['provider'],
                    'label' => $account['label'] ?: $connection['provider']];
            }
            if ($available === []) $blocked = 'payment_no_connection';
        } catch (BankConnectorOperationException $e) {
            $blocked = $e->errorCode;
        }
        return ['submission' => $submission, 'connections' => $available, 'blocked_reason' => $blocked];
    }

    public function submit(int $supplierId, int $batchId, int $connectionId, int $userId): array
    {
        return $this->submissions->submit($supplierId, $batchId, $connectionId, $userId,
            function (array $connection) use ($supplierId, $batchId, $userId): string {
                $batch = $this->batch($supplierId, $batchId);
                $this->assertPayable($supplierId, $batch);
                $payer = $this->payer($supplierId, $batch);
                if (!$this->matches($payer, [
                    'account_number' => $connection['account_number'], 'bank_code' => $connection['bank_code'],
                    'iban' => $connection['iban'], 'code' => $connection['account_code'],
                ])) {
                    throw new BankConnectorOperationException('payment_order_account_mismatch');
                }
                try {
                    $export = $this->exports->export($supplierId, $batchId, 'bank-submission:' . $batchId, $userId, null, 'abo');
                    $bytes = $this->storage->readVerified($supplierId, $export['storage_key']);
                    if ($export['source_snapshot_hash'] !== $batch['snapshot_hash']
                        || !hash_equals($export['file_sha256'], hash('sha256', $bytes))
                        || strlen($bytes) !== $export['size_bytes']) {
                        throw new \RuntimeException();
                    }
                } catch (\Throwable) {
                    throw new BankConnectorOperationException('payment_export_invalid');
                }
                $this->assertPayable($supplierId, $batch);
                return $bytes;
            }, 'payroll');
    }

    private function batch(int $supplierId, int $batchId): array
    {
        return $this->sources->find($supplierId, $batchId)
            ?? throw new BankConnectorOperationException('payment_order_not_found');
    }

    private function assertPayable(int $supplierId, array $batch): void
    {
        if ($batch['channel'] !== 'bank' || $batch['direction'] !== 'outgoing'
            || $batch['export_format'] !== 'abo' || $batch['currency_code'] !== 'CZK'
            || (int) $batch['declared_total_minor'] <= 0 || (int) $batch['declared_item_count'] <= 0) {
            throw new BankConnectorOperationException('payment_order_unsupported');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $batch['planned_payment_date'], new \DateTimeZone('Europe/Prague'));
        if ($date === false || $date->format('Y-m-d') !== $batch['planned_payment_date']
            || $date < new \DateTimeImmutable('today', new \DateTimeZone('Europe/Prague'))) {
            throw new BankConnectorOperationException('payment_order_date_in_past');
        }
        if ($this->sources->hasSettlement($supplierId, (int) $batch['id'])) {
            throw new BankConnectorOperationException('payment_order_no_longer_payable');
        }
    }

    private function payer(int $supplierId, array $batch): array
    {
        try {
            $json = $this->secrets->decryptFor($batch['snapshot_ciphertext'],
                'payroll-payment-batch:' . $supplierId . ':' . $batch['batch_reference']);
            $snapshot = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($snapshot) || CanonicalJson::encode($snapshot) !== $json
                || !hash_equals($batch['snapshot_hash'], hash('sha256', $json))
                || !is_array($snapshot['payer_instruction'] ?? null)) throw new \RuntimeException();
            return $snapshot['payer_instruction'];
        } catch (\Throwable) {
            throw new BankConnectorOperationException('payment_export_invalid');
        }
    }

    private function matches(array $payer, array $account): bool
    {
        return ($account['code'] ?? '') === 'CZK'
            && ($payer['bank_code'] ?? '') === ($account['bank_code'] ?? '')
            && AccountNumberNormalizer::matchesAny((string) ($payer['account_number'] ?? ''),
                $account['account_number'] ?? null, $account['iban'] ?? null);
    }

    private function supportsPayments(string $provider): bool
    {
        foreach ($this->connectors->catalog() as $item) {
            if ($item['code'] === $provider) return $item['implemented'] && $item['capabilities']['payment_order_submission'];
        }
        return false;
    }
}
