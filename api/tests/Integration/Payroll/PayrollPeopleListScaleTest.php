<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollPeopleRepository;
use MyInvoice\Tests\Fixtures\Payroll\PayrollRunScaleFixture;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Seznam osob musí stát tolik dotazů, kolik má STRÁNKA řádků — ne kolik má
 * firma zaměstnanců.
 *
 * `GET /payroll/people` vracel celý stav zaměstnanců a ke každému řádku počítal
 * rozhodnutí o smazatelnosti, tedy další dotazy na osobu. U firmy se stovkami
 * lidí to byl jeden požadavek za tisíce dotazů. Test hlídá obojí, co se u té
 * opravy dá pokazit: že se strop nedá zvednout parametrem a že lehký výběr do
 * rozbalovátka zůstane na počtu osob NEZÁVISLÝ.
 */
#[Group('integration')]
final class PayrollPeopleListScaleTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollPeopleRepository $people;
    private int $sourceSupplierId;
    private int $supplierId;
    private int $actorId;
    private int $tenantOrdinal = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $db = $container->get(Connection::class);
        $people = $container->get(PayrollPeopleRepository::class);
        if (!$db instanceof Connection || !$people instanceof PayrollPeopleRepository) {
            $this->markTestSkipped('Služby mzdového modulu nejsou dostupné.');
        }
        $this->db = $db;
        $this->people = $people;
        foreach ([
            'payroll_employees',
            'payroll_employee_profiles',
            'payroll_employments',
            'payroll_person_identity_history',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped('Mzdové migrace neproběhly.');
            }
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) $this->scalar('SELECT MIN(id) FROM supplier');
        if ($sourceSupplierId <= 0) {
            $this->markTestSkipped('Chybí zdrojová firma.');
        }
        $this->sourceSupplierId = $sourceSupplierId;
        $pdo->beginTransaction();
        $this->newTenant();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    /**
     * Strop stránky nejde zvednout z URL.
     *
     * Akce sice ořezává limit sama, ale repozitář se na to nesmí spolehnout —
     * proto se sem posílá nesmyslné číslo přímo. `total` přitom musí hlásit
     * VŠECHNY osoby, jinak by stránkování nešlo odklikat do konce.
     */
    public function testPageIsCappedWhileTotalReportsEveryone(): void
    {
        $headcount = PayrollPeopleRepository::LIST_MAX_LIMIT + 5;
        $this->seed($headcount);

        $page = $this->people->listForTenant($this->supplierId, 10_000);

        self::assertCount(
            PayrollPeopleRepository::LIST_MAX_LIMIT,
            $page['items'],
            'Limit z URL nesmí zvednout strop stránky.',
        );
        self::assertSame(
            $headcount,
            $page['total'],
            'Celkový počet musí hlásit všechny osoby, ne jen stránku.',
        );
    }

    /** Offset musí stránku skutečně posunout, ne vrátit tutéž. */
    public function testOffsetShiftsThePage(): void
    {
        $this->seed(12);

        $first = $this->people->listForTenant($this->supplierId, 5, 0);
        $second = $this->people->listForTenant($this->supplierId, 5, 5);

        self::assertCount(5, $first['items']);
        self::assertCount(5, $second['items']);
        self::assertSame(12, $first['total']);
        self::assertSame(12, $second['total']);
        self::assertSame(
            [],
            array_intersect($this->pageIds($first), $this->pageIds($second)),
            'Druhá stránka nesmí obsahovat řádky té první.',
        );
        self::assertSame(
            array_merge($this->pageIds($first), $this->pageIds($second)),
            $this->pageIds($this->people->listForTenant($this->supplierId, 10, 0)),
            'Stránky na sebe musí navazovat ve stejném pořadí jako souvislý výpis.',
        );
    }

    /**
     * Lehký výběr stojí pořád stejně — jeden dotaz, ať má firma osobu, nebo čtyřicet.
     *
     * Rovnost je tvrdší tvrzení než horní mez: chytí i to, kdyby se do výběru
     * vrátil jediný dotaz na osobu, protože ten by u čtyřiceti lidí utekl.
     */
    public function testOptionsQueryCountDoesNotGrowWithHeadcount(): void
    {
        $pdo = $this->db->pdo();
        $counts = [];
        $rows = [];
        foreach ([1, 10, 40] as $headcount) {
            if ($headcount > 1) {
                $this->newTenant();
            }
            $this->seed($headcount);
            $before = PayrollRunScaleFixture::statementRoundTrips($pdo);
            $options = $this->people->listOptionsForTenant($this->supplierId);
            $counts[$headcount] = PayrollRunScaleFixture::statementRoundTrips($pdo) - $before;
            $rows[$headcount] = count($options);
            self::assertSame(
                ['id', 'full_name', 'is_active', 'needs_setup'],
                array_keys($options[0]),
                'Výběr smí nést jen to, co rozbalovátko doopravdy čte.',
            );
            self::assertFalse(
                $options[0]['needs_setup'],
                'Naseedovaná osoba má profil i vztah, takže doplňovat není co.',
            );
        }

        self::assertSame([1 => 1, 10 => 10, 40 => 40], $rows);
        self::assertSame(
            $counts[1],
            $counts[10],
            'Výběr deseti osob smí stát tolik dotazů co výběr jedné.',
        );
        self::assertSame(
            $counts[1],
            $counts[40],
            'Výběr čtyřiceti osob smí stát tolik dotazů co výběr jedné.',
        );
    }

    /**
     * Cena seznamu se řídí velikostí stránky, ne počtem zaměstnanců.
     *
     * Rozhodnutí o smazatelnosti se počítá na řádek, takže úplně bez dotazů na
     * osobu to nebude. Podstatné je, že přibývají jen s velikostí stránky:
     * menší stránka musí být levnější a nábor dalších lidí nesmí cenu stránky
     * o deseti řádcích změnit ani o jeden dotaz.
     */
    public function testListQueryCountFollowsPageSizeNotHeadcount(): void
    {
        $pdo = $this->db->pdo();
        $this->seed(40);
        $smallPage = $this->measure($pdo, 10);
        $fullPage = $this->measure($pdo, 40);

        $this->newTenant();
        $this->seed(60);
        $smallPageBiggerFirm = $this->measure($pdo, 10);

        self::assertLessThan(
            $fullPage,
            $smallPage,
            'Stránka o deseti řádcích musí stát míň dotazů než stránka o čtyřiceti.',
        );
        self::assertSame(
            $smallPage,
            $smallPageBiggerFirm,
            'Nábor dalších lidí nesmí zdražit stránku o deseti řádcích.',
        );
    }

    /**
     * Filtr musí ubrat ze stránky i z `total`.
     *
     * Kdyby platil jen na stránku, pager by hlásil počet neodpovídající tomu,
     * co jde odklikat, a nabízel by stránky, na kterých po zúžení nikdo není.
     */
    public function testActiveFilterNarrowsPageAndTotalTogether(): void
    {
        $this->seed(6);
        $ids = $this->employeeIds();
        $this->exec(
            'UPDATE payroll_employees SET is_active = 0
              WHERE supplier_id = ? AND id IN (?, ?)',
            [$this->supplierId, $ids[0], $ids[1]],
        );

        $all = $this->people->listForTenant($this->supplierId, 100, 0, 'all');
        $active = $this->people->listForTenant($this->supplierId, 100, 0, 'active');

        self::assertSame(6, $all['total']);
        self::assertCount(6, $all['items']);
        self::assertSame(4, $active['total'], 'Filtr musí ubrat i z celkového počtu.');
        self::assertCount(4, $active['items']);
        foreach ($active['items'] as $person) {
            self::assertTrue($person['is_active'], 'Filtr aktivních nesmí pustit odejitého.');
        }
    }

    /**
     * Filtr „k doplnění" se musí shodovat s příznakem `needs_setup` na řádku.
     *
     * Dva různé důvody, tentýž závěr: chybějící údaj profilu a žádný pracovní
     * vztah. Kdyby filtr četl jen jeden z nich, seznam by nabízel osobu
     * s výstražným štítkem, kterou by pak sám nenašel.
     *
     * Mezera se dělá SMAZÁNÍM bydliště, ne přepnutím `profile_status` — ten je
     * ručně nastavovaný a příznak se z něj schválně neodvozuje.
     */
    public function testNeedsSetupFilterAgreesWithTheFlagOnTheRow(): void
    {
        $this->seed(6);
        $ids = $this->employeeIds();
        $this->exec(
            'DELETE FROM payroll_person_addresses
              WHERE supplier_id = ? AND employee_id = ?',
            [$this->supplierId, $ids[0]],
        );
        $loneId = $this->employeeWithoutEmployment('Osoba bez vztahu');

        $all = $this->people->listForTenant($this->supplierId, 100, 0, 'all');
        $flagged = array_values(array_map(
            static fn (array $person): int => (int) $person['id'],
            array_filter(
                $all['items'],
                static fn (array $person): bool => $person['needs_setup'] === true,
            ),
        ));
        $needsSetup = $this->people->listForTenant($this->supplierId, 100, 0, 'needs_setup');

        self::assertSame(7, $all['total']);
        // Výchozí řazení je podle příjmení; osoba bez historie identit se řadí
        // podle celého jména („Osoba bez vztahu" až za „Osoba 1").
        self::assertSame([$ids[0], $loneId], $flagged);
        self::assertSame(
            $flagged,
            $this->pageIds($needsSetup),
            'Filtr musí vrátit přesně ty osoby, které nesou příznak k doplnění.',
        );
        self::assertSame(2, $needsSetup['total'], 'Filtr musí ubrat i z celkového počtu.');
    }

    /**
     * Hledá se v účinném jméně, ne ve sloupci.
     *
     * Osoba po svatbě má platné jméno v historii identit a seznam ho i ukazuje;
     * hledání podle sloupce by ji přestalo najít přesně ve chvíli, kdy ji
     * uživatel hledá pod novým jménem.
     */
    public function testSearchNarrowsBothAndFollowsTheEffectiveName(): void
    {
        $this->seed(4);
        $ids = $this->employeeIds();
        $this->exec(
            'INSERT INTO payroll_person_identity_history
                (supplier_id, employee_id, full_name, effective_from)
             VALUES (?, ?, "Přejmenovaná Novotná", "2020-01-01")',
            [$this->supplierId, $ids[2]],
        );

        $found = $this->people->listForTenant($this->supplierId, 100, 0, 'all', 'Novotná');
        $byOldColumn = $this->people->listForTenant(
            $this->supplierId,
            100,
            0,
            'all',
            'Syntetická osoba 3',
        );

        self::assertSame(1, $found['total'], 'Hledání musí ubrat i z celkového počtu.');
        self::assertSame([$ids[2]], $this->pageIds($found));
        self::assertSame('Přejmenovaná Novotná', $found['items'][0]['full_name']);
        self::assertSame(
            0,
            $byOldColumn['total'],
            'Překonané jméno ze sloupce už osobu vracet nesmí.',
        );

        // Filtr a hledání se sčítají — odejitá osoba nesmí propadnout hledáním.
        $this->exec(
            'UPDATE payroll_employees SET is_active = 0 WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $ids[2]],
        );
        $narrowed = $this->people->listForTenant($this->supplierId, 100, 0, 'active', 'Novotná');
        self::assertSame(0, $narrowed['total']);
        self::assertSame([], $narrowed['items']);
    }

    /**
     * Napsané `%`, `_` a escapovací znak jsou hledaný text, ne zástupné symboly.
     *
     * Bez escapování by `%` vrátilo celou firmu a `_` kohokoli — uživatel by se
     * dozvěděl pravý opak toho, na co se ptal.
     */
    public function testSearchTreatsWildcardsAsPlainText(): void
    {
        $this->seed(3);
        $ids = $this->employeeIds();
        $this->rename($ids[0], 'Sleva 50% Novák');
        $this->rename($ids[1], 'Pod_tržítko Dvořák');
        $this->rename($ids[2], 'Vykřičník! Svoboda');

        $percent = $this->people->listForTenant($this->supplierId, 100, 0, 'all', '%');
        $underscore = $this->people->listForTenant($this->supplierId, 100, 0, 'all', '_');
        $escape = $this->people->listForTenant($this->supplierId, 100, 0, 'all', '!');

        self::assertSame(1, $percent['total'], 'Procento je hledaný znak, ne „cokoli".');
        self::assertSame([$ids[0]], $this->pageIds($percent));
        self::assertSame(1, $underscore['total'], 'Podtržítko nesmí zastoupit libovolný znak.');
        self::assertSame([$ids[1]], $this->pageIds($underscore));
        self::assertSame(1, $escape['total'], 'Escapovací znak se musí hledat sám za sebe.');
        self::assertSame([$ids[2]], $this->pageIds($escape));
    }

    /**
     * Osobní číslo je kód pracovního vztahu. Seznam ho ukazuje pod jménem,
     * takže podle něj musí jít i hledat — celé i částí.
     */
    public function testSearchFindsPersonByPersonalNumber(): void
    {
        $this->seed(3);
        $ids = $this->employeeIds();
        $this->exec(
            'UPDATE payroll_employments SET code = "Z0042" WHERE supplier_id = ? AND employee_id = ?',
            [$this->supplierId, $ids[1]],
        );

        $exact = $this->people->listForTenant($this->supplierId, 100, 0, 'all', 'Z0042');
        $partial = $this->people->listForTenant($this->supplierId, 100, 0, 'all', 'z004');

        self::assertSame(1, $exact['total']);
        self::assertSame([$ids[1]], $this->pageIds($exact));
        self::assertSame([$ids[1]], $this->pageIds($partial));
    }

    /**
     * Výchozí pořadí je příjmení a jméno v české abecedě, ne celé jméno.
     * Seznam dřív řadil podle „Jméno Příjmení", takže Adam Novák stál
     * před Evou Adamovou.
     */
    public function testDefaultOrderIsLastNameThenFirstNameInCzechCollation(): void
    {
        $this->seed(4);
        $ids = $this->employeeIds();
        $this->renameParts($ids[0], 'Petr', 'Novák');
        $this->renameParts($ids[1], 'Eva', 'Adamová');
        $this->renameParts($ids[2], 'Adam', 'Novák');
        $this->renameParts($ids[3], 'Jan', 'Šafr');
        $svoboda = $this->employeeWithoutEmployment('Ota Svoboda');
        $this->renameParts($svoboda, 'Ota', 'Svoboda');

        self::assertSame(
            [$ids[1], $ids[2], $ids[0], $svoboda, $ids[3]],
            $this->pageIds($this->people->listForTenant($this->supplierId, 100)),
            'Příjmení, pak jméno; Š patří v české abecedě až za S.',
        );

        $byFirstDesc = $this->pageIds($this->people->listForTenant(
            $this->supplierId,
            100,
            0,
            'all',
            '',
            [],
            'first_name',
            true,
        ));
        self::assertSame([$ids[0], $svoboda, $ids[3], $ids[1], $ids[2]], $byFirstDesc);
    }

    private function renameParts(int $employeeId, string $firstName, string $lastName): void
    {
        $fullName = $firstName . ' ' . $lastName;
        $updated = $this->db->pdo()->prepare(
            'UPDATE payroll_person_identity_history SET full_name = ?, first_name = ?, last_name = ?
              WHERE supplier_id = ? AND employee_id = ?',
        );
        $updated->execute([$fullName, $firstName, $lastName, $this->supplierId, $employeeId]);
        if ($updated->rowCount() === 0) {
            $this->exec(
                'INSERT INTO payroll_person_identity_history
                    (supplier_id, employee_id, full_name, first_name, last_name, effective_from)
                 VALUES (?, ?, ?, ?, ?, "2000-01-01")',
                [$this->supplierId, $employeeId, $fullName, $firstName, $lastName],
            );
        }
    }

    /** @return list<int> */
    private function employeeIds(): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_employees WHERE supplier_id = ? ORDER BY id ASC',
        );
        $stmt->execute([$this->supplierId]);

        return array_map(intval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Osoba s hotovým profilem, ale bez jediného pracovního vztahu. */
    private function employeeWithoutEmployment(string $fullName): int
    {
        $this->exec(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, ?, "employee", 1)',
            [$this->supplierId, $fullName],
        );
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->exec(
            'INSERT INTO payroll_employee_profiles (supplier_id, employee_id, profile_status)
             VALUES (?, ?, "ready")',
            [$this->supplierId, $id],
        );

        return $id;
    }

    /**
     * Přejmenování musí projít i historií identit — účinné jméno se čte z ní
     * a sloupec `payroll_employees.full_name` je jen záložní zdroj.
     */
    private function rename(int $employeeId, string $fullName): void
    {
        $this->exec(
            'UPDATE payroll_employees SET full_name = ? WHERE supplier_id = ? AND id = ?',
            [$fullName, $this->supplierId, $employeeId],
        );
        $this->exec(
            'UPDATE payroll_person_identity_history SET full_name = ?
              WHERE supplier_id = ? AND employee_id = ?',
            [$fullName, $this->supplierId, $employeeId],
        );
    }

    /** @param list<string|int> $params */
    private function exec(string $sql, array $params): void
    {
        $this->db->pdo()->prepare($sql)->execute($params);
    }

    private function measure(\PDO $pdo, int $limit): int
    {
        $before = PayrollRunScaleFixture::statementRoundTrips($pdo);
        $page = $this->people->listForTenant($this->supplierId, $limit);
        self::assertCount($limit, $page['items']);

        return PayrollRunScaleFixture::statementRoundTrips($pdo) - $before;
    }

    /**
     * @param array{items: list<array<string,mixed>>, total: int} $page
     * @return list<int>
     */
    private function pageIds(array $page): array
    {
        return array_map(
            static fn (array $person): int => (int) $person['id'],
            $page['items'],
        );
    }

    /**
     * Založí čerstvou izolovanou firmu (a aktéra) a přepne na ni.
     *
     * Každý počet osob potřebuje vlastní firmu: fixtura připíná primární klíče,
     * takže dvě sady osob ve stejném bloku ID by se srazily.
     */
    private function newTenant(): void
    {
        $pdo = $this->db->pdo();
        ++$this->tenantOrdinal;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $this->sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
        $actor = $pdo->prepare(
            'INSERT INTO users (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, "Syntetický aktér", "readonly", "cs", 1)'
        );
        $actor->execute([
            'people-scale-' . bin2hex(random_bytes(6)) . '@invalid.example',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
        ]);
        $this->actorId = (int) $pdo->lastInsertId();
    }

    private function seed(int $headcount): void
    {
        (new PayrollRunScaleFixture(
            $this->db,
            $this->supplierId,
            $this->actorId,
            7_000_000_000 + ($this->tenantOrdinal * 100_000_000),
        ))->seed($headcount);
    }

    private function scalar(string $sql): mixed
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute();

        return $stmt->fetchColumn();
    }
}
