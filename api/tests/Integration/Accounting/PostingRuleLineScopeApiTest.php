<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\PostingRuleAction;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * GET /posting-rules nese u každé předkontace příznak použitelnosti na řádku přijaté
 * a vydané faktury; z celé seedované sady jsou to jen pravidla faktur příslušného směru.
 */
#[Group('integration')]
final class PostingRuleLineScopeApiTest extends BankPostingTestCase
{
    public function testListMarksOnlyInvoiceRulesOfGivenDirection(): void
    {
        $res = $this->callAction($this->container->get(PostingRuleAction::class), 'list', 'GET', 'accountant');
        self::assertSame(200, $res['status']);

        $purchase = [];
        $sale = [];
        foreach ($res['body'] as $key => $rule) {
            self::assertArrayHasKey('line_purchase', $rule);
            if ($rule['line_purchase']) $purchase[] = $key;
            if ($rule['line_sale']) $sale[] = $key;
        }
        sort($purchase);
        sort($sale);
        self::assertSame([
            'invoice.dhm.received', 'invoice.material.received', 'invoice.services.received',
            'invoice.small_asset.received', 'invoice.small_intangible.received',
        ], $purchase);
        self::assertSame([
            'invoice.goods.issued', 'invoice.penalty.issued', 'invoice.products.issued', 'invoice.services.issued',
        ], $sale);
    }
}
