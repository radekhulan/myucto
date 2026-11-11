<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Repository\Payroll\PayrollEmployeeDeletionRepository;
use MyInvoice\Repository\Payroll\PayrollEmploymentDeletionRepository;
use MyInvoice\Service\Payroll\Retention\PayrollRetentionCatalog;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Každá tabulka, která BLOKUJE smazání osoby, musí mít retenční lhůtu.
 *
 * ── Proč to jde uhlídat jen testem ────────────────────────────────────────────
 * Posudek retence ({@see \MyInvoice\Service\Payroll\Retention\PayrollRetentionService})
 * se ptá výhradně na tabulky, které jmenuje
 * {@see PayrollRetentionCatalog}. Mazací rutiny mají vlastní seznamy
 * (`BLOCKERS`, `GUARD_ONLY`) a nic je s katalogem nespojuje. Tabulka, která je
 * v jednom seznamu a chybí v druhém, vyrobí DVĚ protichůdné a obě nepravdivé
 * odpovědi:
 *   • posudek řekne, že osobu nic nedrží, a navrhne ji k výmazu;
 *   • samotné smazání pak selže na stráži mazací rutiny.
 * Osoba zůstane držená napořád a žádná lhůta ji nikdy neuvolní. Přesně tak se
 * chovaly `payroll_eldp_statements` a po nich dalších šestnáct tabulek — a ani
 * jednu z nich by code review neukázalo, protože se měnila migrace, ne katalog.
 *
 * ── Výjimka musí mít napsaný důvod ────────────────────────────────────────────
 * Výjimku smí dostat jen tabulka, která vlastní lhůtu mít NEMŮŽE, protože je
 * potomkem jiné tabulky s NENULLOVATELNOU vazbou na rodiče — pak rodičovský
 * řádek pro tutéž osobu existuje vždy, když existuje potomek, a lhůta rodiče
 * potomka pokrývá. Je-li vazba nullovatelná, výjimka NEPLATÍ: osiřelý potomek
 * (typicky idempotenční zámek, jehož cíl nikdy nevznikl) by osobu držel bez
 * konce. Takové tabulky proto v katalogu jsou, i když vlastní zákonnou lhůtu
 * nemají — drží lhůtu rodiče, aby vůbec někdy skončila.
 *
 * Důvod se píše sem, do hodnoty allowlistu, ne do commit message: seznam bez
 * napsaného důvodu se za rok stane seznamem, na který se nikdo neptá.
 *
 * ── Výjimka blokátoru, ne tabulky ─────────────────────────────────────────────
 * Blokátor, který tabulku používá jen jako nosič podmínky nad jinou evidencí,
 * dostává výjimku na svůj symbol (`ALLOWED_BLOCKER_WITHOUT_CATEGORY`). Tabulka
 * tím výjimku nezíská: sáhne-li po ní další blokátor, guard ji ohlásí znovu.
 */
final class PayrollRetentionBlockerCoverageTest extends TestCase
{
    /**
     * Blokující tabulky bez vlastní retenční kategorie a důvod, proč ji nemají.
     *
     * @var array<string,string> tabulka => důvod (rodičovská tabulka + kategorie)
     */
    private const ALLOWED_WITHOUT_CATEGORY = [
        'payroll_annual_document_sources' =>
            'Manifest provenience ročního dokladu: jen vazby a hash, žádná vlastní data. '
            . 'Rodič je payroll_annual_document_revisions (kategorie payroll_sheet) přes '
            . 'NOT NULL annual_revision_id, takže rodičovský řádek pro tutéž osobu existuje '
            . 'vždy, když existuje tenhle. Vlastní lhůta by byla druhé číslo pro tutéž věc.',
        'payroll_personnel_documents' =>
            'Personální spis nemá zákonnou lhůtu a osobu ve výmazu nedrží: výkon výmazu '
            . '(PayrollErasureProposalRepository::executeItem) ho přes '
            . 'PayrollPersonnelFileService::purge() odstraní dřív, než osobu smaže. '
            . 'Blokátor chrání jen ruční smazání omylem založené osoby.',
        'payroll_personnel_notes' =>
            'Poznámky personálního spisu: stejný důvod jako u dokumentů spisu. Výkon '
            . 'výmazu je odstraní přes PayrollPersonnelFileService::purge() před smazáním '
            . 'nebo anonymizací osoby, blokátor chrání jen ruční smazání.',
    ];

    /**
     * Výjimky udělené KONKRÉTNÍMU blokátoru, ne tabulce.
     *
     * Blokátor s podmínkou `where` někdy tabulku jen používá jako nosič: řádek
     * v ní má každý vztah, blokuje až podmínka nad jinou evidencí. Výjimka pro
     * celou tabulku by pak kryla i každý další blokátor, který by po ní sáhl
     * z jiného důvodu. Klíčem je proto symbol `Třída::alias` a výjimka platí
     * jen tehdy, když tabulku neblokuje nic jiného.
     *
     * @var array<string,array<string,string>> symbol blokátoru => [tabulka => důvod]
     */
    private const ALLOWED_BLOCKER_WITHOUT_CATEGORY = [
        PayrollEmploymentDeletionRepository::class . '::registration_submission' => [
            'payroll_employment_terms' =>
                'Podmínky vztahu jsou jen nosič: blokuje podmínka nad přihláškou ČSSZ ve frontě '
                . 'podání (payroll_submission_parts nemá employment_id, váže se přes '
                . 'subject_reference). Lhůtu té přihlášky drží sledované tabulky kategorie '
                . 'pension_evidence: přihláška A1 nevznikne bez profilu '
                . 'payroll_registration_a1_profiles a přijatá zapíše payroll_person_external_ids '
                . 'a payroll_employment_external_ids. Blokátor sám je přechodný: zrušená nebo '
                . 'odmítnutá přihláška nedrží, po uplynutí lhůty jde osoba do anonymizace '
                . '(dokládá PayrollE2eReg2RetentionTest). Předběžné přihlášení P1 profil A1 '
                . 'nemá a drží jen do vyřízení: po přijetí následuje plná přihláška A1.',
        ],
    ];

    public function testEveryBlockingTableHasARetentionCategoryOrAWrittenReason(): void
    {
        $tracked = PayrollRetentionCatalog::trackedTables();

        $uncovered = [];
        foreach ($this->blockingSources() as $table => $sources) {
            if (in_array($table, $tracked, true)) {
                continue;
            }
            if (array_key_exists($table, self::ALLOWED_WITHOUT_CATEGORY)) {
                continue;
            }
            $unexplained = array_filter(
                $sources,
                static fn (string $source): bool
                    => !isset(self::ALLOWED_BLOCKER_WITHOUT_CATEGORY[$source][$table]),
            );
            if ($unexplained === []) {
                continue;
            }
            $uncovered[] = $table;
        }

        self::assertSame(
            [],
            $uncovered,
            "Tyhle tabulky blokují smazání osoby, ale retenční katalog o nich neví.\n"
            . "Posudek proto řekne, že osobu nic nedrží, a smazání přesto selže na stráži —\n"
            . "osoba je držená napořád a žádná lhůta ji neuvolní. Doplňte je do kategorie\n"
            . "v PayrollRetentionCatalog, nebo do ALLOWED_WITHOUT_CATEGORY s důvodem:\n"
            . implode("\n", $uncovered),
        );
    }

    public function testEveryWrittenReasonCarriesAnActualExplanation(): void
    {
        $reasons = self::ALLOWED_WITHOUT_CATEGORY;
        foreach (self::ALLOWED_BLOCKER_WITHOUT_CATEGORY as $source => $tables) {
            foreach ($tables as $table => $reason) {
                $reasons["{$source} / {$table}"] = $reason;
            }
        }
        foreach ($reasons as $table => $reason) {
            self::assertGreaterThan(
                80,
                mb_strlen(trim($reason)),
                "Výjimka pro {$table} nemá napsaný důvod. Výjimka bez důvodu je jen "
                . 'ticho na místě, kde má být rozhodnutí.',
            );
        }
    }

    /**
     * Výjimka smí existovat jen pro tabulku, která opravdu blokuje. Zapomenutá
     * výjimka nad zrušeným blokátorem by tiše kryla i tabulku, která se pod
     * stejným jménem objeví znovu s jiným významem.
     */
    public function testNoStaleExceptionSurvivesTheBlockerItExplains(): void
    {
        $blocking = $this->blockingTables();
        foreach (array_keys(self::ALLOWED_WITHOUT_CATEGORY) as $table) {
            self::assertContains(
                $table,
                $blocking,
                "Výjimka pro {$table} přežila blokátor, který vysvětlovala. Smažte ji, "
                . 'ať nekryje tabulku, která se pod tím jménem objeví znovu.',
            );
        }
        $sources = $this->blockingSources();
        foreach (self::ALLOWED_BLOCKER_WITHOUT_CATEGORY as $source => $tables) {
            foreach (array_keys($tables) as $table) {
                self::assertContains(
                    $source,
                    $sources[$table] ?? [],
                    "Výjimka blokátoru {$source} pro {$table} přežila blokátor, který vysvětlovala.",
                );
            }
        }
    }

    /**
     * Výjimka nesmí zároveň být v katalogu — to by znamenalo, že jedno z obou
     * tvrzení je mrtvé a nikdo neví které.
     */
    public function testNoExceptionDuplicatesACatalogEntry(): void
    {
        $tracked = PayrollRetentionCatalog::trackedTables();
        foreach (array_keys(self::ALLOWED_WITHOUT_CATEGORY) as $table) {
            self::assertNotContains(
                $table,
                $tracked,
                "Tabulka {$table} je v katalogu i mezi výjimkami. Jedno z těch dvou "
                . 'tvrzení je mrtvé a nejde poznat které.',
            );
        }
        foreach (self::ALLOWED_BLOCKER_WITHOUT_CATEGORY as $source => $tables) {
            foreach (array_keys($tables) as $table) {
                self::assertNotContains(
                    $table,
                    $tracked,
                    "Tabulka {$table} je v katalogu i mezi výjimkami blokátoru {$source}.",
                );
                self::assertArrayNotHasKey(
                    $table,
                    self::ALLOWED_WITHOUT_CATEGORY,
                    "Tabulka {$table} má výjimku pro celou tabulku i pro blokátor {$source}.",
                );
            }
        }
    }

    /**
     * Sjednocení `BLOCKERS['tables']` a `GUARD_ONLY` obou mazacích repozitářů.
     * Konstanty jsou private záměrně — jsou to interní seznamy mazací rutiny,
     * ne veřejné API. Guard je proto čte reflexí a nepožaduje, aby se kvůli
     * testu otevřely.
     *
     * @return list<string>
     */
    private function blockingTables(): array
    {
        return array_keys($this->blockingSources());
    }

    /**
     * Blokující tabulky a symboly, které přes ně blokují (`Třída::alias`
     * blokátoru, nebo `Třída::GUARD_ONLY`).
     *
     * @return array<string,list<string>> tabulka => symboly
     */
    private function blockingSources(): array
    {
        $tables = [];
        foreach (
            [PayrollEmployeeDeletionRepository::class, PayrollEmploymentDeletionRepository::class]
            as $class
        ) {
            $reflection = new ReflectionClass($class);
            $constants = $reflection->getConstants();

            self::assertArrayHasKey(
                'BLOCKERS',
                $constants,
                "Mazací repozitář {$class} přestal mít BLOCKERS — guard se rozešel s kódem.",
            );
            /** @var array<string,array{tables:list<string>}> $blockers */
            $blockers = $constants['BLOCKERS'];
            foreach ($blockers as $alias => $blocker) {
                foreach ($blocker['tables'] as $table) {
                    $tables[$table][] = "{$class}::{$alias}";
                }
            }

            /** @var list<string> $guardOnly */
            $guardOnly = $constants['GUARD_ONLY'] ?? [];
            foreach ($guardOnly as $table) {
                $tables[$table][] = "{$class}::GUARD_ONLY";
            }
        }

        self::assertNotEmpty($tables, 'Guard nenašel ani jeden blokátor — sonda je slepá.');
        ksort($tables);

        return $tables;
    }
}
