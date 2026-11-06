<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Receivables\DebtCertificateRepository;
use MyInvoice\Repository\Property\Receivables\PartyEventRepository;
use MyInvoice\Service\Property\PropertyHistoryUseGuard;
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use MyInvoice\Tests\Support\PaymentCorrectionFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaymentLifecycleGuardTest extends TestCase
{
    use ChargeIssuanceFixture;
    use PaymentCorrectionFixture;

    private Connection $db;
    private array $f;

    protected function setUp(): void
    {
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        $this->db->pdo()->beginTransaction();
        $this->f = $this->issuanceFixture($this->db);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    public static function deletionPaths(): array
    {
        return ['dependency guard' => [false], 'HTTP deletion' => [true]];
    }

    #[DataProvider('deletionPaths')]
    public function testCertificateRequesterCannotBeDeletedWithoutOtherClientHistory(bool $http): void
    {
        $base = $this->settledPaymentFixture('1000', '0');
        $requester = $this->newClient();
        $guard = new PropertyHistoryUseGuard($this->db);
        self::assertFalse($guard->clientHasHistory($this->f['supplier'], $requester));
        $request = ['unit_id' => $base['charge']['unit_id'], 'as_of' => '2026-02-03T00:00:00+01:00',
            'known_at' => PartyEventRepository::now(), 'requester_id' => $requester,
            'authority_evidence' => 'Synthetic authority for an external requester', 'purpose' => 'Synthetic sale',
            'coverage_acknowledgement' => 'Recorded S3 S4 scope'];
        $repo = new DebtCertificateRepository($this->db);
        $certificate = $repo->issue($this->f['supplier'], $request, $repo->preview($this->f['supplier'], $request)['preview_hash'], 'external-requester', $this->f['actor']);
        if ($http) {
            $response = $this->deleteClient($requester);
            self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
            self::assertSame('has_dependencies', json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['error']['code']);
        } else {
            self::assertTrue($guard->clientHasHistory($this->f['supplier'], $requester, true));
        }
        self::assertSame($certificate, $repo->find($this->f['supplier'], $certificate['id']));
        self::assertFalse($guard->clientHasHistory($this->f['supplier'] + 100000000, $requester));
    }

    public function testOrdinaryUnreferencedClientCanStillBeDeleted(): void
    {
        $client = $this->newClient();
        self::assertSame(200, $this->deleteClient($client)->getStatusCode());
        $q = $this->db->pdo()->prepare('SELECT COUNT(*) FROM clients WHERE id=? AND supplier_id=?');
        $q->execute([$client, $this->f['supplier']]);
        self::assertSame(0, (int) $q->fetchColumn());
    }

    private function newClient(): int
    {
        $q = $this->db->pdo()->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,country_id,main_email,currency_default_id) SELECT supplier_id,'Synthetic external requester','Synthetic street','Synthetic city','10000',country_id,'external-requester@example.test',currency_default_id FROM clients WHERE supplier_id=? AND id=?");
        $q->execute([$this->f['supplier'], $this->f['clients'][1]]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function deleteClient(int $client): \Psr\Http\Message\ResponseInterface
    {
        $container = $this->paymentFixtureContainer();
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('DELETE', '/api/clients/' . $client)
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->f['supplier'])
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $this->f['actor'], 'role' => 'admin']);
        return ($container->get(\MyInvoice\Action\Client\DeleteClientAction::class))($request, new \Slim\Psr7\Response(), ['id' => $client]);
    }
}
