<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Settings;

use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Accounting\AccountingFrameworkService;
use MyInvoice\Service\Accounting\ChartOfAccountsTemplate;
use MyInvoice\Service\Accounting\ChartOfAccountsTemplate504;
use MyInvoice\Service\Accounting\PristineChart500;
use MyInvoice\Service\Ares\AresClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Firma založená v aplikaci (POST /api/suppliers) musí vzniknout stejně jako
 * firma z prvotního setupu — obě cesty jdou přes
 * {@see \MyInvoice\Service\Supplier\SupplierInitializer}.
 *
 * Dřív s.r.o. založené v aplikaci naběhlo v daňové evidenci, bez historie režimu,
 * bez směrné osnovy, bez účetního období, bez automatického účtování a plátce bez
 * zdaňovacího období.
 *
 * ⚠️ Registry se po síti NEVOLAJÍ: bez IČ/DIČ se enricher nespustí a scénář
 * s ARESem má předplněnou cache klienta syntetickým IČ.
 */
#[Group('integration')]
final class CreateSupplierInitializationTest extends TestCase
{
    /** Syntetické IČ — odpověď ARESu se předplní do `ares_cache`. */
    private const IC = '00000035';

    private ContainerInterface $container;
    private Connection $db;
    private SettingsAction $settings;
    private int $userId = 0;
    private int $currentSupplierId = 0;

    /** @var list<int> */
    private array $created = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $this->container = Bootstrap::buildContainer();
            $this->db = $this->container->get(Connection::class);
            $this->settings = $this->container->get(SettingsAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->currentSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->currentSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier/uživatel v DB.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        try {
            foreach ($this->created as $id) {
                $response = $this->settings->deleteSupplierById(
                    $this->request('DELETE', '/api/suppliers/' . $id),
                    new Psr7Response(),
                    ['id' => (string) $id],
                );
                if ($response->getStatusCode() !== 200) {
                    // Náhradní úklid ve stejném pořadí jako deleteSupplier: vypnutá
                    // kontrola FK jen kvůli měnám, samotná firma až se ZAPNUTOU.
                    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
                    $pdo->prepare('DELETE FROM currencies WHERE supplier_id = ?')->execute([$id]);
                    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
                    $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$id]);
                }
            }
        } finally {
            $this->created = [];
            $pdo->prepare('DELETE FROM ares_cache WHERE ic = ?')->execute([self::IC]);
        }
    }

    public function testLegalEntityStartsInDoubleEntryWithFullAccountingSetup(): void
    {
        $id = $this->create([
            'company_name'  => '__TEST Založení PO s.r.o.',
            'taxpayer_type' => 'po',
            'is_vat_payer'  => true,
        ]);

        $row = $this->supplierRow($id);
        self::assertSame('po', (string) $row['taxpayer_type']);
        self::assertSame('double_entry', (string) $row['accounting_mode'], 'právnická osoba vede podvojné účetnictví');
        self::assertSame('monthly', (string) $row['vat_period'], 'nový plátce je ze zákona měsíční (§ 99 ZDPH)');
        self::assertSame(1, (int) $row['is_vat_payer']);

        self::assertSame(
            'double_entry',
            $this->scalar('SELECT accounting_mode FROM supplier_accounting_modes WHERE supplier_id = ? AND effective_from = ?', [$id, date('Y-01-01')]),
            'historie účetního režimu musí vzniknout spolu s firmou',
        );
        self::assertSame(
            'cz_500',
            $this->scalar(
                'SELECT accounting_framework FROM supplier_accounting_frameworks WHERE supplier_id = ? AND effective_from = ?',
                [$id, '1900-01-01'],
            ),
            'nová firma musí dostat historický baseline účetního rámce',
        );
        self::assertGreaterThan(0, (int) $this->scalar('SELECT COUNT(*) FROM chart_of_accounts WHERE supplier_id = ?', [$id]), 'podvojné účetnictví bez směrné osnovy je rozbitý stav');
        self::assertSame(1, (int) $this->scalar(
            'SELECT COUNT(*) FROM accounting_periods WHERE supplier_id = ? AND fiscal_year = ?',
            [$id, (int) date('Y')],
        ), 'firma musí mít účetní období pro rok založení');
        self::assertSame(1, (int) $this->scalar(
            'SELECT COUNT(*) FROM supplier_vat_status_history WHERE supplier_id = ? AND is_vat_payer = 1',
            [$id],
        ));

        // Výchozí automatika účetní jednotky — táž jako po setupu / aktivaci.
        self::assertSame(1, (int) $row['auto_post_invoices'], 'automatické účtování vydaných faktur');
        self::assertSame(1, (int) $row['auto_post_purchases'], 'automatické účtování přijatých faktur');
        self::assertSame('full', $this->scalar('SELECT automation_level FROM accounting_supplier_settings WHERE supplier_id = ?', [$id]));
        self::assertGreaterThan(0, (int) $this->scalar("SELECT COUNT(*) FROM auto_posting_policy WHERE supplier_id = ? AND level = 'auto'", [$id]));
    }

    public function testNaturalPersonStaysInTaxEvidence(): void
    {
        $id = $this->create([
            'company_name'  => '__TEST Založení FO',
            'taxpayer_type' => 'fo',
            'is_vat_payer'  => false,
        ]);

        $row = $this->supplierRow($id);
        self::assertSame('tax_evidence', (string) $row['accounting_mode']);
        self::assertNull($row['vat_period'], 'neplátce zdaňovací období nemá');
        self::assertSame('tax_evidence', $this->scalar('SELECT accounting_mode FROM supplier_accounting_modes WHERE supplier_id = ? AND effective_from = ?', [$id, date('Y-01-01')]));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM chart_of_accounts WHERE supplier_id = ?', [$id]));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM accounting_periods WHERE supplier_id = ?', [$id]));
        self::assertSame(0, (int) $row['auto_post_invoices'], 'daňová evidence deník nevede');
    }

    public function testNaturalPersonTitleIsStoredAndLoadedForEpo(): void
    {
        $id = $this->create([
            'company_name' => '__TEST Titul OSVČ', 'taxpayer_type' => 'fo',
            'person_title' => ' Ing. ', 'is_vat_payer' => false,
        ]);
        self::assertSame('Ing.', $this->supplierRow($id)['person_title']);
        $supplier = \MyInvoice\Service\Report\EpoSupplierBlockBuilder::loadSupplier($this->db->pdo(), $id);
        self::assertSame('Ing.', $supplier['person_title']);
    }

    public function testCreatingSupplierRejectsOverlongTitle(): void
    {
        $before = (int) $this->db->pdo()->query('SELECT COUNT(*) FROM supplier')->fetchColumn();
        $response = $this->settings->createSupplier(
            $this->request('POST', '/api/suppliers')->withParsedBody([
                'company_name' => '__TEST Dlouhý titul', 'street' => 'Testovací 1',
                'city' => 'Zkušební Lhota', 'zip' => '11111', 'email' => 'titul@example.test',
                'person_title' => str_repeat('Ž', 11),
            ]), new Psr7Response(),
        );
        $json = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        if ($response->getStatusCode() === 201) {
            $this->created[] = (int) ($json['data']['id'] ?? $json['id']);
        }
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('validation_failed', $json['error']['code']);
        self::assertSame($before, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM supplier')->fetchColumn());
    }

    public function testQuarterlyVatPeriodChoiceIsKept(): void
    {
        $id = $this->create([
            'company_name'  => '__TEST Založení čtvrtletní',
            'taxpayer_type' => 'fo',
            'is_vat_payer'  => true,
            'vat_period'    => 'quarterly',
        ]);

        self::assertSame('quarterly', (string) $this->supplierRow($id)['vat_period']);
    }

    public function testLegalFormFromAresMovesCompanyToDoubleEntry(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO ares_cache (ic, payload) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = NOW()'
        )->execute([self::IC, json_encode([
            'normalization_version' => AresClient::NORMALIZATION_VERSION,
            'found' => true, 'data' => ['taxpayer_type' => 'po'],
        ], JSON_UNESCAPED_UNICODE)]);

        // Typ poplatníka NEPŘIŠEL — rozhodne právní forma z ARESu.
        $id = $this->create([
            'company_name' => '__TEST Založení ARES s.r.o.',
            'ic'           => self::IC,
            'is_vat_payer' => false,
        ]);

        $row = $this->supplierRow($id);
        self::assertSame('po', (string) $row['taxpayer_type']);
        self::assertSame('double_entry', (string) $row['accounting_mode']);
        self::assertSame('double_entry', $this->scalar('SELECT accounting_mode FROM supplier_accounting_modes WHERE supplier_id = ? AND effective_from = ?', [$id, date('Y-01-01')]), 'historie nesmí protiřečit režimu firmy');
        self::assertGreaterThan(0, (int) $this->scalar('SELECT COUNT(*) FROM chart_of_accounts WHERE supplier_id = ?', [$id]));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM accounting_periods WHERE supplier_id = ? AND fiscal_year = ?', [$id, (int) date('Y')]));
        self::assertSame(1, (int) $row['auto_post_invoices']);
    }

    public function testNonprofitLegalFormFromAresStartsOnFramework504WithChart504(): void
    {
        $this->primeAres(['taxpayer_type' => 'po', 'legal_form' => '706']);

        $id = $this->create([
            'company_name' => '__TEST Založení spolek',
            'ic'           => self::IC,
            'is_vat_payer' => false,
        ]);

        self::assertSame('double_entry', (string) $this->supplierRow($id)['accounting_mode']);
        self::assertSame(
            [['1900-01-01', 'cz_504']],
            $this->frameworkHistory($id),
            'nevýdělečná organizace začíná na rámci vyhlášky 504',
        );
        self::assertSame($this->templateCodes(ChartOfAccountsTemplate504::ACCOUNTS), $this->chartCodes($id));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM posting_rules WHERE supplier_id = ?', [$id]), 'předkontace k analytikám 500 nesmí zůstat');
    }

    public function testCompanyLegalFormFromAresKeepsFramework500AndItsSeededChart(): void
    {
        $this->primeAres(['taxpayer_type' => 'po', 'legal_form' => '112']);

        $id = $this->create([
            'company_name' => '__TEST Založení s.r.o. s právní formou',
            'ic'           => self::IC,
            'is_vat_payer' => false,
        ]);

        self::assertSame([['1900-01-01', 'cz_500']], $this->frameworkHistory($id));
        self::assertSame($this->templateCodes(ChartOfAccountsTemplate::ACCOUNTS), $this->chartCodes($id));
        self::assertGreaterThan(0, (int) $this->scalar('SELECT COUNT(*) FROM posting_rules WHERE supplier_id = ?', [$id]));
    }

    public function testNewCompanyWithUntouchedChartCanSwitchTo504InSettings(): void
    {
        $id = $this->create([
            'company_name'  => '__TEST Založení přepnutí 504',
            'taxpayer_type' => 'po',
            'is_vat_payer'  => false,
        ]);
        $startsOn = (string) $this->scalar('SELECT MIN(starts_on) FROM accounting_periods WHERE supplier_id = ?', [$id]);
        self::assertNotSame('', $startsOn);

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            self::assertTrue((new PristineChart500($this->db))->isPristineForUpdate($id), 'čerstvě založená firma má nedotčenou šablonu 500');
        } finally {
            $pdo->rollBack();
        }

        self::assertTrue($this->container->get(AccountingFrameworkService::class)
            ->changeFromSettings($id, $startsOn, AccountingFrameworkService::CZ_504, $this->userId));
        self::assertSame($this->templateCodes(ChartOfAccountsTemplate504::ACCOUNTS), $this->chartCodes($id));
    }

    public function testExplicitTaxpayerTypeWinsOverAres(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO ares_cache (ic, payload) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = NOW()'
        )->execute([self::IC, json_encode([
            'normalization_version' => AresClient::NORMALIZATION_VERSION,
            'found' => true, 'data' => ['taxpayer_type' => 'po'],
        ], JSON_UNESCAPED_UNICODE)]);

        $id = $this->create([
            'company_name'  => '__TEST Založení explicitní FO',
            'ic'            => self::IC,
            'taxpayer_type' => 'fo',
            'is_vat_payer'  => false,
        ]);

        self::assertSame('tax_evidence', (string) $this->supplierRow($id)['accounting_mode']);
    }

    /** @param array<string,mixed> $data */
    private function primeAres(array $data): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO ares_cache (ic, payload) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = NOW()'
        )->execute([self::IC, json_encode([
            'normalization_version' => AresClient::NORMALIZATION_VERSION,
            'found' => true, 'data' => $data,
        ], JSON_UNESCAPED_UNICODE)]);
    }

    /** @return list<array{0:string,1:string}> */
    private function frameworkHistory(int $id): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT effective_from, accounting_framework FROM supplier_accounting_frameworks WHERE supplier_id = ? ORDER BY effective_from'
        );
        $stmt->execute([$id]);
        return array_map(static fn (array $row): array => [(string) $row[0], (string) $row[1]], $stmt->fetchAll(\PDO::FETCH_NUM));
    }

    /** @param list<array{code:string}> $accounts @return list<string> */
    private function templateCodes(array $accounts): array
    {
        $codes = array_map(static fn (array $account): string => $account['code'], $accounts);
        sort($codes, SORT_STRING);
        return $codes;
    }

    /** @return list<string> */
    private function chartCodes(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT account_code FROM chart_of_accounts WHERE supplier_id = ?');
        $stmt->execute([$id]);
        $codes = array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        sort($codes, SORT_STRING);
        return $codes;
    }

    /** @param array<string,mixed> $body */
    private function create(array $body): int
    {
        $response = $this->settings->createSupplier(
            $this->request('POST', '/api/suppliers')->withParsedBody($body + [
                'street' => 'Testovací 1',
                'city'   => 'Zkušební Lhota',
                'zip'    => '11111',
                'email'  => 'zalozeni@example.test',
            ]),
            new Psr7Response(),
        );
        $response->getBody()->rewind();
        $raw = $response->getBody()->getContents();
        self::assertSame(201, $response->getStatusCode(), $raw);

        $json = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $id = (int) ($json['data']['id'] ?? $json['id'] ?? 0);
        self::assertGreaterThan(0, $id, $raw);
        $this->created[] = $id;

        return $id;
    }

    private function request(string $method, string $path): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->currentSupplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
    }

    /** @return array<string,mixed> */
    private function supplierRow(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM supplier WHERE id = ?');
        $stmt->execute([$id]);

        return (array) $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }
}
