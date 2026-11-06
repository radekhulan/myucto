<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Ares;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Service\Ares\AresClient;
use MyInvoice\Service\Ares\CrpDphClient;
use MyInvoice\Service\Ares\VendorVatPayerResolver;
use MyInvoice\Service\Ares\ViesClient;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Syntetický člen skupiny: testy jsou bez sítě a používají pouze mockované cache. */
final class AresGroupVatRegistrationTest extends TestCase
{
    private const IC = '00000000';
    private const OWN_DIC = 'CZ00000000';
    private const GROUP_DIC = 'CZ699000000';

    private array $rows = [];

    private function config(): Config
    {
        return new Config([
            'ares' => ['api' => '', 'cache_ttl' => 86400],
            'vies' => ['rest_api' => '', 'wsdl' => '', 'cache_ttl' => 86400],
            'crpdph' => ['endpoint' => '', 'cache_ttl' => 86400],
        ]);
    }

    /** PDO uchovává skutečně zapsané JSON payloady, aby test prošel i opakovaným čtením. */
    private function connection(): Connection
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql): PDOStatement {
            preg_match('/(?:FROM|INTO) (ares_cache|vies_cache|crpdph_cache)/', $sql, $match);
            self::assertNotEmpty($match, $sql);
            $table = $match[1];
            $params = [];
            $stmt = $this->createStub(PDOStatement::class);
            $stmt->method('execute')->willReturnCallback(function (array $values) use (&$params, $table, $sql): bool {
                $params = $values;
                if (str_starts_with($sql, 'INSERT')) {
                    $this->rows[$table][$values[0]] = json_decode($values[array_key_last($values)], true);
                }
                return true;
            });
            $stmt->method('fetchColumn')->willReturnCallback(function () use (&$params, $table): string|false {
                $row = $this->rows[$table][$params[0]] ?? null;
                return $row !== null ? json_encode($row) : false;
            });
            return $stmt;
        });
        $connection = new Connection($this->config());
        (new \ReflectionProperty(Connection::class, 'pdo'))->setValue($connection, $pdo);
        return $connection;
    }

    private function ares(): AresClient
    {
        return new AresClient($this->config(), $this->connection(), new NullLogger());
    }

    private function vies(AresClient $ares): ViesClient
    {
        return new ViesClient(
            $this->config(), $this->connection(), new NullLogger(), $ares,
            new CrpDphClient($this->config(), $this->connection(), new NullLogger()),
        );
    }

    private function raw(array $overrides = []): array
    {
        return array_replace([
            'ico' => self::IC,
            'obchodniJmeno' => 'Syntetický člen skupiny',
            'dicSkDph' => self::GROUP_DIC,
            'seznamRegistraci' => ['stavZdrojeDph' => 'NEEXISTUJICI', 'stavZdrojeSkDph' => 'AKTIVNI'],
        ], $overrides);
    }

    private function normalize(array $raw): array
    {
        return (new \ReflectionMethod(AresClient::class, 'normalize'))->invoke($this->ares(), $raw);
    }

    private function cacheAres(array $data): AresClient
    {
        $ares = $this->ares();
        (new \ReflectionMethod(AresClient::class, 'cache'))->invoke($ares, self::IC, ['found' => true, 'data' => $data]);
        return $ares;
    }

    public static function ownRegistrations(): array
    {
        return [
            'bez vlastního DIČ' => [null, 'NEEXISTUJICI'],
            'vlastní DIČ zaniklé' => [self::OWN_DIC, 'ZANIKLY'],
            'vlastní DIČ aktivní' => [self::OWN_DIC, 'AKTIVNI'],
        ];
    }

    #[DataProvider('ownRegistrations')]
    public function testActiveGroupProvidesEffectiveDicAndPayerStatus(?string $ownDic, string $ownStatus): void
    {
        $data = $this->normalize($this->raw([
            'dic' => $ownDic,
            'seznamRegistraci' => ['stavZdrojeDph' => $ownStatus, 'stavZdrojeSkDph' => 'AKTIVNI'],
        ]));
        self::assertSame(self::GROUP_DIC, $data['dic']);
        self::assertTrue($data['is_vat_payer']);
    }

    public function testInactiveGroupDoesNotOverrideIndividualRegistration(): void
    {
        $data = $this->normalize($this->raw([
            'dic' => self::OWN_DIC,
            'seznamRegistraci' => ['stavZdrojeDph' => 'AKTIVNI', 'stavZdrojeSkDph' => 'ZANIKLY'],
        ]));
        self::assertSame(self::OWN_DIC, $data['dic']);
        self::assertTrue($data['is_vat_payer']);
    }

    public function testInactiveGroupDoesNotTurnNonPayerIntoPayer(): void
    {
        $data = $this->normalize($this->raw([
            'seznamRegistraci' => ['stavZdrojeDph' => 'NEEXISTUJICI', 'stavZdrojeSkDph' => 'ZANIKLY'],
        ]));
        self::assertSame('', $data['dic']);
        self::assertFalse($data['is_vat_payer']);
    }

    public function testIndividualPayerWithoutGroupKeepsOwnDic(): void
    {
        $data = $this->normalize([
            'ico' => self::IC, 'dic' => self::OWN_DIC,
            'seznamRegistraci' => ['stavZdrojeDph' => 'AKTIVNI'],
        ]);
        self::assertSame(self::OWN_DIC, $data['dic']);
        self::assertTrue($data['is_vat_payer']);
        self::assertTrue($this->vies($this->cacheAres($data))->lookup(self::OWN_DIC)['valid']);
    }

    public function testActiveGroupWithoutDicKeepsPayerEvidenceButDoesNotValidateOwnDic(): void
    {
        $data = $this->normalize($this->raw(['dic' => self::OWN_DIC, 'dicSkDph' => null]));
        self::assertTrue($data['is_vat_payer']);
        self::assertSame('', $data['dic']);
        self::assertFalse($this->vies($this->cacheAres($data))->lookup(self::OWN_DIC)['valid']);
    }

    public function testActiveIndividualDicRemainsUsableWhenActiveGroupDicIsMissing(): void
    {
        $data = $this->normalize($this->raw([
            'dic' => self::OWN_DIC, 'dicSkDph' => '',
            'seznamRegistraci' => ['stavZdrojeDph' => 'AKTIVNI', 'stavZdrojeSkDph' => 'AKTIVNI'],
        ]));
        self::assertTrue($data['is_vat_payer']);
        self::assertSame(self::OWN_DIC, $data['dic']);
        self::assertTrue($this->vies($this->cacheAres($data))->lookup(self::OWN_DIC)['valid']);
    }

    public function testIcoOnlyMemberIsResolvedAsPayerAfterCacheRoundTrip(): void
    {
        $ares = $this->cacheAres($this->normalize($this->raw()));
        $resolver = new VendorVatPayerResolver($ares, $this->vies($ares), new ClientRepository($this->connection()));
        self::assertSame(['is_vat_payer' => true, 'source' => 'ares', 'group_dic' => self::GROUP_DIC], $resolver->resolve(self::IC, null));
        $cached = $ares->lookup(self::IC);
        self::assertSame('cache', $cached['source']);
        self::assertSame(self::GROUP_DIC, $cached['data']['dic']);
        self::assertArrayNotHasKey('normalization_version', $cached);
    }

    public function testOwnDicIsNotValidatedByGroupPayerEvidence(): void
    {
        $ares = $this->cacheAres(['dic' => self::GROUP_DIC, 'is_vat_payer' => true]);
        $result = $this->vies($ares)->lookup(self::OWN_DIC);
        self::assertFalse($result['valid']);
        self::assertSame('ares', $result['source']);
    }

    public function testLegacyAresPositiveCacheIsBypassedOnOutageAndReplacedByCurrentNormalization(): void
    {
        $this->rows['ares_cache'][self::IC] = ['found' => true, 'data' => ['dic' => '', 'is_vat_payer' => false]];
        // Endpoint je vypnutý: místo zastaralého neplátce musí přijít nezjištěno.
        self::assertNull($this->ares()->lookup(self::IC));
        $ares = $this->cacheAres($this->normalize($this->raw()));
        self::assertTrue($ares->lookup(self::IC)['data']['is_vat_payer']);
        self::assertSame(self::GROUP_DIC, $ares->lookup(self::IC)['data']['dic']);
    }

    public function testLegacyAresNegativeCacheRemainsUsable(): void
    {
        $this->rows['ares_cache'][self::IC] = ['found' => false];
        self::assertSame(['found' => false, 'source' => 'cache'], $this->ares()->lookup(self::IC));
    }

    public function testLegacyAresDerivedViesCacheCannotValidateExpiredOwnDic(): void
    {
        $ares = $this->cacheAres(['dic' => self::GROUP_DIC, 'is_vat_payer' => true]);
        $this->rows['vies_cache'][self::OWN_DIC] = ['valid' => true, 'source' => 'ares'];
        $vies = $this->vies($ares);
        $result = $vies->lookup(self::OWN_DIC);
        self::assertFalse($result['valid']);
        self::assertSame('ares', $result['source']);
        self::assertSame(['valid' => false, 'source' => 'cache'], array_intersect_key($vies->lookup(self::OWN_DIC), ['valid' => true, 'source' => true]));
        self::assertArrayNotHasKey('normalization_version', $vies->lookup(self::OWN_DIC));
    }

    public function testLegacyAresDerivedViesCacheDoesNotBecomeHardNegativeOnOutage(): void
    {
        $this->rows['ares_cache'][self::IC] = ['found' => true, 'data' => ['is_vat_payer' => false]];
        $this->rows['vies_cache'][self::OWN_DIC] = ['valid' => false, 'source' => 'ares'];
        $ares = $this->ares();
        $vies = $this->vies($ares);
        self::assertSame(['valid' => false, 'source' => 'error'], $vies->lookup(self::OWN_DIC));
        $resolver = new VendorVatPayerResolver($ares, $vies, new ClientRepository($this->connection()));
        self::assertSame(['is_vat_payer' => null, 'source' => 'unknown'], $resolver->resolveAndPersist(1, self::IC, self::OWN_DIC));
    }

    public function testUnrelatedViesCacheIsPreserved(): void
    {
        $this->rows['vies_cache']['SK0000000000'] = ['valid' => true, 'source' => 'rest'];
        self::assertSame(['valid' => true, 'source' => 'cache'], $this->vies($this->ares())->lookup('SK0000000000'));
    }
}
