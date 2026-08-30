<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Reports\StatementNotesService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Příloha k účetní závěrce — § 18 odst. 1 písm. c) ZoÚ, § 39/39a/39b vyhlášky 500/2002.
 *
 * Matice účetnictví vedla položku jako CHYBÍ a byl to nález s vysokým rizikem: „závěrka
 * bez přílohy není úplná; potvrzuje to i její absence v `ClosingPackageService::ALL_PARTS`,
 * který § 18 sám cituje."
 *
 * Jádrem je ODSTUPŇOVANÝ rozsah: mikro jednotku by úplný výčet zavalil, velké by ho
 * neúplný výčet zastřel. A povinný audit vytahuje § 39a i u menší jednotky, takže se
 * rozsah nesmí odvozovat jen z kategorie — to hlídá
 * {@see testAuditObligationPullsSection39aEvenForSmallEntity()}.
 */
#[Group('integration')]
final class StatementNotesTest extends TestCase
{
    private const YEAR = 2095;

    private Connection $db;
    private StatementNotesService $notes;
    private AccountingPeriodRepository $periods;
    private int $supplierId = 0;
    private int $periodId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db      = $c->get(Connection::class);
            $this->notes   = $c->get(StatementNotesService::class);
            $this->periods = $c->get(AccountingPeriodRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        if ($pdo->query("SHOW TABLES LIKE 'statement_notes'")->fetch() === false) {
            $this->markTestSkipped('Migrace 1154 neproběhla.');
        }
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($czId === 0 || $currencyId === 0 || $vatRateId === 0) {
            $this->markTestSkipped('Chybí základní data.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, dic,
                                   default_currency_id, default_vat_rate_id)
             VALUES (?, "Účetní 5", "Praha", "11000", ?, ?, "12345678", "CZ12345678", ?, ?)'
        )->execute(['Příloha s.r.o.', $czId, 'priloha@example.com', $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
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

    /** Bez auditu a bez velikosti se vyžadují jen sekce § 39. */
    public function testMicroEntityGetsOnlySection39(): void
    {
        $scopes = $this->notes->build($this->supplierId, $this->periodId)['scopes'];

        self::assertSame([StatementNotesService::SCOPE_ALL], $scopes);
    }

    /**
     * Povinný audit vytahuje § 39a i u malé jednotky. Odvozovat rozsah jen z kategorie
     * by u auditované malé firmy povinné údaje tiše vynechalo.
     */
    public function testAuditObligationPullsSection39aEvenForSmallEntity(): void
    {
        $this->setAudit(true);

        $scopes = $this->notes->build($this->supplierId, $this->periodId)['scopes'];

        self::assertContains(StatementNotesService::SCOPE_AUDITED, $scopes);
        self::assertNotContains(StatementNotesService::SCOPE_LARGE, $scopes, '§ 39b je jen pro velké.');
    }

    /** Velká jednotka dostane všechny tři úrovně. */
    public function testLargeEntityGetsAllScopes(): void
    {
        self::assertSame(
            [StatementNotesService::SCOPE_ALL, StatementNotesService::SCOPE_AUDITED, StatementNotesService::SCOPE_LARGE],
            StatementNotesService::scopesFor('large', false),
        );
    }

    /** Střední jednotka spadá pod § 39a i bez auditu. */
    public function testMediumEntityGetsSection39a(): void
    {
        self::assertContains(
            StatementNotesService::SCOPE_AUDITED,
            StatementNotesService::scopesFor('medium', false),
        );
    }

    /** Nevyplněné sekce se hlásí jako neúplnost — bez toho by závěrka prošla jako hotová. */
    public function testMissingSectionsAreReported(): void
    {
        $r = $this->notes->build($this->supplierId, $this->periodId);

        self::assertFalse($r['complete']);
        self::assertContains('accounting_principles', $r['missing']);
        self::assertContains('subsequent_events', $r['missing']);
    }

    /** Základní údaje o jednotce se předvyplní — účetní je nemá přepisovat ručně. */
    public function testEntityIdentificationIsPrefilled(): void
    {
        $sections = array_column($this->notes->build($this->supplierId, $this->periodId)['sections'], null, 'key');

        self::assertTrue($sections['entity_identification']['filled']);
        self::assertStringContainsString('Příloha s.r.o.', (string) $sections['entity_identification']['content']);
        self::assertStringContainsString('12345678', (string) $sections['entity_identification']['content']);
        self::assertNotContains('entity_identification', $this->notes->build($this->supplierId, $this->periodId)['missing']);
    }

    /** Uložený text se vrátí zpět a sekce přestane chybět. */
    public function testSavedSectionIsReturnedAndNoLongerMissing(): void
    {
        $this->notes->saveSection($this->supplierId, self::YEAR, 'accounting_principles', 'Účtujeme podle ZoÚ.', null);

        $r = $this->notes->build($this->supplierId, $this->periodId);
        $sections = array_column($r['sections'], null, 'key');

        self::assertSame('Účtujeme podle ZoÚ.', $sections['accounting_principles']['content']);
        self::assertNotContains('accounting_principles', $r['missing']);
    }

    /**
     * Ruční text přebíjí předvyplnění. Přepsat účetní automatikou by byla tichá ztráta
     * upřesnění, které vědomě zadala.
     */
    public function testManualContentOverridesPrefill(): void
    {
        $this->notes->saveSection($this->supplierId, self::YEAR, 'entity_identification', 'Vlastní znění.', null);

        $sections = array_column($this->notes->build($this->supplierId, $this->periodId)['sections'], null, 'key');

        self::assertSame('Vlastní znění.', $sections['entity_identification']['content']);
    }

    /** Vyprázdnění sekce ji vrátí mezi chybějící (a smaže záznam). */
    public function testClearingSectionMakesItMissingAgain(): void
    {
        $this->notes->saveSection($this->supplierId, self::YEAR, 'accounting_principles', 'Text.', null);
        $this->notes->saveSection($this->supplierId, self::YEAR, 'accounting_principles', '   ', null);

        self::assertContains('accounting_principles', $this->notes->build($this->supplierId, $this->periodId)['missing']);
    }

    /** Neznámý klíč sekce se odmítne — jinak by v tabulce zůstal text, který nikdo nezobrazí. */
    public function testUnknownSectionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->notes->saveSection($this->supplierId, self::YEAR, 'vymyslena_sekce', 'x', null);
    }

    /** Příloha je vázaná na ROK — jiný rok má vlastní obsah. */
    public function testNotesAreScopedToFiscalYear(): void
    {
        $this->notes->saveSection($this->supplierId, self::YEAR, 'accounting_principles', 'Rok ' . self::YEAR, null);
        $otherPeriod = $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');

        $sections = array_column($this->notes->build($this->supplierId, $otherPeriod)['sections'], null, 'key');

        self::assertNull($sections['accounting_principles']['content']);
    }

    /** Vyplnění všech povinných sekcí přílohu uzavře. */
    public function testFillingEverythingMarksComplete(): void
    {
        foreach ($this->notes->build($this->supplierId, $this->periodId)['missing'] as $key) {
            $this->notes->saveSection($this->supplierId, self::YEAR, $key, 'Doplněno.', null);
        }

        $r = $this->notes->build($this->supplierId, $this->periodId);
        self::assertTrue($r['complete']);
        self::assertSame([], $r['missing']);
    }

    private function setAudit(bool $audited): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_supplier_settings (supplier_id, statutory_audit)
             VALUES (?, ?) ON DUPLICATE KEY UPDATE statutory_audit = VALUES(statutory_audit)'
        )->execute([$this->supplierId, $audited ? 1 : 0]);
    }
    /**
     * Zmražená příloha se už NEMĚNÍ se změnou firmy.
     *
     * Automaticky předvyplněné sekce (název, sídlo, IČO, kategorie ÚJ) se braly
     * z AKTUÁLNÍHO `supplier`, ne ze stavu k rozvahovému dni. Na ostrých datech byl
     * proto výstup pro 2024, 2025 i 2026 bitově shodný, přestože jde o tři různé
     * závěrky — a příloha schváleného roku se měnila, kdykoli firma změnila název
     * nebo sídlo. Příloha je součástí účetní závěrky a ta se po schválení měnit nesmí.
     */
    public function testFrozenNotesDoNotFollowLaterCompanyChanges(): void
    {
        $before = array_column($this->notes->build($this->supplierId, $this->periodId)['sections'], null, 'key');
        self::assertStringContainsString('Příloha s.r.o.', (string) $before['entity_identification']['content']);

        $frozen = $this->notes->freezeAutoValues($this->supplierId, self::YEAR, $this->periodId, null);
        self::assertGreaterThan(0, $frozen, 'Zmrazit se má aspoň identifikace a kategorie.');

        $this->db->pdo()->prepare('UPDATE supplier SET company_name = ? WHERE id = ?')
            ->execute(['Přejmenovaná s.r.o.', $this->supplierId]);

        $after = array_column($this->notes->build($this->supplierId, $this->periodId)['sections'], null, 'key');

        self::assertStringContainsString(
            'Příloha s.r.o.',
            (string) $after['entity_identification']['content'],
            'Zmražená příloha drží stav k rozvahovému dni.',
        );
        self::assertStringNotContainsString('Přejmenovaná', (string) $after['entity_identification']['content']);
    }

    /** Ručně vyplněný text zmražení NEPŘEPÍŠE — účetní ho mohla vědomě upřesnit. */
    public function testFreezingDoesNotOverwriteManualText(): void
    {
        $this->notes->saveSection($this->supplierId, self::YEAR, 'entity_identification', 'Vlastní znění.', null);

        $this->notes->freezeAutoValues($this->supplierId, self::YEAR, $this->periodId, null);

        $sections = array_column($this->notes->build($this->supplierId, $this->periodId)['sections'], null, 'key');
        self::assertSame('Vlastní znění.', $sections['entity_identification']['content']);
    }

    /**
     * Převzetí loňské přílohy. Vlastní text se nikdy nepřebije a převzatá sekce si
     * nese rok původu, dokud ji účetní neuloží jako svou — loňská věta může být
     * letos nepravdivá a příloha je součástí účetní závěrky.
     */
    public function testCarryOverFillsOnlyEmptySectionsAndMarksThem(): void
    {
        $this->notes->saveSection($this->supplierId, self::YEAR - 1, 'accounting_principles', 'Loňské zásady.', null);
        $this->notes->saveSection($this->supplierId, self::YEAR - 1, 'valuation_methods', 'Loňské oceňování.', null);
        $this->notes->saveSection($this->supplierId, self::YEAR, 'valuation_methods', 'Letos jinak.', null);

        $result = $this->notes->carryOverFromPreviousYear($this->supplierId, self::YEAR, null);
        $sections = array_column($this->notes->build($this->supplierId, $this->periodId)['sections'], null, 'key');

        self::assertSame(['accounting_principles'], $result['copied']);
        self::assertSame(self::YEAR - 1, $result['source_year']);
        self::assertSame('Loňské zásady.', $sections['accounting_principles']['content']);
        self::assertSame(self::YEAR - 1, $sections['accounting_principles']['carried_over_from_year']);
        // Vlastní letošní text zůstal a převzatý příznak nedostal.
        self::assertSame('Letos jinak.', $sections['valuation_methods']['content']);
        self::assertNull($sections['valuation_methods']['carried_over_from_year']);
    }

    /** Uložením účetní převzatý text potvrdí jako svůj — příznak zmizí. */
    public function testSavingCarriedOverSectionClearsTheMark(): void
    {
        $this->notes->saveSection($this->supplierId, self::YEAR - 1, 'accounting_principles', 'Loňské zásady.', null);
        $this->notes->carryOverFromPreviousYear($this->supplierId, self::YEAR, null);

        $this->notes->saveSection($this->supplierId, self::YEAR, 'accounting_principles', 'Letošní znění.', null);
        $sections = array_column($this->notes->build($this->supplierId, $this->periodId)['sections'], null, 'key');

        self::assertNull($sections['accounting_principles']['carried_over_from_year']);
    }

    /** Nabídka převzetí se hlásí jen tehdy, když je co převzít. */
    public function testCarryOverOfferCountsOnlyMissingSections(): void
    {
        self::assertSame(0, $this->notes->build($this->supplierId, $this->periodId)['carry_over']['available']);

        $this->notes->saveSection($this->supplierId, self::YEAR - 1, 'accounting_principles', 'Loňské zásady.', null);

        $r = $this->notes->build($this->supplierId, $this->periodId);
        self::assertSame(1, $r['carry_over']['available']);
        self::assertSame(self::YEAR - 1, $r['carry_over']['source_year']);
    }
}