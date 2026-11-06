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
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Regrese k BUG 1 (vendor bugreport 2026-08-06): člen skupinové registrace
 * DPH má vlastní registraci podle IČO zaniklou (vstupem do skupiny) — ARES tedy dle
 * IČO vrátí neplátce/ZANIKLY, přestože subjekt fakturuje s DPH jako člen skupiny.
 * Poznat jde podle DIČ na dokladu: DIČ skupiny (typicky `CZ699xxxxxx`) nemá číselnou
 * část rovnou IČO. VendorVatPayerResolver v tom případě ARES negativum nesmí brát
 * jako konečné a musí doověřit přes CRPDPH (routing je uvnitř ViesClient::lookup()).
 *
 * Test běží offline — ARES i CRPDPH odpovídají z mockované DB cache (žádné síťové
 * volání), viz stejný vzor jako ViesClientCzRoutingTest.
 */
final class VendorVatPayerResolverGroupRegistrationTest extends TestCase
{
    /** Connection s mockovaným PDO, jehož SELECT ... cache dotaz vrátí daný payload (nebo false = cache miss). */
    private function connectionWithCachedRow(Config $config, ?array $payload): Connection
    {
        $stmt = $this->createStub(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn(
            $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : false
        );
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $connection = new Connection($config);
        $ref = new \ReflectionProperty(Connection::class, 'pdo');
        $ref->setValue($connection, $pdo);

        return $connection;
    }

    private function baseConfig(): Config
    {
        // Endpointy záměrně prázdné — pokud by test omylem sáhl na síť, spadne
        // do měkkého 'error' (viz ViesClientCzRoutingTest), nikdy real I/O.
        return new Config([
            'ares'   => ['api' => '', 'timeout' => 5, 'cache_ttl' => 86400],
            'vies'   => ['rest_api' => '', 'wsdl' => '', 'timeout' => 8, 'cache_ttl' => 86400],
            'crpdph' => ['endpoint' => '', 'timeout' => 8, 'cache_ttl' => 86400],
        ]);
    }

    private function clientRepositoryStub(): ClientRepository
    {
        // ClientRepository je final (nejde mockovat standardním createMock/MockBuilder).
        // resolve() (na rozdíl od resolveAndPersist()) do repository vůbec nesahá,
        // takže stačí reálná instance nad nepoužitým mockovaným PDO.
        return new ClientRepository($this->connectionWithCachedRow($this->baseConfig(), null));
    }

    /**
     * ARES: IČO nalezeno, ZANIKLY (is_vat_payer=false). DIČ na dokladu je skupinové
     * (CZ699xxxxxx), číselná část ≠ IČO. CRPDPH: nalezen jako plátce.
     * → resolver MUSÍ vrátit is_vat_payer=true (ne ARES negativum).
     */
    public function testGroupRegistrationMemberIsResolvedAsPayerViaCrpDph(): void
    {
        $config = $this->baseConfig();

        $aresConn = $this->connectionWithCachedRow($config, [
            'normalization_version' => AresClient::NORMALIZATION_VERSION,
            'found' => true,
            'data'  => [
                'company_name' => 'Test Group Member s.r.o.',
                'ic'            => '69900012',
                'dic'           => 'CZ699000123',
                'is_vat_payer'  => false, // ZANIKLY vlastní registrace vstupem do skupiny
            ],
        ]);
        $ares = new AresClient($config, $aresConn, new NullLogger());

        $crpdphConn = $this->connectionWithCachedRow($config, [
            'found'      => true, // nalezen v registru plátců DPH = plátce
            'unreliable' => false,
            'accounts'   => [],
            'fu_code'    => '',
        ]);
        $crpdph = new CrpDphClient($config, $crpdphConn, new NullLogger());

        // Vlastní VIES connection se pro skupinové DIČ nepoužije (routing na CRPDPH
        // proběhne dřív, než ViesClient sáhne na svou cache) — stačí prázdná cache.
        $viesConn = $this->connectionWithCachedRow($config, null);
        $vies = new ViesClient($config, $viesConn, new NullLogger(), $ares, $crpdph);

        $resolver = new VendorVatPayerResolver($ares, $vies, $this->clientRepositoryStub());

        $result = $resolver->resolve('69900012', 'CZ699000123');

        self::assertTrue($result['is_vat_payer'], 'Skupinový plátce se nesmí vytěžit jako neplátce.');
        self::assertSame('vies', $result['source']);
    }

    /**
     * Bez skupinového DIČ (číselná část DIČ = IČO, běžná vlastní registrace) se
     * ARES negativum bere jako konečné — CRPDPH/VIES se vůbec nevolá. Ověřeno
     * mockem ClientRepository i tím, že offline endpointy by při skutečném volání
     * spadly do 'error', výsledek by ale i tak zůstal false/'ares' (assertováno níže).
     */
    public function testRealNonPayerWithMatchingDicIsNotOverridden(): void
    {
        $config = $this->baseConfig();

        $aresConn = $this->connectionWithCachedRow($config, [
            'normalization_version' => AresClient::NORMALIZATION_VERSION,
            'found' => true,
            'data'  => [
                'company_name' => 'Test Real Nonpayer s.r.o.',
                'ic'            => '69900012',
                'dic'           => 'CZ69900012',
                'is_vat_payer'  => false,
            ],
        ]);
        $ares = new AresClient($config, $aresConn, new NullLogger());

        $crpdph = new CrpDphClient($config, $this->connectionWithCachedRow($config, null), new NullLogger());
        $vies = new ViesClient($config, $this->connectionWithCachedRow($config, null), new NullLogger(), $ares, $crpdph);

        $resolver = new VendorVatPayerResolver($ares, $vies, $this->clientRepositoryStub());

        $result = $resolver->resolve('69900012', 'CZ69900012');

        self::assertFalse($result['is_vat_payer']);
        self::assertSame('ares', $result['source']);
    }

    /**
     * ARES pozitivní výsledek je vždy konečný, i kdyby DIČ na dokladu mělo tvar
     * skupinové registrace (např. subjekt sám je "hlava" skupiny s vlastním DIČ
     * skupiny a zároveň aktivní vlastní registrací) — netřeba dovolávat CRPDPH.
     */
    public function testArespositivePayerShortCircuitsRegardlessOfDicShape(): void
    {
        $config = $this->baseConfig();

        $aresConn = $this->connectionWithCachedRow($config, [
            'normalization_version' => AresClient::NORMALIZATION_VERSION,
            'found' => true,
            'data'  => [
                'company_name' => 'Test Active Payer s.r.o.',
                'ic'            => '69900012',
                'dic'           => 'CZ699000123',
                'is_vat_payer'  => true,
            ],
        ]);
        $ares = new AresClient($config, $aresConn, new NullLogger());

        $crpdph = new CrpDphClient($config, $this->connectionWithCachedRow($config, null), new NullLogger());
        $vies = new ViesClient($config, $this->connectionWithCachedRow($config, null), new NullLogger(), $ares, $crpdph);

        $resolver = new VendorVatPayerResolver($ares, $vies, $this->clientRepositoryStub());

        $result = $resolver->resolve('69900012', 'CZ699000123');

        self::assertTrue($result['is_vat_payer']);
        self::assertSame('ares', $result['source']);
    }

    /**
     * Issue #8 — doklad nese OBĚ DIČ: „DIČ: CZ<IČO>" (subjekt) i „DIČ k DPH: CZ699xxxxxx"
     * (skupina). Extrakce vrátí do `dic` DIČ subjektu (na něm stojí párování karty
     * dodavatele) a skupinové DIČ zvlášť ve `vat_dic`. Pro registr plátců je rozhodující
     * to DRUHÉ — bez něj by krok 1 viděl „DIČ = CZ + IČO", vzal ARES negativum jako
     * konečné a doklad by se vytěžil jako od neplátce (nulová DPH, žádný odpočet).
     */
    public function testVatDicFromDocumentDecidesRegistryLookupWhileDicStaysEntity(): void
    {
        $config = $this->baseConfig();

        $aresConn = $this->connectionWithCachedRow($config, [
            'normalization_version' => AresClient::NORMALIZATION_VERSION,
            'found' => true,
            'data'  => [
                'company_name' => 'Test Group Member s.r.o.',
                'ic'            => '69900012',
                'dic'           => 'CZ69900012',
                'is_vat_payer'  => false, // vlastní registrace ZANIKLA vstupem do skupiny
            ],
        ]);
        $ares = new AresClient($config, $aresConn, new NullLogger());

        $crpdph = new CrpDphClient($config, $this->connectionWithCachedRow($config, [
            'found'      => true,
            'unreliable' => false,
            'accounts'   => [],
            'fu_code'    => '',
        ]), new NullLogger());
        $vies = new ViesClient($config, $this->connectionWithCachedRow($config, null), new NullLogger(), $ares, $crpdph);

        $resolver = new VendorVatPayerResolver($ares, $vies, $this->clientRepositoryStub());

        // dic = DIČ subjektu (CZ + IČO), vat_dic = skupinové DIČ z popisku „DIČ k DPH".
        $result = $resolver->resolve('69900012', 'CZ69900012', 'CZ699000123');

        self::assertTrue(
            $result['is_vat_payer'],
            'DIČ k DPH z dokladu musí rozhodnout dotaz do registru — jinak doklad vyjde jako od neplátce.',
        );
        self::assertSame('vies', $result['source']);
    }

    /**
     * Prázdné / chybějící `vat_dic` nesmí nic měnit — doklad s jedním DIČ se chová
     * PŘESNĚ jako dosud (ARES negativum u shodného DIČ je konečné).
     */
    public function testMissingVatDicKeepsPreviousBehaviour(): void
    {
        $config = $this->baseConfig();

        $aresConn = $this->connectionWithCachedRow($config, [
            'normalization_version' => AresClient::NORMALIZATION_VERSION,
            'found' => true,
            'data'  => [
                'company_name' => 'Test Real Nonpayer s.r.o.',
                'ic'            => '69900012',
                'dic'           => 'CZ69900012',
                'is_vat_payer'  => false,
            ],
        ]);
        $ares = new AresClient($config, $aresConn, new NullLogger());
        $crpdph = new CrpDphClient($config, $this->connectionWithCachedRow($config, null), new NullLogger());
        $vies = new ViesClient($config, $this->connectionWithCachedRow($config, null), new NullLogger(), $ares, $crpdph);

        $resolver = new VendorVatPayerResolver($ares, $vies, $this->clientRepositoryStub());

        foreach ([null, '', '   '] as $vatDic) {
            $result = $resolver->resolve('69900012', 'CZ69900012', $vatDic);
            self::assertFalse($result['is_vat_payer'], 'vat_dic=' . var_export($vatDic, true));
            self::assertSame('ares', $result['source'], 'vat_dic=' . var_export($vatDic, true));
        }
    }

    /**
     * DIČ skupinové registrace, ale CRPDPH nedostupný/bez nálezu → konzervativní
     * fallback na ARES negativum (nezhoršuje dnešní stav, nevrací "unknown").
     */
    public function testGroupDicFallsBackToAresNegativeWhenCrpDphUnavailable(): void
    {
        $config = $this->baseConfig(); // crpdph.endpoint = '' → CRPDPH vrátí source='error'

        $aresConn = $this->connectionWithCachedRow($config, [
            'normalization_version' => AresClient::NORMALIZATION_VERSION,
            'found' => true,
            'data'  => [
                'company_name' => 'Test Group Member s.r.o.',
                'ic'            => '69900012',
                'dic'           => 'CZ699000123',
                'is_vat_payer'  => false,
            ],
        ]);
        $ares = new AresClient($config, $aresConn, new NullLogger());

        $crpdph = new CrpDphClient($config, $this->connectionWithCachedRow($config, null), new NullLogger());
        $vies = new ViesClient($config, $this->connectionWithCachedRow($config, null), new NullLogger(), $ares, $crpdph);

        $resolver = new VendorVatPayerResolver($ares, $vies, $this->clientRepositoryStub());

        $result = $resolver->resolve('69900012', 'CZ699000123');

        self::assertFalse($result['is_vat_payer']);
        self::assertSame('ares', $result['source']);
    }
}
