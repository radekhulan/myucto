<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Document\PayrollArchiveDiagnostics;
use MyInvoice\Service\Payroll\Document\PayrollArchiveReencryptionService;
use MyInvoice\Service\Payroll\Document\PayrollDocumentKeyRing;
use MyInvoice\Service\Payroll\Security\PayrollKeyRotationStatusCache;
use Psr\Clock\ClockInterface;
use MyInvoice\Service\Payroll\Document\PayrollDocumentStorage;
use MyInvoice\Service\Payroll\Document\PayrollSheetSnapshotBuilder;
use MyInvoice\Service\Payroll\Export\PayrollPeriodExportStorage;
use MyInvoice\Service\Payroll\Security\PayrollKeyRotationService;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Rotace master klíče: po výměně `app.secret_encryption_key` se mzdové
 * hodnoty přebalí na nový klíč a pak jdou číst i bez starého klíče.
 *
 * Pokryté druhy úložišť: měnitelná tabulka (vyživovaná osoba), tabulka
 * s triggerem nad ověřením (bankovní účet zaměstnance), append-only tabulka
 * (roční snapshot mzdového listu, výjimka z migrace 1915), datové klíče
 * dokumentů a šifrovaný soubor exportu. Klíče jsou náhodné, vznikají jen
 * v testu a nikam se nezapisují.
 */
#[Group('integration')]
final class PayrollKeyRotationServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private int $supplierId;
    private int $userId;
    private int $employeeId;
    private string $oldKey;
    private string $newKey;
    private string|false $previousDataDir;
    private string $dataDir;

    private int $dependantId;
    private int $accountId;
    private int $annualId;
    private string $exportKey;
    private string $documentKey;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            self::markTestSkipped('cfg.php neexistuje - test vyžaduje DB.');
        }
        $this->db = \MyInvoice\Tests\Support\PayrollTestContainer::build()->get(Connection::class);
        foreach ([
            'payroll_dependants',
            'payroll_person_accounts',
            'payroll_annual_document_revisions',
            'payroll_document_data_keys',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                self::markTestSkipped("Chybí tabulka {$table}.");
            }
        }
        $pdo = $this->db->pdo();
        $trigger = array_column(
            \MyInvoice\Infrastructure\Database\TriggerMetadata::read($pdo, (string) $pdo->query('SELECT DATABASE()')->fetchColumn()),
            'ACTION_STATEMENT',
            'TRIGGER_NAME',
        )['trg_payroll_annual_revision_immutable_update'] ?? null;
        if (!is_string($trigger) || !str_contains($trigger, 'payroll_key_rewrap')) {
            self::fail('Chybí migrace 1915 (výjimka pro přebalení v append-only triggerech).');
        }
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            self::markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $this->previousDataDir = getenv('MYINVOICE_DATA_DIR');
        $this->dataDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'myucto-rotace-' . bin2hex(random_bytes(6));
        mkdir($this->dataDir, 0750, true);
        putenv('MYINVOICE_DATA_DIR=' . $this->dataDir);

        $this->oldKey = base64_encode(random_bytes(32));
        $this->newKey = base64_encode(random_bytes(32));

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->seedUnderOldKey();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->dataDir)) {
            $this->removeDirectory($this->dataDir);
            $this->previousDataDir === false
                ? putenv('MYINVOICE_DATA_DIR')
                : putenv('MYINVOICE_DATA_DIR=' . $this->previousDataDir);
        }
    }

    public function testRewrapMovesEverythingToNewKeyAndOldKeyCanBeRetired(): void
    {
        $service = $this->service($this->encryption($this->newKey, [$this->oldKey]));

        $before = $service->status($this->supplierId);
        self::assertSame(5, $before['stale_total'], json_encode($before));
        self::assertSame(0, $before['unknown_total']);

        $dry = $service->rewrapAll($this->supplierId, true);
        self::assertSame(5, $dry['would_rewrap'], json_encode($dry));
        self::assertSame(0, $dry['rewrapped']);
        self::assertSame(5, $service->status($this->supplierId)['stale_total'], 'Dry-run nesmí nic přepsat.');

        $updatedAt = $this->column('payroll_dependants', 'updated_at', $this->dependantId);
        $result = $service->rewrapAll($this->supplierId, false);

        self::assertSame(0, $result['failed'], json_encode($result));
        self::assertSame(5, $result['rewrapped'], json_encode($result));
        self::assertSame(0, $service->status($this->supplierId)['stale_total']);
        self::assertSame(0, $service->rewrapAll($this->supplierId, false)['rewrapped'], 'Druhý běh nemá co dělat.');

        // Starý klíč z konfigurace pryč: všechno musí jít přečíst jen novým.
        $newOnly = $this->encryption($this->newKey, []);
        self::assertSame('7001010009', $newOnly->decryptFor(
            $this->column('payroll_dependants', 'birth_number_ciphertext', $this->dependantId),
            PayrollSensitiveData::context(PayrollSensitiveField::PERSONAL_IDENTIFIER, $this->supplierId, $this->dependantId),
        ));
        self::assertSame('1000000005/0100', $newOnly->decryptFor(
            $this->column('payroll_person_accounts', 'bank_account_ciphertext', $this->accountId),
            PayrollSensitiveData::context(PayrollSensitiveField::BANK_ACCOUNT, $this->supplierId, $this->accountId),
        ));
        self::assertSame('{"synteticky":"snapshot"}', $newOnly->decryptFor(
            $this->column('payroll_annual_document_revisions', 'snapshot_ciphertext', $this->annualId),
            PayrollSheetSnapshotBuilder::encryptionContext($this->supplierId, $this->employeeId, 2019, str_repeat('e', 64)),
        ));
        self::assertSame(
            'synteticky export',
            (new PayrollPeriodExportStorage($newOnly))->readVerified($this->supplierId, $this->exportKey),
        );
        self::assertSame(
            '%PDF-1.4 syntetická páska',
            (new PayrollDocumentStorage(new PayrollDocumentKeyRing($this->db, $newOnly)))
                ->readVerified($this->supplierId, $this->documentKey, $this->employeeId),
        );

        // Obsah se nezměnil, takže se nesmí ztratit ověření účtu ani posunout
        // čas poslední změny.
        self::assertNotNull($this->column('payroll_person_accounts', 'verified_on', $this->accountId));
        self::assertSame($updatedAt, $this->column('payroll_dependants', 'updated_at', $this->dependantId));
        self::assertNull(
            $this->db->pdo()->query('SELECT @payroll_key_rewrap_annual_document_revisions')->fetchColumn(),
            'Výjimka z triggeru nesmí po zápisu zůstat nastavená.',
        );
    }

    /** Výjimka z migrace 1915 je adresná: běžný UPDATE append-only tabulky dál neprojde. */
    public function testAppendOnlyTableStillRejectsOrdinaryUpdate(): void
    {
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('immutable');
        $this->db->pdo()->prepare(
            'UPDATE payroll_annual_document_revisions SET snapshot_ciphertext = ? WHERE id = ?',
        )->execute(['enc:v2:0000000000000000:AAAA', $this->annualId]);
    }

    /** Ani s nastavenou výjimkou nejde změnit nic jiného než ciphertext. */
    public function testRewrapExceptionDoesNotAllowOtherColumns(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('SET @payroll_key_rewrap_annual_document_revisions = CAST(? AS UNSIGNED)')
            ->execute([$this->annualId]);
        try {
            $this->expectException(\PDOException::class);
            $pdo->prepare('UPDATE payroll_annual_document_revisions SET tax_year = 2020 WHERE id = ?')
                ->execute([$this->annualId]);
        } finally {
            $pdo->exec('SET @payroll_key_rewrap_annual_document_revisions = NULL');
        }
    }

    /**
     * Starý klíč odebraný dřív, než rotace doběhla: hodnoty nejdou přečíst.
     * Stav to musí říct nahlas a přebalení je nesmí poškodit.
     */
    public function testMissingOldKeyIsReportedAndNothingIsOverwritten(): void
    {
        $service = $this->service($this->encryption($this->newKey, []));
        $ciphertext = $this->column('payroll_dependants', 'birth_number_ciphertext', $this->dependantId);

        self::assertSame(5, $service->status($this->supplierId)['unknown_total']);
        $result = $service->rewrapAll($this->supplierId, false);

        self::assertSame(0, $result['rewrapped']);
        self::assertSame(5, $result['failed']);
        self::assertSame($ciphertext, $this->column('payroll_dependants', 'birth_number_ciphertext', $this->dependantId));
    }

    /**
     * Kontext každého cíle se skládá ze sloupců, které v tabulce opravdu jsou.
     * Překlep v názvu sloupce by jinak vyšel najevo až při ostré rotaci.
     */
    public function testEveryTargetBuildsContextFromRealColumns(): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        );
        foreach (PayrollKeyRotationService::targets() as $target) {
            $stmt->execute([$target['table']]);
            $row = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_KEY_PAIR) as $column => $type) {
                $row[$column] = in_array($type, ['int', 'bigint', 'smallint', 'tinyint', 'mediumint'], true) ? '7' : 'x';
            }
            self::assertNotSame([], $row, $target['table']);
            $row['purpose'] = $target['table'] === 'payroll_annual_document_revisions'
                ? 'payroll_sheet'
                : ($row['purpose'] ?? 'x');
            set_error_handler(static function (int $errno, string $message): never {
                throw new \OutOfBoundsException($message);
            });
            try {
                $context = ($target['context'])($row);
            } catch (\OutOfBoundsException $e) {
                self::fail($target['table'] . '.' . $target['column'] . ': ' . $e->getMessage());
            } finally {
                restore_error_handler();
            }
            self::assertNotSame('', $context);
        }
    }

    /**
     * Podání předchozím programem (převod PAMICA) přibylo souběžně s rotací
     * klíče; bez vlastního cíle by jeho obsah zůstal navždy pod starým klíčem.
     */
    public function testExternalJmhzSubmissionPayloadIsRewrapped(): void
    {
        if (!$this->db->hasTable('payroll_external_jmhz_submissions')) {
            self::markTestSkipped('Chybí tabulka payroll_external_jmhz_submissions (migrace 1901).');
        }
        $old = $this->encryption($this->oldKey, []);
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO payroll_external_jmhz_submissions
                (supplier_id, environment, source, source_key, document_kind, period, submission_type,
                 status, form_count, payload_ciphertext, payload_hash, payload_sha256)
             VALUES (?, 'test', 'pamica', 'MH:1', 'monthly', '2026-07', 'R', 'sent', 1, 'x', ?, ?)",
        )->execute([$this->supplierId, random_bytes(32), str_repeat('a', 64)]);
        $submissionId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE payroll_external_jmhz_submissions SET payload_ciphertext = ? WHERE id = ?')->execute([
            $old->encryptFor('{"hlaseni":"synteticke"}', PayrollSensitiveData::context(
                PayrollSensitiveField::EXTERNAL_JMHZ_PAYLOAD,
                $this->supplierId,
                $submissionId,
            )),
            $submissionId,
        ]);
        $pdo->prepare(
            "INSERT INTO payroll_external_jmhz_submission_forms
                (supplier_id, submission_id, position, payload_ciphertext, payload_hash, payload_sha256)
             VALUES (?, ?, 1, 'x', ?, ?)",
        )->execute([$this->supplierId, $submissionId, random_bytes(32), str_repeat('b', 64)]);
        $formId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE payroll_external_jmhz_submission_forms SET payload_ciphertext = ? WHERE id = ?')->execute([
            $old->encryptFor('{"formular":"synteticky"}', PayrollSensitiveData::context(
                PayrollSensitiveField::EXTERNAL_JMHZ_PAYLOAD,
                $this->supplierId,
                $formId,
            )),
            $formId,
        ]);

        $service = $this->service($this->encryption($this->newKey, [$this->oldKey]));
        self::assertSame(7, $service->status($this->supplierId)['stale_total']);
        $result = $service->rewrapAll($this->supplierId, false);
        self::assertSame(0, $result['failed'], json_encode($result));
        self::assertSame(0, $service->status($this->supplierId)['stale_total']);

        $newOnly = $this->encryption($this->newKey, []);
        self::assertSame('{"hlaseni":"synteticke"}', $newOnly->decryptFor(
            $this->column('payroll_external_jmhz_submissions', 'payload_ciphertext', $submissionId),
            PayrollSensitiveData::context(PayrollSensitiveField::EXTERNAL_JMHZ_PAYLOAD, $this->supplierId, $submissionId),
        ));
        self::assertSame('{"formular":"synteticky"}', $newOnly->decryptFor(
            $this->column('payroll_external_jmhz_submission_forms', 'payload_ciphertext', $formId),
            PayrollSensitiveData::context(PayrollSensitiveField::EXTERNAL_JMHZ_PAYLOAD, $this->supplierId, $formId),
        ));
    }

    /**
     * Klíč vyměněný bez předchozího v `previous_keys` (nebo databáze z jiné
     * instance): konfigurace žádnou rotaci nehlásí, a Diagnostika proto dřív
     * klíče vůbec neměřila (`skip`). Levná kontrola krajních řádků musí cizí
     * klíč najít i tak a hlásit `fail`.
     */
    public function testQuickCheckFindsUnknownKeyWithoutRotation(): void
    {
        $check = $this->rotationCheck($this->diagnostics([], new \DateTimeImmutable('2026-09-26 10:00'))->checks());

        self::assertSame('fail', $check['status'], json_encode($check));
        self::assertSame('unknown_key', $check['variant'] ?? null);
        self::assertSame('quick', $check['meta']['mode']);
        self::assertGreaterThan(0, $check['meta']['quick_unknown']);
        self::assertContains(
            'payroll_dependants.birth_number_ciphertext',
            array_column($check['meta']['unknown_targets'], 'name'),
        );

        $quick = $this->service($this->encryption($this->newKey, []))->quickStatus();
        self::assertContains($this->oldKeyId(), $quick['unknown_key_ids']);
        $known = $this->service($this->encryption($this->newKey, [$this->oldKey]))->quickStatus();
        self::assertNotContains($this->oldKeyId(), $known['unknown_key_ids']);
    }

    /**
     * Za rotace se plný průchod pustí a uloží s časem měření; do hodiny se
     * znovu nepočítá (Diagnostika ho neopakuje při každém otevření), ruční
     * měření ho obnoví hned.
     */
    public function testFullMeasurementDuringRotationIsCachedAndCanBeRefreshed(): void
    {
        $now = new \DateTimeImmutable('2026-09-26 10:00:00');
        $first = $this->rotationCheck($this->diagnostics([$this->oldKey], $now)->checks());
        self::assertSame('full', $first['meta']['mode'], json_encode($first));
        self::assertSame('warn', $first['status']);
        self::assertNotNull($first['meta']['measured_at']);
        $stale = (int) $first['meta']['stale_total'];
        self::assertGreaterThanOrEqual(5, $stale);

        // Další hodnota pod starým klíčem; cache do hodiny drží původní počet.
        $old = $this->encryption($this->oldKey, []);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_dependants (supplier_id, employee_id, relation, full_name, birth_date, existence_from)
             VALUES (?, ?, "child_own", "Druhé syntetické dítě", "2016-01-01", "2016-01-01")',
        )->execute([$this->supplierId, $this->employeeId]);
        $secondId = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'UPDATE payroll_dependants
                SET birth_number_ciphertext = ?, birth_number_hash = ?, birth_number_masked = "••••03"
              WHERE id = ?',
        )->execute([
            $old->encryptFor('1601010003', PayrollSensitiveData::context(PayrollSensitiveField::PERSONAL_IDENTIFIER, $this->supplierId, $secondId)),
            random_bytes(32),
            $secondId,
        ]);

        $cached = $this->rotationCheck($this->diagnostics([$this->oldKey], $now->modify('+30 minutes'))->checks());
        self::assertSame($stale, (int) $cached['meta']['stale_total']);
        self::assertSame($first['meta']['measured_at'], $cached['meta']['measured_at']);

        $measured = $this->diagnostics([$this->oldKey], $now->modify('+31 minutes'))->measureRotation();
        self::assertSame($stale + 1, (int) $measured['meta']['stale_total']);

        $expired = $this->rotationCheck($this->diagnostics([$this->oldKey], $now->modify('+3 hours'))->checks());
        self::assertNotSame($first['meta']['measured_at'], $expired['meta']['measured_at']);
    }

    public function testLimitStopsEarly(): void
    {
        $service = $this->service($this->encryption($this->newKey, [$this->oldKey]));

        $first = $service->rewrapAll($this->supplierId, false, 3);

        self::assertTrue($first['limit_reached'], json_encode($first));
        self::assertSame(2, $service->status($this->supplierId)['stale_total']);
        $service->rewrapAll($this->supplierId, false, 3);
        self::assertSame(0, $service->status($this->supplierId)['stale_total']);
    }

    private function seedUnderOldKey(): void
    {
        $old = $this->encryption($this->oldKey, []);
        $pdo = $this->db->pdo();

        $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetický zaměstnanec", "employee", 1)',
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payroll_dependants (supplier_id, employee_id, relation, full_name, birth_date, existence_from)
             VALUES (?, ?, "child_own", "Syntetické dítě", "2015-01-01", "2015-01-01")',
        )->execute([$this->supplierId, $this->employeeId]);
        $this->dependantId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'UPDATE payroll_dependants
                SET birth_number_ciphertext = ?, birth_number_hash = ?, birth_number_masked = "••••09",
                    updated_at = "2020-01-01 10:00:00"
              WHERE id = ?',
        )->execute([
            $old->encryptFor('7001010009', PayrollSensitiveData::context(
                PayrollSensitiveField::PERSONAL_IDENTIFIER,
                $this->supplierId,
                $this->dependantId,
            )),
            random_bytes(32),
            $this->dependantId,
        ]);

        $pdo->prepare(
            'INSERT INTO payroll_person_accounts
                (supplier_id, employee_id, label, bank_account_ciphertext, bank_account_hash,
                 bank_account_masked, effective_from)
             VALUES (?, ?, "Mzda", "", ?, "••••0100", "2019-01-01")',
        )->execute([$this->supplierId, $this->employeeId, random_bytes(32)]);
        $this->accountId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE payroll_person_accounts SET bank_account_ciphertext = ? WHERE id = ?')->execute([
            $old->encryptFor('1000000005/0100', PayrollSensitiveData::context(
                PayrollSensitiveField::BANK_ACCOUNT,
                $this->supplierId,
                $this->accountId,
            )),
            $this->accountId,
        ]);
        $pdo->prepare(
            'UPDATE payroll_person_accounts
                SET verification_source = "user_verified", verified_on = "2019-01-02", verified_by = ?
              WHERE id = ?',
        )->execute([$this->userId, $this->accountId]);

        $pdo->prepare(
            'INSERT INTO payroll_annual_document_revisions
                (supplier_id, employee_id, tax_year, purpose, revision_no,
                 snapshot_ciphertext, snapshot_hash, source_manifest_json,
                 source_manifest_hash, approved_at)
             VALUES (?, ?, 2019, ?, 1, ?, ?, "{}", ?, "2019-12-31 12:00:00")',
        )->execute([
            $this->supplierId,
            $this->employeeId,
            PayrollSheetSnapshotBuilder::PURPOSE,
            $old->encryptFor(
                '{"synteticky":"snapshot"}',
                PayrollSheetSnapshotBuilder::encryptionContext($this->supplierId, $this->employeeId, 2019, str_repeat('e', 64)),
            ),
            str_repeat('2', 64),
            str_repeat('e', 64),
        ]);
        $this->annualId = (int) $pdo->lastInsertId();

        $this->exportKey = (new PayrollPeriodExportStorage($old))
            ->store($this->supplierId, 'synteticky export')['storage_key'];
        $this->documentKey = (new PayrollDocumentStorage(new PayrollDocumentKeyRing($this->db, $old)))
            ->store($this->supplierId, '%PDF-1.4 syntetická páska', null, $this->employeeId)['storage_key'];
    }

    private function service(SecretEncryption $encryption): PayrollKeyRotationService
    {
        return new PayrollKeyRotationService(
            $this->db,
            $encryption,
            new PayrollDocumentKeyRing($this->db, $encryption),
        );
    }

    /** @param list<string> $previous */
    private function encryption(string $key, array $previous): SecretEncryption
    {
        return new SecretEncryption($this->config($key, $previous));
    }

    /** @param list<string> $previous */
    private function config(string $key, array $previous): Config
    {
        $config = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($config, 'data'))->setValue($config, [
            'app' => [
                'secret_encryption_key' => $key,
                'secret_encryption_previous_keys' => $previous,
                'pepper' => '',
            ],
        ]);

        return $config;
    }

    /**
     * Diagnostika nad danou konfigurací klíčů a hodinami. Klíč, kterým jsou
     * zašifrovaná data sdílené testovací databáze, je v předchozích klíčích,
     * aby cizí byl jen klíč, se kterým test počítá.
     *
     * @param list<string> $previous
     */
    private function diagnostics(array $previous, \DateTimeImmutable $now): PayrollArchiveDiagnostics
    {
        // Aktuálním klíčem je klíč instalace, ať data sdílené testovací DB
        // zůstanou známá a cizí je jen klíč, se kterým test počítá.
        $installed = (string) \MyInvoice\Tests\Support\PayrollTestContainer::build()->get(Config::class)->get('app.secret_encryption_key', '');
        if ($installed === '') {
            self::markTestSkipped('Instalace nemá šifrovací klíč.');
        }
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn($now);

        return new PayrollArchiveDiagnostics(
            \MyInvoice\Tests\Support\PayrollTestContainer::build()->get(PayrollArchiveReencryptionService::class),
            $this->service($this->encryption($installed, $previous)),
            $this->config($installed, $previous),
            $this->db,
            new PayrollKeyRotationStatusCache($this->db),
            $clock,
        );
    }

    private function oldKeyId(): string
    {
        $old = $this->encryption($this->oldKey, []);

        return (string) $old->keyIdOf($old->encryptFor('x', 'synthetic-key-id'));
    }

    /** @param list<array<string,mixed>> $checks @return array<string,mixed> */
    private function rotationCheck(array $checks): array
    {
        foreach ($checks as $check) {
            if ($check['id'] === PayrollArchiveDiagnostics::CHECK_ROTATION) {
                return $check;
            }
        }
        self::fail('Diagnostika nevrátila kontrolu klíčů.');
    }

    private function column(string $table, string $column, int $id): ?string
    {
        $stmt = $this->db->pdo()->prepare("SELECT {$column} FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);
        $value = $stmt->fetchColumn();

        return $value === null || $value === false ? null : (string) $value;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
