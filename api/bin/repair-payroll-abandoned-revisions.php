<?php

declare(strict_types=1);

/**
 * Odsunutí ZAHOZENÝCH revizí mzdového běhu.
 *
 * ── Co je špatně ────────────────────────────────────────────────────────────
 * Běh má právě jednu živou revizi — tu, na kterou ukazuje
 * `payroll_runs.current_revision_no`. Každá starší revize, která se nikdy
 * neschválila (stav `snapshot`/`calculated`/`reviewed`), je mrtvá: žádný příkaz
 * na ni už nesáhne a dokončit ji nejde. Do opravy v
 * {@see \MyInvoice\Repository\Payroll\PayrollRunRepository::supersedeAbandonedRevisions()}
 * ale zůstávala v původním stavu navždy.
 *
 * U opravné revize (`revision_kind = correction`) to mělo tvrdý následek:
 * uzávěrka mzdového roku počítá otevřené korekce právě přes tenhle stav
 * ({@see \MyInvoice\Repository\Payroll\PayrollYearCloseRepository::openCorrectionCount()}),
 * takže JEDNA zahozená rozpracovaná korekce zablokovala uzávěrku roku natrvalo
 * a z aplikace z toho nevedla cesta ven.
 *
 * ── Co skript dělá ──────────────────────────────────────────────────────────
 * Pro každý běh dohledá aktuální revizi a starší neschválené revize označí za
 * `superseded` s odkazem na ni. Nemění snapshoty, výsledky, deník ani stav běhu
 * — jen srovná stav revizí s tím, co už dávno platí.
 *
 * Použití:
 *   php api/bin/repair-payroll-abandoned-revisions.php                # DRY-RUN
 *   php api/bin/repair-payroll-abandoned-revisions.php --apply
 *   php api/bin/repair-payroll-abandoned-revisions.php --supplier=1 --apply
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
echo "Zahozené revize mzdových běhů — databáze: {$dbName} · "
    . ($apply ? 'ZÁPIS' : 'DRY-RUN') . "\n\n";

$sql = 'SELECT abandoned.supplier_id, abandoned.run_id, abandoned.revision_no,
               abandoned.revision_kind, abandoned.status,
               run.current_revision_no, run.status AS run_status,
               current.id AS current_revision_id
          FROM payroll_run_revisions abandoned
          JOIN payroll_runs run
            ON run.supplier_id = abandoned.supplier_id
           AND run.id = abandoned.run_id
          JOIN payroll_run_revisions current
            ON current.supplier_id = run.supplier_id
           AND current.run_id = run.id
           AND current.revision_no = run.current_revision_no
         WHERE abandoned.status IN ("snapshot", "calculated", "reviewed")
           AND abandoned.revision_no < run.current_revision_no'
    . ($supplierFilter === null ? '' : ' AND abandoned.supplier_id = ?')
    . ' ORDER BY abandoned.supplier_id, abandoned.run_id, abandoned.revision_no';

$stmt = $pdo->prepare($sql);
$stmt->execute($supplierFilter === null ? [] : [$supplierFilter]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($rows === []) {
    echo "Nic k odsunutí.\n";
    exit(0);
}

$targets = [];
foreach ($rows as $row) {
    printf(
        "  firma %d · běh %d (%s) · revize %d [%s/%s] → odsunout pod revizi %d\n",
        (int) $row['supplier_id'],
        (int) $row['run_id'],
        (string) $row['run_status'],
        (int) $row['revision_no'],
        (string) $row['revision_kind'],
        (string) $row['status'],
        (int) $row['current_revision_no'],
    );
    $key = $row['supplier_id'] . ':' . $row['run_id'];
    $targets[$key] = [
        (int) $row['supplier_id'],
        (int) $row['run_id'],
        (int) $row['current_revision_id'],
    ];
}

printf("\nCelkem %d revizí v %d bězích.\n", count($rows), count($targets));

if (!$apply) {
    echo "DRY-RUN — nic se nezapsalo. Spusť s --apply.\n";
    exit(0);
}

$moved = 0;
$pdo->beginTransaction();
try {
    foreach ($targets as [$supplierId, $runId, $currentRevisionId]) {
        $moved += $runs->supersedeAbandonedRevisions(
            $supplierId,
            $runId,
            $currentRevisionId,
        );
    }
    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'CHYBA: ' . $e->getMessage() . "\n");
    exit(1);
}

printf("Odsunuto %d revizí.\n", $moved);
