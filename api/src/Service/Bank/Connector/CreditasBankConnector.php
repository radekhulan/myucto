<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Bank\CreditasTransactionParser;

final class CreditasBankConnector implements StructuredBankConnector
{
    public function __construct(
        private readonly CreditasPremiumClient $client,
        private readonly CreditasTransactionParser $parser,
    ) {}

    public function provider(): string
    {
        return 'creditas';
    }

    public function statementFormat(): string
    {
        return 'json';
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $account
     */
    public function credentials(#[\SensitiveParameter] array $input, array $account, #[\SensitiveParameter] ?string $existing): string
    {
        if ($input === []) {
            if ($existing === null) {
                throw new BankConnectorOperationException('certificate_required');
            }
            $this->credentialData($existing);
            return $existing;
        }

        $input += ['certificate' => '', 'password' => ''];
        $data = [];
        foreach (['bearer_token', 'account_id', 'account_type', 'certificate', 'password'] as $key) {
            if (!is_string($input[$key] ?? null)) {
                throw new BankConnectorOperationException('certificate_required');
            }
            $data[$key] = $input[$key];
        }
        $this->validateCredentialFields($data);
        $data['account_number'] = trim((string) ($account['account_number'] ?? ''));
        $data['iban'] = strtoupper((string) preg_replace('/\s+/', '', (string) ($account['iban'] ?? '')));
        $data['currency'] = strtoupper(trim((string) ($account['account_code'] ?? '')));
        if ($data['account_number'] === '' && $data['iban'] === '') {
            throw new BankConnectorOperationException('provider_account_mismatch');
        }
        $this->validateConfiguredAccount($data['account_number'], $data['iban']);
        if (!preg_match('/^[A-Z]{3}$/D', $data['currency'])) {
            throw new BankConnectorOperationException('account_currency_missing');
        }

        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function downloadStatement(#[\SensitiveParameter] string $token, string $from, string $to): string
    {
        $data = $this->credentialData($token);
        $bankAccount = $this->loadAccount($data);
        $this->verifyAccount($bankAccount, $data);
        $transactions = $this->client->transactions($this->transport($data), $data['account_id'], $from, $to);
        $this->verifyTransactions($transactions, $data['currency'], $from, $to);
        return json_encode([
            'provider' => $this->provider(),
            'account_id' => $data['account_id'],
            'account_number' => $bankAccount['bban'],
            'iban' => $bankAccount['iban'],
            'currency' => $bankAccount['currency'],
            'account_info' => $bankAccount,
            'from' => $from,
            'to' => $to,
            'transactions' => $transactions,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @return array{header:array<string,mixed>,transactions:list<array<string,mixed>>} */
    public function parseStatement(#[\SensitiveParameter] string $content): array
    {
        $data = $this->statementData($content);
        $this->verifyTransactions($data['transactions'], $data['currency'], $data['from'], $data['to']);
        return $this->parser->parse($data['transactions'], $data['account_number'], $data['to']);
    }

    public function submitPaymentOrder(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $abo): array
    {
        $data = $this->credentialData($token);
        $this->verifyAccount($this->loadAccount($data), $data);
        $result = $this->client->startPaymentImport(
            $this->transport($data),
            $data['account_id'],
            $abo,
            $this->uuid(),
        );
        return ['accepted' => true, 'reference' => $result['reference'], 'status' => 'import_started'];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function loadAccount(#[\SensitiveParameter] array $data): array
    {
        $credentials = $this->transport($data);
        return $data['account_type'] === 'current'
            ? $this->client->currentAccount($credentials, $data['account_id'])
            : $this->client->savingsAccount($credentials, $data['account_id']);
    }

    /**
     * @param array<string,mixed> $bankAccount
     * @param array<string,mixed> $data
     */
    private function verifyAccount(array $bankAccount, #[\SensitiveParameter] array $data): void
    {
        $id = $bankAccount['accountId'] ?? null;
        $iban = $bankAccount['iban'] ?? null;
        $bban = $bankAccount['bban'] ?? null;
        $currency = $bankAccount['currency'] ?? null;
        $bankCode = $bankAccount['bankCode'] ?? null;
        if (
            !is_string($id) || $id !== $data['account_id']
            || !is_string($iban) || preg_match('/^CZ\d{2}2250\d{16}$/D', strtoupper(str_replace(' ', '', $iban))) !== 1
            || !is_string($bban) || trim($bban) === '' || mb_strlen($bban) > 42
            || !is_string($currency) || $currency !== $data['currency']
            || !is_string($bankCode) || $bankCode !== '2250'
        ) {
            throw new BankConnectorOperationException('statement_account_mismatch');
        }
        $storedIban = strtoupper((string) preg_replace('/\s+/', '', $data['iban']));
        $returnedIban = strtoupper((string) preg_replace('/\s+/', '', $iban));
        if (
            AccountNumberNormalizer::czechIbanBankCode($returnedIban) !== $bankCode
            || !AccountNumberNormalizer::equalsCzech($returnedIban, $bban)
            || ($storedIban !== '' && !hash_equals($storedIban, $returnedIban))
            || ($data['account_number'] !== '' && !AccountNumberNormalizer::equalsCzech($data['account_number'], $bban))
        ) {
            throw new BankConnectorOperationException('statement_account_mismatch');
        }
    }

    /**
     * @param list<array<string,mixed>> $transactions
     */
    private function verifyTransactions(array $transactions, string $currency, string $from, string $to): void
    {
        $fromDate = $this->date($from);
        $toDate = $this->date($to);
        if ($fromDate > $toDate) {
            throw new BankConnectorOperationException('statement_invalid');
        }
        foreach ($transactions as $transaction) {
            $amount = $transaction['amount'] ?? null;
            $effectiveDate = $transaction['effectiveDate'] ?? null;
            if (
                !is_array($amount)
                || ($amount['currency'] ?? null) !== $currency
                || !is_string($effectiveDate)
            ) {
                throw new BankConnectorOperationException('statement_currency_mismatch');
            }
            $date = $this->date($effectiveDate);
            if ($date < $fromDate || $date > $toDate) {
                throw new BankConnectorOperationException('statement_invalid');
            }
        }
    }

    private function date(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new BankConnectorOperationException('statement_invalid');
        }
        return $date;
    }

    private function validateConfiguredAccount(string $accountNumber, string $iban): void
    {
        if (
            ($accountNumber !== '' && preg_match('/^(?:\d{1,6}-)?[1-9]\d{0,9}(?:\/2250)?$/D', $accountNumber) !== 1)
            || ($iban !== '' && preg_match('/^CZ\d{2}2250\d{16}$/D', $iban) !== 1)
            || ($accountNumber !== '' && $iban !== '' && !AccountNumberNormalizer::equalsCzech($accountNumber, $iban))
        ) {
            throw new BankConnectorOperationException('provider_account_mismatch');
        }
    }

    /**
     * @param array<string,mixed> $data
     * @return array{bearer_token:string,curl_options:array<int,mixed>}
     */
    private function transport(#[\SensitiveParameter] array $data): array
    {
        return [
            'bearer_token' => $data['bearer_token'],
            'curl_options' => $data['certificate'] === '' ? [] : BankClientCertificate::curlOptions($data['certificate'], $data['password']),
        ];
    }

    /** @return array<string,mixed> */
    private function credentialData(#[\SensitiveParameter] string $json): array
    {
        $data = $this->decode($json, 'credential_format_invalid');
        $this->validateCredentialFields($data);
        foreach (['account_number', 'iban', 'currency'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new BankConnectorOperationException('credential_format_invalid');
            }
        }
        if (
            !is_string($data['account_number'])
            || !is_string($data['iban'])
            || !is_string($data['currency'])
            || preg_match('/^[A-Z]{3}$/D', $data['currency']) !== 1
        ) {
            throw new BankConnectorOperationException('credential_format_invalid');
        }
        try {
            $this->validateConfiguredAccount($data['account_number'], $data['iban']);
        } catch (BankConnectorOperationException) {
            throw new BankConnectorOperationException('credential_format_invalid');
        }
        return $data;
    }

    /** @param array<string,mixed> $data */
    private function validateCredentialFields(#[\SensitiveParameter] array $data): void
    {
        if (
            !is_string($data['bearer_token'] ?? null) || preg_match('/^[A-Za-z0-9]{64}$/D', $data['bearer_token']) !== 1
            || !is_string($data['account_id'] ?? null) || preg_match('/^[A-Za-z0-9_-]{1,40}$/D', $data['account_id']) !== 1
            || !is_string($data['account_type'] ?? null) || !in_array($data['account_type'], ['current', 'savings'], true)
            || !is_string($data['certificate'] ?? null)
            || !is_string($data['password'] ?? null)
        ) {
            throw new BankConnectorOperationException('credential_format_invalid');
        }
        if ($data['certificate'] !== '') {
            BankClientCertificate::curlOptions($data['certificate'], $data['password']);
        } elseif ($data['password'] !== '') {
            throw new BankConnectorOperationException('credential_format_invalid');
        }
    }

    /** @return array{provider:string,account_id:string,account_number:string,iban:string,currency:string,account_info:array<string,mixed>,from:string,to:string,transactions:list<array<string,mixed>>} */
    private function statementData(#[\SensitiveParameter] string $json): array
    {
        $data = $this->decode($json, 'statement_invalid');
        if (
            ($data['provider'] ?? null) !== $this->provider()
            || !is_string($data['account_id'] ?? null)
            || !is_string($data['account_number'] ?? null) || $data['account_number'] === ''
            || !is_string($data['iban'] ?? null)
            || !is_string($data['currency'] ?? null) || preg_match('/^[A-Z]{3}$/D', $data['currency']) !== 1
            || !is_array($data['account_info'] ?? null) || array_is_list($data['account_info'])
            || !is_string($data['from'] ?? null)
            || !is_string($data['to'] ?? null)
            || !is_array($data['transactions'] ?? null) || !array_is_list($data['transactions'])
        ) {
            throw new BankConnectorOperationException('statement_invalid');
        }
        if (
            ($data['account_info']['accountId'] ?? null) !== $data['account_id']
            || ($data['account_info']['bban'] ?? null) !== $data['account_number']
            || ($data['account_info']['iban'] ?? null) !== $data['iban']
            || ($data['account_info']['currency'] ?? null) !== $data['currency']
            || ($data['account_info']['bankCode'] ?? null) !== '2250'
        ) {
            throw new BankConnectorOperationException('statement_account_mismatch');
        }
        return $data;
    }

    /** @return array<string,mixed> */
    private function decode(#[\SensitiveParameter] string $json, string $error): array
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (is_array($data) && !array_is_list($data)) {
                return $data;
            }
        } catch (\Throwable) {
        }
        throw new BankConnectorOperationException($error);
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }
}
