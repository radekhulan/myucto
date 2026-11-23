<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Pair;

use MyInvoice\Action\Accounting\JournalTemplateAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/** Šablony ručního zápisu po dvojicích: uložení `pairs`, kontroly kritéria b, složená šablona beze změny. */
#[Group('integration')]
final class TemplatePairsTest extends PairsTestCase
{
    public function testTemplateSavedFromPairsKeepsPairNumbersAndText(): void
    {
        $created = $this->call('create', 'POST', ['name' => 'Leasing', 'pairs' => [
            ['debit_account_code' => '518', 'credit_account_code' => '321', 'amount' => 10000, 'line_text' => 'Splátka'],
            ['debit_account_code' => '343.100', 'credit_account_code' => '321', 'amount' => 2100],
        ]]);

        self::assertSame(201, $created['status']);
        self::assertSame(
            [['518', 'debit', 1, 'Splátka'], ['321', 'credit', 1, 'Splátka'], ['343.100', 'debit', 2, null], ['321', 'credit', 2, null]],
            array_map(static fn (array $l): array => [$l['account_code'], $l['side'], $l['pair_no'] ?? null, $l['line_text'] ?? null], $created['body']['lines']),
        );
    }

    public function testInvalidPairsAreRejectedWithPairCodes(): void
    {
        $cases = [
            'pair_missing_debit_account' => [['debit_account_code' => '', 'credit_account_code' => '321']],
            'pair_missing_credit_account' => [['debit_account_code' => '518', 'credit_account_code' => ' ']],
            'pair_same_account' => [['debit_account_code' => '518', 'credit_account_code' => '518']],
        ];
        foreach ($cases as $code => $pairs) {
            $result = $this->call('create', 'POST', ['name' => 'Chybná', 'pairs' => $pairs]);
            self::assertSame([422, $code], [$result['status'], $result['body']['error']['code'] ?? $result['body']['code'] ?? null], $code);
        }
        $mixed = $this->call('create', 'POST', ['name' => 'Smíšená', 'lines' => [
            ['account_code' => '518', 'side' => 'debit', 'pair_no' => 1],
            ['account_code' => '321', 'side' => 'credit'],
        ]]);
        self::assertSame(422, $mixed['status']);
    }

    /** Šablona bez dvojic: odpověď bez nových klíčů, jako dosud. */
    public function testCompositeTemplateResponseIsUnchanged(): void
    {
        $created = $this->call('create', 'POST', ['name' => 'Složená', 'lines' => [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 100],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 100],
        ]]);

        self::assertSame(201, $created['status']);
        self::assertSame(
            ['line_no', 'label', 'account_code', 'side', 'default_amount', 'cost_center', 'dimensions'],
            array_keys($created['body']['lines'][0]),
        );
    }

    /** @param array<string,mixed> $body @return array{status:int, body:array<string,mixed>} */
    private function call(string $method, string $httpMethod, array $body): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($httpMethod, '/api/accounting/journal-templates')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody($body);
        $response = $this->container->get(JournalTemplateAction::class)->{$method}($request, new Psr7Response());
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
