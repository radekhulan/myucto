<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

final class CsasBankConnector implements StructuredBankConnector, BankConnectorCredentialKeyProvider, BankPaymentCapabilityProvider,
    SignableBankPaymentConnector
{
    public function __construct(private readonly CsasApiClient $api, private readonly CsasCredentialVault $vault,
        private readonly CsasTransactionParser $parser, private readonly CsasAboBatchMapper $batchMapper = new CsasAboBatchMapper()) {}

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

    /** Bez evidence položek by po přerušení nešlo poznat, co už banka založila; odeslání jde jen přes {@see submitStagedPaymentOrder()}. */
    public function submitPaymentOrder(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $abo): array
    {
        throw new BankConnectorException('payment_submission_unavailable', 'Odeslání do České spořitelny vyžaduje evidenci položek.');
    }

    public function canSubmitPaymentOrder(#[\SensitiveParameter] string $credential): bool
    {
        return CsasCredentialVault::paymentAvailability($this->vault->decode($credential)) === 'available';
    }

    public function assertPaymentOrder(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $abo): void
    {
        $credentials = $this->vault->decode($token);
        if (CsasCredentialVault::paymentAvailability($credentials) !== 'available') {
            throw new BankConnectorException('csas_payments_scope_missing', 'Souhlas České spořitelny nezahrnuje platby.');
        }
        $this->batchMapper->map($abo, (string) $credentials['account_iban']);
    }

    /**
     * Založí platby jednu po druhé a pak dávku. Položka, která už má od banky signId, se
     * znovu nezakládá; položka s nejasným výsledkem (sending, unknown) zastaví odeslání
     * k ruční kontrole. Access token platí asi 5 minut, proto se obnovuje před každým voláním.
     */
    public function submitStagedPaymentOrder(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $abo,
        BankPaymentSubmissionJournal $journal): array
    {
        if (!$this->canSubmitPaymentOrder($token)) {
            throw new BankConnectorException('csas_payments_scope_missing', 'Souhlas České spořitelny nezahrnuje platby.');
        }
        $credentials = $this->vault->decode($token);
        $batch = $this->batchMapper->map($abo, (string) $credentials['account_iban']);
        $plan = $journal->plan(array_map(static fn (array $payment): array => [
            'line_no' => $payment['line_no'],
            'instruction_identification' => $payment['instruction_identification'],
            'amount_minor' => $payment['amount_minor'],
            'creditor_iban' => $payment['creditor_iban'],
            'variable_symbol' => $payment['variable_symbol'],
        ], $batch['payments']));

        $signIds = [];
        foreach ($batch['payments'] as $payment) {
            $line = $payment['line_no'];
            $row = $plan[$line] ?? null;
            $status = $row['status'] ?? null;
            if ($status === 'created' && is_string($row['provider_sign_id'] ?? null)) {
                $signIds[] = $row['provider_sign_id'];
                continue;
            }
            if ($status !== 'planned') {
                throw new BankConnectorException($status === 'rejected' ? BankConnectorException::PAYMENT_REJECTED : 'csas_payment_outcome_unknown',
                    'Stav položky odeslání vyžaduje ruční kontrolu.', $status !== 'rejected', null, count($signIds));
            }
            // Obnova tokenu může skončit chybou (souběh, banka) ještě před odesláním platby;
            // položka proto přejde do „sending“ až těsně před samotným HTTP voláním.
            $access = $this->freshAccess($token);
            $journal->sending($line);
            try {
                $created = $this->api->createPayment($access, $payment['body']);
            } catch (BankConnectorException $e) {
                if ($e->ambiguousPaymentOutcome) {
                    $journal->unknown($line, $e->errorCode);
                } else {
                    $journal->rejected($line, $e->errorCode);
                }
                throw new BankConnectorException($e->errorCode, 'Českou spořitelnu nelze o platbě bezpečně informovat.',
                    $e->ambiguousPaymentOutcome, $e->remoteHttpStatus, count($signIds), $e->ambiguousPaymentOutcome ? null : 1);
            } catch (\Throwable) {
                $journal->unknown($line, 'submission_failed');
                throw new BankConnectorException('submission_failed', 'Založení platby skončilo neočekávanou chybou.', true, null, count($signIds));
            }
            $journal->created($line, $created['sign_id'], $created['hash'], $created['instruction_status']);
            $signIds[] = $created['sign_id'];
        }

        $access = $this->freshAccess($token);
        $journal->batchSending();
        try {
            $signed = $this->api->createBatch($access, $batch['exchange_identification'], $signIds);
        } catch (BankConnectorException $e) {
            // Platby jsou založené a bez podpisu se neprovedou; dávku ale už nelze bezpečně zopakovat.
            throw new BankConnectorException($e->errorCode, 'Dávku České spořitelny nelze bezpečně založit.',
                true, $e->remoteHttpStatus, count($signIds));
        }
        return ['accepted' => true, 'reference' => $signed['sign_id'], 'status' => 'awaiting_signature', 'sign_hash' => $signed['hash']];
    }

    /**
     * Platný přístup před dalším krokem odeslání. Selhání obnovy tokenu nic neodeslalo a evidence
     * zůstává konzistentní, proto jde o chybu služby: odeslání zůstane ve stavu creating a lze v něm pokračovat.
     */
    private function freshAccess(#[\SensitiveParameter] string $token): array
    {
        try {
            return $this->vault->access($token);
        } catch (BankConnectorException $e) {
            throw new BankConnectorOperationException($e->errorCode);
        }
    }

    public function startSignature(#[\SensitiveParameter] string $token, string $batchSignId, string $batchHash, string $callbackUri): array
    {
        return $this->api->federatedSign($this->vault->access($token), $batchSignId, $batchHash, $callbackUri);
    }

    public function signatureState(#[\SensitiveParameter] string $token, string $pollId): array
    {
        return $this->api->pollSign($this->vault->access($token), $pollId);
    }

    public function paymentState(#[\SensitiveParameter] string $token, string $signId): array
    {
        return $this->api->paymentStatus($this->vault->access($token), $signId);
    }

    public function callGuardCredential(#[\SensitiveParameter] string $credential): string
    {
        return $this->vault->decode($credential)['call_guard_key'];
    }
}
