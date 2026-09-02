<?php

declare(strict_types=1);

/**
 * Zhasnutí příznaku „absence čeká na opravu mzdového běhu“ tam, kde už oprava
 * PROBĚHLA.
 *
 * ── Co je špatně ────────────────────────────────────────────────────────────
 * `payroll_absences.correction_pending` se rozsvítí, když se zruší (nebo změní)
 * absence, která už byla ve schváleném mzdovém běhu — je to poznámka „tenhle
 * měsíc se musí přepočítat“. Nikde se ale nezhasínal, ani po opravné revizi,
 * která přesně tohle udělala.
 *
 * Následek byl trvalý: uzávěrka mzdového roku počítá nevyřízenou dovolenou přes
 * `status = 'requested' OR correction_pending = 1`
 * ({@see \MyInvoice\Repository\Payroll\PayrollYearCloseRepository::openLeaveCount()}),
 * takže jedna zrušená dovolená držela rok neuzavíratelný napořád — a shodit ten
 * příznak nešlo odnikud, protože zrušená absence se už znovu rozhodnout nedá.
 * Týž příznak navíc vyřazuje absenci z podkladů JMHZ.
 *
 * Nově ho zhasíná schválení revize
 * ({@see \MyInvoice\Repository\Payroll\PayrollRunRepository::clearAbsenceCorrectionPending()}).
 * Tenhle skript dorovná HISTORICKÁ data — období, která už schválená jsou
 * a znovu se schvalovat nebudou.
 *
 * ── Co skript dělá ──────────────────────────────────────────────────────────
 * Pro každé mzdové období se schválenou revizí zhasne příznak u absencí, které
 * celé leží uvnitř toho období. Absence přesahující do dalšího měsíce zůstává
 * beze změny, dokud není schválený i ten. Nic jiného nemění.
 *
 * Použití:
 *   php api/bin/repair-payroll-absence-correction-pending.php            # DRY-RUN
 *   php api/bin/repair-payroll-absence-correction-pending.php --apply
 *   php api/bin/repair-payroll-absence-correction-pending.php --supplier=1 --apply
 *
 * Cílovou databázi lze přepnout přes MYINVOICE_DB_NAME.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollRunRepository;

$opts = getopt('', ['apply', 'supplier::']);
$apply = array_key_exists('apply', $opts);
$supplierFilter = isset($opts['supplier']) ? (int) $opts['supplier'] : null;

$container = Bootstrap::buildApp()->getContainer();
$db = $container->get(Connection::class);
$runs = $container->get(PayrollRunRepository::class);
$pdo = $db->pdo();

$dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
echo "Nevyřízené opravy absencí — databáze: {$dbName} · "
    . ($apply ? 'ZÁPIS' : 'DRY-RUN') . "\n\n";

$sql = 'SELECT absence.id, absence.supplier_id, absence.employment_id,
               absence.absence_type, absence.status,
               absence.date_from, absence.date_to,
               run.period_start
          FROM payroll_absences absence
          JOIN payroll_runs run
            ON run.supplier_id = absence.supplier_id
           AND absence.date_from >= run.period_start
           AND absence.date_to < DATE_ADD(run.period_start, INTERVAL 1 MONTH)
         WHERE absence.correction_pending = 1
           AND EXISTS (
               SELECT 1
                 FROM payroll_run_revisions revision
                WHERE revision.supplier_id = run.supplier_id
                  AND revision.run_id = run.id
                  AND revision.status IN ("approved", "superseded")
           )'
    . ($supplierFilter === null ? '' : ' AND absence.supplier_id = ?')
    . ' GROUP BY absence.id, run.period_start
        ORDER BY absence.supplier_id, absence.date_from, absence.id';

$stmt = $pdo->prepare($sql);
$stmt->execute($supplierFilter === null ? [] : [$supplierFilter]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($rows === []) {
    echo "Nic k uzavření.\n";
    exit(0);
}

$periods = [];
foreach ($rows as $row) {
    printf(
        "  firma %d · absence %d (%s, %s) %s–%s · období %s\n",
        (int) $row['supplier_id'],
        (int) $row['id'],
        (string) $row['absence_type'],
        (string) $row['status'],
        (string) $row['date_from'],
        (string) $row['date_to'],
        substr((string) $row['period_start'], 0, 7),
    );
    $periods[$row['supplier_id'] . ':' . $row['period_start']] = [
        (int) $row['supplier_id'],
        (string) $row['period_start'],
    ];
}

printf("\nCelkem %d absencí v %d obdobích.\n", count($rows), count($periods));

if (!$apply) {
    echo "DRY-RUN — nic se nezapsalo. Spusť s --apply.\n";
    exit(0);
}

$cleared = 0;
$pdo->beginTransaction();
try {
    foreach ($periods as [$supplierId, $periodStart]) {
        $cleared += $runs->clearAbsenceCorrectionPending($supplierId, $periodStart);
    }
    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'CHYBA: ' . $e->getMessage() . "\n");
    exit(1);
}

printf("Uzavřeno %d absencí.\n", $cleared);
