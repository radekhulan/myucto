<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RoutePermissionMap;
use PHPUnit\Framework\TestCase;

/**
 * W30 / D-04 + C-01 — silná práva na koncové příkazy mzdového běhu a na
 * uvolnění rezervace období.
 *
 * `close` a `cancel` spadaly pod catch-all `payroll.inputs.write`, tedy pod
 * právo na ZÁPIS MZDOVÝCH VSTUPŮ. Kdo směl opravit odpracované hodiny, směl
 * tím i zapečetit období nebo nevratně zneplatnit schválený běh. Test hlídá,
 * že se catch-all pod ně nevrátí — a zároveň že catch-all dál funguje pro
 * příkazy, které pod něj patřit mají (`lock_inputs`).
 */
final class PayrollRunCommandPermissionContractTest extends TestCase
{
    public function testTerminalRunCommandsDoNotFallUnderTheInputsCatchAll(): void
    {
        $map = new RoutePermissionMap();

        foreach ([
            ['close', 'payroll.approve'],
            ['cancel', 'payroll.reopen'],
            ['approve', 'payroll.approve'],
            ['reopen', 'payroll.reopen'],
            ['post', 'payroll.post'],
            ['prepare_payments', 'payroll.payments'],
            ['mark_paid', 'payroll.payments'],
            ['calculate', 'payroll.calculate'],
            ['review', 'payroll.review'],
        ] as [$command, $permission]) {
            $matched = $map->match(
                'POST',
                '/api/payroll/runs/7/commands/' . $command,
            );
            self::assertNotNull($matched, $command);
            self::assertSame($permission, $matched->key, $command);
            self::assertSame(AccessLevel::WRITE, $matched->minimum, $command);
        }
    }

    /** Catch-all zůstává tam, kam patří — jen už nekryje pečeť a zrušení. */
    public function testInputCommandsStayUnderTheInputsPermission(): void
    {
        $matched = new RoutePermissionMap()->match(
            'POST',
            '/api/payroll/runs/7/commands/lock_inputs',
        );

        self::assertNotNull($matched);
        self::assertSame('payroll.inputs.write', $matched->key);
    }

    /**
     * Uvolnění legacy rezervace období (C-01) je zásah do už zabraného období,
     * ne zápis vstupu — proto `payroll.reopen`, stejné právo jako odemknutí.
     */
    public function testLegacyPeriodReleaseRequiresReopenPermission(): void
    {
        $map = new RoutePermissionMap();

        $read = $map->match('GET', '/api/payroll/periods/2026-07/ownership');
        self::assertNotNull($read);
        self::assertSame('payroll', $read->key);
        self::assertSame(AccessLevel::READ, $read->minimum);

        $release = $map->match(
            'POST',
            '/api/payroll/periods/2026-07/ownership/release-legacy',
        );
        self::assertNotNull($release);
        self::assertSame('payroll.reopen', $release->key);
        self::assertSame(AccessLevel::WRITE, $release->minimum);
    }

    /** Odvolání schválení výmazu je táž agenda jako schválení samo. */
    public function testErasureRevokeIsCoveredByTheErasurePermission(): void
    {
        $matched = new RoutePermissionMap()->match(
            'POST',
            '/api/payroll/retention/erasure/12/revoke',
        );

        self::assertNotNull($matched);
        self::assertSame('payroll.erasure', $matched->key);
        self::assertSame(AccessLevel::WRITE, $matched->minimum);
    }
}
