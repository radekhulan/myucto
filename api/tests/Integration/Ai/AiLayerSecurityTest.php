<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Ai;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Ai\AiDpaException;
use MyInvoice\Service\Ai\AiDpaGate;
use MyInvoice\Service\Ai\AiPayloadSanitizer;
use MyInvoice\Service\Ai\AiSuggestionRepository;
use MyInvoice\Service\Ai\AiJobService;
use MyInvoice\Service\Ai\AiKillSwitchService;
use MyInvoice\Service\Ai\AiSuggestionService;
use MyInvoice\Service\Ai\AiWorker;
use MyInvoice\Service\Ai\AnomalyDetector;
use MyInvoice\Service\Ai\KnnSuggester;
use MyInvoice\Service\Import\OllamaClient;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class AiLayerSecurityTest extends TestCase
{
    private Connection $db;
    private AiWorker $worker;
    private AiKillSwitchService $killSwitch;
    private AiSuggestionService $suggestionService;
    private int $supplierId;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 4);
        if (!is_file($root . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->worker = $container->get(AiWorker::class);
            $this->killSwitch = $container->get(AiKillSwitchService::class);
            $this->suggestionService = $container->get(AiSuggestionService::class);
            $this->supplierId = (int) ($this->db->pdo()->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        if ($this->supplierId <= 0) {
            $this->markTestSkipped('Chybí testovací firma.');
        }
        $this->db->pdo()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testSanitizerDoesNotLeakIdentifiersAndClassifiesVariableSymbolShapes(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET ai_pseudo_salt=? WHERE id=?')
            ->execute([random_bytes(32), $this->supplierId]);
        $sanitizer = new AiPayloadSanitizer($this->db);
        $base = [
            'amount' => -1500,
            'currency' => 'CZK',
            'posted_at' => '2026-07-14',
            'counterparty_account' => '1000000005/0100',
            'counterparty_bank' => '0100',
            'counterparty_name' => 'Jan Novák',
            'description' => 'Uhrada najemneho Jan Novák, tel. 603123456, jan@novak.cz',
        ];

        $result = $sanitizer->sanitizeBankTx($this->supplierId, $base + ['variable_symbol' => '7455124573']);
        self::assertTrue($result['ok']);
        $serialized = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::assertStringContainsString('rc_like', $serialized);
        self::assertNull($result['data']['variable_symbol_token']);
        self::assertStringContainsString('uhrada najemneho', $serialized);
        self::assertMatchesRegularExpression('/acct_[0-9a-f]{12}/', $serialized);
        self::assertMatchesRegularExpression('/name_[0-9a-f]{12}/', $serialized);
        foreach (['7455124573', '1000000005', 'Jan', 'Novák', '603123456', 'jan@novak.cz'] as $secret) {
            self::assertStringNotContainsString($secret, $serialized);
        }

        self::assertSame('ico_like', $sanitizer->sanitizeBankTx($this->supplierId, $base + ['variable_symbol' => '12345678'])['data']['variable_symbol_shape']);
        $invoiceLike = $sanitizer->sanitizeBankTx($this->supplierId, $base + ['variable_symbol' => '2026000123']);
        self::assertSame('invoice_like', $invoiceLike['data']['variable_symbol_shape']);
        self::assertMatchesRegularExpression('/^vs_[0-9a-f]{12}$/', (string) $invoiceLike['data']['variable_symbol_token']);
        self::assertSame(
            $sanitizer->hmacToken($this->supplierId, 'acct_', '1000000005'),
            $sanitizer->hmacToken($this->supplierId, 'acct_', '1000000005'),
        );
        // Uživatelský kontext NEprochází whitelistem slov (na rozdíl od strojového popisu z banky):
        // je to text, který uživatel vědomě píše proto, aby dodal souvislost, kterou transakce nenese,
        // a whitelist by ji zahodil — z „platba kartou za pojištění mobilního telefonu" zbylo
        // „kartou pojisteni" a model pak sáhl po pojistném účtu 525 místo nákladového 548.
        // Ochranou zůstává strip PII + strop délky; UI u pole varuje, ať se osobní údaje nevkládají.
        self::assertSame(
            'jde o platbu kartou za pojištění mobilního telefonu',
            $sanitizer->sanitizeUserContext('jde o platbu kartou za pojištění mobilního telefonu'),
        );
        self::assertSame(
            'převod z běžného účtu na vlastní spořící',
            $sanitizer->sanitizeUserContext('  převod z běžného účtu   na vlastní spořící '),
        );
        // PII se z uživatelského kontextu musí odstranit i tak
        foreach (['jan@novak.cz', '+420 603 123 456', 'CZ6508000000192000145399'] as $secret) {
            $out = $sanitizer->sanitizeUserContext('kontakt ' . $secret . ' k platbě');
            self::assertStringNotContainsString($secret, $out);
        }
        // strop délky (300 znaků) — dlouhý text neprojde v plné délce do promptu
        self::assertSame(300, mb_strlen($sanitizer->sanitizeUserContext(str_repeat('a', 500))));
    }

    public function testSanitizerAndDpaGateFailClosed(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET ai_pseudo_salt=NULL,ai_dpa_confirmations=NULL WHERE id=?')
            ->execute([$this->supplierId]);
        $sanitizer = new AiPayloadSanitizer($this->db);
        self::assertSame(
            ['ok' => false, 'error' => 'salt_missing'],
            $sanitizer->sanitizeBankTx($this->supplierId, ['amount' => 1]),
        );

        $gate = new AiDpaGate($this->db, Bootstrap::buildContainer()->get(OllamaClient::class));
        self::assertFalse($gate->isConfirmed($this->supplierId, 'openai'));
        $this->expectException(AiDpaException::class);
        $gate->assertConfirmed($this->supplierId, 'openai');
    }

    public function testSuggestionLookupAndKnnStayInsideSupplier(): void
    {
        $supplierIds = array_map('intval', $this->db->pdo()->query('SELECT id FROM supplier ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN));
        if (count($supplierIds) < 2) {
            $clone = $this->db->pdo()->prepare(
                "INSERT INTO supplier (company_name,display_name,street,city,zip,country_id,is_vat_payer,email,
                                       default_currency_id,default_vat_rate_id,default_payment_due_days,default_hourly_rate,accounting_mode)
                 SELECT '__TEST AI TENANT B','__TEST AI TENANT B',street,city,zip,country_id,0,email,
                        default_currency_id,default_vat_rate_id,default_payment_due_days,default_hourly_rate,accounting_mode
                   FROM supplier WHERE id=?"
            );
            $clone->execute([$this->supplierId]);
            $supplierIds[] = (int) $this->db->pdo()->lastInsertId();
        }
        [$supplierA, $supplierB] = $supplierIds;
        $this->db->pdo()->prepare(
            "INSERT INTO ai_suggestions (supplier_id,entity_type,entity_id,source,payload_json,confidence)
             VALUES (?,'purchase_invoice',999999991,'knn','{\"debit_account_code\":\"501\"}',0.4)"
        )->execute([$supplierA]);
        $suggestionId = (int) $this->db->pdo()->lastInsertId();
        $repository = new AiSuggestionRepository($this->db);
        self::assertNotNull($repository->find($supplierA, $suggestionId));
        self::assertNull($repository->find($supplierB, $suggestionId));

        $vector = json_encode(array_fill(0, 1536, 0.01), JSON_THROW_ON_ERROR);
        $insert = $this->db->pdo()->prepare(
            "INSERT INTO ai_embeddings
                (supplier_id,entity_type,entity_id,content_hash,sanitized_text,embedding,label_debit,label_credit,label_source,embed_provider,embed_model,embed_region)
             VALUES (?,'purchase_invoice',?,SHA2(?,256),?,VEC_FromText(?),?,NULL,'approved','openai','text-embedding-3-small','eu')"
        );
        for ($i = 1; $i <= 20; $i++) {
            $insert->execute([$supplierA, 910000000 + $i, 'a' . $i, 'tenant a', $vector, '501']);
            $insert->execute([$supplierB, 920000000 + $i, 'b' . $i, 'tenant b', $vector, '518']);
        }
        $explain = $this->db->pdo()->prepare(
            "EXPLAIN FORMAT=JSON SELECT entity_id,VEC_DISTANCE_COSINE(embedding,VEC_FromText(?)) dist
               FROM ai_embeddings WHERE supplier_id=? AND entity_type='purchase_invoice' ORDER BY dist LIMIT 40"
        );
        $explain->execute([$vector, $supplierA]);
        $plan = (string) $explain->fetchColumn();
        self::assertStringContainsString('supplier_id', $plan);
        self::assertTrue(
            str_contains($plan, 'idx_aie_embedding') || str_contains($plan, 'filesort'),
            'MariaDB musí použít VECTOR index, nebo bezpečný tenantový exact-scan fallback.',
        );
        $result = (new KnnSuggester($this->db))->suggestForVector($supplierA, 'purchase_invoice', $vector);
        self::assertNotNull($result);
        self::assertSame('501', $result['debit']);
    }

    public function testDatabaseEnforcesConfidenceCapAndTenantScopedOpenKeys(): void
    {
        $indexes = $this->db->pdo()->query(
            "SELECT TABLE_NAME,INDEX_NAME,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) columns_list
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA=DATABASE() AND INDEX_NAME IN ('uq_ais_pending','uq_aij_open')
              GROUP BY TABLE_NAME,INDEX_NAME"
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(2, $indexes);
        foreach ($indexes as $index) {
            self::assertStringStartsWith('supplier_id,', (string) $index['columns_list']);
        }

        $this->expectException(PDOException::class);
        $this->db->pdo()->prepare(
            "INSERT INTO ai_suggestions (supplier_id,entity_type,entity_id,source,payload_json,confidence)
             VALUES (?,'purchase_invoice',999999992,'llm','{}',0.41)"
        )->execute([$this->supplierId]);
    }

    public function testPurchaseSuggestionKeepsInputFingerprintAndEditExpiresIt(): void
    {
        $repository = new AiSuggestionRepository($this->db);
        $hash = hash('sha256', 'sanitized purchase v1');
        $created = $repository->create([
            'supplier_id' => $this->supplierId,
            'entity_type' => 'purchase_invoice',
            'entity_id' => 999999993,
            'source' => 'knn',
            'payload' => ['debit_account_code' => '501'],
            'input_hash' => $hash,
            'confidence' => 0.4,
        ]);
        $userId = (int) $this->db->pdo()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $userId);
        self::assertTrue($repository->accept(
            $this->supplierId,
            $created['id'],
            $userId,
            ['debit_account_code' => '501'],
        ));
        self::assertSame(
            ['debit' => '501', 'input_hash' => $hash],
            $repository->acceptedForPurchase($this->supplierId, 999999993),
        );

        $repository->expireForEntity($this->supplierId, 'purchase_invoice', 999999993);
        self::assertNull($repository->acceptedForPurchase($this->supplierId, 999999993));
    }

    public function testDailyClassificationLimitIsAtomicAndHardCapped(): void
    {
        $this->db->pdo()->prepare('DELETE FROM ai_daily_usage WHERE supplier_id=? AND usage_date=CURDATE()')
            ->execute([$this->supplierId]);
        $jobs = new AiJobService($this->db);
        for ($i = 0; $i < AiJobService::DAILY_JOB_LIMIT; $i++) {
            self::assertTrue($jobs->tryReserveClassification($this->supplierId));
        }
        self::assertFalse($jobs->tryReserveClassification($this->supplierId));
        self::assertSame(AiJobService::DAILY_JOB_LIMIT, $jobs->todayUsed($this->supplierId));
    }

    public function testAnomalyDetectorUsesOnlyCurrentSupplierHistory(): void
    {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO bank_statements (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date)
             VALUES (?,?,?,?,?,?,?)'
        );
        $statement->execute([
            $this->supplierId,
            '__test_ai_statement.gpc',
            hash('sha256', random_bytes(16)),
            '1000000005',
            '0100',
            'CZK',
            '2026-07-14',
        ]);
        $statementId = (int) $this->db->pdo()->lastInsertId();
        $insert = $this->db->pdo()->prepare(
            'INSERT INTO bank_transactions (statement_id,posted_at,amount,variable_symbol,counterparty_account,counterparty_bank)
             VALUES (?,?,?,?,?,?)'
        );
        for ($i = 1; $i <= 6; $i++) {
            $insert->execute([$statementId, sprintf('2026-06-%02d', $i), -100, (string) (1000 + $i), '123456789', '0300']);
        }
        $insert->execute([$statementId, '2026-07-14', -1000, '999999', '123456789', '0300']);
        $txId = (int) $this->db->pdo()->lastInsertId();
        $result = (new AnomalyDetector($this->db))->checkBankTx($this->supplierId, [
            'id' => $txId,
            'posted_at' => '2026-07-14',
            'amount' => -1000,
            'variable_symbol' => '999999',
            'counterparty_account' => '123456789',
            'recipient_account' => '1000000005',
            'recipient_bank' => '0100',
            'statement_supplier_id' => $this->supplierId,
        ]);
        self::assertSame('amount_zscore', $result[0]['code'] ?? null);
        self::assertSame([], (new AnomalyDetector($this->db))->checkBankTx($this->supplierId + 999999, [
            'id' => $txId,
            'statement_supplier_id' => $this->supplierId,
        ]));
    }

    public function testWorkerDryRunKeepsQueueUntouched(): void
    {
        $this->db->pdo()->prepare('DELETE FROM ai_jobs WHERE supplier_id=?')->execute([$this->supplierId]);
        $jobs = new AiJobService($this->db);
        self::assertTrue($jobs->enqueue($this->supplierId, 'classify_purchase', 'purchase_invoice', 880000001));
        $stats = $this->worker->run($this->supplierId, 10, true);
        self::assertSame(1, $stats['processed']);
        $status = $this->db->pdo()->prepare('SELECT status FROM ai_jobs WHERE supplier_id=? AND entity_id=880000001');
        $status->execute([$this->supplierId]);
        self::assertSame('queued', $status->fetchColumn());
    }

    public function testKillSwitchTreatsApprovedSuggestionsWithOverrideAsOverridden(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "DELETE c FROM accounting_corrections c
              JOIN bank_posting_suggestions s ON s.id=c.suggestion_id
             WHERE s.supplier_id=? AND s.source='llm'"
        )->execute([$this->supplierId]);
        $pdo->prepare("DELETE FROM bank_posting_suggestions WHERE supplier_id=? AND source='llm'")
            ->execute([$this->supplierId]);
        $pdo->prepare("DELETE FROM ai_suggestions WHERE supplier_id=? AND source='llm'")
            ->execute([$this->supplierId]);

        $statement = $pdo->prepare(
            'INSERT INTO bank_statements
                (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date)
             VALUES (?,?,?,?,?,?,?)'
        );
        $statement->execute([
            $this->supplierId, '__test_ai_kill_switch.gpc', hash('sha256', random_bytes(16)),
            '1000000005', '0100', 'CZK', '2026-07-14',
        ]);
        $statementId = (int) $pdo->lastInsertId();
        $insertTx = $pdo->prepare(
            'INSERT INTO bank_transactions (statement_id,posted_at,amount,currency) VALUES (?,?,-100,\'CZK\')'
        );
        $insertSuggestion = $pdo->prepare(
            "INSERT INTO bank_posting_suggestions
                (supplier_id,bank_transaction_id,source,debit_account_code,credit_account_code,amount,status,reviewed_at)
             VALUES (?,?,'llm','518','221',100,'approved',NOW())"
        );
        $insertCorrection = $pdo->prepare(
            "INSERT INTO accounting_corrections
                (supplier_id,event_type,entity_type,entity_id,suggestion_id,suggestion_source,
                 suggested_debit,suggested_credit,final_debit,final_credit,amount)
             VALUES (?,'approve_override','bank_transaction',?,?,'llm','518','221','501','221',100)"
        );
        for ($i = 0; $i < 10; $i++) {
            $insertTx->execute([$statementId, '2026-07-14']);
            $txId = (int) $pdo->lastInsertId();
            $insertSuggestion->execute([$this->supplierId, $txId]);
            $suggestionId = (int) $pdo->lastInsertId();
            if ($i < 6) {
                $insertCorrection->execute([$this->supplierId, $txId, $suggestionId]);
            }
        }

        $method = new \ReflectionMethod($this->killSwitch, 'decisions');
        $rows = $method->invoke($this->killSwitch, $this->supplierId, 'llm', true);
        self::assertCount(10, $rows);
        self::assertCount(6, array_filter($rows, static fn (array $row): bool => $row['status'] === 'overridden'));
        self::assertCount(4, array_filter($rows, static fn (array $row): bool => $row['status'] === 'approved'));
    }

    public function testRejectedBankAiSourcesAreRememberedBeforeQueueing(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE supplier SET ai_assist_enabled=1,ai_assist_scope='bank_tx' WHERE id=?")
            ->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM ai_source_mutes WHERE supplier_id=?')->execute([$this->supplierId]);
        $statement = $pdo->prepare(
            'INSERT INTO bank_statements
                (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date)
             VALUES (?,?,?,?,?,?,?)'
        );
        $statement->execute([
            $this->supplierId, '__test_ai_reject_memory.gpc', hash('sha256', random_bytes(16)),
            '1000000005', '0100', 'CZK', '2026-07-14',
        ]);
        $statementId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO bank_transactions (statement_id,posted_at,amount,currency)
             VALUES (?,?,-100,'CZK')"
        )->execute([$statementId, '2026-07-14']);
        $txId = (int) $pdo->lastInsertId();
        $insert = $pdo->prepare(
            "INSERT INTO bank_posting_suggestions
                (supplier_id,bank_transaction_id,source,debit_account_code,credit_account_code,amount,status,reviewed_at)
             VALUES (?,?,?,'518','221',100,'rejected',NOW())"
        );
        $insert->execute([$this->supplierId, $txId, 'knn']);
        self::assertTrue($this->suggestionService->enqueueBank($this->supplierId, $txId),
            'Odmítnutí kNN nesmí zablokovat dosud neodmítnutý LLM zdroj.');

        $insert->execute([$this->supplierId, $txId, 'llm']);
        self::assertFalse($this->suggestionService->enqueueBank($this->supplierId, $txId),
            'Po odmítnutí obou zdrojů se klasifikace nesmí znovu zařadit.');
    }

    /**
     * Job je jen fotka stavu při zařazení. Než ho worker vezme, může tx zaúčtovat
     * pravidlo/detektor/člověk — pak se nesmí utratit ani token a nesmí vzniknout
     * duplicitní návrh nad živým zápisem (ostrá data: 58 takových, migrace 1132).
     */
    public function testStaleJobOverPostedTransactionIsSkippedBeforeSpendingTokens(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE supplier SET ai_assist_enabled=1,ai_assist_scope='bank_tx' WHERE id=?")
            ->execute([$this->supplierId]);
        $statement = $pdo->prepare(
            'INSERT INTO bank_statements
                (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date)
             VALUES (?,?,?,?,?,?,?)'
        );
        $statement->execute([
            $this->supplierId, '__test_ai_stale_job.gpc', hash('sha256', random_bytes(16)),
            '1000000005', '0100', 'CZK', '2026-07-14',
        ]);
        $statementId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO bank_transactions (statement_id,posted_at,amount,currency)
             VALUES (?,'2026-07-14',-100,'CZK')"
        )->execute([$statementId]);
        $txId = (int) $pdo->lastInsertId();

        $periodId = (int) $pdo->query(
            'SELECT id FROM accounting_periods WHERE supplier_id=' . $this->supplierId . ' ORDER BY id DESC LIMIT 1'
        )->fetchColumn();
        if ($periodId <= 0) {
            $pdo->prepare(
                "INSERT INTO accounting_periods (supplier_id,fiscal_year,starts_on,ends_on,status)
                 VALUES (?,2026,'2026-01-01','2026-12-31','open')"
            )->execute([$this->supplierId]);
            $periodId = (int) $pdo->lastInsertId();
        }
        $pdo->prepare(
            "INSERT INTO journal_entries (supplier_id,period_id,entry_date,source_type,source_id,posted_at)
             VALUES (?,?,'2026-07-14','bank',?,NOW())"
        )->execute([$this->supplierId, $periodId, $txId]);

        $before = (int) $pdo->query(
            'SELECT COUNT(*) FROM bank_posting_suggestions WHERE bank_transaction_id=' . $txId
        )->fetchColumn();

        $result = $this->suggestionService->suggestBankNow($this->supplierId, $txId);

        self::assertFalse($result['ok']);
        self::assertSame('already_posted', $result['error']);
        self::assertSame($before, (int) $pdo->query(
            'SELECT COUNT(*) FROM bank_posting_suggestions WHERE bank_transaction_id=' . $txId
        )->fetchColumn(), 'Zvětralý job nesmí založit návrh nad už zaúčtovanou tx.');
    }

    /**
     * Pravidlo mohlo vzniknout až po zařazení jobu — deterministická kontace má
     * přednost, LLM se na takový pohyb nemá ptát vůbec.
     */
    public function testJobIsSkippedWhenRuleNowMatchesTransaction(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE supplier SET ai_assist_enabled=1,ai_assist_scope='bank_tx' WHERE id=?")
            ->execute([$this->supplierId]);
        $statement = $pdo->prepare(
            'INSERT INTO bank_statements
                (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date)
             VALUES (?,?,?,?,?,?,?)'
        );
        $statement->execute([
            $this->supplierId, '__test_ai_rule_wins.gpc', hash('sha256', random_bytes(16)),
            '1000000005', '0100', 'CZK', '2026-07-14',
        ]);
        $statementId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO bank_transactions (statement_id,posted_at,amount,currency,counterparty_account,counterparty_bank)
             VALUES (?,'2026-07-14',-1436,'CZK','21012-7928311','0710')"
        )->execute([$statementId]);
        $txId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO bank_posting_rules
                (supplier_id,name,direction,counterparty_account,counterparty_bank,
                 debit_account_code,credit_account_code,mode,is_active,applies_currency)
             VALUES (?, '__test Odvod OSSZ','outgoing','21012-7928311','0710','336','221','suggest',1,'CZK')"
        )->execute([$this->supplierId]);

        $result = $this->suggestionService->suggestBankNow($this->supplierId, $txId);

        self::assertFalse($result['ok']);
        self::assertSame('rule_matched', $result['error']);
    }

    /** Pravidlo omezené na jiný vlastní účet pohyb nepokrývá, AI ho smí navrhnout. */
    public function testRuleOfAnotherOwnAccountDoesNotBlockSuggestion(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE supplier SET ai_assist_enabled=1,ai_assist_scope='bank_tx' WHERE id=?")
            ->execute([$this->supplierId]);
        $account = $pdo->prepare(
            "INSERT INTO supplier_bank_accounts (supplier_id, label, account_number, bank_code, bank_code_norm, currency, account_canonical, kind, source, is_active)
             VALUES (?, ?, ?, '0100', '0100', 'CZK', ?, 'current', 'manual', 1)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)"
        );
        $account->execute([$this->supplierId, '__test vlastní', '1000000005', '1000000005']);
        $ownId = (int) $pdo->lastInsertId();
        $account->execute([$this->supplierId, '__test jiný', '3000000004', '3000000004']);
        $otherId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO bank_statements
                (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $this->supplierId, '__test_ai_rule_account.gpc', hash('sha256', random_bytes(16)),
            '1000000005', '0100', 'CZK', '2026-07-14',
        ]);
        $pdo->prepare(
            "INSERT INTO bank_transactions (statement_id,posted_at,amount,currency,counterparty_account,counterparty_bank)
             VALUES (?,'2026-07-14',-1436,'CZK','21012-7928311','0710')"
        )->execute([(int) $pdo->lastInsertId()]);
        $txId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO bank_posting_rules
                (supplier_id,name,direction,bank_account_id,counterparty_account,counterparty_bank,
                 debit_account_code,credit_account_code,mode,is_active,applies_currency)
             VALUES (?, '__test Odvod OSSZ jiného účtu','outgoing',?,'21012-7928311','0710','336','221','suggest',1,'CZK')"
        )->execute([$this->supplierId, $otherId]);
        $ruleId = (int) $pdo->lastInsertId();

        $other = $this->suggestionService->suggestBankNow($this->supplierId, $txId);
        self::assertNotSame('rule_matched', $other['error'] ?? null, 'Pravidlo jiného vlastního účtu pohyb nepokrývá.');

        $pdo->prepare('UPDATE bank_posting_rules SET bank_account_id = ? WHERE id = ?')->execute([$ownId, $ruleId]);
        $own = $this->suggestionService->suggestBankNow($this->supplierId, $txId);
        self::assertSame('rule_matched', $own['error'] ?? null, 'Pravidlo účtu výpisu pohyb pokrývá.');
    }

    /**
     * Pohyb kartou, ke kterému párování našlo doklad ke kontrole, se účtuje přes doklad.
     * Kontace z popisu (typicky náklad místo 321) by účetní jen mátla.
     */
    public function testTransactionAwaitingDocumentMatchIsNotClassified(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE supplier SET ai_assist_enabled=1,ai_assist_scope='bank_tx' WHERE id=?")
            ->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO bank_statements
                (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $this->supplierId, '__test_ai_document_match.gpc', hash('sha256', random_bytes(16)),
            '1000000005', '0100', 'CZK', '2026-07-14',
        ]);
        $statementId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO bank_transactions (statement_id,posted_at,amount,currency,card_last4,description,match_reason)
             VALUES (?,'2026-07-14',-250.37,'CZK','6801','SYNTHETIC SHOP','card_match_requires_review')"
        )->execute([$statementId]);
        $txId = (int) $pdo->lastInsertId();

        $result = $this->suggestionService->suggestBankNow($this->supplierId, $txId);

        self::assertFalse($result['ok']);
        self::assertSame('document_match', $result['error']);
        self::assertSame(0, (int) $pdo->query(
            'SELECT COUNT(*) FROM bank_posting_suggestions WHERE bank_transaction_id=' . $txId
        )->fetchColumn());
    }
}
