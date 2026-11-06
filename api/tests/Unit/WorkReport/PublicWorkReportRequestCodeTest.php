<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\WorkReport;

use MyInvoice\Action\WorkReport\PublicWorkReportRequestCodeAction;
use MyInvoice\Http\AfterResponseTasks;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\WorkReportLinkRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Captcha\TurnstileVerifier;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Tenant\PublicTenantGuard;
use MyInvoice\Service\WorkReport\WorkReportLinkService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Žádost o kód k náhledu výkazu práce nesmí odlišit povolenou a cizí adresu:
 * stejné tělo odpovědi (cooldown_remaining je vždy nastavený cooldown) a e-mail
 * se posílá až po odeslání odpovědi, aby ho neprozradila ani doba odpovědi.
 */
final class PublicWorkReportRequestCodeTest extends TestCase
{
    /** @return array<string, array{array{sent:bool,cooldown_remaining:int,allowed:bool}}> */
    public static function issueResults(): array
    {
        return [
            'cizí adresa' => [['sent' => false, 'cooldown_remaining' => 0, 'allowed' => false]],
            'povolená, kód odeslán' => [['sent' => true, 'cooldown_remaining' => 60, 'allowed' => true]],
            'povolená v cooldownu' => [['sent' => false, 'cooldown_remaining' => 17, 'allowed' => true]],
        ];
    }

    #[DataProvider('issueResults')]
    public function testResponseIsSameForAllowedAndForeignAddress(array $issueResult): void
    {
        $service = $this->createStub(WorkReportLinkService::class);
        $service->method('findActiveLink')->willReturn(['id' => 1, 'supplier_id' => 2, 'client_id' => 3, 'project_id' => null]);
        $service->method('issueCode')->willReturn($issueResult);
        $service->method('resendCooldownSeconds')->willReturn(60);

        $body = $this->callAction($service, ['email' => 'kdokoli@example.test']);
        self::assertSame(['ok' => true, 'cooldown_remaining' => 60], $body);
    }

    public function testInvalidEmailGetsSameResponse(): void
    {
        $service = $this->createStub(WorkReportLinkService::class);
        $service->method('findActiveLink')->willReturn(['id' => 1, 'supplier_id' => 2, 'client_id' => 3, 'project_id' => null]);
        $service->method('resendCooldownSeconds')->willReturn(60);

        self::assertSame(['ok' => true, 'cooldown_remaining' => 60], $this->callAction($service, ['email' => 'neni-email']));
    }

    public function testCodeMailIsDeferredAfterResponseWhenSapiCanDetach(): void
    {
        $pdo = \PDO::connect('sqlite::memory:');
        $pdo->createFunction('NOW', static fn () => date('Y-m-d H:i:s'));
        $pdo->createFunction('UNIX_TIMESTAMP', static fn ($v) => strtotime((string) $v));
        $pdo->exec('CREATE TABLE clients (id INTEGER, main_email TEXT, company_name TEXT, language TEXT, street TEXT, city TEXT, zip TEXT, ic TEXT, dic TEXT, country_id INTEGER)');
        $pdo->exec("INSERT INTO clients VALUES (3, 'klient@example.test', 'Klient', 'cs', NULL, NULL, NULL, NULL, NULL, NULL)");
        $pdo->exec('CREATE TABLE countries (id INTEGER, name_cs TEXT)');
        $pdo->exec('CREATE TABLE client_email_contacts (client_id INTEGER, email TEXT, is_active INTEGER)');
        $pdo->exec('CREATE TABLE work_report_link_codes (id INTEGER PRIMARY KEY, link_id INTEGER, email TEXT, code_hash TEXT, expires_at TEXT, attempts INTEGER DEFAULT 0, used_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, ip BLOB)');
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);

        $mailer = $this->createMock(Mailer::class);
        $mailer->expects(self::once())->method('sendTemplate')
            ->with('work_report_access_code', 'cs', ['klient@example.test']);

        $service = new \ReflectionClass(WorkReportLinkService::class)->newInstanceWithoutConstructor();
        foreach ([
            'db' => $db,
            'config' => new Config([]),
            'mailer' => $mailer,
            'links' => new WorkReportLinkRepository($db),
            'logger' => new NullLogger(),
        ] as $name => $value) {
            new \ReflectionProperty(WorkReportLinkService::class, $name)->setValue($service, $value);
        }

        $afterResponse = new AfterResponseTasks(new NullLogger(), true);
        $link = ['id' => 1, 'supplier_id' => 0, 'client_id' => 3, 'project_id' => null];

        $foreign = $service->issueCode($link, 'cizi@example.test', '127.0.0.1', false, $afterResponse);
        self::assertFalse($foreign['sent']);
        self::assertFalse($afterResponse->hasPending(), 'Cizí adrese se nic neposílá');

        $result = $service->issueCode($link, 'klient@example.test', '127.0.0.1', false, $afterResponse);
        self::assertTrue($result['sent']);
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM work_report_link_codes')->fetchColumn());
        self::assertTrue($afterResponse->hasPending(), 'E-mail čeká až na odeslání odpovědi');

        $afterResponse->run(false);
        self::assertFalse($afterResponse->hasPending());
    }

    private function callAction(WorkReportLinkService $service, array $payload): array
    {
        $captcha = $this->createStub(TurnstileVerifier::class);
        $captcha->method('verify')->willReturn(true);
        $ipMatcher = $this->createStub(IpMatcher::class);
        $ipMatcher->method('clientIpFromRequest')->willReturn('127.0.0.1');
        $guard = $this->createStub(PublicTenantGuard::class);
        $guard->method('allows')->willReturn(true);

        $action = new PublicWorkReportRequestCodeAction(
            $service,
            $captcha,
            $ipMatcher,
            $this->createStub(ActivityLogger::class),
            $guard,
            new AfterResponseTasks(new NullLogger(), true),
        );
        $token = str_repeat('a', 48);
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/public/work-report/' . $token . '/request-code')
            ->withParsedBody($payload);
        $response = $action($request, new Response(), ['token' => $token]);
        self::assertSame(200, $response->getStatusCode());
        return (array) json_decode((string) $response->getBody(), true);
    }
}
