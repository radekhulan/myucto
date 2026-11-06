<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Settings;

use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class SupplierAccountingDecreeTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private SettingsAction $action;
    private int $supplierId;
    private int $otherSupplierId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->action = $container->get(SettingsAction::class);
        $pdo = $this->db->pdo();
        $sourceId = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        self::assertGreaterThan(0, $sourceId);
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceId);
        $pdo->prepare('DELETE FROM supplier_accounting_modes WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM supplier_accounting_frameworks WHERE supplier_id = ?')->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public function testEffectiveDecreeFollowsFrameworkAndPreservesContraryManualValue(): void
    {
        $this->setState('double_entry', '504', 'cz_500');
        $data = $this->read();
        self::assertSame('504', $data['tax_accounting_decree']);
        self::assertSame('500', $data['tax_accounting_decree_effective']);
        self::assertTrue($data['tax_accounting_decree_is_derived']);
        $this->setState('double_entry', '500', 'cz_504');
        $data = $this->read();
        self::assertSame('500', $data['tax_accounting_decree']);
        self::assertSame('504', $data['tax_accounting_decree_effective']);
        self::assertTrue($data['tax_accounting_decree_is_derived']);
    }

    public function testTaxEvidenceUsesManualValue(): void
    {
        $this->setState('tax_evidence', '501', 'cz_504');
        $data = $this->read();
        self::assertSame('501', $data['tax_accounting_decree_effective']);
        self::assertFalse($data['tax_accounting_decree_is_derived']);
    }

    public function testTodaysModeHistoryControlsWhetherDecreeIsDerived(): void
    {
        $this->setState('double_entry', '501', 'cz_500');
        $this->db->pdo()->prepare("INSERT INTO supplier_accounting_modes (supplier_id, effective_from, accounting_mode) VALUES (?, '1900-01-01', 'tax_evidence'), (?, '2099-01-01', 'double_entry')")->execute([$this->supplierId, $this->supplierId]);
        $data = $this->read();
        self::assertSame('501', $data['tax_accounting_decree_effective']);
        self::assertFalse($data['tax_accounting_decree_is_derived']);
    }

    public function testLegalFormRecommendationDoesNotChangeFrameworkOrInferTaxExemption(): void
    {
        $this->setState('double_entry', '500', 'cz_500');
        foreach (['117', '118', '141', '161', '706', '736', '145', '112'] as $legalForm) {
            $this->db->pdo()->prepare('UPDATE supplier SET legal_form=? WHERE id=?')->execute([$legalForm, $this->supplierId]);
            $data = $this->read();
            $expected = $legalForm === '145'
                ? ['entity_profile' => 'svj', 'accounting_framework' => 'cz_504', 'public_benefit_taxpayer' => false]
                : ($legalForm === '112'
                    ? ['entity_profile' => 'unknown', 'accounting_framework' => null, 'public_benefit_taxpayer' => null]
                    : ['entity_profile' => 'nonprofit', 'accounting_framework' => 'cz_504', 'public_benefit_taxpayer' => null]);
            self::assertSame($expected, $data['accounting_profile_recommendation']);
            self::assertSame('cz_500', $data['accounting_framework']);
            self::assertCount(1, $data['accounting_framework_history']);
        }
    }

    public function testRecommendationUsesEffectiveAccountingModeHistory(): void
    {
        $this->setState('double_entry', '500', 'cz_500');
        $this->db->pdo()->prepare("UPDATE supplier SET legal_form='706' WHERE id=?")->execute([$this->supplierId]);
        $this->db->pdo()->prepare("INSERT INTO supplier_accounting_modes (supplier_id, effective_from, accounting_mode) VALUES (?, '1900-01-01', 'tax_evidence')")->execute([$this->supplierId]);
        self::assertSame(['entity_profile' => 'nonprofit', 'accounting_framework' => null, 'public_benefit_taxpayer' => null], $this->read()['accounting_profile_recommendation']);
    }

    public function testForeignSupplierRemainsHiddenFromBoundToken(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/suppliers/' . $this->otherSupplierId)
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['supplier_id' => $this->supplierId]);
        $response = $this->action->getSupplierById($request, new Response(), ['id' => $this->otherSupplierId]);
        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('tax_accounting_decree_effective', (string) $response->getBody());
    }

    private function setState(string $mode, string $manual, string $framework): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE supplier SET accounting_mode = ?, tax_accounting_decree = ? WHERE id = ?')->execute([$mode, $manual, $this->supplierId]);
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id, effective_from, accounting_framework) VALUES (?, '1900-01-01', ?) ON DUPLICATE KEY UPDATE accounting_framework = VALUES(accounting_framework)")->execute([$this->supplierId, $framework]);
    }

    private function read(): array
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/settings/supplier')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId);
        $response = $this->action->getSupplier($request, new Response());
        self::assertSame(200, $response->getStatusCode());
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
