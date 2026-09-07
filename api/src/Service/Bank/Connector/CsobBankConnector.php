<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Connector;

use MyInvoice\Service\Bank\AccountNumberNormalizer;

final class CsobBankConnector implements MultiFileBankConnector
{
    private const MAX_FILES = 100;
    private const MAX_BYTES = 25 * 1024 * 1024;

    public function __construct(
        private readonly CsobBusinessConnectorClient $client,
        private readonly CsobGpcStatementSplitter $splitter,
    ) {}

    public function provider(): string
    {
        return 'csob';
    }

    public function credentials(#[\SensitiveParameter] array $input, array $account, #[\SensitiveParameter] ?string $existing): string
    {
        if (array_diff(array_keys($input), ['contract_number', 'certificate', 'password']) !== []) {
            throw new BankConnectorOperationException('credential_invalid');
        }
        $values = $existing === null ? [] : $this->decodeCredentials($existing);
        foreach ($input as $key => $value) {
            if (!is_string($value)) throw new BankConnectorOperationException('credential_invalid');
            $values[$key] = $key === 'password' ? $value : trim($value);
        }
        $values['password'] ??= '';
        $values['client_app_guid'] ??= $this->newGuid();
        $values['account_number'] = $this->nationalAccount((string) ($account['account_number'] ?? ''), (string) ($account['iban'] ?? ''));
        $values['currency'] = strtoupper((string) ($account['account_code'] ?? $account['code'] ?? ''));
        $values['bank_code'] = (string) ($account['bank_code'] ?? '');
        $encoded = json_encode($values, JSON_THROW_ON_ERROR);
        $this->decodeCredentials($encoded);
        BankClientCertificate::curlOptions($values['certificate'], $values['password']);
        return $encoded;
    }

    public function verifyAccount(#[\SensitiveParameter] string $credentials): array
    {
        $values = $this->decodeCredentials($credentials);
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Prague'));
        $this->list($values, $today->format('Y-m-d'), $today->format('Y-m-d'));
        return $this->identity($values);
    }

    public function downloadStatement(#[\SensitiveParameter] string $token, string $from, string $to): string
    {
        $deadline = hrtime(true) + 120_000_000_000;
        $values = $this->decodeCredentials($token);
        $listed = $this->list($values, $from, $to);
        foreach ($listed as $file) {
            if ($file['status'] !== 'D') {
                throw new BankConnectorException(
                    $file['status'] === 'R' ? 'csob_files_pending' : 'csob_file_failed',
                    'Výpisy ČSOB zatím nelze kompletně stáhnout.',
                );
            }
        }
        $files = [];
        $bytes = 0;
        foreach ($listed as $file) {
            foreach ($this->download($values, $file, $bytes, $deadline) as $block) {
                if (!$this->matches($block['parsed']['header'], $values)) continue;
                if (count($files) >= self::MAX_FILES) throw $this->tooLarge();
                $files[] = [
                    'content' => base64_encode($block['content']),
                    'filename' => 'csob-' . substr(hash('sha256', $block['content']), 0, 24) . '.gpc',
                ];
            }
        }
        return json_encode(['version' => 1, 'account' => $this->identity($values), 'files' => $files], JSON_THROW_ON_ERROR);
    }

    public function parseStatement(#[\SensitiveParameter] string $content): array
    {
        $envelope = $this->envelope($content);
        return ['header' => $envelope['account'], 'transactions' => []];
    }

    public function statementFiles(#[\SensitiveParameter] string $content): array
    {
        return $this->envelope($content)['files'];
    }

    public function statementFormat(): string
    {
        return 'gpc';
    }

    public function submitPaymentOrder(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $abo): array
    {
        $values = $this->decodeCredentials($token);
        if ($values['currency'] !== 'CZK') {
            throw new BankConnectorException('payment_currency_unsupported', 'Příkaz musí být veden v CZK.');
        }
        $result = $this->client->submitUnsignedAbo($this->clientCredentials($values), $abo, $values['client_app_guid']);
        if ($result['status'] !== 'import_started') {
            throw new BankConnectorException(BankConnectorException::PAYMENT_REJECTED, 'Banka nepřijala soubor příkazů.');
        }
        return [
            'accepted' => true, 'status' => 'import_started', 'reference' => $result['reference'],
            'upload_file_hash' => $result['upload_file_hash'], 'client_app_guid' => $result['client_app_guid'],
        ];
    }

    private function list(#[\SensitiveParameter] array $values, string $from, string $to): array
    {
        $zone = new \DateTimeZone('Europe/Prague');
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $from, $zone);
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $to, $zone);
        $today = new \DateTimeImmutable('today', $zone);
        if (!$start || !$end || $start->format('Y-m-d') !== $from || $end->format('Y-m-d') !== $to
            || $end < $start || $end > $today) {
            throw new BankConnectorException('period_invalid', 'Období výpisů není platné.');
        }
        if ($start < $today->modify('-44 days')) {
            throw new BankConnectorException('history_gap', 'Požadované období přesahuje dostupnou historii ČSOB.');
        }
        $result = $this->client->listFiles($this->clientCredentials($values), [
            'file_types' => ['VYPIS'], 'file_formats' => ['BBGPC'],
            'created_after' => $start->format(\DateTimeInterface::ATOM),
            'created_before' => $end->modify('+1 day')->format(\DateTimeInterface::ATOM),
        ]);
        if (count($result['files']) > self::MAX_FILES) throw $this->tooLarge();
        foreach ($result['files'] as $file) {
            if ($file['type'] !== 'VYPIS' || $file['format'] !== 'BBGPC') throw $this->invalid();
        }
        return $result['files'];
    }

    private function download(#[\SensitiveParameter] array $values, #[\SensitiveParameter] array $file, int &$bytes, int $deadline): array
    {
        $this->checkDeadline($deadline);
        if ($file['size'] > self::MAX_BYTES - $bytes) throw $this->tooLarge();
        $raw = $this->client->downloadFile($this->clientCredentials($values), $file);
        $this->checkDeadline($deadline);
        $bytes += strlen($raw);
        return $this->splitter->split($raw);
    }

    private function envelope(#[\SensitiveParameter] string $content): array
    {
        if (strlen($content) > 36 * 1024 * 1024) throw $this->tooLarge();
        try {
            $value = json_decode($content, true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw $this->invalid();
        }
        if (!is_array($value) || ($value['version'] ?? null) !== 1 || !is_array($value['account'] ?? null)
            || !is_array($value['files'] ?? null) || !array_is_list($value['files']) || count($value['files']) > self::MAX_FILES) throw $this->invalid();
        $account = $value['account'];
        if (!is_string($account['account_number'] ?? null) || preg_match('/^\d{16}$/D', $account['account_number']) !== 1
            || trim($account['account_number'], '0') === '' || ($account['bank_code'] ?? null) !== '0300'
            || !is_string($account['currency'] ?? null) || preg_match('/^[A-Z]{3}$/D', $account['currency']) !== 1) throw $this->invalid();
        $files = [];
        $bytes = 0;
        foreach ($value['files'] as $file) {
            if (!is_array($file) || !is_string($file['content'] ?? null) || !is_string($file['filename'] ?? null)
                || preg_match('/^csob-[a-f0-9]{24}\.gpc$/D', $file['filename']) !== 1) throw $this->invalid();
            $raw = base64_decode($file['content'], true);
            if ($raw === false) throw $this->invalid();
            $bytes += strlen($raw);
            if ($bytes > self::MAX_BYTES) throw $this->tooLarge();
            $blocks = $this->splitter->split($raw);
            if (count($blocks) !== 1 || !$this->matches($blocks[0]['parsed']['header'], $account)
                || $file['filename'] !== 'csob-' . substr(hash('sha256', $raw), 0, 24) . '.gpc') throw $this->invalid();
            $files[] = ['content' => $raw, 'filename' => $file['filename']];
        }
        return ['account' => $account, 'files' => $files];
    }

    private function decodeCredentials(#[\SensitiveParameter] string $encoded): array
    {
        if (strlen($encoded) > 50000) throw new BankConnectorOperationException('credential_invalid');
        try {
            $values = json_decode($encoded, true, 4, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new BankConnectorOperationException('credential_invalid');
        }
        if (!is_array($values)) throw new BankConnectorOperationException('credential_invalid');
        foreach (['contract_number', 'certificate', 'password', 'client_app_guid', 'account_number', 'bank_code', 'currency'] as $key) {
            if (!is_string($values[$key] ?? null)) throw new BankConnectorOperationException('credential_invalid');
        }
        if (preg_match('/^[1-9]\d{0,17}$/D', $values['contract_number']) !== 1
            || $values['certificate'] === '' || strlen($values['certificate']) > 32768 || strlen($values['password']) > 1024
            || preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/Di', $values['client_app_guid']) !== 1
            || preg_match('/^\d{16}$/D', $values['account_number']) !== 1 || trim($values['account_number'], '0') === ''
            || $values['bank_code'] !== '0300' || preg_match('/^[A-Z]{3}$/D', $values['currency']) !== 1) {
            throw new BankConnectorOperationException('credential_invalid');
        }
        return $values;
    }

    private function clientCredentials(#[\SensitiveParameter] array $values): array
    {
        return [
            'contract_number' => $values['contract_number'],
            'curl_options' => BankClientCertificate::curlOptions($values['certificate'], $values['password']),
        ];
    }

    private function nationalAccount(string $account, string $iban): string
    {
        $raw = trim($account) !== '' ? trim($account) : trim($iban);
        $ibanBank = AccountNumberNormalizer::czechIbanBankCode($raw);
        if ($ibanBank !== null && $ibanBank !== '0300') throw new BankConnectorOperationException('provider_account_mismatch');
        $national = AccountNumberNormalizer::czechIbanAccountPart($raw) ?? preg_replace('#/0300$#', '', $raw);
        if (!is_string($national) || preg_match('/^(?:(?:\d{1,6}-)?\d{1,10}|\d{16})$/D', $national) !== 1) {
            throw new BankConnectorOperationException('provider_account_mismatch');
        }
        $base = AccountNumberNormalizer::czechAccountBase($national);
        if ($base === null || $base === '') throw new BankConnectorOperationException('provider_account_mismatch');
        return str_pad(AccountNumberNormalizer::czechAccountPrefix($national) ?? '', 6, '0', STR_PAD_LEFT)
            . str_pad($base, 10, '0', STR_PAD_LEFT);
    }

    private function matches(array $header, array $identity): bool
    {
        return ($header['account_number'] ?? null) === $identity['account_number']
            && ($header['currency'] ?? null) === $identity['currency'];
    }

    private function identity(array $values): array
    {
        return ['account_number' => $values['account_number'], 'bank_code' => '0300', 'currency' => $values['currency']];
    }

    private function newGuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private function invalid(): BankConnectorException
    {
        return new BankConnectorException('statement_invalid', 'Odpověď ČSOB nemá platný formát výpisu.');
    }

    private function tooLarge(): BankConnectorException
    {
        return new BankConnectorException(BankConnectorException::STATEMENT_TOO_LARGE, 'Objem výpisů ČSOB překračuje povolený limit.');
    }

    private function checkDeadline(int $deadline): void
    {
        if (hrtime(true) >= $deadline) {
            throw new BankConnectorException('csob_download_timeout', 'Stahování výpisů překročilo časový limit.');
        }
    }
}
