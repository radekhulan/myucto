<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use MyInvoice\Service\Bank\AccountNumberNormalizer;

final class RaiffeisenbankConnector implements StructuredBankConnector
{
    public function __construct(
        private readonly RaiffeisenbankPremiumClient $client,
        private readonly RaiffeisenbankTransactionParser $parser,
    ) {}

    public function provider(): string
    {
        return 'raiffeisenbank';
    }

    public function statementFormat(): string
    {
        return 'json';
    }

    public function credentials(#[\SensitiveParameter] array $input, array $account, #[\SensitiveParameter] ?string $existing): string
    {
        if ($input === []) {
            if ($existing === null) throw new BankConnectorOperationException('certificate_required');
            $data = $this->decode($existing);
        } else {
            $data = [];
            foreach (['client_id', 'certificate', 'password'] as $key) {
                if (!is_string($input[$key] ?? null)) throw new BankConnectorOperationException('certificate_required');
                $data[$key] = $input[$key];
            }
        }
        if (!preg_match('/^[a-zA-Z0-9_-]{1,200}$/D', $data['client_id'] ?? '')) {
            throw new BankConnectorOperationException('certificate_invalid');
        }
        BankClientCertificate::curlOptions($data['certificate'] ?? '', $data['password'] ?? '');
        $number = preg_replace('/\s+/', '', (string) ($account['account_number'] ?? ''));
        if ($number === '') $number = preg_replace('/\s+/', '', (string) ($account['iban'] ?? ''));
        if (preg_match('/^CZ\d{2}5500(\d{6})(\d{10})$/D', $number, $parts)) {
            $number = (ltrim($parts[1], '0') !== '' ? ltrim($parts[1], '0') . '-' : '') . ltrim($parts[2], '0');
        }
        if (!preg_match('/^(?:(\d{1,6})-)?([1-9]\d{0,9})(?:\/5500)?$/D', $number, $parts)) {
            throw new BankConnectorOperationException('provider_account_mismatch');
        }
        $data['account_number'] = ($parts[1] !== '' ? $parts[1] . '-' : '') . $parts[2];
        $data['base_number'] = $parts[2];
        $data['currency'] = strtoupper((string) ($account['account_code'] ?? ''));
        if (!preg_match('/^[A-Z]{3}$/D', $data['currency'])) throw new BankConnectorOperationException('account_currency_missing');
        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    public function downloadStatement(#[\SensitiveParameter] string $token, string $from, string $to): string
    {
        $data = $this->decode($token);
        $credentials = $this->transport($data);
        $account = $this->client->account($credentials, $data['base_number']);
        $number = (ltrim((string) ($account['numberPart1'] ?? ''), '0') !== ''
            ? ltrim((string) $account['numberPart1'], '0') . '-' : '') . (string) ($account['numberPart2'] ?? '');
        if (($account['bankCode'] ?? '') !== '5500' || !AccountNumberNormalizer::equalsCzech($number, $data['account_number'])) {
            throw new BankConnectorOperationException('statement_account_mismatch');
        }
        $found = false;
        foreach ($account['currencyFolders'] ?? [] as $folder) {
            if (($folder['currency'] ?? '') === $data['currency'] && ($folder['status'] ?? '') === 'ACTIVE') $found = true;
        }
        if (!$found) throw new BankConnectorOperationException('statement_account_mismatch');
        $transactions = $this->client->transactions($credentials, $data['base_number'], $data['currency'], $from, $to);
        return json_encode([
            'provider' => $this->provider(), 'account_number' => $data['account_number'],
            'currency' => $data['currency'], 'from' => $from, 'to' => $to, 'transactions' => $transactions,
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    public function parseStatement(#[\SensitiveParameter] string $content): array
    {
        $data = $this->decode($content);
        if (($data['provider'] ?? '') !== $this->provider()) throw new BankConnectorOperationException('statement_invalid');
        return $this->parser->parse($data['transactions'], $data['account_number'], $data['currency'], $data['from'], $data['to']);
    }

    public function submitPaymentOrder(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $abo): array
    {
        return $this->client->submitBatch($this->transport($this->decode($token)), $abo) + ['status' => 'import_started'];
    }

    private function transport(#[\SensitiveParameter] array $data): array
    {
        return ['client_id' => $data['client_id'], 'curl_options' => BankClientCertificate::curlOptions($data['certificate'], $data['password'])];
    }

    private function decode(#[\SensitiveParameter] string $json): array
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (is_array($data)) return $data;
        } catch (\Throwable) {}
        throw new BankConnectorOperationException('credential_format_invalid');
    }
}
