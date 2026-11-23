<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Settings;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\StatementDefinitionRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Dimension\DimensionRuleService;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\StatementOverrideService;
use MyInvoice\Service\Migration\Shared\CompanyProfileCarryOver;
use MyInvoice\Service\Settings\CompanyProfile\CompanyProfileException;
use MyInvoice\Service\Settings\CompanyProfile\CompanyProfileExporter;
use MyInvoice\Service\Settings\CompanyProfile\CompanyProfileImporter;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Profil firmy přežije nový převod: firma A má ručně vybudované nastavení (výjimky
 * mapování s platností po letech, sloupec minulého období z uzavřeného výkazu,
 * souhrnné vykázání daní vůči FÚ, dimenze, předkontace, pravidlo banky), firma B má
 * stejné účetnictví bez nastavení, tak vypadá firma po smazání a novém převodu.
 * Po nahrání profilu A do B musí výkazy obou firem vyjít stejně.
 */
#[Group('integration')]
final class CompanyProfileRoundTripTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PREV_YEAR = 2091;
    private const YEAR = 2092;
    private const CLIENT_IC = '10000005';
    private const BANK_ACCOUNT = '3000000004';

    private Connection $db;
    private CompanyProfileExporter $exporter;
    private CompanyProfileImporter $importer;
    private FinancialStatementService $statements;
    private StatementOverrideService $overrides;
    private DimensionService $dimensions;
    private DimensionRuleService $rules;
    private PostingService $posting;
    private AccountingPeriodRepository $periods;
    private ChartOfAccountsSeeder $seeder;
    private CompanyProfileCarryOver $carryOver;
    private int $versionId = 0;
    private int $sourceSupplierId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    /** @var array<int,array{prev:int,current:int}> firma => období */
    private array $periodIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje, test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db = $c->get(Connection::class);
            $this->exporter = $c->get(CompanyProfileExporter::class);
            $this->importer = $c->get(CompanyProfileImporter::class);
            $this->statements = $c->get(FinancialStatementService::class);
            $this->overrides = $c->get(StatementOverrideService::class);
            $this->dimensions = $c->get(DimensionService::class);
            $this->rules = $c->get(DimensionRuleService::class);
            $this->posting = $c->get(PostingService::class);
            $this->periods = $c->get(AccountingPeriodRepository::class);
            $this->seeder = $c->get(ChartOfAccountsSeeder::class);
            $this->carryOver = $c->get(CompanyProfileCarryOver::class);
            $version = $c->get(StatementDefinitionRepository::class)->findVersion('balance_sheet', self::YEAR . '-12-31');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->sourceSupplierId === 0 || $this->userId === 0 || $version === null) {
            $this->markTestSkipped('Chybí supplier / user / verze rozvahy.');
        }
        $this->versionId = (int) $version['id'];
        $pdo->beginTransaction();
        $this->inTx = true;
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testProfileRestoresStatementsOfReconvertedCompany(): void
    {
        $a = $this->company();
        $this->buildSettings($a);
        $b = $this->company();

        $expected = $this->statementsFingerprint($a);
        self::assertNotSame($expected, $this->statementsFingerprint($b), 'Fixture: bez nastavení vyjdou výkazy jinak.');

        $profile = $this->exporter->export($a);
        self::assertSame('myucto.company-profile', $profile['format']);

        $preview = $this->importer->import($b, $profile, true);
        self::assertTrue($preview['dry_run']);
        self::assertGreaterThan(0, $preview['changed']);
        self::assertSame(1, $preview['sections']['statement_overrides']['created']);
        self::assertNotSame($expected, $this->statementsFingerprint($b), 'Zkouška nanečisto nic nezapíše.');
        self::assertSame(0, $this->rowCount('statement_account_overrides', $b));

        $result = $this->importer->import($b, $profile, false, null, $this->userId);
        self::assertSame($preview['changed'], $result['changed'], 'Náhled ukazuje přesně to, co udělá ostré nahrání.');
        self::assertSame($expected, $this->statementsFingerprint($b), 'Po nahrání profilu jsou výkazy shodné.');

        $again = $this->importer->import($b, $profile, false, null, $this->userId);
        self::assertSame(0, $again['changed'], 'Opakované nahrání nic nezmění.');

        $exportB = $this->exporter->export($b);
        self::assertSame($profile['sections'], $exportB['sections'], 'Profil firmy B po nahrání odpovídá profilu firmy A.');
        self::assertContains(
            ['entity' => 'bank_account', 'bank_account' => ['account' => self::BANK_ACCOUNT, 'bank_code' => '0100'], 'type_code' => 'lokalita', 'value_code' => 'SEVER'],
            $exportB['sections']['dimension_defaults'],
            'Výchozí dimenze bankovního účtu se přenese podle čísla účtu.',
        );
        $interest = array_values(array_filter($exportB['sections']['bank_posting_rules'], static fn (array $r): bool => $r['name'] === 'Úroky spořicího účtu'));
        self::assertSame(['account' => self::BANK_ACCOUNT, 'bank_code' => '0100'], $interest[0]['bank_account'] ?? null, 'Vlastní účet pravidla se přenese číslem.');
        self::assertSame([['type_code' => 'lokalita', 'value_code' => 'SEVER']], $interest[0]['dimensions'], 'Dimenze pravidla se přenesou kódy.');
        self::assertSame(1, $result['sections']['dimension_rules']['created'] ?? null, 'Pravidlo dimenze se přenese.');

        // Změna dimenzí pravidla v profilu upraví existující pravidlo, nevznikne druhé.
        $withoutDims = $profile;
        foreach ($withoutDims['sections']['bank_posting_rules'] as $i => $r) {
            if ($r['name'] === 'Úroky spořicího účtu') $withoutDims['sections']['bank_posting_rules'][$i]['dimensions'] = [];
        }
        $updated = $this->importer->import($b, $withoutDims, false, null, $this->userId);
        self::assertSame(1, $updated['sections']['bank_posting_rules']['updated']);
        $left = $this->db->pdo()->prepare('SELECT COUNT(*) FROM bank_posting_rule_dimensions WHERE supplier_id = ?');
        $left->execute([$b]);
        self::assertSame(0, (int) $left->fetchColumn(), 'Prázdné dimenze v profilu dimenze pravidla smažou.');
        self::assertSame($this->rulesFingerprint($a), $this->rulesFingerprint($b), 'Pravidla dimenzí firmy B odpovídají firmě A.');

        $rule = $this->db->pdo()->prepare("SELECT mode, mode_set_manually_at FROM bank_posting_rules WHERE supplier_id = ? AND name = 'Poplatek za vedení účtu'");
        $rule->execute([$b]);
        $row = $rule->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('auto', $row['mode'], 'Automatika pravidla se obnoví auditovaným povýšením.');
        self::assertNotNull($row['mode_set_manually_at']);
    }

    public function testPersonTitleSurvivesTaxProfileRoundTrip(): void
    {
        $source = $this->company();
        $target = $this->company();
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE supplier SET person_title = ? WHERE id = ?')->execute(['Ing.', $source]);
        $pdo->prepare('UPDATE supplier SET person_title = NULL WHERE id = ?')->execute([$target]);
        $profile = $this->exporter->export($source, ['tax_profile']);
        self::assertSame('Ing.', $profile['sections']['tax_profile']['person_title']);
        $this->importer->import($target, $profile, false);
        $exported = $this->exporter->export($target, ['tax_profile']);
        self::assertSame('Ing.', $exported['sections']['tax_profile']['person_title']);

        $profile['sections']['tax_profile']['person_title'] = str_repeat('Ž', 11);
        try {
            $this->importer->import($target, $profile, false);
            self::fail('Import profilu musí odmítnout titul přes limit EPO.');
        } catch (CompanyProfileException $e) {
            self::assertSame('validation_failed', $e->errorCode);
        }
        self::assertSame('Ing.', $this->exporter->export($target, ['tax_profile'])['sections']['tax_profile']['person_title']);
    }

    /** Dávkový převod odloží profil před převodem do existující firmy a po převodu ho obnoví. */
    public function testBatchCarryOverRestoresSettingsDroppedByReconversion(): void
    {
        $a = $this->company();
        $this->buildSettings($a);
        $expected = $this->statementsFingerprint($a);

        $profile = $this->carryOver->capture($a);
        self::assertNotNull($profile, 'Firma s nastavením má co odložit.');

        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM statement_account_overrides WHERE supplier_id = ?')->execute([$a]);
        $pdo->prepare(
            'UPDATE accounting_supplier_settings
                SET comparative_from_prior_year = 0, tax_authority_offset = 0, tax_authority_offset_from_year = NULL
              WHERE supplier_id = ?'
        )->execute([$a]);
        self::assertNotSame($expected, $this->statementsFingerprint($a), 'Fixture: bez nastavení vyjdou výkazy jinak.');

        $messages = $this->carryOver->restore($a, $profile);
        self::assertSame($expected, $this->statementsFingerprint($a), 'Po obnově profilu jsou výkazy shodné.');
        self::assertNotEmpty($messages, 'Obnova se hlásí do protokolu dávky.');
    }

    public function testProfileOfForeignFormatOrNewerVersionIsRejected(): void
    {
        $b = $this->company();
        foreach ([
            [['format' => 'jiny-program', 'version' => 1, 'sections' => []], 'invalid_profile'],
            [['format' => 'myucto.company-profile', 'version' => 99, 'sections' => []], 'unsupported_version'],
        ] as [$profile, $code]) {
            try {
                $this->importer->import($b, $profile, false);
                self::fail('Neplatný profil musí být odmítnut.');
            } catch (CompanyProfileException $e) {
                self::assertSame($code, $e->errorCode);
            }
        }

        $result = $this->importer->import($b, ['format' => 'myucto.company-profile', 'version' => 1, 'sections' => ['budouci_sekce' => []]], true);
        self::assertSame(0, $result['changed']);
        self::assertStringContainsString('budouci_sekce', implode("\n", $result['warnings']), 'Neznámou sekci starší aplikace přeskočí s upozorněním.');
    }

    public function testBankAccountDefaultMatchesNormalizedNumberAndNeedsDoubleEntry(): void
    {
        $b = $this->company();
        $this->dimensions->setEnabled($b, true);
        $type = $this->dimensions->createType($b, ['code' => 'lokalita', 'name' => 'Lokalita', 'kind' => 'location']);
        $this->dimensions->createValue($b, (int) $type['id'], ['code' => 'SEVER', 'name' => 'Sever']);
        $profile = [
            'format' => 'myucto.company-profile',
            'version' => 1,
            'sections' => ['dimension_defaults' => [[
                'entity' => 'bank_account',
                'bank_account' => ['account' => '000000-' . self::BANK_ACCOUNT, 'bank_code' => '100'],
                'type_code' => 'lokalita',
                'value_code' => 'SEVER',
            ]]],
        ];
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")->execute([$b]);
        $skipped = $this->importer->import($b, $profile, false, null, $this->userId);
        self::assertSame(0, $skipped['changed']);
        self::assertStringContainsString('podvojné účetnictví', implode("\n", $skipped['sections']['dimension_defaults']['warnings']),
            'Daňová evidence výchozí dimenze účtu nenahraje.');

        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$b]);
        $imported = $this->importer->import($b, $profile, false, null, $this->userId);
        self::assertSame(1, $imported['changed'], 'Číslo s předčíslím z nul a kód banky bez nuly se spárují s registrem.');

        // Účet registru bez kódu banky se spáruje stejně jako při zaúčtování pohybu.
        $pdo->prepare("UPDATE supplier_bank_accounts SET bank_code = NULL, bank_code_norm = '' WHERE supplier_id = ?")->execute([$b]);
        $pdo->prepare('DELETE FROM dimension_defaults WHERE supplier_id = ?')->execute([$b]);
        $withoutBank = $this->importer->import($b, $profile, false, null, $this->userId);
        self::assertSame(1, $withoutBank['changed'], 'Účet bez kódu banky se najde i podle profilu s kódem.');

        $profile['sections']['dimension_defaults'][0]['bank_account'] = ['account' => '1000000005', 'bank_code' => '0100'];
        $missing = $this->importer->import($b, $profile, false, null, $this->userId);
        self::assertSame(0, $missing['changed']);
        self::assertStringContainsString('Bankovní účet 1000000005/0100 ve firmě není', implode("\n", $missing['sections']['dimension_defaults']['warnings']));
    }

    public function testTemplateBankRuleTakesOwnAccountFromProfile(): void
    {
        $b = $this->company();
        $this->db->pdo()->prepare(
            "INSERT INTO bank_posting_rules (supplier_id, name, direction, message_contains, debit_account_code, credit_account_code, priority, system_template_key)
             VALUES (?, 'Úroky', 'incoming', 'urok', '221', '662', 40, 'test.urok')"
        )->execute([$b]);
        $profile = [
            'format' => 'myucto.company-profile',
            'version' => 1,
            'sections' => ['bank_posting_rules' => [[
                'name' => 'Úroky', 'direction' => 'incoming', 'message_contains' => 'urok',
                'debit_account_code' => '221', 'credit_account_code' => '662', 'priority' => 40,
                'system_template_key' => 'test.urok',
                'bank_account' => ['account' => self::BANK_ACCOUNT, 'bank_code' => '0100'],
            ]]],
        ];
        $result = $this->importer->import($b, $profile, false, null, $this->userId);

        self::assertSame(1, $result['sections']['bank_posting_rules']['updated'], json_encode($result['sections']['bank_posting_rules']));
        $stored = $this->db->pdo()->prepare(
            'SELECT a.account_number FROM bank_posting_rules r JOIN supplier_bank_accounts a ON a.id = r.bank_account_id
              WHERE r.supplier_id = ? AND r.system_template_key = ?'
        );
        $stored->execute([$b, 'test.urok']);
        self::assertSame(self::BANK_ACCOUNT, $stored->fetchColumn(), 'Instance šablony převezme omezení na vlastní účet z profilu.');
        self::assertSame(1, $this->rowCount('bank_posting_rules', $b));

        $older = $profile;
        unset($older['sections']['bank_posting_rules'][0]['bank_account']);
        $this->importer->import($b, $older, false, null, $this->userId);
        $stored->execute([$b, 'test.urok']);
        self::assertSame(self::BANK_ACCOUNT, $stored->fetchColumn(), 'Starší profil bez vlastního účtu omezení šablony nezruší.');

        $general = $profile;
        $general['sections']['bank_posting_rules'][0]['bank_account'] = null;
        $this->importer->import($b, $general, false, null, $this->userId);
        $stored->execute([$b, 'test.urok']);
        self::assertFalse($stored->fetchColumn(), 'Profil s obecnou šablonou omezení zruší.');
    }

    public function testBankRuleOfMissingOwnAccountIsSkippedNotWidened(): void
    {
        $b = $this->company();
        $profile = [
            'format' => 'myucto.company-profile',
            'version' => 1,
            'sections' => ['bank_posting_rules' => [[
                'name' => 'Úroky', 'direction' => 'incoming', 'message_contains' => 'urok',
                'debit_account_code' => '221', 'credit_account_code' => '662', 'priority' => 40,
                'bank_account' => ['account' => '1000000005', 'bank_code' => '0100'],
                'bank_account_id' => 1,
                'dimensions' => [['type_code' => 'lokalita', 'value_code' => 'SEVER']],
            ]]],
        ];
        $result = $this->importer->import($b, $profile, false, null, $this->userId);

        self::assertSame(0, $result['changed']);
        self::assertSame(0, $this->rowCount('bank_posting_rules', $b), 'Bez účtu by pravidlo platilo pro všechny účty, proto se nenahraje.');
        self::assertStringContainsString('bankovní účet 1000000005/0100 ve firmě není', implode("
", $result['sections']['bank_posting_rules']['warnings']));

        unset($profile['sections']['bank_posting_rules'][0]['bank_account']);
        $general = $this->importer->import($b, $profile, false, null, $this->userId);
        self::assertSame(1, $general['changed']);
        $stored = $this->db->pdo()->prepare('SELECT bank_account_id FROM bank_posting_rules WHERE supplier_id = ?');
        $stored->execute([$b]);
        self::assertNull($stored->fetchColumn(), 'Id účtu z cizí instance se nepoužije.');
        self::assertStringContainsString('nemá zapnuté dimenze', implode("
", $general['sections']['bank_posting_rules']['warnings']));

        // Firma bez zapnutých dimenzí: import varuje, dosavadní dimenze pravidla nesmaže.
        $this->dimensions->setEnabled($b, true);
        $type = $this->dimensions->createType($b, ['code' => 'lokalita', 'name' => 'Lokalita', 'kind' => 'location']);
        $north = $this->dimensions->createValue($b, (int) $type['id'], ['code' => 'SEVER', 'name' => 'Sever']);
        $ruleId = (int) $this->db->pdo()->query('SELECT id FROM bank_posting_rules WHERE supplier_id = ' . $b)->fetchColumn();
        $this->db->pdo()->prepare('INSERT INTO bank_posting_rule_dimensions (rule_id, supplier_id, dimension_type_id, dimension_value_id) VALUES (?, ?, ?, ?)')
            ->execute([$ruleId, $b, (int) $type['id'], (int) $north['id']]);
        $this->dimensions->setEnabled($b, false);
        $this->importer->import($b, $profile, false, null, $this->userId);
        $kept = $this->db->pdo()->prepare('SELECT COUNT(*) FROM bank_posting_rule_dimensions WHERE rule_id = ?');
        $kept->execute([$ruleId]);
        self::assertSame(1, (int) $kept->fetchColumn(), 'Vypnuté dimenze import nesmí brát jako prázdné dimenze pravidla.');
        $emptyDims = $profile;
        $emptyDims['sections']['bank_posting_rules'][0]['dimensions'] = [];
        $this->importer->import($b, $emptyDims, false, null, $this->userId);
        $kept->execute([$ruleId]);
        self::assertSame(1, (int) $kept->fetchColumn(), 'Ani prázdné dimenze z profilu dimenze firmy s vypnutými dimenzemi nesmažou.');

        // Stejná kritéria omezená na vlastní účet jsou jiné pravidlo, ne úprava obecného.
        $profile['sections']['bank_posting_rules'][0]['bank_account'] = ['account' => self::BANK_ACCOUNT, 'bank_code' => '0100'];
        $restricted = $this->importer->import($b, $profile, false, null, $this->userId);
        self::assertSame(1, $restricted['sections']['bank_posting_rules']['created']);
        self::assertSame(2, $this->rowCount('bank_posting_rules', $b));
    }

    public function testInvalidItemRollsBackWholeImport(): void
    {
        $b = $this->company();
        $profile = [
            'format' => 'myucto.company-profile',
            'version' => 1,
            'sections' => [
                'company' => ['dimensions_enabled' => true],
                'statement_overrides' => [[
                    'statement_type' => 'balance_sheet',
                    'version_code' => $this->versionCode(),
                    'items' => [['account_prefix' => '351', 'row_code' => 'NEEXISTUJE']],
                ]],
            ],
        ];
        try {
            $this->importer->import($b, $profile, false);
            self::fail('Neplatná výjimka musí nahrání zastavit.');
        } catch (CompanyProfileException $e) {
            self::assertSame('statement_overrides', $e->section);
        }
        $stmt = $this->db->pdo()->prepare('SELECT dimensions_enabled FROM supplier WHERE id = ?');
        $stmt->execute([$b]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'Chyba v pozdější sekci vrátí i dřívější sekce.');
    }

    // ── fixture ─────────────────────────────────────────────────────────────────

    /** Firma se stejným účetnictvím, jako vznikne převodem (osnova, období, zápisy, klient). */
    private function company(): int
    {
        $pdo = $this->db->pdo();
        $id = $this->createIsolatedSupplier($pdo, $this->sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET dimensions_enabled = 0, stock_enabled = 0 WHERE id = ?')->execute([$id]);
        $this->seeder->seedForSupplier($id);
        $this->periodIds[$id] = [
            'prev' => $this->periods->create($id, self::PREV_YEAR, self::PREV_YEAR . '-01-01', self::PREV_YEAR . '-12-31'),
            'current' => $this->periods->create($id, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31'),
        ];
        $pdo->prepare(
            "INSERT INTO accounting_supplier_settings (supplier_id, statement_scope_override) VALUES (?, 'full')
             ON DUPLICATE KEY UPDATE statement_scope_override = 'full'"
        )->execute([$id]);
        $this->analytic($id, '351', '351.100', 'Dlouhodobá pohledávka za ovládanou osobou', 'asset', 'debit');

        $this->post($id, self::PREV_YEAR, '351.100', '602', 200_000.00);
        $this->post($id, self::YEAR, '311', '602', 100_000.00);
        $this->post($id, self::YEAR, '343', '602', 30_000.00);
        $this->post($id, self::YEAR, '591', '341', 20_000.00);

        $currency = (int) $pdo->query('SELECT id FROM currencies ORDER BY id LIMIT 1')->fetchColumn();
        $country = (int) $pdo->query('SELECT id FROM countries ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, currency_default_id, ic)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, 'Odběratel Test s.r.o.', 'Testovací 1', 'Praha', '11000', $country, $currency, self::CLIENT_IC]);
        $pdo->prepare(
            "INSERT INTO supplier_bank_accounts
                (supplier_id, label, account_number, bank_code, bank_code_norm, currency, account_canonical, kind, source, is_active)
             VALUES (?, 'Spořicí účet', ?, '0100', '0100', 'CZK', ?, 'savings', 'manual', 1)"
        )->execute([$id, self::BANK_ACCOUNT, self::BANK_ACCOUNT]);

        return $id;
    }

    /** Ručně vybudované nastavení firmy A. */
    private function buildSettings(int $id): void
    {
        $pdo = $this->db->pdo();
        $this->overrides->save($id, $this->versionId, [
            ['account_prefix' => '351.100', 'row_code' => 'C.II.1.5.4.', 'valid_from_year' => self::YEAR, 'note' => 'Splatnost prodloužena'],
        ], $this->userId);
        $pdo->prepare(
            'UPDATE accounting_supplier_settings
                SET comparative_from_prior_year = 1, tax_authority_offset = 1, tax_authority_offset_from_year = ?
              WHERE supplier_id = ?'
        )->execute([self::YEAR, $id]);
        $pdo->prepare('UPDATE supplier SET stock_enabled = 1, tax_investment_incentive = 1 WHERE id = ?')->execute([$id]);

        $this->dimensions->setEnabled($id, true);
        $type = $this->dimensions->createType($id, ['code' => 'lokalita', 'name' => 'Lokalita', 'kind' => 'location']);
        $north = $this->dimensions->createValue($id, (int) $type['id'], ['code' => 'SEVER', 'name' => 'Sever']);
        $this->dimensions->createValue($id, (int) $type['id'], ['code' => 'SEVER-1', 'name' => 'Sever první', 'parent_id' => $north['id'], 'note' => 'Pobočka']);
        $closed = $this->dimensions->createValue($id, (int) $type['id'], ['code' => 'JIH', 'name' => 'Jih']);
        $this->dimensions->updateValue($id, (int) $closed['id'], ['is_active' => false]);
        $client = $pdo->prepare('SELECT id FROM clients WHERE supplier_id = ? AND ic = ?');
        $client->execute([$id, self::CLIENT_IC]);
        $this->dimensions->saveEntityDefaults($id, 'client', (int) $client->fetchColumn(), [(int) $type['id'] => (int) $north['id']]);
        $account = $pdo->prepare('SELECT id FROM supplier_bank_accounts WHERE supplier_id = ? AND account_canonical = ?');
        $account->execute([$id, self::BANK_ACCOUNT]);
        $accountId = (int) $account->fetchColumn();
        $this->dimensions->saveEntityDefaults($id, 'bank_account', $accountId, [(int) $type['id'] => (int) $north['id']]);
        $this->rules->create($id, [
            'dimension_type_id' => (int) $type['id'],
            'account_mask' => '5, !59',
            'enforcement' => 'warning',
            'default_value_id' => (int) $north['id'],
            'valid_from' => self::YEAR . '-01-01',
            'note' => 'Náklady vždy s lokalitou',
        ], $this->userId);

        $pdo->prepare(
            "INSERT INTO posting_rules (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
             VALUES (?, 'TEST-ÚČET 1', 'Testovací předkontace se zkratkou z jiného programu', '501', '321', 100, 0)"
        )->execute([$id]);
        $pdo->prepare(
            "INSERT INTO bank_posting_rules (supplier_id, name, direction, message_contains, debit_account_code, credit_account_code,
                                             mode, mode_set_manually_at, priority)
             VALUES (?, 'Poplatek za vedení účtu', 'outgoing', 'poplatek', '568', '221', 'auto', NOW(), 40)"
        )->execute([$id]);
        // Pravidlo omezené na vlastní účet s dimenzí: přenese se číslem účtu a kódy dimenze.
        $pdo->prepare(
            "INSERT INTO bank_posting_rules (supplier_id, name, direction, bank_account_id, message_contains, debit_account_code, credit_account_code, priority)
             VALUES (?, 'Úroky spořicího účtu', 'incoming', ?, 'urok', '221', '662', 40)"
        )->execute([$id, $accountId]);
        $pdo->prepare(
            'INSERT INTO bank_posting_rule_dimensions (rule_id, supplier_id, dimension_type_id, dimension_value_id) VALUES (?, ?, ?, ?)'
        )->execute([(int) $pdo->lastInsertId(), $id, (int) $type['id'], (int) $north['id']]);
    }

    /** Hodnoty řádků rozvahy (oba sloupce) a výsledovky běžného roku. */
    private function statementsFingerprint(int $supplierId): string
    {
        $period = $this->periodIds[$supplierId]['current'];
        $asOf = self::YEAR . '-12-31';
        $sheet = $this->statements->balanceSheet($supplierId, $period, $asOf, 'full');
        $income = $this->statements->incomeStatement($supplierId, $period, $asOf, 'full');
        $keep = array_flip(['row_code', 'gross', 'correction', 'net', 'prev_net', 'amount', 'prev_amount']);
        $rows = static fn (array $list): array => array_map(
            static fn (array $r): array => array_map(
                static fn (mixed $v): mixed => is_float($v) || is_int($v) ? round((float) $v, 2) : $v,
                array_intersect_key($r, $keep),
            ),
            $list,
        );

        return (string) json_encode([
            'assets' => $rows($sheet['assets']),
            'liabilities' => $rows($sheet['liabilities']),
            'income' => $rows($income['rows']),
        ]);
    }

    /** @return list<array<string,mixed>> pravidla dimenzí bez id a časových razítek */
    private function rulesFingerprint(int $supplierId): array
    {
        return array_map(static fn (array $r): array => [
            'type_code' => $r['type_code'],
            'account_mask' => $r['account_mask'],
            'enforcement' => $r['enforcement'],
            'default_value_code' => $r['default_value_code'],
            'default_from_card' => $r['default_from_card'],
            'valid_from' => $r['valid_from'],
            'valid_to' => $r['valid_to'],
            'is_active' => $r['is_active'],
            'note' => $r['note'],
        ], $this->rules->list($supplierId));
    }

    private function versionCode(): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT version_code FROM statement_versions WHERE id = ?');
        $stmt->execute([$this->versionId]);

        return (string) $stmt->fetchColumn();
    }

    private function rowCount(string $table, int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ?");
        $stmt->execute([$supplierId]);

        return (int) $stmt->fetchColumn();
    }

    private function analytic(int $supplierId, string $parentCode, string $code, string $name, string $type, string $side): void
    {
        $pdo = $this->db->pdo();
        $parent = $pdo->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?');
        $parent->execute([$supplierId, $parentCode]);
        $pdo->prepare(
            'INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active)
             VALUES (?, ?, ?, ?, ?, 0, ?, 1)'
        )->execute([$supplierId, $code, $name, $type, $side, (int) $parent->fetchColumn()]);
    }

    private function post(int $supplierId, int $year, string $debit, string $credit, float $amount): void
    {
        $this->posting->postDocument($supplierId, 'manual', null, [
            ['account_code' => $debit, 'side' => 'debit', 'amount' => $amount],
            ['account_code' => $credit, 'side' => 'credit', 'amount' => $amount],
        ], [
            'entry_date' => $year . '-06-30',
            'description' => 'Test profilu firmy ' . $debit . '/' . $credit,
            'posted' => true,
            'user_id' => $this->userId,
        ]);
    }
}
