<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Accounting;

use MyInvoice\Action\Accounting\MigrationDataCheckAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Security\RoutePermissionMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Kontrolu dat po převodu čte role s přístupem do účetnictví, opravu spouští jen správce
 * firmy nebo interní role se zápisem do účetnictví, a jen z přihlášené relace
 * ({@see RequestAuthorization::canFixMigrationData()}). Odmítnutí přijde dřív, než akce
 * sáhne na službu nebo databázi.
 */
final class MigrationDataCheckActionAccessTest extends TestCase
{
    /** @return iterable<string,array{string,string,int,?string,bool}> */
    public static function fixers(): iterable
    {
        yield 'správce firmy' => ['session', 'staff', 0, 'admin', true];
        yield 'účetní se zápisem' => ['session', 'staff', 2, null, true];
        yield 'jen čtení účetnictví' => ['session', 'staff', 1, null, false];
        yield 'klient se zápisem' => ['session', 'client', 2, null, false];
        yield 'API token správce' => ['bearer', 'staff', 2, 'admin', false];
    }

    #[DataProvider('fixers')]
    public function testFixRequiresCompanyAdminOrAccountingWriteInSession(string $method, string $type, int $accounting, ?string $systemKey, bool $allowed): void
    {
        self::assertSame($allowed, RequestAuthorization::canFixMigrationData($this->request($method, $type, $accounting, $systemKey)));
    }

    public function testReadOnlyRoleCannotFixAndActionStopsBeforeService(): void
    {
        $response = $this->action()->fix($this->request('session', 'staff', 1), new Response(), ['key' => 'tax_document_advance_link']);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testFixNeedsConfirmationAndPreviewFingerprint(): void
    {
        $request = $this->request('session', 'staff', 2);
        $response = $this->action()->fix($request->withParsedBody(['fingerprint' => 'abcdef0123456789']), new Response(), ['key' => 'x']);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('confirmation_required', (string) $response->getBody());
        $response = $this->action()->fix($request->withParsedBody(['confirm' => true]), new Response(), ['key' => 'x']);
        self::assertSame(422, $response->getStatusCode());
        $response = $this->action()->fix($request->withParsedBody(['confirm' => true, 'fingerprint' => 'abc', 'options' => []]), new Response(), ['key' => 'x']);
        self::assertSame(422, $response->getStatusCode());
    }

    public function testRoleWithoutAccountingCannotRead(): void
    {
        $request = $this->request('session', 'staff', 0);
        self::assertSame(403, $this->action()->list($request, new Response())->getStatusCode());
        self::assertSame(403, $this->action()->show($request, new Response(), ['key' => 'x'])->getStatusCode());
        self::assertSame(403, $this->action()->preview($request, new Response(), ['key' => 'x'])->getStatusCode());
    }

    public function testRouteMapReadsWithAccountingAndFixNeedsWrite(): void
    {
        $map = new RoutePermissionMap();
        foreach ([
            ['GET', '/api/accounting/migration-checks', AccessLevel::READ],
            ['GET', '/api/accounting/migration-checks/settled_without_payment', AccessLevel::READ],
            ['POST', '/api/accounting/migration-checks/settled_without_payment/preview', AccessLevel::READ],
            ['POST', '/api/accounting/migration-checks/settled_without_payment/fix', AccessLevel::WRITE],
        ] as [$method, $path, $level]) {
            $rule = $map->match($method, $path);
            self::assertNotNull($rule, $path);
            self::assertSame([RoutePermissionMap::PERMISSION, 'accounting', $level], [$rule->kind, $rule->key, $rule->minimum], "{$method} {$path}");
        }
    }

    private function action(): MigrationDataCheckAction
    {
        return (new \ReflectionClass(MigrationDataCheckAction::class))->newInstanceWithoutConstructor();
    }

    private function request(string $method, string $type, int $accounting, ?string $systemKey = null): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/accounting/migration-checks/x/fix')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $method)
            ->withAttribute('auth.effective_role', new EffectiveRole(5, 'Role', $type, true, ['accounting' => $accounting], $systemKey));
    }
}
