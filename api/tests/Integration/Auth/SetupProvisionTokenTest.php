<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Auth;

use MyInvoice\Action\Auth\SetupAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Ares\SupplierRegistryEnricher;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\SessionCookieFactory;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Setup\PasswordSetupLinkIssuer;
use MyInvoice\Service\Setup\ProvisionTokenGuard;
use MyInvoice\Service\System\AppUrlConfiguration;
use MyInvoice\Service\System\ManagedModeGuard;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * H-01 na úrovni celé akce: `POST /api/auth/setup` musí zřizovací token ověřit
 * dřív, než se vůbec podívá na tělo — a odmítnutý pokus nesmí do `users` sáhnout.
 *
 * Logika samotné brány je pokrytá jednotkově
 * ({@see \MyInvoice\Tests\Unit\Service\Setup\ProvisionTokenGuardTest}); tenhle test
 * hlídá zapojení do akce a invariantu „odmítnutý setup nezaložil uživatele".
 *
 * Všechna data jsou syntetická; testy nic nezakládají — všechny scénáře končí
 * dřív, než akce zapíše první řádek.
 */
#[Group('integration')]
final class SetupProvisionTokenTest extends TestCase
{
    private const TOKEN = 'aaaabbbbccccddddeeeeffff00001111';

    private ContainerInterface $container;
    private Connection $db;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DI kontejner.');
        }
        try {
            $this->container = Bootstrap::buildApp()->getContainer();
            $this->db = $this->container->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
    }

    public function testManagedInstanceWithoutConfiguredTokenRefusesSetup(): void
    {
        $before = $this->userCount();

        $response = $this->invokeSetup(
            $this->guard(managed: true, configured: ''),
            $this->fullSetupBody(),
            ProvisionTokenGuard::HEADER,
            self::TOKEN,
            expectLog: ProvisionTokenGuard::REASON_NOT_CONFIGURED,
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(ProvisionTokenGuard::CODE_REQUIRED, $this->errorCode($response));
        self::assertSame($before, $this->userCount(), 'Odmítnutý setup nesmí založit uživatele.');
    }

    public function testManagedInstanceRefusesWrongToken(): void
    {
        $before = $this->userCount();

        $response = $this->invokeSetup(
            $this->guard(managed: true, configured: self::TOKEN),
            $this->fullSetupBody(),
            ProvisionTokenGuard::HEADER,
            'ffff0000111122223333444455556666',
            expectLog: ProvisionTokenGuard::REASON_MISMATCH,
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(ProvisionTokenGuard::CODE_INVALID, $this->errorCode($response));
        self::assertSame($before, $this->userCount());
    }

    public function testRejectionRevealsNothingAboutTheConfiguredToken(): void
    {
        $response = $this->invokeSetup(
            $this->guard(managed: true, configured: self::TOKEN),
            $this->fullSetupBody(),
            ProvisionTokenGuard::HEADER,
            'ffff0000111122223333444455556666',
            expectLog: ProvisionTokenGuard::REASON_MISMATCH,
        );

        $body = (string) $response->getBody();
        self::assertStringNotContainsString(self::TOKEN, $body);
        self::assertStringNotContainsString(substr(self::TOKEN, 0, 6), $body);
        self::assertStringNotContainsString(ProvisionTokenGuard::REASON_MISMATCH, $body);
    }

    public function testManagedInstanceWithMatchingTokenPassesTheGuard(): void
    {
        // Prázdné tělo → akce se musí dostat až k validaci (400), ne skončit na 403.
        // Dál v integračním běhu nedojdeme: úspěšný setup vyžaduje prázdnou `users`.
        $response = $this->invokeSetup(
            $this->guard(managed: true, configured: self::TOKEN),
            [],
            ProvisionTokenGuard::HEADER,
            self::TOKEN,
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('validation_failed', $this->errorCode($response));
    }

    public function testManagedInstanceAcceptsTokenFromBody(): void
    {
        $response = $this->invokeSetup(
            $this->guard(managed: true, configured: self::TOKEN),
            [ProvisionTokenGuard::BODY_FIELD => self::TOKEN],
            null,
            null,
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('validation_failed', $this->errorCode($response));
    }

    /**
     * ⚠️ `instance_id` přiděluje provozovatel spravovaného provozu a licenční
     * server proti němu ověřuje, že instalace je opravdu ta, kterou zřídil.
     * Cokoliv mimo očekávaný tvar je buď překlep v provisioningu, nebo pokus
     * podstrčit cizí identitu — a projít nesmí ani jedno.
     */
    public function testMalformedAssignedInstanceIdIsRefused(): void
    {
        $before = $this->userCount();

        $response = $this->invokeSetup(
            $this->guard(managed: true, configured: self::TOKEN),
            ['instance_id' => "inst-A\nX: 1"],
            ProvisionTokenGuard::HEADER,
            self::TOKEN,
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('validation_failed', $this->errorCode($response));
        self::assertStringContainsString('instance_id', (string) $response->getBody());
        self::assertSame($before, $this->userCount(), 'Odmítnutý setup nesmí založit uživatele.');
    }

    /**
     * Self-hosted setup `instance_id` neposílá a posílat nemusí — instalace
     * u externího hostingu je pořád nespravovaná a vlastní identifikátor si
     * generuje sama.
     */
    public function testAssignedInstanceIdIsOptional(): void
    {
        $response = $this->invokeSetup(
            $this->guard(managed: false, configured: ''),
            ['supplier' => ['company_name' => 'Bez identifikátoru s.r.o.']],
            null,
            null,
        );

        self::assertSame(400, $response->getStatusCode(), 'Padá to na chybějícím adminovi, ne na instance_id.');
        self::assertStringNotContainsString('instance_id', (string) $response->getBody());
    }

    public function testSelfHostedSetupNeedsNoToken(): void
    {
        $response = $this->invokeSetup($this->guard(managed: false, configured: ''), [], null, null);

        self::assertSame(400, $response->getStatusCode(), 'Self-hosted instalace se nesmí změnit.');
        self::assertSame('validation_failed', $this->errorCode($response));
    }

    public function testManagedSetupRefusesMalformedLicenseKey(): void
    {
        // Licenční klíč přichází ze zřizovacího požadavku, ne od uživatele —
        // překlep v něm znamená, že by instalace tiše naběhla bez licence.
        $response = $this->invokeSetup(
            $this->guard(managed: true, configured: self::TOKEN),
            [ProvisionTokenGuard::BODY_FIELD => self::TOKEN, 'license_key' => 'a b'],
            null,
            null,
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('validation_failed', $this->errorCode($response));
    }

    public function testManagedSetupAcceptsWellFormedLicenseKey(): void
    {
        // Dál než k validaci těla se v integračním běhu nedostaneme (setup
        // vyžaduje prázdnou `users`), ale klíč správného tvaru NESMÍ skončit
        // na 400 — jinak by zřízení spadlo na tom, co ho má opravit.
        $response = $this->invokeSetup(
            $this->guard(managed: true, configured: self::TOKEN),
            [ProvisionTokenGuard::BODY_FIELD => self::TOKEN, 'license_key' => 'MYU-AAAA-BBBB-CCCC-DDDD'],
            null,
            null,
        );

        self::assertSame(400, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringNotContainsString('license_key', $body, 'klíč správného tvaru není důvod odmítnutí');
        self::assertStringNotContainsString('MYU-AAAA', $body, 'klíč se nesmí vracet v odpovědi');
    }

    /**
     * @param array<string,mixed> $body
     */
    private function invokeSetup(
        ProvisionTokenGuard $guard,
        array $body,
        ?string $header,
        ?string $headerValue,
        ?string $expectLog = null,
    ): ResponseInterface {
        $logger = $this->createMock(ActivityLogger::class);
        if ($expectLog !== null) {
            $logger->expects(self::once())
                ->method('log')
                ->with(
                    self::identicalTo(ProvisionTokenGuard::LOG_EVENT),
                    self::isNull(),
                    self::isNull(),
                    self::isNull(),
                    self::identicalTo(['reason' => $expectLog]),
                );
        } else {
            $logger->expects(self::never())->method('log');
        }

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/auth/setup')
            ->withParsedBody($body);
        if ($header !== null && $headerValue !== null) {
            $request = $request->withHeader($header, $headerValue);
        }

        return $this->action($guard, $logger)($request, new Psr7Response());
    }

    private function action(ProvisionTokenGuard $guard, ActivityLogger $logger): SetupAction
    {
        return new SetupAction(
            $this->db,
            $this->container->get(PasswordHasher::class),
            $logger,
            $this->container->get(IpMatcher::class),
            $this->container->get(SessionManager::class),
            $this->container->get(Config::class),
            $this->container->get(AppUrlConfiguration::class),
            $this->container->get(SupplierRegistryEnricher::class),
            $this->container->get(BankStatementOwnershipResolver::class),
            $this->container->get(SessionCookieFactory::class),
            $guard,
            new PasswordSetupLinkIssuer(),
            $this->container->get(ManagedModeGuard::class),
            $this->container->get(\MyInvoice\Service\Accounting\ChartOfAccountsSeeder::class),
            $this->container->get(\MyInvoice\Service\License\LicenseService::class),
            $this->container->get(\MyInvoice\Service\Ares\CrpDphClient::class),
            $this->container->get(\MyInvoice\Service\Vat\VatStatusService::class),
            $this->container->get(\Psr\Log\LoggerInterface::class),
            $this->container->get(\MyInvoice\Repository\AccountingModeRepository::class),
        );
    }

    private function guard(bool $managed, string $configured): ProvisionTokenGuard
    {
        return new ProvisionTokenGuard(new Config([
            'app'   => ['managed' => $managed],
            'setup' => ['provision_token' => $configured],
        ]));
    }

    /**
     * Kompletní, jinak platné tělo — kdyby brána nefungovala, setup by se o zápis
     * skutečně pokusil a `users` by se změnila (nebo by vrátil 409, ne 403).
     *
     * @return array<string,mixed>
     */
    private function fullSetupBody(): array
    {
        return [
            'terms_accepted' => true,
            'admin' => [
                'name'     => '__TEST H01 Admin',
                'email'    => 'h01-provision@example.test',
                'password' => 'SyntetickeHeslo123!',
            ],
        ];
    }

    private function errorCode(ResponseInterface $response): string
    {
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        return (string) ($body['error']['code'] ?? '');
    }

    private function userCount(): int
    {
        return (int) $this->db->pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }
}

