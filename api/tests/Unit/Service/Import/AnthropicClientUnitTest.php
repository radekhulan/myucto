<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Import\AnthropicClient;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pure-logic testy pro privátní helpery v AnthropicClientu.
 *
 * Pokrývá buildTenantContextBlock — dynamickou hlavičku promptu, která říká AI
 * že tenant je vždy odběratel, nikdy dodavatel. Bez tohoto bloku AI občas
 * zamíchá vendor↔customer u faktur s dominantní hlavičkou dodavatele.
 */
#[AllowMockObjectsWithoutExpectations]
final class AnthropicClientUnitTest extends TestCase
{
    public function testTenantBlock_full_info_includes_name_ic_dic(): void
    {
        $client = $this->makeClient([
            'company_name' => 'Vzorová firma s.r.o.',
            'ic'           => '12345679',
            'dic'          => 'CZ12345679',
        ]);

        $block = $this->invokeBuild($client, 1);
        $this->assertNotEmpty($block);
        $this->assertStringContainsString('Vzorová firma s.r.o.', $block);
        $this->assertStringContainsString('12345679', $block);
        $this->assertStringContainsString('CZ12345679', $block);
        // Klíčové sdělení: tenant je VŽDY odběratel
        $this->assertStringContainsString('VŽDY odběratel', $block);
        $this->assertStringContainsString('NIKDY', $block);
    }

    public function testTenantBlock_only_name_omits_ic_dic(): void
    {
        // Když supplier nemá IČ/DIČ, hint sentence obsahuje jen `název "..."`,
        // BEZ položek `IČO "..."` a `DIČ "..."`. Pozor: statický text promptu
        // pořád obsahuje slovo "IČO" v instrukci "matchuj IČO nebo název" —
        // proto kontrolujeme jen formátování citace s uvozovkou (`IČO "`).
        $client = $this->makeClient([
            'company_name' => 'OSVČ bez identifikace',
            'ic'           => null,
            'dic'          => null,
        ]);

        $block = $this->invokeBuild($client, 1);
        $this->assertStringContainsString('OSVČ bez identifikace', $block);
        $this->assertStringContainsString('pro firmu: název "OSVČ bez identifikace"', $block);
        $this->assertStringNotContainsString('IČO "', $block);
        $this->assertStringNotContainsString('DIČ "', $block);
    }

    public function testTenantBlock_empty_supplier_returns_empty_string(): void
    {
        $client = $this->makeClient([
            'company_name' => '',
            'ic'           => '',
            'dic'          => '',
        ]);
        $this->assertSame('', $this->invokeBuild($client, 1));
    }

    public function testTenantBlock_supplier_not_found_returns_empty_string(): void
    {
        $client = $this->makeClient(false); // fetch vrátí false = řádek neexistuje
        $this->assertSame('', $this->invokeBuild($client, 999));
    }

    public function testTenantBlock_db_error_returns_empty_string_no_exception(): void
    {
        // Když Connection->pdo() hodí, buildTenantContextBlock to musí
        // tiše ošetřit (prompt se vrátí bez tenant hlavičky, AI extrakce
        // proběhne bez kontextu, ale ne kompletně selže).
        $conn = $this->createMock(Connection::class);
        $conn->method('pdo')->willThrowException(new \RuntimeException('DB down'));

        $client = new AnthropicClient(
            $conn,
            $this->createMock(SecretEncryption::class),
            new NullLogger(),
        );
        $this->assertSame('', $this->invokeBuild($client, 1));
    }

    public function testCapabilitiesReuseModelFromCredentialsAndCachePerSupplier(): void
    {
        $stmt = $this->createStub(PDOStatement::class);
        $stmt->method('fetch')->willReturn(['anthropic_default_model' => 'claude-sonnet-5-5']);
        $stmt->method('fetchColumn')->willReturn('claude-opus-5-5');
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::exactly(2))->method('prepare')->willReturn($stmt);
        $conn = $this->createStub(Connection::class);
        $conn->method('pdo')->willReturn($pdo);
        $client = new AnthropicClient($conn, $this->createStub(SecretEncryption::class), new NullLogger());

        self::assertNull($client->getCredentials(1));
        self::assertSame('claude-sonnet-5-5', $client->capabilities(1)->defaultModel);
        self::assertSame('claude-opus-5-5', $client->capabilities(2)->defaultModel);
        self::assertSame('claude-opus-5-5', $client->capabilities(2)->defaultModel);
        self::assertSame('claude-sonnet-5-5', $client->capabilities(1)->defaultModel);
    }

    public function testCapabilitiesReloadAfterChangingTheModelOrCredentials(): void
    {
        $stmt = $this->createStub(PDOStatement::class);
        $stmt->method('fetchColumn')->willReturnOnConsecutiveCalls('claude-haiku-5-5', 'claude-sonnet-5-5', 'claude-opus-5-5');
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::exactly(5))->method('prepare')->willReturn($stmt);
        $conn = $this->createStub(Connection::class);
        $conn->method('pdo')->willReturn($pdo);
        $client = new AnthropicClient($conn, $this->createStub(SecretEncryption::class), new NullLogger());

        self::assertSame('claude-haiku-5-5', $client->capabilities(1)->defaultModel);
        $client->updateDefaultModel(1, 'claude-sonnet-5-5');
        self::assertSame('claude-sonnet-5-5', $client->capabilities(1)->defaultModel);
        $client->setCredentials(1, 'sk-ant-fixture', 'claude-opus-5-5');
        self::assertSame('claude-opus-5-5', $client->capabilities(1)->defaultModel);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed>|false $supplierRow Mock řádek z supplier tabulky,
     *                                               nebo false (fetch nic nenašel)
     */
    private function makeClient(array|false $supplierRow): AnthropicClient
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn($supplierRow);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $conn = $this->createMock(Connection::class);
        $conn->method('pdo')->willReturn($pdo);

        return new AnthropicClient(
            $conn,
            $this->createMock(SecretEncryption::class),
            new NullLogger(),
        );
    }

    private function invokeBuild(AnthropicClient $client, int $supplierId): string
    {
        $ref = new \ReflectionMethod($client, 'buildTenantContextBlock');
        return (string) $ref->invoke($client, $supplierId);
    }
}
