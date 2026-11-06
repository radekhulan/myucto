<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Service\Ares\AresClient;
use MyInvoice\Service\Ares\CrpDphClient;
use MyInvoice\Service\Ares\VendorVatPayerResolver;
use MyInvoice\Service\Ares\ViesClient;
use MyInvoice\Service\Import\ClientResolver;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Issue #8 — doklad odštěpného závodu nese DVĚ DIČ: „DIČ" subjektu (CZ + IČO) a
 * „DIČ k DPH" skupinové registrace (CZ699xxxxxx). Extrakce je vrací odděleně
 * (`dic` / `vat_dic`) a ClientResolver s nimi musí naložit RŮZNĚ:
 *
 *   - kartu dodavatele dohledá podle IČO / DIČ SUBJEKTU (kdyby se do lookupu dostalo
 *     skupinové DIČ, přestala by se párovat existující karta a vznikla by duplicita),
 *   - plátcovství ověří podle DIČ K DPH (jinak ARES podle IČO vrátí ZANIKLY — vlastní
 *     registrace člena skupiny zaniká vstupem do skupiny — a doklad se vytěží jako
 *     od neplátce, s nulovou DPH a bez nároku na odpočet).
 *
 * Registry běží OFFLINE — odpovědi ARESu i CRPDPH jsou předplněné v cache tabulkách
 * uvnitř transakce (endpointy jsou prázdné, takže bez cache by se jen vrátilo 'error'
 * a žádné síťové volání nevznikne). Data jsou syntetická, transakce se v tearDown
 * rollbackuje.
 */
#[Group('integration')]
final class ClientResolverVatDicTest extends TestCase
{
    use IsolatedSupplierTrait;

    /** Syntetické IČO + DIČ; skupinové DIČ má tvar reálné skupinové registrace, ale vymyšlené číslo. */
    private const IC          = '69900012';
    private const ENTITY_DIC  = 'CZ69900012';
    private const GROUP_DIC   = 'CZ699000123';

    private Connection $db;
    private ClientResolver $resolver;
    private ClientRepository $clients;

    private int $supplierId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db      = $c->get(Connection::class);
            $this->clients = $c->get(ClientRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $offlineConfig = new Config([
            'ares'   => ['api' => '', 'timeout' => 5, 'cache_ttl' => 86400],
            'vies'   => ['rest_api' => '', 'wsdl' => '', 'timeout' => 8, 'cache_ttl' => 86400],
            'crpdph' => ['endpoint' => '', 'timeout' => 8, 'cache_ttl' => 86400],
        ]);
        $ares   = new AresClient($offlineConfig, $this->db, new NullLogger());
        $crpdph = new CrpDphClient($offlineConfig, $this->db, new NullLogger());
        $vies   = new ViesClient($offlineConfig, $this->db, new NullLogger(), $ares, $crpdph);
        $vatPayer = new VendorVatPayerResolver($ares, $vies, $this->clients);
        $this->resolver = new ClientResolver($this->db, $this->clients, $ares, $vies, $vatPayer);

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0) {
            $this->markTestSkipped('Chybí základní data (supplier).');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, "CZK", "CZK", "Kč", "CZK", "CZK", 2, 1, 1)'
        )->execute([$this->supplierId]);

        // ARES podle IČO: subjekt existuje, ale jako plátce NE (vlastní registrace zanikla
        // vstupem do skupiny) — přesně to, co ARES u člena skupiny vrací.
        $this->seedCache('ares_cache', 'ic', self::IC, [
            'normalization_version' => AresClient::NORMALIZATION_VERSION,
            'found' => true,
            'data'  => [
                'company_name' => 'Testovací odštěpný závod s.r.o.',
                'ic'           => self::IC,
                'dic'          => self::ENTITY_DIC,
                'is_vat_payer' => false,
            ],
        ]);
        // Registr plátců DPH: skupinové DIČ nalezeno = plátce.
        $this->seedCache('crpdph_cache', 'dic', '699000123', [
            'found'      => true,
            'unreliable' => false,
            'accounts'   => [],
            'fu_code'    => '',
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    /**
     * Karta dodavatele už existuje (DIČ subjektu) a je označená jako neplátce — tak,
     * jak ji zanechal import PŘED opravou. Nový import téhož dokladu musí:
     *   1) najít TUTO kartu (podle IČO), ne založit duplicitu,
     *   2) překlopit plátcovství na true podle DIČ K DPH z dokladu,
     *   3) nechat `dic` na kartě jako DIČ SUBJEKTU (skupinové ho nesmí přepsat).
     */
    public function testVatDicDecidesVatPayerWhileCardIsMatchedByEntityIdentifiers(): void
    {
        $existingId = $this->clients->create([
            'company_name' => 'Testovací odštěpný závod s.r.o.',
            'ic'            => self::IC,
            'dic'           => self::ENTITY_DIC,
            'street'        => 'Ulice 1',
            'city'          => 'Praha',
            'zip'           => '11000',
        ], $this->supplierId);
        $this->clients->setVatPayer($existingId, false);

        $result = $this->resolver->resolveVendor([
            'company_name' => 'Testovací odštěpný závod s.r.o.',
            'ic'           => self::IC,
            'dic'          => self::ENTITY_DIC,
            'vat_dic'      => self::GROUP_DIC,
        ], $this->supplierId);

        self::assertFalse($result['created'], 'Karta se musí napárovat podle IČO, ne založit znovu.');
        self::assertSame($existingId, $result['id']);
        self::assertTrue(
            $result['is_vat_payer'],
            'DIČ k DPH z dokladu musí rozhodnout plátcovství — jinak doklad vyjde jako od neplátce (nulová DPH).',
        );
        self::assertSame(1, (int) $this->column('is_vat_payer', $existingId), 'Příznak se musí uložit i na kartu.');
        self::assertSame(
            self::ENTITY_DIC,
            (string) $this->column('dic', $existingId),
            'Na kartě musí zůstat DIČ subjektu — skupinové DIČ je jen pro ověření v registru.',
        );
        self::assertSame(1, $this->countCardsByIc(), 'Nesmí vzniknout duplicitní karta dodavatele.');
    }

    /**
     * Kontrolní protiklad k témuž dokladu: BEZ `vat_dic` (tak, jak extrakce vracela
     * dosud) zůstává výsledek dnešní — ARES negativum u shodného DIČ je konečné.
     * Tím je doložené, že rozdíl dělá právě „DIČ k DPH", ne jiná změna v okolí.
     */
    public function testWithoutVatDicTheVendorStillResolvesAsNonPayer(): void
    {
        $existingId = $this->clients->create([
            'company_name' => 'Testovací odštěpný závod s.r.o.',
            'ic'            => self::IC,
            'dic'           => self::ENTITY_DIC,
            'street'        => 'Ulice 1',
            'city'          => 'Praha',
            'zip'           => '11000',
        ], $this->supplierId);

        $result = $this->resolver->resolveVendor([
            'company_name' => 'Testovací odštěpný závod s.r.o.',
            'ic'           => self::IC,
            'dic'          => self::ENTITY_DIC,
        ], $this->supplierId);

        self::assertSame($existingId, $result['id']);
        self::assertFalse($result['is_vat_payer']);
    }

    public function testIcoOnlyGroupMemberUpgradesExistingNonPayer(): void
    {
        $ic = '00000000';
        $this->seedActiveGroupMember($ic, 'Syntetický člen skupiny');
        $existingId = $this->clients->create([
            'company_name' => 'Syntetický člen skupiny',
            'ic' => $ic,
            'dic' => 'CZ00000000',
            'street' => 'Testovací 1', 'city' => 'Vzorov', 'zip' => '00000',
        ], $this->supplierId);
        $this->clients->setVatPayer($existingId, false);

        $result = $this->resolver->resolveVendor(['ic' => $ic], $this->supplierId);

        self::assertFalse($result['created']);
        self::assertSame($existingId, $result['id']);
        self::assertTrue($result['is_vat_payer']);
        self::assertSame(1, (int) $this->column('is_vat_payer', $existingId));
        self::assertSame('CZ699000000', $this->column('dic', $existingId), 'Karta plátce nesmí do KH nést zaniklé vlastní DIČ člena skupiny.');
    }

    public function testGroupMemberKeepsManuallyEnteredForeignDic(): void
    {
        $ic = '00000000';
        $this->seedActiveGroupMember($ic, 'Syntetický člen skupiny');
        $existingId = $this->clients->create([
            'company_name' => 'Syntetický člen skupiny',
            'ic' => $ic,
            'dic' => 'SK1234567890',
            'street' => 'Testovací 1', 'city' => 'Vzorov', 'zip' => '00000',
        ], $this->supplierId);

        $result = $this->resolver->resolveVendor(['ic' => $ic], $this->supplierId);

        self::assertSame($existingId, $result['id']);
        self::assertTrue($result['is_vat_payer']);
        self::assertSame('SK1234567890', $this->column('dic', $existingId), 'Jiné ručně zadané DIČ se nepřepisuje.');
    }

    public function testActiveGroupWithoutGroupDicTakesVatDicFromDocument(): void
    {
        $ic = '00000000';
        $this->seedActiveGroupMember($ic, 'Syntetický člen skupiny', '');

        $result = $this->resolver->resolveVendor(['ic' => $ic, 'dic' => 'CZ00000000', 'vat_dic' => 'CZ699000000'], $this->supplierId);

        self::assertTrue($result['created']);
        self::assertTrue($result['is_vat_payer']);
        self::assertSame('CZ699000000', $this->column('dic', $result['id']), 'Karta plátce nesmí vzniknout bez DIČ, když ho doklad uvádí.');
    }

    public function testDifferentIcosSharingGroupDicRemainSeparateCards(): void
    {
        $ids = [];
        foreach (['00000000', '00000001'] as $ic) {
            $this->seedActiveGroupMember($ic, 'Syntetický člen ' . $ic);
            $result = $this->resolver->resolveVendor(['ic' => $ic, 'dic' => 'CZ699000000'], $this->supplierId);
            self::assertTrue($result['created']);
            self::assertTrue($result['is_vat_payer']);
            self::assertSame('CZ699000000', $this->column('dic', $result['id']));
            $ids[$ic] = $result['id'];
        }
        self::assertNotSame($ids['00000000'], $ids['00000001']);

        foreach ($ids as $ic => $id) {
            $result = $this->resolver->resolveVendor(['ic' => $ic, 'dic' => 'CZ699000000'], $this->supplierId);
            self::assertFalse($result['created']);
            self::assertSame($id, $result['id']);
        }
    }

    /** Cache vzniká stejnou normalizací jako čerstvá odpověď registru, bez HTTP. */
    private function seedActiveGroupMember(string $ic, string $name, string $groupDic = 'CZ699000000'): void
    {
        $ares = new AresClient(new Config([]), $this->db, new NullLogger());
        $data = (new \ReflectionMethod(AresClient::class, 'normalize'))->invoke($ares, [
            'ico' => $ic, 'obchodniJmeno' => $name, 'dicSkDph' => $groupDic,
            'seznamRegistraci' => ['stavZdrojeDph' => 'NEEXISTUJICI', 'stavZdrojeSkDph' => 'AKTIVNI'],
        ]);
        $this->seedCache('ares_cache', 'ic', $ic, [
            'normalization_version' => AresClient::NORMALIZATION_VERSION,
            'found' => true, 'data' => $data,
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function seedCache(string $table, string $keyColumn, string $key, array $payload): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO {$table} ({$keyColumn}, payload, fetched_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = NOW()"
        )->execute([$key, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    }

    private function column(string $name, int $clientId): mixed
    {
        $stmt = $this->db->pdo()->prepare("SELECT {$name} FROM clients WHERE id = ?");
        $stmt->execute([$clientId]);
        return $stmt->fetchColumn();
    }

    private function countCardsByIc(): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM clients WHERE supplier_id = ? AND LPAD(REGEXP_REPLACE(ic, '[^0-9]', ''), 8, '0') = ?"
        );
        $stmt->execute([$this->supplierId, self::IC]);
        return (int) $stmt->fetchColumn();
    }
}
