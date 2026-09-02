<?php

declare(strict_types=1);

/**
 * Cron — detekce hlásitelných změn v registru pojištěnců (ČSSZ).
 *
 * Použití:
 *   php api/bin/cron-payroll-registration-changes.php
 *   php api/bin/cron-payroll-registration-changes.php --environment=test
 *   php api/bin/cron-payroll-registration-changes.php --supplier=12   (lze opakovat)
 *   php api/bin/cron-payroll-registration-changes.php --batch=50 --max-batches=5
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč to běží samo
 * ═══════════════════════════════════════════════════════════════════════════
 * Detekce zakládá návrh povinnosti a tím rozjíždí osmidenní lhůtu
 * (§ 19 odst. 5 zákona č. 323/2025 Sb.). Spouštěla se ale jen při otevření
 * karty konkrétního zaměstnance a na tlačítko ve frontě odchozích podání —
 * u stovky zaměstnanců s téměř denními změnami kartu denně nikdo neotevře
 * a lhůty tiše utíkají. Denní tah je jediné, co tomu zabrání.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Skript NIC NEODESÍLÁ
 * ═══════════════════════════════════════════════════════════════════════════
 * Vzniká pouze návrh povinnosti s termínem. Podání z něj vyrobí a odešle
 * výhradně člověk z fronty **Mzdy → Podání a hlášení**. Strojové odeslání do
 * registru pojištěnců za zády účetní tenhle modul nemá nikde a mít nebude.
 *
 * Právě proto tahle úloha SMÍ být zapnutá ve výchozím stavu, na rozdíl od
 * automatiky, která tu vědomě chybí: rozesílání přehledu termínů e-mailem
 * ({@see \MyInvoice\Service\Payroll\Deadline\PayrollDeadlineOverviewService} —
 * „Cron ani e-mail tady NENÍ") a vybírání datové schránky (přístup ke zprávě
 * může způsobit její doručení, viz manual § 68.3). Obě sahají ven a mají
 * následky, které nejde vzít zpět; tahle úloha jen počítá a zapisuje do vlastní
 * tabulky návrhů. Nejhorší, co může způsobit, je návrh, který účetní uzavře
 * jako vyřízený jinak.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč je bezpečné to pouštět denně (idempotence)
 * ═══════════════════════════════════════════════════════════════════════════
 * Skript je jen obal; celou logiku drží
 * {@see \MyInvoice\Service\Payroll\Submission\Registration\Change\PayrollRegistrationChangeSweepRunner},
 * ať jde otestovat bez procesu. Dvojí pojistka proti duplicitám je stejná,
 * jaká chrání kartu zaměstnance i tlačítko ve frontě:
 *
 *   1. `payroll_registration_change_scans.source_watermark` — otisk hlásitelných
 *      údajů z posledního porovnání. Kandidáty vybírá `staleEmployments()` jen
 *      tam, kde se otisk pohnul, takže druhý běh nad nezměněnými daty neporovná
 *      ani jeden vztah.
 *   2. Unikátní klíč nad otiskem AKTUÁLNÍHO stavu — i kdyby se týž vztah
 *      porovnal dvakrát (cron souběžně s otevřenou kartou), druhý insert se
 *      srazí na klíči a vrátí ten původní návrh.
 *
 * Všechny tři cesty volají tutéž službu v transakci nad zamčeným vztahem, takže
 * se cron s ručním spuštěním nemá jak poprat.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Firmy bez mezd
 * ═══════════════════════════════════════════════════════════════════════════
 * Mzdy jsou opt-in per firma (`supplier.payroll_enabled`, migrace 1290; stav
 * plného modulu `payroll_module_state`, migrace 1186). Instalace, kde je nikdo
 * nezapnul, se pozná ještě před stavbou DI kontejneru jedním dotazem
 * ({@see CronPreflight::hasPayrollSuppliers()}) a skončí jako prázdný tick —
 * bez chyby, bez záznamu v historii běhů, v řádu milisekund.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Cron\CronPreflight;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Service\Payroll\Submission\Registration\Change\PayrollRegistrationChangeSweepRunner;

const CRON_SCRIPT = 'cron-payroll-registration-changes';

$environment = 'production';
$onlySuppliers = [];
$batch = PayrollRegistrationChangeSweepRunner::DEFAULT_BATCH;
$maxBatches = PayrollRegistrationChangeSweepRunner::DEFAULT_MAX_BATCHES;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--environment=')) {
        $environment = substr($arg, 14);
        if (!in_array($environment, ['production', 'test'], true)) {
            fwrite(STDERR, "Neplatné --environment (production|test)\n");
            exit(1);
        }
        continue;
    }
    if (str_starts_with($arg, '--supplier=')) {
        $id = (int) substr($arg, 11);
        if ($id <= 0) {
            fwrite(STDERR, "Neplatné --supplier: {$arg}\n");
            exit(1);
        }
        $onlySuppliers[] = $id;
        continue;
    }
    if (str_starts_with($arg, '--batch=')) {
        $batch = (int) substr($arg, 8);
        continue;
    }
    if (str_starts_with($arg, '--max-batches=')) {
        $maxBatches = (int) substr($arg, 14);
        continue;
    }
    fwrite(STDERR, "Unknown arg: {$arg}\n");
    exit(1);
}

$lightPdo = (new Connection(Config::load(Bootstrap::rootDir())))->pdo();
if (!CronPreflight::hasPayrollSuppliers($lightPdo)) {
    // Instalace bez mezd: prázdný tick, žádná chyba. Heartbeat se přesto
    // posune — mlčící cron a cron bez práce musí jít v UI rozlišit.
    $result = ['environment' => $environment, 'skipped' => 'payroll_disabled'];
    CronRun::start($lightPdo, CRON_SCRIPT)->finish('ok', $result, null, null, false);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$container = Bootstrap::buildContainer();

/** @var Connection $connection */
$connection = $container->get(Connection::class);

$run = CronRun::start($connection->pdo(), CRON_SCRIPT);

// Migrace detekce (1644) neproběhla — bez tabulek nemá co porovnávat a hlásit
// to jako chybu by byl falešný poplach na instalaci, která se teprve aktualizuje.
foreach ([
    'payroll_registration_change_proposals',
    'payroll_registration_change_scans',
] as $table) {
    if (!$connection->hasTable($table)) {
        $result = ['environment' => $environment, 'skipped' => 'migrations_pending'];
        $run->finish('ok', $result, null, null, false);
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }
}

/** @var PayrollRegistrationChangeSweepRunner $runner */
$runner = $container->get(PayrollRegistrationChangeSweepRunner::class);

$startedAt = microtime(true);
try {
    $report = $runner->run(
        $environment,
        $onlySuppliers === [] ? null : $onlySuppliers,
        $batch,
        $maxBatches,
    );
} catch (\Throwable $e) {
    $run->finish('error', ['environment' => $environment, 'error' => $e->getMessage()]);
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

$ms = (int) ((microtime(true) - $startedAt) * 1000);
printf(
    "[%s] %s — %s: firem=%d, vztahů=%d, se změnou=%d, nových návrhů=%d,"
        . " nečitelných=%d, chyb=%d (%d ms)\n",
    date('Y-m-d H:i:s'),
    CRON_SCRIPT,
    $environment,
    $report['suppliers'],
    $report['scanned'],
    $report['changed'],
    $report['created'],
    $report['unreadable'],
    $report['errors'],
    $ms,
);
foreach ($report['failures'] as $failure) {
    fprintf(STDERR, "  ✗ firma #%d — %s\n", $failure['supplier_id'], $failure['message']);
}
foreach ($report['truncated'] as $supplierId) {
    printf("  … firma #%d nedojela do konce, zbytek dokončí další běh\n", $supplierId);
}

// Selhání JEDNÉ firmy je chyba běhu (musí být vidět v Systém → Plánované úlohy),
// ale ostatní firmy už mají hotovo — proto se nikdy nekončí dřív.
//
// `didWork` se nedá nechat na heuristice reportu: počet firem se mzdami je
// nenulový každý den, takže by se do historie běhů zapisoval i tick, který
// nic neporovnal. Prací je až porovnaný vztah nebo chyba.
$run->finish(
    $report['errors'] > 0 ? 'error' : 'ok',
    $report,
    null,
    null,
    $report['scanned'] > 0 || $report['errors'] > 0,
);

exit($report['errors'] > 0 ? 2 : 0);
