<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Logbook\OdometerSource;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSourceException;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSourceVault;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OdometerSourceVaultTest extends TestCase
{
    private OdometerSourceVault $vault;

    protected function setUp(): void
    {
        $this->vault = new OdometerSourceVault(new SecretEncryption(new Config(['app' => [
            'pepper' => 'synthetic-pepper', 'secret_encryption_key' => base64_encode(str_repeat('k', 32)),
        ]])));
    }

    public function testCredentialsRoundTripAndAreNotReadable(): void
    {
        $sealed = $this->vault->sealCredentials(1, 2, 'fake_source', ['token' => 'synthetic-secret', 'expires_at' => 99]);

        self::assertStringStartsWith('enc:v2:', $sealed);
        self::assertStringNotContainsString('synthetic-secret', $sealed);
        self::assertSame(['token' => 'synthetic-secret', 'expires_at' => 99], $this->vault->openCredentials(1, 2, 'fake_source', $sealed));
    }

    /** @return iterable<string, array{int, int, string}> */
    public static function foreignBindings(): iterable
    {
        yield 'jiný dodavatel' => [9, 2, 'fake_source'];
        yield 'jiné napojení' => [1, 9, 'fake_source'];
        yield 'jiný zdroj' => [1, 2, 'other_source'];
    }

    #[DataProvider('foreignBindings')]
    public function testCredentialsAreBoundToSupplierConnectionAndSource(int $supplierId, int $connectionId, string $provider): void
    {
        $sealed = $this->vault->sealCredentials(1, 2, 'fake_source', ['token' => 'synthetic-secret']);

        $this->expectExceptionObject(new OdometerSourceException(OdometerSourceException::REAUTH_REQUIRED, 'Uložený přístup ke zdroji je neplatný, připojte auto znovu.'));
        $this->vault->openCredentials($supplierId, $connectionId, $provider, $sealed);
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function invalidData(): iterable
    {
        yield 'prázdná data' => [[]];
        yield 'seznam místo mapy' => [['a', 'b']];
        yield 'vnořené pole' => [['token' => ['x']]];
        yield 'řídicí znak' => [['token' => "a\nb"]];
        yield 'neplatný klíč' => [['Token' => 'x']];
    }

    /** @param array<mixed> $data */
    #[DataProvider('invalidData')]
    public function testOnlyFlatMapOfStringsAndIntsIsSealed(array $data): void
    {
        $this->expectException(OdometerSourceException::class);
        $this->vault->sealCredentials(1, 2, 'fake_source', $data);
    }
}
