<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

final class KbPlusConnector implements StructuredBankConnector, BankConnectorCredentialKeyProvider, BankPaymentCapabilityProvider
{
    public function __construct(
        private readonly KbPlusApiClient $api,
        private readonly KbPlusCredentialVault $vault,
        private readonly KbPlusTransactionParser $parser,
        private readonly KbPlusAboBatchMapper $batchMapper,
    ) {}

    public function provider(): string
    {
        return 'kb_plus';
    }

    public function credentials(#[\SensitiveParameter] array $input, array $account, #[\SensitiveParameter] ?string $existing): string
    {
        if ($input !== [] || $existing === null) {
            throw new BankConnectorOperationException('kb_plus_oauth_required');
        }
        $credentials = $this->vault->decode($existing);
        if ((int) $credentials['supplier_id'] !== (int) ($account['supplier_id'] ?? 0)
            || (int) $credentials['connection_id'] !== (int) ($account['id'] ?? 0)
        ) {
            throw new BankConnectorOperationException('credential_context_invalid');
        }
        return $existing;
    }

    public function downloadStatement(#[\SensitiveParameter] string $token, string $from, string $to): string
    {
        $access = $this->vault->access($token);
        $credentials = $access['credentials'];
        $result = $this->api->transactions(
            $credentials,
            $access['access_token'],
            (string) $credentials['account_id'],
            $from,
            $to,
        );
        try {
            return json_encode([
                'account_number' => $credentials['account_iban'],
                'currency' => $credentials['account_currency'],
                'from' => $from,
                'to' => $to,
                'transactions' => $result['transactions'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, 64);
        } catch (\JsonException) {
            throw new BankConnectorException(BankConnectorException::INVALID_RESPONSE, 'Pohyby KB+ nelze bezpečně serializovat.');
        }
    }

    public function parseStatement(#[\SensitiveParameter] string $content): array
    {
        try {
            $data = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('KB+ statement envelope is invalid.');
        }
        if (!is_array($data) || array_is_list($data)
            || array_diff(array_keys($data), ['account_number', 'currency', 'from', 'to', 'transactions']) !== []
        ) {
            throw new \RuntimeException('KB+ statement envelope is invalid.');
        }
        return $this->parser->parse(
            is_array($data['transactions'] ?? null) ? $data['transactions'] : [],
            is_string($data['account_number'] ?? null) ? $data['account_number'] : '',
            is_string($data['currency'] ?? null) ? $data['currency'] : '',
            is_string($data['from'] ?? null) ? $data['from'] : '',
            is_string($data['to'] ?? null) ? $data['to'] : '',
        );
    }

    public function statementFormat(): string
    {
        return 'json';
    }

    public function submitPaymentOrder(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $abo): array
    {
        if (!$this->canSubmitPaymentOrder($token)) {
            throw new BankConnectorException('payment_submission_unavailable', 'Připojení KB+ nemá klíč BATCHDA.');
        }
        $access = $this->vault->access($token);
        $batch = $this->batchMapper->map($abo, (string) $access['credentials']['account_iban']);
        return $this->api->submitPaymentBatch($access['credentials'], $access['access_token'], $batch);
    }

    public function callGuardCredential(#[\SensitiveParameter] string $credential): string
    {
        return (string) $this->vault->decode($credential)['call_guard_key'];
    }

    public function canSubmitPaymentOrder(#[\SensitiveParameter] string $credential): bool
    {
        return trim((string) $this->vault->decode($credential)['batchda_api_key']) !== '';
    }
}
