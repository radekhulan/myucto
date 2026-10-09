<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\VatCoefficientRepository;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Validation\XmlSchemaValidator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * C2' (audit 2026-07, vat) — krácený nárok na odpočet koeficientem § 76 ZDPH.
 *
 * Pokrývá:
 *   (a) krácený PF bez nastaveného koeficientu → tvrdá chyba (žádný tichý default),
 *   (b) krácený PF s koeficientem 80 % → plná daň ve sloupci „Krácený odpočet" (ř.40
 *       odp_tuz23), ř.52 odp_uprav_kf = 80 % z něj, ř.63 odp_zocelk sedí,
 *   (c) běžný „full" PF beze změny (regrese proti sloupci „V plné výši" odp_tuz23_nar),
 *   (d) RC + reduced současně → tvrdá chyba (ř.43 nemá krácený protějšek),
 *   (e) prosincové roční vypořádání (§ 76 odst. 7) z celoročních dat — vypořádací
 *       koeficient + vypor_odp na rozdíl mezi ročním krácením a Σ zálohově uplatněného,
 *   (f) XSD validace vygenerovaného XML s krácenými řádky proti dphdp3.xsd.
 *
 * Izolovaný rok 2094 pod existujícím supplierem (vynucen plátce), úklid v tearDown.
 */
#[Group('integration')]
final class VatReducedDeductionTest extends TestCase
{
    private const YEAR = 2094;

    private Connection $db;
    private DphPriznaniBuilder $dph;
    private VatCoefficientRepository $coef;
    private ?XmlSchemaValidator $validator = null;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $deId = 0;

    /** @var array{customers:int[], vendors:int[]} */
    private array $clientIds = ['customers' => [], 'vendors' => []];
    /** @var int[] */
    private array $invoiceIds = [];
    /** @var int[] */
    private array $purchaseIds = [];
    /** @var int[] */
    private array $tempClsfIds = [];
    private ?array $origVatFlags = null;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db   = $container->get(Connection::class);
            $this->dph  = $container->get(DphPriznaniBuilder::class);
            $this->coef = $container->get(VatCoefficientRepository::class);
            $this->validator = $container->get(XmlSchemaValidator::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = $this->countryId('CZ');
        $this->deId = $this->countryId('DE');

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0
            || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        // Scénáře předpokládají plátce DPH (typ P s odpočty). Vynutit a v tearDown vrátit.
        $flags = $pdo->query("SELECT is_vat_payer, is_identified FROM supplier WHERE id = {$this->supplierId}")
            ->fetch(\PDO::FETCH_ASSOC) ?: [];
        $this->origVatFlags = $flags;
        $pdo->prepare('UPDATE supplier SET is_vat_payer = 1, is_identified = 0 WHERE id = ?')
            ->execute([$this->supplierId]);

        // Čistý start pro rok — smaž případné zbytky koeficientů z předchozího běhu.
        $pdo->prepare('DELETE FROM vat_coefficients WHERE supplier_id = ? AND year BETWEEN 2090 AND 2099')
            ->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($this->origVatFlags !== null && $this->supplierId > 0) {
            $pdo->prepare('UPDATE supplier SET is_vat_payer = ?, is_identified = ? WHERE id = ?')
                ->execute([
                    (int) ($this->origVatFlags['is_vat_payer'] ?? 1),
                    (int) ($this->origVatFlags['is_identified'] ?? 0),
                    $this->supplierId,
                ]);
        }
        foreach ($this->invoiceIds as $id) {
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->purchaseIds as $id) {
            $pdo->prepare('DELETE FROM purchase_invoice_items WHERE purchase_invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        foreach (array_merge($this->clientIds['customers'], $this->clientIds['vendors']) as $id) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        $pdo->prepare('DELETE FROM vat_coefficients WHERE supplier_id = ? AND year BETWEEN 2090 AND 2099')
            ->execute([$this->supplierId]);
        foreach ($this->tempClsfIds as $id) {
            $pdo->prepare('DELETE FROM vat_classifications WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    /** (a) Krácený PF bez koeficientu → PostingException 'vat_coefficient_missing'. */
    public function testReducedDeductionWithoutCoefficientThrows(): void
    {
        $vend = $this->client('Dodavatel §76', $this->czId, 'CZ90000010', vendor: true);
        // BEZ nastaveného koeficientu pro rok.
        $this->purchase('R-A', $vend, '40', false, 'invoice', $this->d(6, 10), $this->d(6, 10),
            [[10000, 2100, 21]], 'reduced');

        try {
            $this->dph->build($this->supplierId, self::YEAR, 6, 'monthly');
            $this->fail('Očekávána chyba: krácený odpočet bez koeficientu.');
        } catch (PostingException $e) {
            $this->assertSame('vat_coefficient_missing', $e->errorCode, $e->getMessage());
        }
    }

    /** (b) Krácený PF s koeficientem 80 %: plná daň v „Krácený odpočet", ř.52 = 80 %. */
    public function testReducedDeductionAppliesProvisionalCoefficient(): void
    {
        $this->coef->setProvisionalPercent($this->supplierId, self::YEAR, 80);
        $vend = $this->client('Dodavatel §76', $this->czId, 'CZ90000028', vendor: true);
        $this->purchase('R-B', $vend, '40', false, 'invoice', $this->d(6, 10), $this->d(6, 10),
            [[10000, 2100, 21]], 'reduced');

        $xml = $this->buildXml(6);
        $v4 = $xml->DPHDP3->Veta4;
        $v5 = $xml->DPHDP3->Veta5;
        $v6 = $xml->DPHDP3->Veta6;

        // ř.40: základ v pln23, PLNÁ daň ve sloupci „Krácený odpočet" (odp_tuz23), NE _nar.
        $this->assertSame('10000', (string) $v4['pln23']);
        $this->assertSame('2100', (string) $v4['odp_tuz23']);
        $this->assertSame('', (string) $v4['odp_tuz23_nar'], 'krácený odpočet nesmí být ve sloupci V plné výši');
        // ř.46: krácený součet 2100, plný součet 0.
        $this->assertSame('2100', (string) $v4['odp_sum_kr']);
        $this->assertSame('0', (string) $v4['odp_sum_nar']);
        // ř.52: koeficient 80 %, odpočet = round(2100 × 80/100) = 1680.
        $this->assertSame('80', (string) $v5['koef_p20_nov']);
        $this->assertSame('1680', (string) $v5['odp_uprav_kf']);
        // ř.53 se v NEposledním období nevykazuje.
        $this->assertSame('', (string) $v5['vypor_odp']);
        // ř.63 = 0 (V plné výši) + 1680 (ř.52) + 0 (ř.53).
        $this->assertSame('1680', (string) $v6['odp_zocelk']);
    }

    /** (c) Běžný „full" PF beze změny — daň ve sloupci „V plné výši" (regrese). */
    public function testFullDeductionUnchanged(): void
    {
        $vend = $this->client('Dodavatel plný', $this->czId, 'CZ90000036', vendor: true);
        $this->purchase('R-C', $vend, '40', false, 'invoice', $this->d(6, 10), $this->d(6, 10),
            [[10000, 2100, 21]], 'full');

        $xml = $this->buildXml(6);
        $v4 = $xml->DPHDP3->Veta4;
        $v6 = $xml->DPHDP3->Veta6;

        $this->assertSame('10000', (string) $v4['pln23']);
        $this->assertSame('2100', (string) $v4['odp_tuz23_nar'], 'plný odpočet ve sloupci V plné výši');
        $this->assertSame('', (string) $v4['odp_tuz23'], 'plný odpočet NESMÍ jít do kráceného sloupce');
        $this->assertSame('2100', (string) $v4['odp_sum_nar']);
        $this->assertSame('', (string) $v4['odp_sum_kr'], 'bez krácených řádků není odp_sum_kr');
        $this->assertSame('2100', (string) $v6['odp_zocelk']);
        // Veta5 se nevytvoří (žádná osvobozená ani krácená plnění).
        $this->assertSame(0, $xml->DPHDP3->Veta5->count());
    }

    /**
     * (d) RC + krácený odpočet § 76: samovyměření na výstupu (ř. 10) v plné výši, zrcadlový
     * odpočet ř. 43 ve sloupci „Krácený odpočet" (odkr_zdp23 — XSD ho má), krácení
     * koeficientem souhrnně na ř. 52 jako u tuzemského ř. 40k.
     */
    public function testReverseChargeWithReducedGoesToReducedColumnOfRow43(): void
    {
        $this->coef->setProvisionalPercent($this->supplierId, self::YEAR, 80);
        $vend = $this->client('Dodavatel RC', $this->czId, 'CZ90000044', vendor: true);
        $this->purchase('R-D', $vend, '5', true, 'invoice', $this->d(6, 10), $this->d(6, 10),
            [[10000, 0, 21]], 'reduced');

        $xml = $this->buildXml(6);
        $v1 = $xml->DPHDP3->Veta1;
        $v4 = $xml->DPHDP3->Veta4;
        $v5 = $xml->DPHDP3->Veta5;

        $this->assertSame('10000', (string) $v1['rez_pren23']);
        $this->assertSame('2100', (string) $v1['dan_rpren23'], 'samovyměření na výstupu v plné výši');
        $this->assertSame('10000', (string) $v4['nar_zdp23']);
        $this->assertSame('', (string) $v4['od_zdp23'], 'krácený odpočet NESMÍ jít do sloupce V plné výši');
        $this->assertSame('2100', (string) $v4['odkr_zdp23']);
        $this->assertSame('2100', (string) $v4['odp_sum_kr']);
        $this->assertSame('80', (string) $v5['koef_p20_nov']);
        $this->assertSame('1680', (string) $v5['odp_uprav_kf']);
    }

    /**
     * (e) Prosincové roční vypořádání (§ 76 odst. 7). Data celého roku:
     *   výstup ř.1 = 80 000 (s nárokem), ř.50 = 20 000 (osvobozeno bez nároku)
     *     → vypořádací koeficient = 80 000 / 100 000 = 80 %.
     *   krácené PF: červen daň 2100, prosinec daň 2100 → roční krácený odpočet 4200.
     *   zálohový koeficient 70 % → Σ uplatněno = round(2100×0,7)×2 = 2940.
     *   annualAtFinal = round(4200 × 80/100) = 3360 → vypor_odp = 3360 − 2940 = 420.
     */
    public function testYearEndSettlementRecomputesCoefficient(): void
    {
        $this->coef->setProvisionalPercent($this->supplierId, self::YEAR, 70);

        $cust = $this->client('Odběratel', $this->czId, 'CZ90000052', customer: true);
        $vend = $this->client('Dodavatel §76', $this->czId, 'CZ90000060', vendor: true);

        // Výstup s nárokem (ř.1) + osvobozené bez nároku (ř.50) — čitatel/jmenovatel koeficientu.
        $this->sale('S-1', $cust, '1', false, $this->d(3, 10), $this->d(3, 10), [[80000, 16800, 21]]);
        $this->sale('S-50', $cust, '3', false, $this->d(3, 11), $this->d(3, 11), [[20000, 0, 0]]);

        // Krácené přijaté plnění: červen + prosinec, každé daň 2100.
        $this->purchase('R-E1', $vend, '40', false, 'invoice', $this->d(6, 10), $this->d(6, 10),
            [[10000, 2100, 21]], 'reduced');
        $this->purchase('R-E2', $vend, '40', false, 'invoice', $this->d(12, 10), $this->d(12, 10),
            [[10000, 2100, 21]], 'reduced');

        $result = $this->dph->build($this->supplierId, self::YEAR, 12, 'monthly');
        $xml = new \SimpleXMLElement($result['xml']);
        $v5 = $xml->DPHDP3->Veta5;
        $v6 = $xml->DPHDP3->Veta6;

        // Prosincové období: vlastní krácený odpočet + zálohový koeficient.
        $this->assertSame('70', (string) $v5['koef_p20_nov']);
        $this->assertSame('1470', (string) $v5['odp_uprav_kf'], 'prosinec: round(2100 × 0,7)');
        // Roční vypořádání: vypořádací koeficient 80 %, vypor_odp = 420.
        $this->assertSame('80', (string) $v5['koef_p20_vypor']);
        $this->assertSame('420', (string) $v5['vypor_odp']);
        // ř.63 = 0 (V plné výši) + 1470 (ř.52) + 420 (ř.53).
        $this->assertSame('1890', (string) $v6['odp_zocelk']);

        // Summary — vypořádací koeficient ze skutečných dat.
        $s = $result['summary']['vat_settlement'];
        $this->assertNotNull($s);
        $this->assertSame(80, $s['final_percent']);
        $this->assertSame(80000, $s['numerator']);
        $this->assertSame(100000, $s['denominator']);
        $this->assertSame(420.0, $s['vypor_odp']);

        // Celoroční kontrola: Σ uplatněných ř.52 (červen 1470 + prosinec 1470) + vypor_odp(420)
        // = 3360 = roční krácený odpočet vypořádacím koeficientem (round(4200 × 0,8)).
        $this->assertSame(3360.0, 1470.0 + 1470.0 + 420.0);
    }

    /** (f) XML s krácenými řádky (§76) projde XSD validací dphdp3.xsd. */
    public function testReducedDeclarationPassesXsd(): void
    {
        if ($this->validator === null || !$this->validator->hasSchema('dphdp3')) {
            $this->markTestSkipped('XSD schema dphdp3.xsd není k dispozici.');
        }
        $this->coef->setProvisionalPercent($this->supplierId, self::YEAR, 70);
        $cust = $this->client('Odběratel', $this->czId, 'CZ90000079', customer: true);
        $vend = $this->client('Dodavatel §76', $this->czId, 'CZ90000087', vendor: true);
        $this->sale('S-X1', $cust, '1', false, $this->d(12, 5), $this->d(12, 5), [[80000, 16800, 21]]);
        $this->sale('S-X50', $cust, '3', false, $this->d(12, 6), $this->d(12, 6), [[20000, 0, 0]]);
        $this->purchase('R-X', $vend, '40', false, 'invoice', $this->d(12, 10), $this->d(12, 10),
            [[10000, 2100, 21]], 'reduced');

        $result = $this->dph->build($this->supplierId, self::YEAR, 12, 'monthly');
        $validation = $this->validator->validate($result['xml'], 'dphdp3');
        $this->assertSame(
            'passed',
            $validation['status'],
            "XSD validace selhala:\n  - " . implode("\n  - ", $validation['errors']),
        );
    }

    /** M44: prodej dlouhodobého majetku zůstane na ř.1 a současně se vyloučí přes ř.51. */
    public function testFixedAssetSaleIsExcludedFromAnnualCoefficientAndExportedOnLine51(): void
    {
        $cust = $this->client('Kupující majetku', $this->czId, 'CZ90000159', customer: true);

        $this->sale('S-M44-REG', $cust, '1', false, $this->d(12, 2), $this->d(12, 2), [[50000, 10500, 21]]);
        $this->sale('S-M44-ASSET', $cust, '1m', false, $this->d(12, 3), $this->d(12, 3), [[100000, 21000, 21]]);
        $this->sale('S-M44-EXEMPT', $cust, '3', false, $this->d(12, 4), $this->d(12, 4), [[50000, 0, 0]]);
        $this->sale('S-M44-EXCLUDED', $cust, '3m', false, $this->d(12, 5), $this->d(12, 5), [[30000, 0, 0]]);

        $coef = $this->dph->computeAnnualCoefficient($this->supplierId, self::YEAR);
        $this->assertSame(50, $coef['final_percent']);
        $this->assertSame(50000, $coef['numerator'], 'prodej majetku 100 000 Kč se musí odečíst z čitatele');
        $this->assertSame(100000, $coef['denominator']);

        $result = $this->dph->build($this->supplierId, self::YEAR, 12, 'monthly');
        $this->assertSame(0.0, (float) $result['summary']['lines']['51']['vat'], 'ř.51 je pouze doplňující základ bez daně');
        $xml = new \SimpleXMLElement($result['xml']);
        $this->assertSame('150000', (string) $xml->DPHDP3->Veta1['obrat23'], 'prodej majetku zůstává na ř.1');
        $this->assertSame('100000', (string) $xml->DPHDP3->Veta5['pln_nkf'], 'prodej majetku se současně vykáže na ř.51');
        $this->assertSame('80000', (string) $xml->DPHDP3->Veta5['plnosv_kf'], 'ř.50 obsahuje všechna osvobozená plnění');
        $this->assertSame('30000', (string) $xml->DPHDP3->Veta5['plnosv_nkf'], 'vyloučené osvobozené plnění je na ř.51 bez nároku');

        if ($this->validator !== null && $this->validator->hasSchema('dphdp3')) {
            $validation = $this->validator->validate($result['xml'], 'dphdp3');
            $this->assertSame('passed', $validation['status'], implode("\n", $validation['errors']));
        }
    }

    /**
     * § 76 odst. 5: koeficient se zaokrouhluje na celé procento nahoru, takže podíl, který
     * vyjde přesně na celé procento, se nezaokrouhluje. 7 000 / 100 000 je ve float
     * 7.000000000000001 a holé ceil() z toho udělalo 8 %.
     */
    public function testAnnualCoefficientExactPercentIsNotRoundedUp(): void
    {
        $cust = $this->client('Odběratel koef', $this->czId, 'CZ90000167', customer: true);
        $this->sale('S-K7-1', $cust, '1', false, $this->d(12, 2), $this->d(12, 2), [[7000, 1470, 21]]);
        $this->sale('S-K7-50', $cust, '3', false, $this->d(12, 3), $this->d(12, 3), [[93000, 0, 0]]);

        $coef = $this->dph->computeAnnualCoefficient($this->supplierId, self::YEAR);
        $this->assertSame(7000, $coef['numerator']);
        $this->assertSame(100000, $coef['denominator']);
        $this->assertSame(7, $coef['final_percent'], '7 000 / 100 000 = přesně 7 %, ne 8 %');
    }

    /**
     * (g) Zaokrouhlení kráceného odpočtu je PER ŘÁDEK (40k/41k/42k zvlášť), shodně na
     * formuláři (ř.46/52 v build()) i ve vypořádání (kr_year, Σ uplatněných ř.52). Sum-then-round
     * by v období s víc krácenými sazbovými buckety rozešel podaný ř.52 s vypořádacím základem.
     *
     * Prosinec (poslední období), dva krácené buckety s haléřovými součty:
     *   ř.40 (21 %) krácená daň 100,50 · ř.41 (12 %) krácená daň 200,50.
     *   Per-line:      round(100,50)+round(200,50) = 101+201 = 302  → ř.46 odp_sum_kr, ř.52 základ.
     *   (Sum-then-round by dalo round(301,00) = 301 → o 1 Kč jinam než reálně podaný ř.52.)
     *   zálohový koeficient 100 % → ř.52 odp_uprav_kf = 302.
     *   vypořádací koeficient 50 % (výstup s nárokem 50 000 / (50 000 + 50 000 osvob.)).
     *   annualAtFinal = round(302 × 50/100) = 151 → vypor_odp = 151 − 302 = −151.
     *   Identita: Σ podaných ř.52 (302) + ř.53 (−151) = 151 = round(roční krácený 302 × 50 %).
     *   (Se starým sum-then-round: kr_year 301, appliedSum 301 → vypor_odp = round(301×0,5)−301
     *    = 151−301 = −150; pak 302+(−150) = 152 ≠ 151 → přeplacený odpočet o 1 Kč.)
     */
    public function testReducedDeductionRoundingIsPerLineAcrossBuckets(): void
    {
        $this->coef->setProvisionalPercent($this->supplierId, self::YEAR, 100);

        $cust = $this->client('Odběratel', $this->czId, 'CZ90000095', customer: true);
        $vend = $this->client('Dodavatel §76', $this->czId, 'CZ90000108', vendor: true);

        // Výstup s nárokem (ř.1) 50 000 + osvobozené bez nároku (ř.50) 50 000 → vypořádací koef. 50 %.
        $this->sale('S-G1', $cust, '1', false, $this->d(12, 3), $this->d(12, 3), [[50000, 10500, 21]]);
        $this->sale('S-G50', $cust, '3', false, $this->d(12, 4), $this->d(12, 4), [[20000, 0, 0]]);
        // ř.50 musí být 50 000, aby jmenovatel = 100 000. Doplníme druhé osvobozené plnění 30 000.
        $this->sale('S-G50b', $cust, '3', false, $this->d(12, 4), $this->d(12, 4), [[30000, 0, 0]]);

        // Dva krácené buckety s haléřovými součty (prosinec).
        $this->purchase('R-G40', $vend, '40', false, 'invoice', $this->d(12, 10), $this->d(12, 10),
            [[1000, 100.50, 21]], 'reduced');
        $this->purchase('R-G41', $vend, '41', false, 'invoice', $this->d(12, 11), $this->d(12, 11),
            [[2000, 200.50, 12]], 'reduced');

        $result = $this->dph->build($this->supplierId, self::YEAR, 12, 'monthly');
        $xml = new \SimpleXMLElement($result['xml']);
        $v4 = $xml->DPHDP3->Veta4;
        $v5 = $xml->DPHDP3->Veta5;
        $v6 = $xml->DPHDP3->Veta6;

        // ř.46 krácený součet = per-line round (101+201), NE sum-then-round (301).
        $this->assertSame('302', (string) $v4['odp_sum_kr']);
        // ř.52 zálohově uplatněno (koef. 100 %) = 302.
        $this->assertSame('100', (string) $v5['koef_p20_nov']);
        $this->assertSame('302', (string) $v5['odp_uprav_kf']);
        // ř.53 vypořádání: vypořádací koef. 50 %, vypor_odp = −151 (starý kód dával −150).
        $this->assertSame('50', (string) $v5['koef_p20_vypor']);
        $this->assertSame('-151', (string) $v5['vypor_odp']);
        // ř.63 = 0 (V plné výši) + 302 (ř.52) + (−151) (ř.53) = 151 (starý kód 152).
        $this->assertSame('151', (string) $v6['odp_zocelk']);

        // Identita § 76 odst. 7: Σ podaných ř.52 + ř.53 = round(roční krácený × vypořádací koef.).
        $s = $result['summary']['vat_settlement'];
        $this->assertSame(50, $s['final_percent']);
        $this->assertSame(-151.0, $s['vypor_odp']);
        $this->assertSame(151.0, 302.0 + (-151.0));
    }

    /**
     * (h) 'reduced' na odpočtovém řádku bez kráceného protějšku (ř. 44/45 korekce, ř. 43 mirror
     * mimo RC) NESMÍ tiše propadnout do sloupce „V plné výši". Konzervativní tvrdý stop místo
     * nadhodnoceného nekráceného odpočtu.
     */
    public function testReducedDeductionOnUnsupportedLineThrows(): void
    {
        $this->coef->setProvisionalPercent($this->supplierId, self::YEAR, 80);
        $vend = $this->client('Dodavatel §76', $this->czId, 'CZ90000116', vendor: true);
        // Katalog dnes žádný odpočtový kód mimo 40/41 nemá → guard je defenzivní. Dočasný
        // per-tenant kód na ř.44 (korekce, bez kráceného protějšku) simuluje budoucí rozšíření.
        $code = $this->tempClassification('44');
        $this->purchase('R-H', $vend, $code, false, 'invoice', $this->d(6, 10), $this->d(6, 10),
            [[10000, 2100, 21]], 'reduced');

        try {
            $this->dph->build($this->supplierId, self::YEAR, 6, 'monthly');
            $this->fail('Očekávána chyba: krácený odpočet na řádku bez protějšku.');
        } catch (PostingException $e) {
            $this->assertSame('reduced_deduction_unsupported_line', $e->errorCode, $e->getMessage());
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function d(int $month, int $day): string
    {
        return sprintf('%04d-%02d-%02d', self::YEAR, $month, $day);
    }

    private function buildXml(int $month): \SimpleXMLElement
    {
        $result = $this->dph->build($this->supplierId, self::YEAR, $month, 'monthly');
        return new \SimpleXMLElement($result['xml']);
    }

    private function client(string $name, int $countryId, ?string $dic, bool $customer = false, bool $vendor = false): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, ?, "test@example.com", "cs", ?, ?, ?)'
        );
        $stmt->execute([$this->supplierId, $name, $countryId, $dic, $this->currencyId, $customer ? 1 : 0, $vendor ? 1 : 0]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->clientIds[$vendor ? 'vendors' : 'customers'][] = $id;
        return $id;
    }

    /**
     * @param list<array{0:float,1:float,2:float}> $items [base, vat, vat_rate_snapshot]
     */
    private function sale(string $varsymbol, int $clientId, ?string $code, bool $rc, string $issue, string $tax, array $items): void
    {
        [$base, $vat, $with] = $this->sumItems($items);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, ?, ?, ?, ?, "issued", ?, ?)'
        );
        $stmt->execute([
            $this->supplierId, $varsymbol, $clientId, $issue, $tax, $issue,
            $this->currencyId, $rc ? 1 : 0, $base, $vat, $with, $code, $this->userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->invoiceIds[] = $id;
        $this->insertItems('invoice_items', 'invoice_id', $id, $items);
    }

    /**
     * @param list<array{0:float,1:float,2:float}> $items [base, vat, vat_rate_snapshot]
     */
    private function purchase(string $number, int $vendorId, ?string $code, bool $rc, string $kind, string $issue, ?string $tax, array $items, string $vatDeduction = 'full'): void
    {
        [$base, $vat, $with] = $this->sumItems($items);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, exchange_rate, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, "{}", ?, ?, ?, "received", ?, ?, ?)'
        );
        $stmt->execute([
            $this->supplierId, $vendorId, $number, $kind, $issue, $tax, $issue, $issue,
            $this->currencyId, $rc ? 1 : 0, $base, $vat, $with, $code, $vatDeduction, $this->userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->purchaseIds[] = $id;
        $this->insertItems('purchase_invoice_items', 'purchase_invoice_id', $id, $items);
    }

    /**
     * @param list<array{0:float,1:float,2:float}> $items
     * @return array{0:float,1:float,2:float}
     */
    private function sumItems(array $items): array
    {
        $base = 0.0; $vat = 0.0;
        foreach ($items as $it) { $base += $it[0]; $vat += $it[1]; }
        return [$base, $vat, $base + $vat];
    }

    /**
     * @param list<array{0:float,1:float,2:float}> $items
     */
    private function insertItems(string $table, string $fk, int $id, array $items): void
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO {$table}
                ({$fk}, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Test položka', 1, 'ks', ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($items as $i => $it) {
            [$base, $vat, $snapshot] = $it;
            $stmt->execute([$id, $base, $this->vatRateId, $snapshot, $base, $vat, $base + $vat, $i]);
        }
    }

    /** Dočasný per-tenant klasifikační kód na daný dphdp3_line (úklid v tearDown). */
    private function tempClassification(string $dphLine): string
    {
        $code = 'T' . $dphLine;
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO vat_classifications
                (supplier_id, code, label, direction, dphdp3_line, is_reverse_charge, display_order)
             VALUES (?, ?, ?, "purchase", ?, 0, 9990)'
        );
        $stmt->execute([$this->supplierId, $code, 'TEST ř.' . $dphLine, $dphLine]);
        $this->tempClsfIds[] = (int) $this->db->pdo()->lastInsertId();
        return $code;
    }

    private function countryId(string $iso2): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM countries WHERE iso2 = ? LIMIT 1');
        $stmt->execute([$iso2]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }
}
