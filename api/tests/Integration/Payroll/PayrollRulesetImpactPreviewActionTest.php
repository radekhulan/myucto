<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollRulesetAction;
use MyInvoice\Bootstrap;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RoutePermissionMap;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollRulesetImpactPreviewActionTest extends TestCase
{
    private PayrollRulesetAction $action;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer()
            ?? throw new \RuntimeException('DI kontejner není dostupný.');
        $action = $container->get(PayrollRulesetAction::class);
        if (!$action instanceof PayrollRulesetAction) {
            throw new \RuntimeException('Action náhledu dopadu rulesetu není dostupná.');
        }
        $this->action = $action;
    }

    public function testSessionSuperadminGetsImpactPreviewWrapper(): void
    {
        $response = $this->action->impactPreview(
            $this->request('session', 'admin'),
            new Response(),
            ['rulesetId' => 'cz-payroll-2026.income-tax.v1'],
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertArrayHasKey('impact_preview', $body);
        self::assertSame('income_tax', $body['impact_preview']['ruleset']['domain']);
        self::assertArrayHasKey('parameter_diff', $body['impact_preview']);
        self::assertArrayHasKey('activation_effect', $body['impact_preview']);
        self::assertTrue($body['impact_preview']['activation_effect']['existing_snapshots_are_immutable']);
    }

    public function testOrdinaryRoleAndBearerAreRejected(): void
    {
        $ordinary = $this->action->impactPreview(
            $this->request('session', 'accountant'),
            new Response(),
            ['rulesetId' => 'cz-payroll-2026.income-tax.v1'],
        );
        self::assertSame(403, $ordinary->getStatusCode());
        self::assertSame('forbidden', $this->json($ordinary)['error']['code']);

        $bearer = $this->action->impactPreview(
            $this->request('bearer', 'admin'),
            new Response(),
            ['rulesetId' => 'cz-payroll-2026.income-tax.v1'],
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame('session_required', $this->json($bearer)['error']['code']);
    }

    public function testSessionSuperadminGetsNotFoundForUnknownRuleset(): void
    {
        $response = $this->action->impactPreview(
            $this->request('session', 'admin'),
            new Response(),
            ['rulesetId' => 'test.unknown.ruleset'],
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('not_found', $this->json($response)['error']['code']);
    }

    public function testRoutePermissionMapExplicitlyCoversImpactPreview(): void
    {
        $match = (new RoutePermissionMap())->match(
            'GET',
            '/api/payroll/rulesets/cz-payroll-2026.income-tax.v1/impact-preview',
        );

        self::assertNotNull($match);
        self::assertSame(RoutePermissionMap::PERMISSION, $match->kind);
        self::assertSame('payroll.rulesets', $match->key);
        self::assertSame(AccessLevel::READ, $match->minimum);
    }

    private function request(string $authMethod, string $role): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest(
                'GET',
                '/api/payroll/rulesets/cz-payroll-2026.income-tax.v1/impact-preview',
            )
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => $role])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod);
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
