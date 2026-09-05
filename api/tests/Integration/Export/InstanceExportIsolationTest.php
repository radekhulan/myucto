<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Export;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Export\Instance\InstanceExportJobStore;
use MyInvoice\Service\Export\Instance\InstanceExportService;
use MyInvoice\Service\Export\Instance\TenantScopeResolver;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * H-14 — izolace firem v kompletním exportu.
 *
 * Tohle je ta vlastnost, kvůli které existuje {@see TenantScopeResolver}: instalace
 * může vést víc firem (účetní kancelář) a vydat zákazníkovi cizí firmu není chyba
 * v souboru, ale únik dat.
 *
 * Testy jsou psané tak, aby NEBYLY zelené omylem:
 *   • {@see testFixtureWouldExposeForeignDataWithoutScope()} je NEGATIVNÍ KONTROLA —
 *     spustí tutéž kontrolu nad NEoscopovaným dumpem a ověří, že cizí řádky NAJDE.
 *     Bez ní by „v archivu nejsou cizí data" mohlo znamenat jen „v DB žádná nejsou".
 *   • {@see testResolverRefusesUnscopedTables()} hlídá default deny: každý vrácený
 *     filtr musí být vázaný na supplier_id, žádný `1 = 1`.
 *
 * DB část běží v transakci s rollbackem; soubory uklízí tearDown.
 */
#[Group('integration')]
final class InstanceExportIsolationTest extends TestCase
{
    /** Řetězec, který smí být JEN v datech cizí firmy. */
    private const FOREIGN_MARKER = 'CIZI-FIRMA-NESMI-BYT-V-ARCHIVU-4711';

    /** Řetězec, který MUSÍ být v archivu vlastníka (jinak testujeme prázdno). */
    private const OWN_MARKER = 'VLASTNI-FIRMA-MA-BYT-V-ARCHIVU-1234';

    private Connection $db;
    private InstanceExportService $export;
    private InstanceExportJobStore $jobs;
    private TenantScopeResolver $resolver;

    private int $supplierId = 0;
    private int $otherSupplierId = 0;
    private int $czId = 0;
    private int $currencyId = 0;
    private bool $inTx = false;

    /** @var list<string> */
    private array $tempPaths = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->export = $container->get(InstanceExportService::class);
            $this->jobs = $container->get(InstanceExportJobStore::class);
            $this->resolver = $container->get(TenantScopeResolver::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($currencyId === 0 || $vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data (currency/vat_rate/country) v DB.');
        }

        $this->czId = $czId;
        $this->currencyId = $currencyId;

        $pdo->beginTransaction();
        $this->inTx = true;

        $createSupplier = static function (string $name, string $email) use ($pdo, $czId, $currencyId, $vatRateId): int {
            $stmt = $pdo->prepare(
                'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
                 VALUES (?, "Testovaci 1", "Praha", "11000", ?, ?, ?, ?)'
            );
            $stmt->execute([$name, $czId, $email, $currencyId, $vatRateId]);
            return (int) $pdo->lastInsertId();
        };
        $this->supplierId = $createSupplier('H14 export vlastnik s.r.o.', 'h14-vlastnik@example.com');
        $this->otherSupplierId = $createSupplier('H14 export cizi s.r.o.', 'h14-cizi@example.com');

        // Data OBOU firem — bez cizích dat by test nic neověřoval.
        $this->createTenantData($this->supplierId, self::OWN_MARKER);
        $this->createTenantData($this->otherSupplierId, self::FOREIGN_MARKER);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        foreach ([$this->supplierId, $this->otherSupplierId] as $sid) {
            if ($sid === 0) {
                continue;
            }
            $dir = RuntimePaths::storage('instance-exports') . DIRECTORY_SEPARATOR . 'sup-' . $sid;
            if (is_dir($dir)) {
                foreach (glob($dir . '/*') ?: [] as $file) {
                    is_dir($file) ? @rmdir($file) : @unlink($file);
                }
                @rmdir($dir);
            }
            @unlink(RuntimePaths::storage('locks') . '/instance-export-sup' . $sid . '.lock');
        }
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    // ── vlastní izolace ───────────────────────────────────────────────────────

    public function testExportOfCompanyAContainsNoRowOfCompanyB(): void
    {
        $zipPath = $this->runDataExport();

        $zip = new ZipArchive();
        self::assertTrue($zip->open($zipPath) === true, 'Archiv jde otevřít.');

        $ownFound = false;
        $checkedTables = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (!str_starts_with($name, 'data/') || !str_ends_with($name, '.jsonl')) {
                continue;
            }
            $content = (string) $zip->getFromIndex($i);
            $checkedTables++;

            self::assertStringNotContainsString(
                self::FOREIGN_MARKER,
                $content,
                "V {$name} je marker CIZÍ firmy — export prosákl přes hranici tenanta!",
            );
            if (str_contains($content, self::OWN_MARKER)) {
                $ownFound = true;
            }

            $foreign = $this->foreignRows($content, $this->supplierId);
            self::assertSame(
                [],
                $foreign,
                "V {$name} jsou řádky s cizím supplier_id: " . implode(', ', array_slice($foreign, 0, 5)),
            );
        }
        $zip->close();

        self::assertGreaterThan(5, $checkedTables, 'Archiv obsahuje data víc tabulek (jinak netestujeme nic).');
        self::assertTrue($ownFound, 'Archiv obsahuje data VLASTNÍ firmy — jinak by prázdný archiv prošel jako izolovaný.');
    }

    /**
     * NEGATIVNÍ KONTROLA. Kdyby se filtr na `supplier_id` vypustil, tatáž kontrola
     * MUSÍ cizí řádky najít. Bez tohohle testu by „v archivu nejsou cizí data"
     * mohlo znamenat jen to, že v testovací DB žádná cizí data nejsou.
     */
    public function testFixtureWouldExposeForeignDataWithoutScope(): void
    {
        $pdo = $this->db->pdo();
        $rows = $pdo->query('SELECT * FROM clients')->fetchAll(PDO::FETCH_ASSOC);
        $unscopedDump = '';
        foreach ($rows as $row) {
            $unscopedDump .= json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
        }

        self::assertStringContainsString(
            self::FOREIGN_MARKER,
            $unscopedDump,
            'Fixture musí obsahovat data cizí firmy, jinak test izolace nic neověřuje.',
        );
        self::assertNotSame(
            [],
            $this->foreignRows($unscopedDump, $this->supplierId),
            'Kontrola cizích řádků musí nad NEoscopovaným dumpem selhat — jinak nic nekontroluje.',
        );
    }

    /**
     * Default deny: resolver nesmí vrátit filtr, který není vázaný na firmu.
     * Jeden `1 = 1` by stačil k vydání celé instalace.
     */
    public function testResolverRefusesUnscopedTables(): void
    {
        $scopes = $this->resolver->resolveAll($this->supplierId);
        self::assertNotSame([], $scopes, 'Resolver našel aspoň nějaké tabulky.');

        foreach ($scopes as $table => $scope) {
            self::assertNotSame('', trim($scope->where), "Tabulka {$table} má prázdný filtr.");
            self::assertDoesNotMatchRegularExpression(
                '/^\s*1\s*=\s*1\s*$/',
                $scope->where,
                "Tabulka {$table} má filtr, který nic neomezuje.",
            );
            self::assertNotSame([], $scope->params, "Tabulka {$table} nemá navázaný žádný parametr.");
            self::assertContains(
                $this->supplierId,
                $scope->params,
                "Filtr tabulky {$table} není navázaný na ID firmy.",
            );
            self::assertMatchesRegularExpression(
                '/supplier_id\s*=\s*\?|`?id`?\s*=\s*\?/',
                $scope->where,
                "Filtr tabulky {$table} nekončí u supplier_id: " . $scope->where,
            );
        }
    }

    /** Systémové a cross-tenant tabulky se do exportu firmy nesmí dostat vůbec. */
    public function testSystemAndCrossTenantTablesAreNotExported(): void
    {
        $scopes = $this->resolver->resolveAll($this->supplierId);
        foreach (['users', 'user_suppliers', 'migrations', 'api_tokens', 'password_resets', 'login_attempts'] as $table) {
            self::assertArrayNotHasKey($table, $scopes, "Tabulka {$table} nepatří do exportu firmy.");
        }
        // A musí být VIDĚT, že chybí záměrně — jinak se po roce nedopočítáme.
        self::assertArrayHasKey('users', $this->resolver->skipped());
    }

    /**
     * Default deny je bezpečný, ale mlčí — tabulka, kterou neumí zařadit, prostě
     * v archivu chybí a nikdo si toho nevšimne. Tenhle test je proti tomu pojistka:
     * hlavní agendy MUSÍ být v exportu.
     *
     * Vzniklo to z konkrétní chyby: `journal_entries` a `journal_entry_lines` jsou
     * v MariaDB SYSTEM VERSIONED tabulky, takže je filtr `TABLE_TYPE = "BASE TABLE"`
     * tiše vynechal — a z archivu vypadl ÚČETNÍ DENÍK, tedy to nejcennější, co v něm
     * má být. Nešlo to nijak poznat: export doběhl, manifest seděl sám se sebou.
     */
    public function testCoreAgendaTablesAreAllExported(): void
    {
        $scopes = $this->resolver->resolveAll($this->supplierId);
        $required = [
            'supplier', 'clients', 'projects',
            'invoices', 'invoice_items', 'invoice_payments',
            'purchase_invoices', 'purchase_invoice_items',
            'journal_entries', 'journal_entry_lines', 'chart_of_accounts', 'accounting_periods',
            'bank_statements', 'bank_transactions', 'bank_rule_templates',
            'cash_registers', 'cash_documents',
            'assets', 'stock_items', 'documents',
            'payment_orders', 'payment_order_items',
        ];
        $missing = [];
        foreach ($required as $table) {
            if ($this->tableExists($table) && !isset($scopes[$table])) {
                $missing[] = $table . ' (' . ($this->resolver->skipped()[$table] ?? 'nezařazena') . ')';
            }
        }
        self::assertSame(
            [],
            $missing,
            "Z archivu vypadly hlavní agendy: " . implode(', ', $missing),
        );
    }

    /** Credentials nesmí opustit instalaci ani zašifrované. */
    public function testCredentialColumnsAreRedacted(): void
    {
        $scopes = $this->resolver->resolveAll($this->supplierId);
        $supplierScope = $scopes['supplier'] ?? null;
        self::assertNotNull($supplierScope, 'Master řádek firmy se exportuje.');

        foreach ($supplierScope->columns as $column) {
            self::assertFalse(
                TenantScopeResolver::isSecretColumn($column),
                "Sloupec {$column} vypadá jako credential a je v exportu.",
            );
        }
        self::assertTrue(TenantScopeResolver::isSecretColumn('idoklad_client_secret_enc'));
        self::assertTrue(TenantScopeResolver::isSecretColumn('password_hash'));
        self::assertFalse(TenantScopeResolver::isSecretColumn('company_name'));
    }

    /** Cizí firma se k běhu ani jeho archivu nedostane. */
    public function testCrossTenantAccessToExportJobDenied(): void
    {
        $jobId = $this->jobs->create($this->supplierId, [InstanceExportService::PART_DATA], null, null, null);

        self::assertNotNull($this->jobs->find($jobId, $this->supplierId), 'Vlastník svůj běh vidí.');
        self::assertNull($this->jobs->find($jobId, $this->otherSupplierId), 'Cizí firma běh nevidí.');
        self::assertFalse($this->jobs->requestCancel($jobId, $this->otherSupplierId), 'Cizí firma běh nezruší.');
        self::assertFalse($this->jobs->delete($jobId, $this->otherSupplierId), 'Cizí firma běh nesmaže.');
        self::assertNotNull($this->jobs->find($jobId, $this->supplierId), 'Běh vlastníka po cizích pokusech zůstává.');
    }

    // ── pomocné ───────────────────────────────────────────────────────────────

    private function runDataExport(): string
    {
        $result = $this->export->runForSupplier(
            $this->supplierId,
            [InstanceExportService::PART_DATA],
        );
        $this->tempPaths[] = (string) $result['abs_path'];
        $this->tempPaths[] = (string) $result['abs_path'] . '.sha256';
        self::assertFileExists((string) $result['abs_path']);
        return (string) $result['abs_path'];
    }

    /**
     * Řádky JSONL, které nesou CIZÍ `supplier_id`. Vrací popisy pro hlášku.
     *
     * @return list<string>
     */
    private function foreignRows(string $jsonl, int $expectedSupplierId): array
    {
        $bad = [];
        foreach (explode("\n", $jsonl) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (!is_array($row) || !array_key_exists('supplier_id', $row) || $row['supplier_id'] === null) {
                continue;
            }
            if ((int) $row['supplier_id'] !== $expectedSupplierId) {
                $bad[] = 'supplier_id=' . (string) $row['supplier_id'];
            }
        }
        return $bad;
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Data firmy ve VÍC agendách, ne jen v `clients`.
     *
     * Jedna tabulka by test izolace udělala falešně klidným: prošel by i tehdy,
     * kdyby se filtr rozpadl u všech ostatních. Vybrané tabulky pokrývají různé
     * tvary scopu (přímý `supplier_id` napříč moduly) a marker nesou v názvu,
     * takže se prosáknutí pozná v kterémkoli z exportovaných JSONL.
     */
    private function createTenantData(int $supplierId, string $marker): void
    {
        $pdo = $this->db->pdo();

        $client = $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, currency_default_id, main_email)
             VALUES (?, ?, "Testovaci 2", "Brno", "60200", ?, ?, ?)'
        );
        $client->execute([
            $supplierId,
            $marker,
            $this->czId,
            $this->currencyId,
            strtolower(substr($marker, 0, 20)) . '@example.com',
        ]);

        // (tabulka, sloupec s názvem) — všechny berou jen supplier_id + název.
        $named = [
            'document_folders'         => 'name',
            'document_tags'            => 'name',
            'cash_registers'           => 'name',
            'journal_entry_templates'  => 'name',
            'branding_profiles'        => 'name',
        ];
        foreach ($named as $table => $column) {
            if (!$this->tableExists($table)) {
                continue;
            }
            $stmt = $pdo->prepare(
                'INSERT INTO ' . $table . ' (supplier_id, ' . $column . ') VALUES (?, ?)'
            );
            // document_tags má název kratší; marker se do 64 znaků vejde celý.
            $stmt->execute([$supplierId, $marker]);
        }
    }
}
