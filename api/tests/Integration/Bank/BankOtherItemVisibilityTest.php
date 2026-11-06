<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Action\Bank\ClientBankAccess;
use MyInvoice\Bootstrap;
use MyInvoice\Middleware\LicenseMiddleware;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\License\LicenseState;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/** Vazba pohybu na ostatní položku v bezplatném výpisu banky se řídí právem i licencí. */
#[Group('integration')]
final class BankOtherItemVisibilityTest extends TestCase
{
    public function testLinkIsKeptOnlyWithPermissionAndLicense(): void
    {
        $access = Bootstrap::buildContainer()->get(ClientBankAccess::class);
        $rows = [['id' => 1, 'matched_other_items' => [['other_item_id' => 9]]]];
        $request = static fn (array $permissions) => (new ServerRequestFactory())->createServerRequest('GET', '/api/bank-transactions')
            ->withAttribute('auth.effective_role', new EffectiveRole(9, 'Synthetic staff', 'staff', true, $permissions));

        self::assertSame($rows, $access->redactTransactions($request(['bank' => 1, 'other_items' => 1]), $rows));
        self::assertSame([], $access->redactTransactions($request(['bank' => 1]), $rows)[0]['matched_other_items']);

        $free = $request(['bank' => 1, 'other_items' => 1])->withAttribute(LicenseMiddleware::ATTR_STATE, new LicenseState(LicenseState::ACTIVE, 'synthetic', null, null, 0, 0, 0, null, null, null, null, null, false, commercial: false));
        self::assertSame([], $access->redactTransactions($free, $rows)[0]['matched_other_items']);
    }
}
