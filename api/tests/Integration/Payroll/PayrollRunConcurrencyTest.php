<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollRunConflictException;
use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Repository\Payroll\PayrollSurchargeClaimRepository;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeException;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeKind;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P-15 — SKUTEČNÝ souběh mzdových zápisů, ne jeho simulace.
 *
 * `PayrollRunPersistenceTest` umí ověřit, že zastaralá `row_version` skončí
 * konfliktem, ale dělá to v JEDNOM procesu a v jedné DB session: obě „strany"
 * sedí za týmž spojením, takže si zámek nikdy nepostaví do cesty sám sobě.
 * Tenhle test staví bariérový scénář nad DVĚMA nezávislými PDO spojeními —
 * stejně jako {@see \MyInvoice\Tests\Integration\Accounting\PostingClosingConcurrencyTest}
 * pro účetnictví.
 *
 * Měří se místa, kde na serializaci opravdu záleží:
 *   1. dvojí schválení téhož běhu (zámek řádku `payroll_runs` + `row_version`),
 *   2. souběžné zabrání nároku na zákonný příplatek dvěma cestami — schválenou
 *      docházkou a rychlým měsíčním vstupem (mezerový zámek nad unikátním klíčem
 *      z migrace 1628); bez něj by se příplatek vyplatil dvakrát,
 *   3. souběžná materializace TÉHOŽ zdroje (dvakrát docházka), která se nesmí
 *      rozpadnout na dva řádky ani na chybu — je to pořád jeden nárok.
 *
 * Testuje se REPOZITÁŘ, ne `PayrollRunCommandService`: `execute()` má jako úplně
 * první krok `runs->lock()` a hned za ním porovnání `row_version`, takže právě
 * tahle dvojice JE serializační bod. Postavit kolem druhého spojení celý běh se
 * schválenou revizí by do testu přineslo desítky řádků podkladů, které se souběhem
 * nesouvisejí, a přesně o ně by se pak test rozbíjel.
 *
 * COMMITNUTÝ seed (druhé spojení musí seed vidět) → explicitní úklid v tearDown.
 */
#[Group('integration')]
final class PayrollRunConcurrencyTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = '2099-09-01';
    private const PAYMENT = '2099-10-31';

    private Connection $dbA;
    private Connection $dbB;
    private PayrollRunRepository $runsA;
    private PayrollRunRepository $runsB;
    private PayrollSurchargeClaimRepository $claimsA;
    private PayrollSurchargeClaimRepository $claimsB;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $runId = 0;
    private int $employmentId = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            // Dva NEZÁVISLÉ kontejnery → dvě Connection (dvě DB sessions).
            // Kontejner B se staví v nesdílené zóně: testovací běh jinak recykluje
            // jedno PDO přes všechny Connection a obě strany souběhu by seděly
            // v téže session — zámek by se sám sobě nikdy nepostavil do cesty
            // a test by serializaci jen předstíral. Connection MUSÍ vzniknout
            // UVNITŘ zóny; rozhodnutí „smím sdílet PDO?" padá v konstruktoru.
            $containerA = Bootstrap::buildApp()->getContainer();
            $containerB = Connection::withoutSharedTestConnection(static function () {
                $c = Bootstrap::buildApp()->getContainer();
                $c->get(Connection::class);

                return $c;
            });
            $this->dbA = $containerA->get(Connection::class);
            $this->dbB = $containerB->get(Connection::class);
            $this->runsA = $containerA->get(PayrollRunRepository::class);
            $this->runsB = $containerB->get(PayrollRunRepository::class);
            $this->claimsA = $containerA->get(PayrollSurchargeClaimRepository::class);
            $this->claimsB = $containerB->get(PayrollSurchargeClaimRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdoA = $this->dbA->pdo();
        foreach (['payroll_runs', 'payroll_employments', 'payroll_surcharge_period_claims'] as $table) {
            if (!$this->dbA->hasTable($table)) {
                $this->markTestSkipped('Mzdové migrace neproběhly (' . $table . ').');
            }
        }
        $sourceSupplierId = (int) $pdoA->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->userId = (int) ($pdoA->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId <= 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí zdrojová firma nebo uživatel.');
        }

        // COMMITNUTÝ seed přes A — spojení B ho musí vidět.
        $this->supplierId = $this->createIsolatedSupplier($pdoA, $sourceSupplierId);
        $pdoA->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, self::PERIOD, self::PAYMENT, $this->userId, $this->userId]);
        $this->runId = (int) $pdoA->lastInsertId();

        $pdoA->prepare('INSERT INTO payroll_employees (supplier_id, full_name) VALUES (?, ?)')
            ->execute([$this->supplierId, 'P-15 souběh']);
        $employeeId = (int) $pdoA->lastInsertId();
        $pdoA->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, monthly_gross_minor)
             VALUES (?, ?, "p15-concurrency", "employment", "active", "2099-01-01", 5000000)'
        )->execute([$this->supplierId, $employeeId]);
        $this->employmentId = (int) $pdoA->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->dbB)) {
            $pdoB = $this->dbB->pdo();
            if ($pdoB->inTransaction()) {
                $pdoB->rollBack();
            }
            $this->dbB->close();
        }
        if (isset($this->dbA) && $this->supplierId !== 0) {
            $pdoA = $this->dbA->pdo();
            if ($pdoA->inTransaction()) {
                $pdoA->rollBack();
            }
            foreach ([
                'payroll_surcharge_period_claims',
                'payroll_run_revisions',
                'payroll_runs',
                'payroll_employments',
                'payroll_employees',
            ] as $table) {
                $pdoA->prepare("DELETE FROM {$table} WHERE supplier_id = ?")
                    ->execute([$this->supplierId]);
            }
            $pdoA->prepare('DELETE FROM activity_log WHERE supplier_id = ?')->execute([$this->supplierId]);
            $pdoA->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierId]);
            $this->dbA->close();
        }
    }

    /**
     * Dvojí schválení téhož běhu se SERIALIZUJE a druhé skončí konfliktem.
     *
     * Akceptační kritérium: běh se schválí právě jednou a `row_version` se posune
     * o jedničku, ne o dvě — druhý schvalovatel nesmí přepsat výsledek prvního.
     */
    public function testConcurrentApprovalIsSerializedAndSecondOneConflicts(): void
    {
        $pdoA = $this->dbA->pdo();
        $pdoB = $this->dbB->pdo();

        $before = $this->runsA->find($this->supplierId, $this->runId);
        self::assertNotNull($before);
        $expectedVersion = (int) $before['row_version'];

        // A: schvaluje. Zamkne řádek běhu FOR UPDATE (přesně to dělá
        // PayrollRunCommandService::execute jako první krok) a přepne stav.
        // Bez commitu — drží zámek.
        $pdoA->beginTransaction();
        $lockedA = $this->runsA->lock($this->supplierId, $this->runId);
        self::assertNotNull($lockedA, 'A zamkne řádek běhu FOR UPDATE.');
        $this->runsA->updateRun(
            $this->supplierId,
            $this->runId,
            $expectedVersion,
            'approved',
            null,
            $this->userId,
        );

        // B: schvaluje týž běh se STEJNOU (v tu chvíli ještě platnou) verzí.
        // Zámek A ho zastaví dřív, než se vůbec dostane ke kontrole verze.
        $pdoB->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $blocked = null;
        try {
            $this->runsB->lock($this->supplierId, $this->runId);
        } catch (\PDOException $e) {
            // Lock wait timeout (1205) — B čeká na zámek běhu, který drží A.
            $blocked = $e;
        }
        self::assertNotNull($blocked, 'Druhé schválení je serializováno zámkem řádku běhu.');
        // Kontroluje se KONKRÉTNÍ chyba. Kdyby se catch spokojil s čímkoli, prošel
        // by test i tehdy, kdyby B spadl na překlepu v SQL — a tvrdil by přitom,
        // že prokázal serializaci.
        self::assertSame('1205', (string) ($blocked->errorInfo[1] ?? ''), 'Očekává se lock wait timeout, ne jiná chyba: ' . $blocked->getMessage());
        if ($pdoB->inTransaction()) {
            $pdoB->rollBack();
        }

        $pdoA->commit();

        // B to zkusí znovu: zámek je volný, ale verze už je jiná → konflikt.
        // Tohle je ta část, kterou jednoprocesový test simuloval; tady o ni
        // soupeří dvě skutečné session.
        $lockedB = $this->runsB->lock($this->supplierId, $this->runId);
        self::assertNotNull($lockedB);
        self::assertSame('approved', (string) $lockedB['status'], 'B vidí commitnuté schválení A.');
        try {
            $this->runsB->updateRun(
                $this->supplierId,
                $this->runId,
                $expectedVersion,
                'approved',
                null,
                $this->userId,
            );
            self::fail('Druhé schválení se zastaralou verzí musí skončit konfliktem.');
        } catch (PayrollRunConflictException $e) {
            self::assertSame($expectedVersion + 1, $e->currentVersion);
        }

        $after = $this->runsA->find($this->supplierId, $this->runId);
        self::assertNotNull($after);
        self::assertSame($expectedVersion + 1, (int) $after['row_version'], 'Běh se schválil právě jednou.');
    }

    /**
     * Docházka a rychlý měsíční vstup si nemohou zabrat týž příplatek najednou.
     *
     * Bez serializace by obě strany přečetly prázdno a obě zapsaly vstup —
     * zaměstnanec by dostal příplatek dvakrát a obě částky by vypadaly věrohodně.
     * Akceptační kritérium: v `payroll_surcharge_period_claims` zůstane právě jeden
     * řádek a druhá cesta dostane vysvětlenou chybu, ne tichý duplikát.
     */
    public function testConcurrentSurchargeClaimFromTimeAndQuickInputCannotBothWin(): void
    {
        $pdoA = $this->dbA->pdo();
        $pdoB = $this->dbB->pdo();
        $kind = PayrollSurchargeKind::Overtime;

        // A: schválení docházky si bere nárok. Drží mezerový zámek nad unikátním
        // klíčem (supplier, vztah, období, druh) — nezacommitnuto.
        $pdoA->beginTransaction();
        $this->claimsA->claim(
            $this->supplierId,
            $this->employmentId,
            self::PERIOD,
            $kind,
            PayrollSurchargeClaimRepository::SOURCE_TIME,
            $this->userId,
        );

        // B: rychlý vstup chce týž nárok. Zamykají se různé řádky (docházkový
        // měsíc × pracovní vztah), takže je neserializuje nic jiného než tenhle
        // klíč — a právě proto tabulka z migrace 1628 vznikla.
        $pdoB->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $blocked = null;
        try {
            $this->claimsB->claim(
                $this->supplierId,
                $this->employmentId,
                self::PERIOD,
                $kind,
                PayrollSurchargeClaimRepository::SOURCE_MANUAL,
                $this->userId,
            );
        } catch (PayrollSurchargeException $e) {
            self::fail('B nesmí projít až na výklad stavu — má být zablokován zámkem A (' . $e->reason . ').');
        } catch (\PDOException $e) {
            $blocked = $e;
        }
        self::assertNotNull($blocked, 'Souběžné zabrání nároku je serializováno na unikátním klíči.');
        self::assertSame('1205', (string) ($blocked->errorInfo[1] ?? ''), 'Očekává se lock wait timeout, ne jiná chyba: ' . $blocked->getMessage());
        if ($pdoB->inTransaction()) {
            $pdoB->rollBack();
        }

        $pdoA->commit();

        // Po commitu A vidí B skutečného držitele a dostane vysvětlenou chybu.
        try {
            $this->claimsB->claim(
                $this->supplierId,
                $this->employmentId,
                self::PERIOD,
                $kind,
                PayrollSurchargeClaimRepository::SOURCE_MANUAL,
                $this->userId,
            );
            self::fail('Druhá cesta nesmí zabrat už obsazený nárok.');
        } catch (PayrollSurchargeException $e) {
            self::assertSame('surcharge_source_conflict', $e->reason);
        }

        self::assertSame(1, $this->claimCount($kind), 'Nárok drží právě jeden zdroj.');
        self::assertSame(
            PayrollSurchargeClaimRepository::SOURCE_TIME,
            $this->claimSource($kind),
            'Nárok drží ten, kdo commitnul první.',
        );
    }

    /**
     * Dvě souběžné materializace TÉHOŽ zdroje jsou pořád jeden nárok.
     *
     * Opakované zpracování téže docházky (retry, dvojí kliknutí, paralelní worker)
     * nesmí skončit ani duplicitním řádkem, ani chybou — jinak by se legitimní
     * oprava už zabraného měsíce nedala dokončit.
     */
    public function testConcurrentMaterializationFromTheSameSourceIsIdempotent(): void
    {
        $pdoA = $this->dbA->pdo();
        $pdoB = $this->dbB->pdo();
        $kind = PayrollSurchargeKind::Night;

        $pdoA->beginTransaction();
        $this->claimsA->claim(
            $this->supplierId,
            $this->employmentId,
            self::PERIOD,
            $kind,
            PayrollSurchargeClaimRepository::SOURCE_TIME,
            $this->userId,
        );
        $pdoA->commit();

        // Druhý běh materializace ze stejného zdroje — bez transakce A, takže
        // se nic neblokuje: musí projít jako no-op.
        $this->claimsB->claim(
            $this->supplierId,
            $this->employmentId,
            self::PERIOD,
            $kind,
            PayrollSurchargeClaimRepository::SOURCE_TIME,
            $this->userId,
        );
        if ($pdoB->inTransaction()) {
            $pdoB->commit();
        }

        self::assertSame(1, $this->claimCount($kind), 'Týž zdroj nesmí založit druhý nárok.');
    }

    private function claimCount(PayrollSurchargeKind $kind): int
    {
        $stmt = $this->dbA->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_surcharge_period_claims
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?
                AND surcharge_kind = ?'
        );
        $stmt->execute([$this->supplierId, $this->employmentId, self::PERIOD, $kind->value]);

        return (int) $stmt->fetchColumn();
    }

    private function claimSource(PayrollSurchargeKind $kind): string
    {
        $stmt = $this->dbA->pdo()->prepare(
            'SELECT claim_source FROM payroll_surcharge_period_claims
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?
                AND surcharge_kind = ?'
        );
        $stmt->execute([$this->supplierId, $this->employmentId, self::PERIOD, $kind->value]);

        return (string) $stmt->fetchColumn();
    }
}
