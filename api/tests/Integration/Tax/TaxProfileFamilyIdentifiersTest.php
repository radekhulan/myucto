<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Action\Tax\Return\TaxReturnAction;
use MyInvoice\Action\Tax\TaxAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\TaxProfileRepository;
use MyInvoice\Service\Payroll\Security\PayrollRevealPurpose;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Tax\Return\PreFinalizeCheckService;
use MyInvoice\Service\Tax\Return\TaxReturnService;
use MyInvoice\Service\Tax\TaxFamilyBirthNumberBackfill;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * H28 — rodná čísla dětí a manžela/manželky v daňovém profilu: zapečetěná v DB,
 * maskovaná v odpovědích, bez osobních údajů pro API token a MCP, plná jen
 * v XML přiznání pro oprávněnou relaci a v auditovaném odhalení.
 *
 * Izolovaný FO supplier, vše v transakci s rollbackem v tearDown.
 */
#[Group('integration')]
final class TaxProfileFamilyIdentifiersTest extends TestCase
{
    private const YEAR = 2025;
    private const CHILD_RC = '1504110003';
    private const CHILD2_RC = '1806150012';
    private const SPOUSE_RC = '8555101236';

    private Connection $db;
    private TaxReturnAction $returnAction;
    private TaxAction $taxAction;
    private TaxProfileRepository $profiles;
    private TaxReturnService $returns;
    private PayrollSensitiveData $sensitive;
    private int $supplierId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php') && !getenv('MYINVOICE_DB_NAME')) {
            $this->markTestSkipped('Integrační databáze není nastavená.');
        }
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->returnAction = $container->get(TaxReturnAction::class);
        $this->taxAction = $container->get(TaxAction::class);
        $this->profiles = $container->get(TaxProfileRepository::class);
        $this->returns = $container->get(TaxReturnService::class);
        $this->sensitive = $container->get(PayrollSensitiveData::class);

        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->userId === 0 || $czId === 0 || $currencyId === 0 || $vatRateId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id,
                                   taxpayer_type, accounting_mode, ic, dic, financial_office_code, cz_nace_code)
             VALUES (?, "Krátká 12/3", "Praha", "11000", ?, "family@example.com", ?, ?,
                     "fo", "tax_evidence", "87654321", "CZ7801011234", "451", "62020")'
        )->execute(['Jan Rodinný', $czId, $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $snapshot = json_encode(['year' => self::YEAR, 'journal' => ['totals' => [], 'rows' => []]], JSON_THROW_ON_ERROR);
        $pdo->prepare(
            "INSERT INTO tax_evidence_closings
                (supplier_id, year, status, checklist, source_snapshot, source_hash, row_version, finalized_at, finalized_by)
             VALUES (?, ?, 'final', '{}', ?, ?, 1, NOW(), ?)"
        )->execute([$this->supplierId, self::YEAR, $snapshot, hash('sha256', $snapshot), $this->userId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testBirthNumbersAreSealedAndOnlyMaskedOnRead(): void
    {
        $this->saveFamily();

        $rows = $this->db->pdo()->query(
            'SELECT birth_number, birth_number_ciphertext, birth_number_hash, birth_number_masked
               FROM tax_profile_children WHERE supplier_id = ' . $this->supplierId . ' ORDER BY order_index'
        )->fetchAll();
        self::assertCount(3, $rows);
        self::assertNull($rows[0]['birth_number'], 'Otevřený sloupec zůstává prázdný.');
        self::assertStringStartsWith('enc:v2:', (string) $rows[0]['birth_number_ciphertext']);
        self::assertSame('••••••••03', $rows[0]['birth_number_masked']);
        self::assertNull($rows[2]['birth_number_ciphertext'], 'Dítě jen s datem narození zůstává bez čísla.');
        $spouse = $this->db->pdo()->query('SELECT birth_number, birth_number_ciphertext FROM tax_profile_spouse_claims WHERE supplier_id = ' . $this->supplierId)->fetch();
        self::assertNull($spouse['birth_number']);
        self::assertStringStartsWith('enc:v2:', (string) $spouse['birth_number_ciphertext']);

        $profile = $this->profiles->find($this->supplierId, self::YEAR);
        $json = json_encode($profile);
        foreach ([self::CHILD_RC, self::CHILD2_RC, self::SPOUSE_RC] as $rc) {
            self::assertStringNotContainsString($rc, (string) $json);
        }
        self::assertTrue($profile['children'][0]['has_birth_number']);
        self::assertNull($profile['children'][0]['birth_number']);
        self::assertFalse($profile['children'][2]['has_birth_number']);
        self::assertSame('2019-05-01', $profile['children'][2]['birth_date']);
        self::assertSame('••••••••36', $profile['spouse_claim']['birth_number_masked']);

        $plain = $this->profiles->familyBirthNumbers($this->supplierId, self::YEAR, PayrollRevealPurpose::SUBMISSION_INCOME_TAX_RETURN);
        self::assertSame([self::CHILD_RC, self::CHILD2_RC], array_values($plain['children']));
        self::assertSame([self::SPOUSE_RC], array_values($plain['spouse']));
    }

    public function testRoundTripKeepsReplacesAndClearsBirthNumbers(): void
    {
        $this->saveFamily();
        $profile = $this->profiles->find($this->supplierId, self::YEAR);

        // Tvar z TaxOptimizer.vue / IncomeTaxReport.vue: profil z GET se pošle zpět,
        // rodné číslo prázdné (klient ho nikdy nedostal) nebo jako maska.
        $children = $profile['children'];
        $children[1]['birth_number'] = $children[1]['birth_number_masked'];
        $spouse = $profile['spouse_claim'];
        [$req, $res] = $this->req('PUT', ['year' => self::YEAR, 'children' => $children, 'spouse_claim' => $spouse]);
        $r = $this->taxAction->updateProfile($req, $res);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
        $plain = $this->profiles->familyBirthNumbers($this->supplierId, self::YEAR, PayrollRevealPurpose::SUBMISSION_INCOME_TAX_RETURN);
        self::assertSame([self::CHILD_RC, self::CHILD2_RC], array_values($plain['children']), 'Uložená čísla zůstávají.');
        self::assertSame([self::SPOUSE_RC], array_values($plain['spouse']));

        // Nová hodnota přepíše, birth_number_clear odebere.
        $profile = $this->profiles->find($this->supplierId, self::YEAR);
        $children = $profile['children'];
        $children[0]['birth_number'] = '150411/0014';
        $children[1]['birth_number_clear'] = true;
        $children[1]['birth_date'] = '2018-06-15';
        $this->profiles->upsert($this->supplierId, self::YEAR, ['children' => $children]);
        $plain = $this->profiles->familyBirthNumbers($this->supplierId, self::YEAR, PayrollRevealPurpose::SUBMISSION_INCOME_TAX_RETURN);
        self::assertSame(['1504110014'], array_values($plain['children']));
        self::assertSame([self::SPOUSE_RC], array_values($plain['spouse']), 'Vynechaný manžel zůstává.');

        // Položka bez id (nové dítě, starý klient) bez čísla číslo nedostane.
        $this->profiles->upsert($this->supplierId, self::YEAR, ['children' => [[
            'first_name' => 'Nový', 'last_name' => 'Rodinný', 'birth_date' => '2020-01-01', 'months' => [],
        ]]]);
        $plain = $this->profiles->familyBirthNumbers($this->supplierId, self::YEAR, PayrollRevealPurpose::SUBMISSION_INCOME_TAX_RETURN);
        self::assertSame([], $plain['children']);
    }

    public function testDuplicateBirthNumberIsRejected(): void
    {
        [$req, $res] = $this->req('PUT', ['year' => self::YEAR, 'children' => [
            $this->child('Anna', self::CHILD_RC, null, 0),
            $this->child('Anna', '150411/0003', null, 1),
        ]]);
        $r = $this->taxAction->updateProfile($req, $res);
        self::assertSame(422, $r->getStatusCode(), (string) $r->getBody());
    }

    public function testAnalysisMasksForSessionAndStripsFamilyForBearer(): void
    {
        $this->saveFamily();
        $session = $this->json($this->taxAction->analysis(...$this->req('GET', [], 'accountant', 'session', ['year' => self::YEAR])));
        $this->assertNoPlainBirthNumbers($session);
        self::assertSame('Anna', $session['profile']['children'][0]['first_name']);
        self::assertSame('••••••••03', $session['profile']['children'][0]['birth_number_masked']);

        $bearer = $this->json($this->taxAction->analysis(...$this->req('GET', [], 'accountant', 'bearer', ['year' => self::YEAR])));
        $this->assertNoPlainBirthNumbers($bearer);
        $child = $bearer['profile']['children'][0];
        self::assertArrayNotHasKey('first_name', $child);
        self::assertArrayNotHasKey('birth_number_masked', $child);
        self::assertArrayNotHasKey('birth_date', $child);
        self::assertCount(12, $child['months'], 'Měsíce pro výpočet zvýhodnění zůstávají.');
        self::assertArrayNotHasKey('own_income', $bearer['profile']['spouse_claim']);
        self::assertArrayNotHasKey('last_name', $bearer['profile']['spouse_claim']);
        self::assertSame(12, $bearer['profile']['spouse_claim']['eligible_months']);
    }

    public function testTaxReturnJsonNeverCarriesPlainBirthNumbers(): void
    {
        $this->saveFamily();
        $args = ['type' => 'fo', 'year' => (string) self::YEAR];
        $session = $this->json($this->returnAction->get(...[...$this->req('GET'), $args]));
        $this->assertNoPlainBirthNumbers($session);
        self::assertSame('••••••••03', $session['computed']['family']['children'][0]['birth_number_masked']);
        self::assertSame('Anna', $session['podklady']['profile']['children'][0]['first_name']);
        $check = array_column($session['prefinalize_check']['checks'], null, 'key');
        self::assertTrue($check['dpfo_children']['ok'], 'Dítě se zapečetěným číslem je úplně identifikované.');
        self::assertTrue($check['dpfo_spouse']['ok'], json_encode($check['dpfo_spouse']));

        $bearer = $this->json($this->returnAction->get(...[...$this->req('GET', [], 'accountant', 'bearer'), $args]));
        $this->assertNoPlainBirthNumbers($bearer);
        self::assertArrayNotHasKey('first_name', $bearer['computed']['family']['children'][0]);
        self::assertArrayNotHasKey('first_name', $bearer['podklady']['profile']['children'][0]);
        self::assertArrayNotHasKey('own_income', $bearer['computed']['family']['spouse']);
        self::assertSame($session['computed']['summary'], $bearer['computed']['summary'], 'Částky jsou pro token stejné.');
    }

    public function testXmlCarriesFullBirthNumbersOnlyForAuthorizedSession(): void
    {
        $this->saveFamily();
        $args = ['type' => 'fo', 'year' => (string) self::YEAR];

        $full = $this->body($this->returnAction->previewXml(...[...$this->req('GET'), $args]));
        self::assertStringContainsString('vyzdite_r_cislo="' . self::CHILD_RC . '"', $full);
        self::assertStringContainsString('vyzdite_r_cislo="' . self::CHILD2_RC . '"', $full);
        self::assertStringContainsString('manz_r_cislo="' . self::SPOUSE_RC . '"', $full);
        self::assertStringContainsString('vyzdite_d_nar="1.5.2019"', $full, 'Dítě bez čísla nese datum narození jako dřív.');

        foreach ([['readonly', 'session'], ['accountant', 'bearer']] as [$role, $method]) {
            $xml = $this->body($this->returnAction->previewXml(...[...$this->req('GET', [], $role, $method), $args]));
            foreach ([self::CHILD_RC, self::CHILD2_RC, self::SPOUSE_RC] as $rc) {
                self::assertStringNotContainsString($rc, $xml, "{$role}/{$method} nesmí dostat rodné číslo.");
            }
            self::assertStringContainsString('vyzdite_pocmes="12"', $xml);
        }
    }

    /**
     * Převod úložiště nesmí změnit XML: řádky uložené otevřeně (stav před migrací
     * 2160) dají bajtově totéž XML jako po zapečetění backfillem.
     */
    public function testLegacyPlaintextRowsGiveIdenticalXmlBeforeAndAfterBackfill(): void
    {
        $pdo = $this->db->pdo();
        $this->saveFamily();
        // Návrat do stavu před migrací: otevřené hodnoty, žádná šifra.
        foreach ($this->profiles->familyBirthNumbers($this->supplierId, self::YEAR, PayrollRevealPurpose::MIGRATION_VERIFY) as $key => $values) {
            $table = $key === 'children' ? 'tax_profile_children' : 'tax_profile_spouse_claims';
            foreach ($values as $id => $plain) {
                $pdo->prepare("UPDATE {$table} SET birth_number_ciphertext = NULL, birth_number_hash = NULL,
                                      birth_number_masked = NULL, birth_number = ? WHERE id = ?")->execute([$plain, $id]);
            }
        }
        $args = ['type' => 'fo', 'year' => (string) self::YEAR];
        $legacyJson = $this->json($this->returnAction->get(...[...$this->req('GET'), $args]));
        $this->assertNoPlainBirthNumbers($legacyJson);
        self::assertSame('••••••••03', $legacyJson['podklady']['profile']['children'][0]['birth_number_masked']);
        $before = $this->body($this->returnAction->previewXml(...[...$this->req('GET'), $args]));

        $backfill = new TaxFamilyBirthNumberBackfill($pdo, $this->sensitive);
        self::assertGreaterThanOrEqual(3, $backfill->pending());
        $dry = $backfill->run(false);
        self::assertSame(0, $dry['sealed']);
        $result = $backfill->run(true);
        self::assertSame(0, $result['failed']);
        self::assertGreaterThanOrEqual(3, $result['sealed']);
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM tax_profile_children WHERE supplier_id = ' . $this->supplierId . ' AND birth_number IS NOT NULL')->fetchColumn());
        self::assertSame(0, $backfill->run(true)['found'], 'Opakovaný běh nic nedělá.');

        $after = $this->body($this->returnAction->previewXml(...[...$this->req('GET'), $args]));
        self::assertSame($before, $after);
    }

    public function testFinalizedReturnStoresXmlWithBirthNumbersButNoPlainValuesInSnapshot(): void
    {
        $this->saveFamily();
        $args = ['type' => 'fo', 'year' => (string) self::YEAR];
        $state = $this->json($this->returnAction->get(...[...$this->req('GET'), $args]));
        $preview = $this->body($this->returnAction->previewXml(...[...$this->req('GET'), $args]));

        $final = $this->returns->finalize($this->supplierId, self::YEAR, 'fo', (int) $state['return']['row_version'], $this->userId);
        self::assertSame('final', $final['return']['status']);
        $this->assertNoPlainBirthNumbers($final);

        $pdo = $this->db->pdo();
        $computed = (string) $pdo->query('SELECT computed FROM income_tax_returns WHERE supplier_id = ' . $this->supplierId)->fetchColumn();
        $snapshot = $pdo->query('SELECT snapshot_json, xml_content FROM income_tax_return_snapshots WHERE supplier_id = ' . $this->supplierId)->fetch();
        foreach ([self::CHILD_RC, self::CHILD2_RC, self::SPOUSE_RC] as $rc) {
            self::assertStringNotContainsString($rc, $computed);
            self::assertStringNotContainsString($rc, (string) $snapshot['snapshot_json']);
            self::assertStringContainsString($rc, (string) $snapshot['xml_content'], 'Uložené podání rodná čísla nese.');
        }
        self::assertSame($this->withoutVolatile($preview), $this->withoutVolatile((string) $snapshot['xml_content']));

        $export = $this->body($this->returnAction->xml(...[...$this->req('GET', [], 'readonly'), $args]));
        self::assertStringNotContainsString(self::CHILD_RC, $export, 'Role jen ke čtení dostane XML bez rodných čísel.');
    }

    public function testRevealIsSessionOnlyAuditedAndRequiresWritePermission(): void
    {
        $this->saveFamily();
        $body = ['year' => self::YEAR, 'reason' => 'Kontrola rodného čísla před podáním'];

        $r = $this->taxAction->revealFamily(...$this->req('POST', $body, 'accountant', 'bearer'));
        self::assertSame(403, $r->getStatusCode(), (string) $r->getBody());
        self::assertSame('session_required', $this->json($r)['error']['code']);
        $r = $this->taxAction->revealFamily(...$this->req('POST', $body, 'readonly'));
        self::assertSame(403, $r->getStatusCode(), (string) $r->getBody());
        $r = $this->taxAction->revealFamily(...$this->req('POST', ['year' => self::YEAR, 'reason' => 'krátký']));
        self::assertSame(422, $r->getStatusCode(), (string) $r->getBody());

        $before = (int) $this->db->pdo()->query("SELECT COUNT(*) FROM activity_log WHERE action = 'tax_profile.family_revealed' AND supplier_id = " . $this->supplierId)->fetchColumn();
        $r = $this->taxAction->revealFamily(...$this->req('POST', $body));
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
        self::assertStringContainsString('no-store', $r->getHeaderLine('Cache-Control'));
        $reveal = $this->json($r)['reveal'];
        self::assertSame([self::CHILD_RC, self::CHILD2_RC], array_column($reveal['children'], 'birth_number'));
        self::assertSame(self::SPOUSE_RC, $reveal['spouse']['birth_number']);

        $log = $this->db->pdo()->query("SELECT payload FROM activity_log WHERE action = 'tax_profile.family_revealed' AND supplier_id = " . $this->supplierId . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
        self::assertSame($before + 1, (int) $this->db->pdo()->query("SELECT COUNT(*) FROM activity_log WHERE action = 'tax_profile.family_revealed' AND supplier_id = " . $this->supplierId)->fetchColumn());
        self::assertStringContainsString('Kontrola rodného čísla', (string) $log);
        foreach ([self::CHILD_RC, self::SPOUSE_RC] as $rc) {
            self::assertStringNotContainsString($rc, (string) $log, 'Audit nese důvod, ne hodnotu.');
        }
    }

    private function saveFamily(): void
    {
        [$req, $res] = $this->req('PUT', [
            'year' => self::YEAR,
            'children' => [
                $this->child('Anna', self::CHILD_RC, null, 0),
                $this->child('Bedřich', '180615/0012', null, 1),
                $this->child('Cyril', '', '2019-05-01', 2),
            ],
            'spouse_claim' => [
                'first_name' => 'Eva', 'last_name' => 'Rodinná', 'birth_number' => '855510/1236', 'birth_date' => null,
                'eligible_months' => 12, 'ztpp' => false, 'own_income' => 1000, 'income_proved' => true,
                'shared_household_proved' => true, 'child_under_three_proved' => true,
            ],
        ]);
        $r = $this->taxAction->updateProfile($req, $res);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
        $this->assertNoPlainBirthNumbers($this->json($r));
    }

    /** @return array<string,mixed> */
    private function child(string $name, string $rc, ?string $birthDate, int $order): array
    {
        return [
            'first_name' => $name, 'last_name' => 'Rodinný', 'birth_number' => $rc, 'birth_date' => $birthDate,
            'shared_household_proved' => true, 'other_parent_not_claimed_proved' => true,
            'months' => array_map(static fn (int $m): array => ['month' => $m, 'order' => min(3, $order + 1), 'ztpp' => false, 'claimed' => true], range(1, 12)),
        ];
    }

    /** @param array<string,mixed> $payload */
    private function assertNoPlainBirthNumbers(array $payload): void
    {
        $json = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
        foreach ([self::CHILD_RC, self::CHILD2_RC, self::SPOUSE_RC, '1504110014'] as $rc) {
            self::assertStringNotContainsString($rc, $json);
        }
    }

    private function withoutVolatile(string $xml): string
    {
        return (string) preg_replace('/\s(d_uv|dapdpf_forma_sw|verze_sw)="[^"]*"/', '', $xml);
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,string> $query
     * @return array{0:ServerRequestInterface,1:Psr7Response}
     */
    private function req(string $method, array $body = [], string $role = 'accountant', string $auth = 'session', array $query = []): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest($method, '/api/tax')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $auth)
            ->withQueryParams($query)
            ->withParsedBody($body);

        return [$req, new Psr7Response()];
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $r): array
    {
        $r->getBody()->rewind();
        $decoded = json_decode((string) $r->getBody(), true);
        self::assertIsArray($decoded, (string) $r->getBody());

        return $decoded;
    }

    private function body(ResponseInterface $r): string
    {
        $r->getBody()->rewind();
        $body = (string) $r->getBody();
        self::assertSame(200, $r->getStatusCode(), $body);

        return $body;
    }
}
