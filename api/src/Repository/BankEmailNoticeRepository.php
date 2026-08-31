<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Bank\AccountNumberNormalizer;

final class BankEmailNoticeRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly SecretEncryption $secrets,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function imapSettings(int $supplierId, bool $includeSecret = false): array
    {
        return $this->imapAccounts($supplierId, $includeSecret)[0] ?? $this->emptyImapAccount($supplierId);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function imapAccounts(int $supplierId, bool $includeSecret = false): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM bank_email_imap_settings WHERE supplier_id = ? ORDER BY name, id'
        );
        $stmt->execute([$supplierId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $this->castImapAccount($row, $includeSecret);
        }
        return $rows;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function imapAccount(int $supplierId, int $id, bool $includeSecret = false): ?array
    {
        $row = $this->imapAccountRaw($supplierId, $id);
        if ($row === null) {
            return null;
        }
        $this->castImapAccount($row, $includeSecret);
        return $row;
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function saveImapSettings(int $supplierId, array $body): array
    {
        $current = $this->imapSettings($supplierId);
        $id = isset($current['id']) ? (int) $current['id'] : null;
        return $this->saveImapAccount($supplierId, $body, $id);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function saveImapAccount(int $supplierId, array $body, ?int $id = null): array
    {
        $current = $id !== null ? $this->imapAccountRaw($supplierId, $id) : null;
        if ($id !== null && $current === null) {
            throw new \RuntimeException('IMAP účet nebyl nalezen.');
        }
        $passwordEnc = $current['password_enc'] ?? null;
        if (array_key_exists('password', $body) && trim((string) $body['password']) !== '') {
            $passwordEnc = $this->secrets->encrypt((string) $body['password']);
        }
        $name = trim((string) ($body['name'] ?? ($current['name'] ?? 'Výchozí IMAP účet')));
        if ($name === '') {
            throw new \RuntimeException('Název IMAP účtu je povinný.');
        }

        $data = [
            'supplier_id' => $supplierId,
            'name' => $name,
            'enabled' => !empty($body['enabled']) ? 1 : 0,
            'host' => trim((string) ($body['host'] ?? '')),
            'port' => max(1, min(65535, (int) ($body['port'] ?? 993))),
            'encryption' => in_array($body['encryption'] ?? 'ssl', ['ssl', 'tls', 'none'], true) ? (string) $body['encryption'] : 'ssl',
            'validate_cert' => array_key_exists('validate_cert', $body) ? (int) (bool) $body['validate_cert'] : 1,
            // Fail-closed default: ověření autenticity (DKIM/DMARC) je u NOVÉHO účtu
            // ZAPNUTÉ, stejně jako DB default. Chybí-li klíč v payloadu u existujícího
            // účtu, ponech dosavadní volbu — vypnout jde jen explicitním
            // `require_email_auth: false` z UI, ne opomenutím pole.
            'require_email_auth' => array_key_exists('require_email_auth', $body)
                ? (int) (bool) $body['require_email_auth']
                : (int) (bool) ($current['require_email_auth'] ?? 1),
            'allow_forwarded' => !empty($body['allow_forwarded']) ? 1 : 0,
            'forwarded_from' => $this->nullable($body['forwarded_from'] ?? null),
            'email_auth_serv_id' => $this->nullable($body['email_auth_serv_id'] ?? null),
            'username' => trim((string) ($body['username'] ?? '')),
            'password_enc' => $passwordEnc,
            'folder' => trim((string) ($body['folder'] ?? 'INBOX')) ?: 'INBOX',
            'max_messages_per_run' => max(1, min(500, (int) ($body['max_messages_per_run'] ?? 50))),
            'process_from_date' => $this->dateOrNull($body['process_from_date'] ?? null),
            'success_action' => $this->enum((string) ($body['success_action'] ?? 'none'), ['none', 'add_flag', 'move', 'mark_seen'], 'none'),
            'success_flag' => $this->nullable($body['success_flag'] ?? 'MyInvoiceProcessed'),
            'success_move_folder' => $this->nullable($body['success_move_folder'] ?? null),
            'failure_action' => $this->enum((string) ($body['failure_action'] ?? 'none'), ['none', 'add_flag', 'move'], 'none'),
            'failure_flag' => $this->nullable($body['failure_flag'] ?? 'MyInvoiceFailed'),
            'failure_move_folder' => $this->nullable($body['failure_move_folder'] ?? null),
            'retry_failed' => !empty($body['retry_failed']) ? 1 : 0,
            'max_attempts' => max(1, min(20, (int) ($body['max_attempts'] ?? 3))),
        ];

        if ($id !== null) {
            $data['id'] = $id;
            $sql = 'UPDATE bank_email_imap_settings
                       SET name = :name, enabled = :enabled, host = :host, port = :port, encryption = :encryption,
                           validate_cert = :validate_cert, require_email_auth = :require_email_auth,
                           allow_forwarded = :allow_forwarded, forwarded_from = :forwarded_from,
                           email_auth_serv_id = :email_auth_serv_id, username = :username, password_enc = :password_enc,
                           folder = :folder, max_messages_per_run = :max_messages_per_run,
                           process_from_date = :process_from_date, success_action = :success_action,
                           success_flag = :success_flag, success_move_folder = :success_move_folder,
                           failure_action = :failure_action, failure_flag = :failure_flag,
                           failure_move_folder = :failure_move_folder, retry_failed = :retry_failed,
                           max_attempts = :max_attempts
                     WHERE id = :id AND supplier_id = :supplier_id';
            $this->db->pdo()->prepare($sql)->execute($data);
            return $this->imapAccount($supplierId, $id) ?? $this->emptyImapAccount($supplierId);
        }

        $sql = 'INSERT INTO bank_email_imap_settings
                  (supplier_id, name, enabled, host, port, encryption, validate_cert, require_email_auth, allow_forwarded, forwarded_from, email_auth_serv_id,
                   username, password_enc, folder,
                   max_messages_per_run, process_from_date, success_action, success_flag, success_move_folder,
                   failure_action, failure_flag, failure_move_folder, retry_failed, max_attempts)
                VALUES
                  (:supplier_id, :name, :enabled, :host, :port, :encryption, :validate_cert, :require_email_auth, :allow_forwarded, :forwarded_from, :email_auth_serv_id,
                   :username, :password_enc, :folder,
                   :max_messages_per_run, :process_from_date, :success_action, :success_flag, :success_move_folder,
                   :failure_action, :failure_flag, :failure_move_folder, :retry_failed, :max_attempts)';
        $this->db->pdo()->prepare($sql)->execute($data);

        return $this->imapAccount($supplierId, (int) $this->db->pdo()->lastInsertId()) ?? $this->emptyImapAccount($supplierId);
    }

    public function deleteImapAccount(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM bank_email_imap_settings WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function providers(int $supplierId): array
    {
        // `enabled` u globálního provideru je efektivní hodnota: per-supplier override
        // (viz saveProvider) přebíjí globální nastavení, ať si dodavatel umí vypnout
        // provider, který nevlastní a nemůže editovat.
        $stmt = $this->db->pdo()->prepare(
            'SELECT p.*, o.enabled AS override_enabled
               FROM bank_email_notice_providers p
          LEFT JOIN bank_email_notice_provider_overrides o
                 ON o.provider_id = p.id AND o.supplier_id = ? AND p.supplier_id IS NULL
              WHERE p.supplier_id IS NULL OR p.supplier_id = ?
              ORDER BY p.supplier_id IS NULL DESC, p.name'
        );
        $stmt->execute([$supplierId, $supplierId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $this->castProvider($row);
        }
        return $rows;
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function saveProvider(int $supplierId, array $body, ?int $id = null): array
    {
        $fieldPatterns = $body['field_patterns'] ?? [];
        if (is_string($fieldPatterns)) {
            $fieldPatterns = json_decode($fieldPatterns, true);
        }
        if (!is_array($fieldPatterns)) {
            throw new \RuntimeException('field_patterns musí být JSON objekt.');
        }
        $normalizer = $body['normalizer_config'] ?? [];
        if (is_string($normalizer)) {
            $normalizer = json_decode($normalizer, true) ?: [];
        }

        // Regexy se uloží do DB a pak jdou rovnou do preg_match v parseru. Ověř, že se
        // zkompilují, ať admin dostane hezkou 400 chybu místo tichého selhání scanu.
        foreach ($fieldPatterns as $field => $pattern) {
            if (is_string($pattern) && trim($pattern) !== '') {
                $this->assertValidRegex($pattern, 'pole ' . (string) $field);
            }
        }
        foreach (['subject_pattern' => 'předmět', 'body_pattern' => 'tělo'] as $key => $label) {
            $value = trim((string) ($body[$key] ?? ''));
            if ($value !== '') {
                $this->assertValidRegex($value, $label);
            }
        }

        $data = [
            'supplier_id' => $supplierId,
            'code' => preg_replace('/[^a-z0-9_\\-]/', '_', strtolower(trim((string) ($body['code'] ?? '')))) ?: 'provider',
            'name' => trim((string) ($body['name'] ?? '')),
            'parser_type' => 'regex',
            'enabled' => array_key_exists('enabled', $body) ? (int) (bool) $body['enabled'] : 1,
            'sender_whitelist' => $this->nullable($body['sender_whitelist'] ?? null),
            'subject_pattern' => $this->nullable($body['subject_pattern'] ?? null),
            'body_pattern' => $this->nullable($body['body_pattern'] ?? null),
            'field_patterns' => json_encode($fieldPatterns, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'normalizer_config' => json_encode(is_array($normalizer) ? $normalizer : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        if ($data['name'] === '') {
            throw new \RuntimeException('Název provideru je povinný.');
        }

        if ($id !== null && $id > 0) {
            $owner = $this->providerSupplierId($id);
            if ($owner === null) {
                // Globální provider (supplier_id IS NULL) patří všem dodavatelům, takže se
                // jeho definice needituje. Dodavatel si ho ale MUSÍ umět vypnout pro sebe —
                // dokud to nešlo, byl nebezpečný globální default (provider bez whitelistu
                // odesílatele) z produktu neopravitelný pro všechny role včetně superadmina.
                // Ukládá se per-supplier override; globální řádek zůstává nedotčený.
                $this->assertGlobalProviderBodyUnchanged($id, $data);
                $this->setGlobalProviderEnabled($supplierId, $id, (bool) $data['enabled']);
                return $this->providerById($id, $supplierId);
            }
            if ($owner !== $supplierId) {
                throw new \RuntimeException('Provider jiného dodavatele nelze upravit.');
            }
            $data['id'] = $id;
            $stmt = $this->db->pdo()->prepare(
                'UPDATE bank_email_notice_providers
                    SET code = :code, name = :name, parser_type = :parser_type, enabled = :enabled,
                        sender_whitelist = :sender_whitelist, subject_pattern = :subject_pattern,
                        body_pattern = :body_pattern, field_patterns = :field_patterns,
                        normalizer_config = :normalizer_config
                  WHERE id = :id AND supplier_id = :supplier_id'
            );
            $stmt->execute($data);
            return $this->providerById($id);
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO bank_email_notice_providers
                (supplier_id, code, name, parser_type, enabled, sender_whitelist, subject_pattern, body_pattern, field_patterns, normalizer_config)
             VALUES (:supplier_id, :code, :name, :parser_type, :enabled, :sender_whitelist, :subject_pattern, :body_pattern, :field_patterns, :normalizer_config)'
        );
        $stmt->execute($data);
        return $this->providerById((int) $this->db->pdo()->lastInsertId());
    }

    public function deleteProvider(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM bank_email_notice_providers WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function accountMappings(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT c.id AS currency_id, c.code AS currency_code, c.label, c.account_number, c.bank_code, c.bank_name,
                    m.id, m.imap_account_id, m.provider_id, m.provider_code AS mapped_provider_code, m.enabled, m.amount_tolerance,
                    im.name AS imap_account_name,
                    COALESCE(p.code, m.provider_code) AS provider_code,
                    COALESCE(p.name, m.provider_code) AS provider_name
               FROM currencies c
          LEFT JOIN bank_email_account_mappings m ON m.currency_id = c.id
          LEFT JOIN bank_email_imap_settings im ON im.id = m.imap_account_id AND im.supplier_id = c.supplier_id
          LEFT JOIN bank_email_notice_providers p ON p.id = m.provider_id AND (p.supplier_id IS NULL OR p.supplier_id = c.supplier_id)
              WHERE c.supplier_id = ?
           ORDER BY c.code, c.is_default DESC, c.label'
        );
        $stmt->execute([$supplierId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = $row['id'] !== null ? (int) $row['id'] : null;
            $row['currency_id'] = (int) $row['currency_id'];
            $row['imap_account_id'] = $row['imap_account_id'] !== null ? (int) $row['imap_account_id'] : null;
            $row['provider_id'] = $row['provider_id'] !== null ? (int) $row['provider_id'] : null;
            $row['provider_ref'] = $this->providerRefFromMapping($row);
            $row['mapped_provider_code'] = $this->nullable($row['mapped_provider_code'] ?? null);
            $row['enabled'] = (bool) ($row['enabled'] ?? false);
            $row['amount_tolerance'] = (float) ($row['amount_tolerance'] ?? 0.05);
        }
        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<string> $allowedSystemCodes whitelist `system:<code>` referencí (kódy parserů z registry)
     */
    public function saveAccountMappings(int $supplierId, array $rows, array $allowedSystemCodes = []): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO bank_email_account_mappings
                (supplier_id, currency_id, imap_account_id, provider_id, provider_code, enabled, amount_tolerance)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                imap_account_id = VALUES(imap_account_id), provider_id = VALUES(provider_id), provider_code = VALUES(provider_code),
                enabled = VALUES(enabled), amount_tolerance = VALUES(amount_tolerance)'
        );
        foreach ($rows as $row) {
            $currencyId = (int) ($row['currency_id'] ?? 0);
            if ($currencyId <= 0 || !$this->currencyBelongsToSupplier($currencyId, $supplierId)) {
                continue;
            }
            $rawImapAccountId = $row['imap_account_id'] ?? null;
            $noImapAccount = $rawImapAccountId === 0 || $rawImapAccountId === '0';
            $imapAccountId = (!$noImapAccount && !empty($rawImapAccountId)) ? (int) $rawImapAccountId : null;
            if ($imapAccountId !== null && !$this->imapAccountBelongsToSupplier($imapAccountId, $supplierId)) {
                continue;
            }
            [$providerId, $providerCode] = $this->providerTargetFromMappingPayload($row, $supplierId, $allowedSystemCodes);
            $stmt->execute([
                $supplierId,
                $currencyId,
                $imapAccountId,
                $providerId,
                $providerCode,
                !empty($row['enabled']) && !$noImapAccount ? 1 : 0,
                max(0.0, (float) ($row['amount_tolerance'] ?? 0.05)),
            ]);
        }
    }

     /**
     * @return array<string,mixed>|null
     */
    public function mappingForRecipientAccount(
        int $supplierId,
        string $recipientAccount,
        ?int $imapAccountId = null,
        ?string $providerRef = null,
    ): ?array
    {
        [$account, $bankCode] = $this->splitAccount($recipientAccount);
        foreach ($this->accountMappings($supplierId) as $row) {
            if (!$row['enabled'] || empty($row['account_number'])) {
                continue;
            }
            if ($imapAccountId !== null && $row['imap_account_id'] !== null && (int) $row['imap_account_id'] !== $imapAccountId) {
                continue;
            }
            if ($providerRef !== null && $row['provider_ref'] !== null && (string) $row['provider_ref'] !== $providerRef) {
                continue;
            }
            if ($bankCode !== null && !empty($row['bank_code']) && (string) $row['bank_code'] !== $bankCode) {
                continue;
            }
            if (AccountNumberNormalizer::equals((string) $row['account_number'], $account)) {
                return $row;
            }
        }
        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function knownMessage(int $supplierId, ?int $imapAccountId, ?string $messageId, string $fallbackHash): ?array
    {
        $sql = 'SELECT * FROM bank_email_processed_messages WHERE supplier_id = ? AND imap_account_id = ? AND (fallback_hash = ?';
        $params = [$supplierId, $imapAccountId, $fallbackHash];
        if ($messageId !== null && $messageId !== '') {
            $sql .= ' OR message_id = ?';
            $params[] = $messageId;
        }
        $sql .= ') ORDER BY id DESC LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function recordMessage(array $data): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO bank_email_processed_messages
                (supplier_id, imap_account_id, imap_uid, message_id, fallback_hash, message_date, sender, subject,
                 provider_id, provider_code, status, attempts, parsed_payload, bank_statement_id,
                 bank_transaction_id, matched_invoice_id, error_message)
             VALUES
                (:supplier_id, :imap_account_id, :imap_uid, :message_id, :fallback_hash, :message_date, :sender, :subject,
                 :provider_id, :provider_code, :status, :attempts, :parsed_payload, :bank_statement_id,
                 :bank_transaction_id, :matched_invoice_id, :error_message)'
        );
        $stmt->execute([
            'supplier_id' => (int) $data['supplier_id'],
            'imap_account_id' => $data['imap_account_id'] ?? null,
            'imap_uid' => $data['imap_uid'] ?? null,
            'message_id' => $this->nullable($data['message_id'] ?? null),
            'fallback_hash' => (string) $data['fallback_hash'],
            'message_date' => $data['message_date'] ?? null,
            'sender' => $this->nullable($data['sender'] ?? null),
            'subject' => $this->nullable($data['subject'] ?? null),
            'provider_id' => $data['provider_id'] ?? null,
            'provider_code' => $this->nullable($data['provider_code'] ?? null),
            'status' => (string) $data['status'],
            'attempts' => (int) ($data['attempts'] ?? 1),
            'parsed_payload' => isset($data['parsed_payload']) ? json_encode($data['parsed_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'bank_statement_id' => $data['bank_statement_id'] ?? null,
            'bank_transaction_id' => $data['bank_transaction_id'] ?? null,
            'matched_invoice_id' => $data['matched_invoice_id'] ?? null,
            'error_message' => $this->nullable($data['error_message'] ?? null),
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function processedMessages(int $supplierId, int $limit = 100, int $offset = 0): array
    {
        // Stav `status` je snapshot z okamžiku skenu. Když se avízo-transakce spáruje
        // AŽ POZDĚJI (např. faktura vznikla po přijetí avíza), scanner už řádek nepřepíše
        // a zůstane viset na `match_failed`, přestože transakce je dnes spárovaná. Proto
        // odvozujeme `effective_status` ze živého `bank_transactions.match_status`
        // (a případný link na přijatou fakturu z payment_matches). Sloupec `status`
        // zůstává věrným záznamem výsledku skenu; pro zobrazení řídí `effective_status`.
        $stmt = $this->db->pdo()->prepare(
            'SELECT pm.*, im.name AS imap_account_name, i.varsymbol AS matched_varsymbol,
                    bt.match_status AS tx_match_status,
                    (SELECT recv.purchase_invoice_id
                       FROM payment_matches recv
                      WHERE recv.bank_transaction_id = pm.bank_transaction_id
                        AND recv.supplier_id = pm.supplier_id
                      ORDER BY recv.id LIMIT 1) AS matched_purchase_invoice_id
               FROM bank_email_processed_messages pm
          LEFT JOIN bank_email_imap_settings im ON im.id = pm.imap_account_id AND im.supplier_id = pm.supplier_id
          LEFT JOIN invoices i ON i.id = pm.matched_invoice_id AND i.supplier_id = pm.supplier_id
          LEFT JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
              WHERE pm.supplier_id = ?
           ORDER BY pm.processed_at DESC, pm.id DESC
              LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, $supplierId, \PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, min(500, $limit)), \PDO::PARAM_INT);
        $stmt->bindValue(3, max(0, $offset), \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['supplier_id'] = (int) $row['supplier_id'];
            $row['imap_account_id'] = $row['imap_account_id'] !== null ? (int) $row['imap_account_id'] : null;
            $row['imap_uid'] = $row['imap_uid'] !== null ? (int) $row['imap_uid'] : null;
            $row['provider_id'] = $row['provider_id'] !== null ? (int) $row['provider_id'] : null;
            $row['bank_statement_id'] = $row['bank_statement_id'] !== null ? (int) $row['bank_statement_id'] : null;
            $row['bank_transaction_id'] = $row['bank_transaction_id'] !== null ? (int) $row['bank_transaction_id'] : null;
            $row['matched_invoice_id'] = $row['matched_invoice_id'] !== null ? (int) $row['matched_invoice_id'] : null;
            $row['matched_purchase_invoice_id'] = $row['matched_purchase_invoice_id'] !== null ? (int) $row['matched_purchase_invoice_id'] : null;
            $row['attempts'] = (int) $row['attempts'];
            $row['parsed_payload'] = $this->decodeJson($row['parsed_payload'] ?? null);

            // Odvozený stav ze živého párování transakce (viz komentář u dotazu).
            $txStatus = isset($row['tx_match_status']) && $row['tx_match_status'] !== null
                ? (string) $row['tx_match_status'] : null;
            $row['tx_match_status'] = $txStatus;
            $raw = (string) $row['status'];
            $effective = $raw;
            if ($row['bank_transaction_id'] !== null && $txStatus !== null) {
                $txMatched = in_array($txStatus, ['auto_exact', 'auto_partial', 'manual'], true);
                if ($txMatched && in_array($raw, ['match_failed', 'postprocess_failed'], true)) {
                    // Aktuálně spárovaná platba je úspěch. Chybu následného přesunu
                    // e-mailu zachová error_message, ale nesmí přebít stav párování.
                    $effective = 'processed_success';
                } elseif (!$txMatched && $raw === 'processed_success') {
                    // Původně spárováno, později rozpárováno.
                    $effective = 'match_failed';
                }
            }
            $row['effective_status'] = $effective;
            $row['matched'] = $effective === 'processed_success';
        }
        return $rows;
    }

    public function countProcessedMessages(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM bank_email_processed_messages WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    public function deleteProcessedMessage(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM bank_email_processed_messages WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @return array{statement_id:int,transaction_id:int,match_result:array<string,mixed>}
     */
    public function createTransactionFromNotice(
        int $supplierId,
        \MyInvoice\Service\Bank\EmailNotice\ParsedBankEmailNotice $notice,
        string $sourceRef,
        ?float $tolerance,
        \MyInvoice\Service\Bank\StatementMatcher $matcher,
        ?string $accountCurrency = null,
    ): array {
        [$account, $bankCode] = $this->splitAccount($notice->recipientAccount);
        $pdo = $this->db->pdo();

        // Měna účtu je autoritativní: Fio avíza „příjem/výdaj na kontě" měnu neobsahují,
        // takže parser defaultuje na CZK. Cílový účet je ale už vyřešený na currencies row,
        // jejíž kód (EUR…) přicházi jako $accountCurrency. Parserem defaultovaná měna
        // zůstává jen poslední fallback (např. nemapovaná auto-detekce bez měny účtu).
        $currency = strtoupper(
            $accountCurrency !== null && trim($accountCurrency) !== ''
                ? trim($accountCurrency)
                : $notice->currency
        );

        // Měsíční výpis: avíza pro stejný účet/měnu/měsíc se sbírají do jednoho
        // bank_statements (source=email_notice), ať seznam výpisů nezaplaví 1 řádek/avízo.
        // Tenant je součástí klíče i SQL scope: stejné číslo účtu může být ve více
        // firmách a globální uq_bs_hash nesmí sloučit jejich avíza do cizího výpisu.
        // Deterministický file_hash + UNIQUE uq_bs_hash = atomický find-or-create.
        $ym = preg_match('/^\d{4}-\d{2}/', (string) $notice->postedAt, $mm) === 1 ? $mm[0] : date('Y-m');
        $legacyMonthKey = $account . '|' . ($bankCode ?? '') . '|' . $currency . '|' . $ym;
        $monthKey = $supplierId . '|' . $legacyMonthKey;
        $fileHash = hash('sha256', 'email-notice-monthly:' . $monthKey);
        $legacyFileHash = hash('sha256', 'email-notice-monthly:' . $legacyMonthKey);

        $findStmt = $pdo->prepare(
            'SELECT id
               FROM bank_statements
              WHERE supplier_id = ? AND file_hash IN (?, ?)
              ORDER BY (file_hash = ?) DESC
              LIMIT 1'
        );
        $findStmt->execute([$supplierId, $fileHash, $legacyFileHash, $fileHash]);
        $found = $findStmt->fetchColumn();
        if ($found !== false) {
            $statementId = (int) $found;
        } else {
            try {
                $pdo->prepare(
                    'INSERT INTO bank_statements
                        (source, source_ref, file_name, file_hash, supplier_id, account_number, bank_code, currency, statement_date,
                         transaction_count, matched_count, imported_by)
                     VALUES (?,?,?,?,?,?,?,?,?,0,0,NULL)'
                )->execute([
                    'email_notice',
                    'imap-monthly:' . $monthKey,
                    'Email avíza ' . $ym,
                    $fileHash,
                    $supplierId,
                    $account,
                    $bankCode,
                    $currency,
                    $ym . '-01',
                ]);
                $statementId = (int) $pdo->lastInsertId();
            } catch (\PDOException $e) {
                // Souběh — jiný běh výpis mezitím založil; načti existující.
                if ($e->getCode() === '23000') {
                    $findStmt->execute([$supplierId, $fileHash, $legacyFileHash, $fileHash]);
                    $statementId = (int) $findStmt->fetchColumn();
                } else {
                    throw $e;
                }
            }
        }

        // Idempotence (#161): re-scan téhož avíza (typicky po smazání processed-message
        // záznamu přes „Smazat záznam", což odstraní dedup na úrovni zprávy) nesmí založit
        // druhou transakci. source_ref nese message_id (nebo fallback hash) → je per-avízo
        // stabilní. Ukládaný klíč obsahuje tenant + hash reference; legacy raw
        // reference se hledá jen uvnitř stejného supplier scope. Najdeme-li
        // existující, jen re-matchujeme a vrátíme ji.
        $transactionSourceRef = 'supplier-' . $supplierId . ':' . hash('sha256', $sourceRef);
        $dupStmt = $pdo->prepare(
            "SELECT bt.id, bt.statement_id
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.source = 'email_notice'
                AND bs.supplier_id = ?
                AND bt.source_ref IN (?, ?)
              LIMIT 1"
        );
        $dupStmt->execute([$supplierId, $transactionSourceRef, $sourceRef]);
        $existing = $dupStmt->fetch(\PDO::FETCH_ASSOC);
        if ($existing !== false) {
            $transactionId = (int) $existing['id'];
            return [
                'statement_id' => (int) $existing['statement_id'],
                'transaction_id' => $transactionId,
                'match_result' => $matcher->match($transactionId),
            ];
        }

        $pdo->prepare(
            'INSERT INTO bank_transactions
                (source, source_ref, statement_id, posted_at, amount, balance, currency, variable_symbol, constant_symbol,
                 counterparty_account, counterparty_bank, counterparty_name, description, bank_ref, match_tolerance)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            'email_notice',
            $transactionSourceRef,
            $statementId,
            $notice->postedAt,
            $notice->amount,
            $notice->balance,
            $currency,
            // Avízo bez VS (odchozí platba, poplatek, karetní transakce) ukládáme jako
            // NULL, ne jako prázdný řetězec — sloupec je nullable a párování i sestavy
            // testují „VS není". Parsery vracejí '' a bez tohohle převodu by v DB vznikly
            // dvě různé reprezentace téhož „nemá VS" (#45).
            $notice->variableSymbol !== '' ? $notice->variableSymbol : null,
            $notice->constantSymbol,
            $notice->counterpartyAccount,
            $notice->counterpartyBank,
            $notice->counterpartyName,
            $notice->message,
            $notice->bankRef,
            $tolerance,
        ]);
        $transactionId = (int) $pdo->lastInsertId();
        $match = $matcher->match($transactionId);
        $matched = in_array($match['status'] ?? '', ['auto_exact', 'auto_partial'], true);
        // Naakumuluj počty do měsíčního výpisu.
        $pdo->prepare(
            'UPDATE bank_statements
                SET transaction_count = transaction_count + 1, matched_count = matched_count + ?
              WHERE id = ?'
        )->execute([$matched ? 1 : 0, $statementId]);

        return ['statement_id' => $statementId, 'transaction_id' => $transactionId, 'match_result' => $match];
    }

    public function updateScanStatus(int $supplierId, int $imapAccountId, string $status, ?string $message): void
    {
        $this->db->pdo()->prepare(
            'UPDATE bank_email_imap_settings
                SET last_scan_at = NOW(), last_scan_status = ?, last_scan_message = ?
              WHERE supplier_id = ? AND id = ?'
        )->execute([$status, $message !== null ? mb_substr($message, 0, 500) : null, $supplierId, $imapAccountId]);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function imapAccountRaw(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM bank_email_imap_settings WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyImapAccount(int $supplierId): array
    {
        return [
            'id' => null,
            'supplier_id' => $supplierId,
            'name' => 'Výchozí IMAP účet',
            'enabled' => false,
            'host' => '',
            'port' => 993,
            'encryption' => 'ssl',
            'validate_cert' => true,
            // Fail-closed default nového (dosud neuloženého) účtu — viz saveImapAccount.
            'require_email_auth' => true,
            'allow_forwarded' => false,
            'forwarded_from' => null,
            'email_auth_serv_id' => null,
            'username' => '',
            'folder' => 'INBOX',
            'max_messages_per_run' => 50,
            'process_from_date' => null,
            'success_action' => 'none',
            'success_flag' => 'MyInvoiceProcessed',
            'success_move_folder' => null,
            'failure_action' => 'none',
            'failure_flag' => 'MyInvoiceFailed',
            'failure_move_folder' => null,
            'retry_failed' => false,
            'max_attempts' => 3,
            'has_password' => false,
        ];
    }

    /**
     * @param int|null $supplierId Kontext dodavatele pro efektivní `enabled`
     *                             globálního provideru (null = syrová globální hodnota).
     * @return array<string,mixed>
     */
    private function providerById(int $id, ?int $supplierId = null): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT p.*, o.enabled AS override_enabled
               FROM bank_email_notice_providers p
          LEFT JOIN bank_email_notice_provider_overrides o
                 ON o.provider_id = p.id AND o.supplier_id = ? AND p.supplier_id IS NULL
              WHERE p.id = ?'
        );
        // supplier_id 0 v `supplier` neexistuje → LEFT JOIN nikdy nenajde override.
        $stmt->execute([$supplierId ?? 0, $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('Provider nenalezen.');
        }
        $this->castProvider($row);
        return $row;
    }

    /**
     * Zapne/vypne globálního providera JEN pro daného dodavatele.
     */
    private function setGlobalProviderEnabled(int $supplierId, int $providerId, bool $enabled): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO bank_email_notice_provider_overrides (supplier_id, provider_id, enabled)
                  VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)'
        )->execute([$supplierId, $providerId, $enabled ? 1 : 0]);
    }

    /**
     * U globálního provideru smí dodavatel přepnout jen `enabled`. Když payload mění
     * i definici (kódy, vzory), skonči hláškou místo tichého zahození změn — na úpravu
     * je v UI „Duplikovat", které založí vlastní editovatelnou kopii.
     *
     * @param array<string,mixed> $data
     */
    private function assertGlobalProviderBodyUnchanged(int $id, array $data): void
    {
        $current = $this->providerById($id);
        $differs = false;
        foreach (['code', 'name'] as $key) {
            if ((string) ($current[$key] ?? '') !== (string) ($data[$key] ?? '')) {
                $differs = true;
            }
        }
        foreach (['sender_whitelist', 'subject_pattern', 'body_pattern'] as $key) {
            if ($this->nullable($current[$key] ?? null) !== $this->nullable($data[$key] ?? null)) {
                $differs = true;
            }
        }
        foreach (['field_patterns', 'normalizer_config'] as $key) {
            $before = is_array($current[$key] ?? null) ? $current[$key] : [];
            $after = json_decode((string) ($data[$key] ?? '{}'), true);
            if (!is_array($after)) {
                $after = [];
            }
            if ($before != $after) {
                $differs = true;
            }
        }
        if ($differs) {
            throw new \RuntimeException(
                'U globálního provideru lze přepnout jen zapnutí/vypnutí pro vlastní firmu. '
                . 'Pro úpravu vzorů použij Duplikovat a edituj vlastní kopii.'
            );
        }
    }

    private function providerSupplierId(int $id): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT supplier_id FROM bank_email_notice_providers WHERE id = ?');
        $stmt->execute([$id]);
        $value = $stmt->fetchColumn();
        if ($value === false) {
            throw new \RuntimeException('Provider nenalezen.');
        }
        return $value !== null ? (int) $value : null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function castImapAccount(array &$row, bool $includeSecret = false): void
    {
        $row['id'] = (int) $row['id'];
        $row['supplier_id'] = (int) $row['supplier_id'];
        $row['enabled'] = (bool) $row['enabled'];
        $row['port'] = (int) $row['port'];
        $row['validate_cert'] = (bool) $row['validate_cert'];
        $row['require_email_auth'] = (bool) ($row['require_email_auth'] ?? false);
        $row['allow_forwarded'] = (bool) ($row['allow_forwarded'] ?? false);
        $row['max_messages_per_run'] = (int) $row['max_messages_per_run'];
        $row['retry_failed'] = (bool) $row['retry_failed'];
        $row['max_attempts'] = (int) $row['max_attempts'];
        $row['has_password'] = !empty($row['password_enc']);
        if ($includeSecret && !empty($row['password_enc'])) {
            $row['password'] = $this->secrets->decrypt((string) $row['password_enc']);
        }
        unset($row['password_enc']);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function castProvider(array &$row): void
    {
        // Per-supplier override globálního provideru (viz saveProvider) přebíjí `enabled`.
        $override = $row['override_enabled'] ?? null;
        unset($row['override_enabled']);
        $row['id'] = (int) $row['id'];
        $row['supplier_id'] = $row['supplier_id'] !== null ? (int) $row['supplier_id'] : null;
        $row['enabled'] = $override !== null ? (bool) $override : (bool) $row['enabled'];
        $row['disabled_by_supplier'] = $override !== null && !(bool) $override;
        $row['field_patterns'] = $this->decodeJson($row['field_patterns'] ?? '{}');
        $row['normalizer_config'] = $this->decodeJson($row['normalizer_config'] ?? '{}');
        $row['provider_ref'] = 'db:' . $row['id'];
        $row['system'] = false;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function providerRefFromMapping(array $row): ?string
    {
        if (!empty($row['provider_id'])) {
            return 'db:' . (int) $row['provider_id'];
        }
        $code = $this->nullable($row['mapped_provider_code'] ?? null);
        return $code !== null ? 'system:' . $code : null;
    }

    /**
     * Neplatná reference (cizí/neexistující DB provider, neznámý system kód)
     * degraduje na [null, null] = auto-detekce, stejně jako prázdný výběr.
     *
     * @param array<string,mixed> $row
     * @param list<string> $allowedSystemCodes
     * @return array{0:?int,1:?string}
     */
    private function providerTargetFromMappingPayload(array $row, int $supplierId, array $allowedSystemCodes): array
    {
        $ref = trim((string) ($row['provider_ref'] ?? ''));
        if ($ref === '') {
            return [null, null];
        }
        if (preg_match('/^db:(\d+)$/', $ref, $m) === 1) {
            $id = (int) $m[1];
            return $this->providerAvailableToSupplier($id, $supplierId) ? [$id, null] : [null, null];
        }
        if (preg_match('/^system:([a-z0-9_\\-]{1,80})$/', $ref, $m) === 1) {
            return in_array($m[1], $allowedSystemCodes, true) ? [null, $m[1]] : [null, null];
        }
        return [null, null];
    }

    private function currencyBelongsToSupplier(int $currencyId, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM currencies WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$currencyId, $supplierId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function imapAccountBelongsToSupplier(int $imapAccountId, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM bank_email_imap_settings WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$imapAccountId, $supplierId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function providerAvailableToSupplier(int $providerId, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM bank_email_notice_providers WHERE id = ? AND (supplier_id IS NULL OR supplier_id = ?)'
        );
        $stmt->execute([$providerId, $supplierId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * @return array{0:string,1:?string}
     */
    private function splitAccount(string $value): array
    {
        if (preg_match('/^(?<account>[0-9\-]+)\/(?<bank>[0-9]{4})$/', trim($value), $m) === 1) {
            return [$m['account'], $m['bank']];
        }
        return [$value, null];
    }

    private function enum(string $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    /**
     * Ověří, že se regex zkompiluje (stejné `~…~u` delimitery jako parser).
     */
    private function assertValidRegex(string $pattern, string $label): void
    {
        set_error_handler(static fn (): bool => true);
        $result = preg_match('~' . $pattern . '~u', '');
        restore_error_handler();
        if ($result === false) {
            throw new \RuntimeException("Neplatný regulární výraz ({$label}).");
        }
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value !== '' ? $value : null;
    }

    private function dateOrNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    /**
     * @return array<string,mixed>|list<mixed>|null
     */
    private function decodeJson(mixed $value): array|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : null;
    }
}
