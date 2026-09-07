<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

final class CsasBankConnector implements StructuredBankConnector, BankConnectorCredentialKeyProvider
{
    public function __construct(private readonly CsasApiClient $api, private readonly CsasCredentialVault $vault,
        private readonly CsasTransactionParser $parser) {}

    public function provider(): string { return 'csas'; }
    public function statementFormat(): string { return 'json'; }

    public function credentials(#[\SensitiveParameter] array $input, array $account, #[\SensitiveParameter] ?string $existing): string
    {
        if ($input !== [] || $existing === null) throw new BankConnectorOperationException('csas_oauth_required');
        $data = $this->vault->decode($existing);
        if ($data['supplier_id'] !== (int) ($account['supplier_id'] ?? 0)
            || $data['connection_id'] !== (int) ($account['id'] ?? 0)) throw new BankConnectorOperationException('credential_context_invalid');
        return $existing;
    }

    public function downloadStatement(#[\SensitiveParameter] string $token, string $from, string $to): string
    {
        $credentials = $this->vault->access($token);
        $accounts = $this->api->accounts($credentials);
        $matches = array_values(array_filter($accounts, static fn (array $account): bool =>
            ($account['identification']['iban'] ?? null) === $credentials['account_iban']
            && ($account['currency'] ?? null) === $credentials['account_currency']));
        if (count($matches) !== 1) throw new BankConnectorException('statement_account_mismatch', 'Účet není dostupný v uděleném souhlasu.');
        $credentials['account_id'] = $matches[0]['id'];
        $rows = $this->api->transactions($credentials, $from, $to);
        $this->parser->parse($rows, $credentials['account_iban'], $credentials['account_currency'], $from, $to);
        return json_encode(['account_iban' => $credentials['account_iban'], 'currency' => $credentials['account_currency'],
            'from' => $from, 'to' => $to, 'transactions' => $rows], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public function parseStatement(#[\SensitiveParameter] string $content): array
    {
        try {
            if (strlen($content) > 32 * 1024 * 1024) throw new \RuntimeException();
            $data = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
            return $this->parser->parse($data['transactions'], $data['account_iban'], $data['currency'], $data['from'], $data['to']);
        } catch (\Throwable) {
            throw new BankConnectorException(BankConnectorException::INVALID_RESPONSE, 'Neplatný výpis České spořitelny.');
        }
    }

    public function submitPaymentOrder(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $abo): array
    {
        throw new BankConnectorException('payment_submission_unsupported', 'Konektor České spořitelny podporuje pouze čtení.');
    }

    public function callGuardCredential(#[\SensitiveParameter] string $credential): string
    {
        return $this->vault->decode($credential)['call_guard_key'];
    }
}
