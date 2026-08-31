<?php

declare(strict_types=1);

/**
 * Vyčíslení rozdílu u zákonného pojištění odpovědnosti předepsaného ze
 * zastropovaného vyměřovacího základu.
 *
 * Materializér do 31. 8. 2026 bral `capped_assessment_base_minor_units`, tedy
 * základ PO ročním maximu podle § 15a zákona č. 589/1992 Sb. Roční maximum se
 * ale na zákonné pojištění odpovědnosti nevztahuje: § 12 odst. 2 vyhlášky
 * č. 125/1993 Sb. přebírá z toho zákona jen § 5 odst. 1 písm. a), tedy KTERÉ
 * příjmy do základu patří. Shodně to vykládají Kooperativa i Generali Česká
 * pojišťovna a stanovisko Ministerstva financí z 20. 5. 2008.
 *
 * Firmy, které nikoho nad ročním stropem nemají, rozdíl nemají — u nich se oba
 * základy rovnají a skript nevypíše nic. Kde rozdíl je, je předepsané pojistné
 * NIŽŠÍ, než mělo být, a § 12 odst. 9 vyhlášky zvyšuje nedoplatek o 10 % za
 * každý započatý měsíc prodlení. Proto se to vyčísluje po čtvrtletích: nedoplatek
 * u každého z nich stárne zvlášť.
 *
 * Skript je READ-ONLY. Nic nepředepisuje ani neopravuje — rozdíl se doplňuje
 * opravnou revizí dotčeného čtvrtletí a řeší se s pojišťovnou. Přepsat už
 * předepsaný závazek na místě by smazalo stopu, podle které se dohledá, co se
 * pojišťovně skutečně poslalo.
 *
 * Použití:
 *   php api/bin/audit-accident-insurance-base.php               # všechny tenanty
 *   php api/bin/audit-accident-insurance-base.php --supplier=3  # jen jeden tenant
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Payment\PayrollAccidentInsuranceCalculator;

$supplierId = null;
foreach ($argv as $arg) {
    if (preg_match('/^--supplier=(\d+)$/', $arg, $m) === 1) {
        $supplierId = (int) $m[1];
    }
}

$container = \MyInvoice\Bootstrap::buildApp()->getContainer();
$pdo = $container->get(Connection::class)->pdo();
$calculator = new PayrollAccidentInsuranceCalculator();

$sql = "SELECT liability.id, liability.supplier_id, liability.revision_id,
               liability.liability_reference, liability.direction,
               liability.amount_minor, liability.due_on,
               liability.source_snapshot_json
          FROM payroll_payment_liabilities liability
         WHERE liability.liability_kind = 'statutory_insurance'
           AND liability.liability_reference LIKE 'accident-insurance:quarter:%'";
$params = [];
if ($supplierId !== null) {
    $sql .= ' AND liability.supplier_id = ?';
    $params[] = $supplierId;
}
$sql .= ' ORDER BY liability.supplier_id, liability.due_on, liability.id';

$statement = $pdo->prepare($sql);
$statement->execute($params);

/**
 * Nezastropovaný základ měsíce ze schváleného výsledku sociálního pojištění.
 * Čte se stejnou cestou jako v materializéru, aby se čísla nerozešla.
 */
$monthBase = static function (PDO $pdo, int $supplierId, string $monthStart): ?int {
    $sql = 'SELECT result.result_snapshot_json
              FROM payroll_statutory_results result
              JOIN payroll_run_revisions revision
                ON revision.supplier_id = result.supplier_id
               AND revision.id = result.revision_id
              JOIN payroll_runs run
                ON run.supplier_id = revision.supplier_id
               AND run.id = revision.run_id
             WHERE result.supplier_id = ?
               AND result.calculation_kind = "social_insurance"
               AND result.result_status = "calculated"
               AND run.period_start = ?
               AND revision.status = "approved"
               AND revision.revision_no = run.current_revision_no';
    $statement = $pdo->prepare($sql);
    $statement->execute([$supplierId, $monthStart]);
    $json = $statement->fetchColumn();
    if (!is_string($json)) {
        return null;
    }
    try {
        $snapshot = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
    if (!is_array($snapshot) || !is_array($snapshot['people'] ?? null)) {
        return null;
    }

    // Stejné pravidlo jako v materializéru: základ vztahu před ročním maximem,
    // jen účastnící se vztahy a bez druhu corporate_body (příjem společníka a
    // odměna za výkon funkce do základu tohoto pojištění nepatří). Kdyby se tu
    // sčítalo jinak, vyčíslený rozdíl by neodpovídal tomu, co appka předepíše.
    $base = 0;
    foreach ($snapshot['people'] as $person) {
        $relationships = is_array($person) ? ($person['relationships'] ?? null) : null;
        if (!is_array($relationships)) {
            continue;
        }
        foreach ($relationships as $relationship) {
            if (!is_array($relationship) || ($relationship['kind'] ?? null) === 'corporate_body') {
                continue;
            }
            $participation = $relationship['participation'] ?? null;
            if (!is_array($participation) || ($participation['status'] ?? null) !== 'participates') {
                continue;
            }
            $relationshipBase = $relationship['assessment_base_minor_units'] ?? null;
            if (!is_int($relationshipBase) || $relationshipBase < 0) {
                return null;
            }
            $base += $relationshipBase;
        }
    }

    return $base;
};

$money = static fn (int $minor): string => number_format($minor / 100, 2, ',', ' ') . ' Kč';

$affected = 0;
$unresolved = 0;
$totalDifference = 0;
$perSupplier = [];

foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
    try {
        $source = json_decode((string) $row['source_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        $source = null;
    }
    if (!is_array($source)) {
        continue;
    }
    // v2 už stojí na správném základu; starší závazky nesou `assessment_base_…`.
    if (($source['schema_reference'] ?? null) !== 'payroll-payment-accident-insurance-source.v1') {
        continue;
    }

    $quarterStart = $source['quarter_start'] ?? null;
    $rate = $source['rate_per_mille'] ?? null;
    $cappedBase = $source['assessment_base_minor_units'] ?? null;
    if (!is_string($quarterStart) || !is_string($rate) || !is_int($cappedBase)) {
        $unresolved++;
        continue;
    }

    $uncappedBase = 0;
    $complete = true;
    for ($offset = 0; $offset < 3; $offset++) {
        $month = (new DateTimeImmutable($quarterStart))
            ->modify("+{$offset} month")
            ->format('Y-m-01');
        $base = $monthBase($pdo, (int) $row['supplier_id'], $month);
        if ($base === null) {
            $complete = false;
            break;
        }
        $uncappedBase += $base;
    }
    if (!$complete) {
        printf(
            "tenant %d  %s  NELZE VYČÍSLIT — měsíc čtvrtletí nemá schválený výsledek sociálního pojištění%s",
            (int) $row['supplier_id'],
            $quarterStart,
            PHP_EOL,
        );
        $unresolved++;
        continue;
    }
    if ($uncappedBase === $cappedBase) {
        continue;
    }

    $shouldBe = $calculator->premiumMinor($uncappedBase, $rate);
    $difference = $shouldBe - (int) $row['amount_minor'];
    if ($difference === 0) {
        continue;
    }

    $affected++;
    $totalDifference += $difference;
    $perSupplier[(int) $row['supplier_id']] = ($perSupplier[(int) $row['supplier_id']] ?? 0) + $difference;

    printf(
        'tenant %d  čtvrtletí od %s  splatnost %s  sazba %s ‰%s',
        (int) $row['supplier_id'],
        $quarterStart,
        (string) $row['due_on'],
        $rate,
        PHP_EOL,
    );
    printf(
        '    základ zastropovaný %s → bez maxima %s%s',
        $money($cappedBase),
        $money($uncappedBase),
        PHP_EOL,
    );
    printf(
        '    předepsáno %s → mělo být %s → rozdíl %s%s%s',
        $money((int) $row['amount_minor']),
        $money($shouldBe),
        $difference > 0 ? '+' : '',
        $money($difference),
        PHP_EOL,
    );
}

echo PHP_EOL;
if ($affected === 0 && $unresolved === 0) {
    echo 'ČISTÉ — žádné čtvrtletí nestojí na zastropovaném základu s odlišným výsledkem.' . PHP_EOL;
    exit(0);
}
printf('Dotčených čtvrtletí: %d%s', $affected, PHP_EOL);
if ($unresolved > 0) {
    printf('Nevyčíslených (chybí podklad): %d%s', $unresolved, PHP_EOL);
}
foreach ($perSupplier as $tenant => $difference) {
    printf('  tenant %d: %s%s', $tenant, $money($difference), PHP_EOL);
}
printf('Celkem: %s%s', $money($totalDifference), PHP_EOL);
echo PHP_EOL;
echo 'Rozdíl doplňte opravnou revizí dotčeného čtvrtletí a projednejte ho s pojišťovnou.' . PHP_EOL;
echo 'U nedoplatku § 12 odst. 9 vyhlášky č. 125/1993 Sb. přidává 10 % za každý započatý měsíc prodlení.' . PHP_EOL;
exit($affected > 0 || $unresolved > 0 ? 1 : 0);
