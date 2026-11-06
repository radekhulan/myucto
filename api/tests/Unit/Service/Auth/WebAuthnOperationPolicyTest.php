<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Auth;

use MyInvoice\Http\RequestPath;
use MyInvoice\Service\Auth\WebAuthnOperationPolicy;
use PHPUnit\Framework\TestCase;

final class WebAuthnOperationPolicyTest extends TestCase
{
    public function testEveryInventoriedOperationMatchesItsMethodAndExamplePath(): void
    {
        $policy = new WebAuthnOperationPolicy();
        $inventory = $policy->inventory();

        self::assertCount(14, $inventory);
        self::assertCount(14, array_unique(array_map(
            static fn (array $operation): string => $operation['method'] . ' ' . $operation['route_pattern'],
            $inventory,
        )));
        self::assertSame(['HEAD'], $inventory['credentials.list']['method_aliases'] ?? null);

        foreach ($inventory as $name => $operation) {
            self::assertSame(
                $name,
                $policy->operationFor($operation['method'], $operation['example_path']),
                $name,
            );
            self::assertTrue(
                $policy->requiresCanonicalOrigin(
                    strtolower($operation['method']),
                    $operation['example_path'],
                ),
                $name,
            );
            foreach ($operation['method_aliases'] ?? [] as $alias) {
                self::assertSame(
                    $name,
                    $policy->operationFor($alias, $operation['example_path']),
                    "$name alias $alias",
                );
            }
        }
    }

    public function testPolicyIsSemanticInsteadOfAWebAuthnPathPrefix(): void
    {
        $policy = new WebAuthnOperationPolicy();

        self::assertSame(
            'login.password_mfa_options',
            $policy->operationFor('POST', '/api/auth/login'),
            'Heslový login může založit WebAuthn MFA challenge, i když URL nemá webauthn prefix.',
        );
        self::assertSame(
            'session_unlock.options',
            $policy->operationFor('POST', '/api/auth/session/unlock/options'),
        );
        self::assertSame(
            'session_unlock.verify',
            $policy->operationFor('POST', '/api/auth/session/unlock/verify'),
        );

        foreach ([
            ['GET', '/api/auth/login'],
            ['GET', '/api/auth/webauthn/register/options'],
            ['POST', '/api/auth/webauthn/register/options/extra'],
            ['POST', '/api/auth/webauthn/status'],
            ['PATCH', '/api/auth/webauthn/credentials/not-an-id'],
            ['POST', '/api/auth/session/unlock'],
            ['POST', '/api/auth/totp/setup'],
            ['POST', '/api/auth/mfa/step-up/totp'],
        ] as [$method, $path]) {
            self::assertNull($policy->operationFor($method, $path), "$method $path");
        }
    }

    public function testNormalizedPathAliasesReachTheSameSemanticOperation(): void
    {
        $policy = new WebAuthnOperationPolicy();

        foreach ([
            '/api/auth/session/%75nlock/options',
            '/api//auth/session/unlock/options',
            '/api/auth/x/../session/unlock/options',
        ] as $path) {
            self::assertSame(
                'session_unlock.options',
                $policy->operationFor('POST', RequestPath::normalize($path)),
                $path,
            );
        }
        self::assertSame(
            'registration.verify',
            $policy->operationFor(
                'POST',
                RequestPath::normalize('/api/auth/%77ebauthn/register/verify'),
            ),
        );
    }
}
