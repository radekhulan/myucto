<?php

declare(strict_types=1);

/**
 * Vyčíslení rozdílu u zákonného pojištění odpovědnosti předepsaného starým
 * pravidlem pro vyměřovací základ.
 *
 * Materializér do 31. 8. 2026 sčítal `capped_assessment_base_minor_units` přes
 * celou firmu. To je vadné ve dvou ohledech, které táhnou OPAČNÝM směrem:
 *
 *  - **roční maximum** podle § 15a zákona č. 589/1992 Sb. se na tohle pojištění
 *    nevztahuje. § 12 odst. 2 vyhlášky č. 125/1993 Sb. z toho zákona přebírá jen
 *    § 5 odst. 1 písm. a), tedy KTERÉ příjmy do základu patří; maximum je
 *    samostatné omezení až v § 15a. Shodně to vykládají Kooperativa i Generali
 *    Česká pojišťovna a stanovisko Ministerstva financí z 20. 5. 2008.
 *    Zastropováním se pojistné PODHODNOCOVALO;
 *  - **vztahy druhu `corporate_body`** (příjem společníka a odměna za výkon
 *    funkce) do základu nepatří — pojištěni jsou zaměstnanci pro případ
 *    pracovního úrazu a nemoci z povolání a Kooperativa je ze základu výslovně
 *    vylučuje, přestože se za ně sociální pojistné odvádí. Jejich započtením se
 *    pojistné NADHODNOCOVALO.
 *
 * Čtvrtletí proto může skončit nedoplatkem i přeplatkem podle toho, která z vad
 * převáží. Skript obojí rozlišuje a u každého čtvrtletí vypíše, kolik odměn
 * orgánů ze základu vypadlo. Vyčísluje se po čtvrtletích: nedoplatek u každého
 * z nich stárne zvlášť (§ 12 odst. 9 přidává 10 % za každý započatý měsíc
 * prodlení) a přeplatek se podle § 12 odst. 6 vrací jen na žádost plátce.
 *
 * Skript je READ-ONLY. Nic nepředepisuje ani neopravuje — rozdíl se doplňuje
 * opravnou revizí dotčeného čtvrtletí a řeší se s pojišťovnou. Přepsat už
 * předepsaný závazek na místě by smazalo stopu, podle které se dohledá, co se
 * pojišťovně skutečně poslalo, a databáze to stejně nedovolí; závazky jsou
 * neměnné.
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
 * Základ měsíce rozpadlý na to, co do pojištění patří, a na odměny orgánů.
 *
 * Zaměstnanecká část se sčítá stejným pravidlem jako v materializéru: základ
 * vztahu PŘED ročním maximem a jen účastnící se vztahy. Kdyby se tu sčítalo
 * jinak, vyčíslený rozdíl by neodpovídal tomu, co aplikace předepíše.
 *
 * @return array{employment:int,corporate_body:int}|null
 */
$monthBase = static function (PDO $pdo, int $supplierId, string $monthStart): ?array {
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

    $employment = 0;
    $corporateBody = 0;
    foreach ($snapshot['people'] as $person) {
        $relationships = is_array($person) ? ($person['relationships'] ?? null) : null;
        if (!is_array($relationships)) {
            continue;
        }
        foreach ($relationships as $relationship) {
            if (!is_array($relationship)) {
                continue;
            }
            $participation = $relationship['participation'] ?? null;
            if (!is_array($participation) || ($participation['status'] ?? null) !== 'participates') {
                continue;
            }
            $base = $relationship['assessment_base_minor_units'] ?? null;
            if (!is_int($base) || $base < 0) {
                return null;
            }
            // Odměny orgánů se sčítají zvlášť: do základu nepatří, ale u
            // čtvrtletí předepsaných starým pravidlem se z nich pojistné
            // odvedlo, takže je potřeba je vyčíslit.
            if (($relationship['kind'] ?? null) === 'corporate_body') {
                $corporateBody += $base;
                continue;
            }
            $employment += $base;
        }
    }

    return ['employment' => $employment, 'corporate_body' => $corporateBody];
};

$money = static fn (int $minor): string => number_format($minor / 100, 2, ',', ' ') . ' Kč';

$affected = 0;
$unresolved = 0;
$underpaid = 0;
$overpaid = 0;
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
    $oldBase = $source['assessment_base_minor_units'] ?? null;
    if (!is_string($quarterStart) || !is_string($rate) || !is_int($oldBase)) {
        $unresolved++;
        continue;
    }

    $correctBase = 0;
    $corporateBodyBase = 0;
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
        $correctBase += $base['employment'];
        $corporateBodyBase += $base['corporate_body'];
    }
    if (!$complete) {
        printf(
            'tenant %d  %s  NELZE VYČÍSLIT — měsíc čtvrtletí nemá schválený výsledek sociálního pojištění%s',
            (int) $row['supplier_id'],
            $quarterStart,
            PHP_EOL,
        );
        $unresolved++;
        continue;
    }

    $shouldBe = $calculator->premiumMinor($correctBase, $rate);
    $difference = $shouldBe - (int) $row['amount_minor'];
    if ($difference === 0) {
        continue;
    }

    $affected++;
    $totalDifference += $difference;
    if ($difference > 0) {
        $underpaid += $difference;
    } else {
        $overpaid += -$difference;
    }
    $perSupplier[(int) $row['supplier_id']] =
        ($perSupplier[(int) $row['supplier_id']] ?? 0) + $difference;

    printf(
        'tenant %d  čtvrtletí od %s  splatnost %s  sazba %s ‰  %s%s',
        (int) $row['supplier_id'],
        $quarterStart,
        (string) $row['due_on'],
        $rate,
        $difference > 0 ? 'NEDOPLATEK' : 'PŘEPLATEK',
        PHP_EOL,
    );
    printf(
        '    základ podle starého pravidla %s → správně %s%s',
        $money($oldBase),
        $money($correctBase),
        PHP_EOL,
    );
    // Obě příčiny táhnou opačným směrem, takže samotný rozdíl neřekne, co se
    // stalo. Bez tohohle řádku by účetní nevěděla, o čem s pojišťovnou mluví.
    if ($corporateBodyBase > 0) {
        printf(
            '    z toho vyloučeny odměny orgánů a příjmy společníků: %s%s',
            $money($corporateBodyBase),
            PHP_EOL,
        );
    }
    printf(
        '    předepsáno %s → mělo být %s → rozdíl %s%s%s',
        $money((int) $row['amount_minor']),
        $money($shouldBe),
        $difference > 0 ? '+' : '−',
        $money(abs($difference)),
        PHP_EOL,
    );
}

echo PHP_EOL;
if ($affected === 0 && $unresolved === 0) {
    echo 'ČISTÉ — žádné čtvrtletí nestojí na starém pravidle s odlišným výsledkem.' . PHP_EOL;
    exit(0);
}
printf('Dotčených čtvrtletí: %d%s', $affected, PHP_EOL);
if ($unresolved > 0) {
    printf('Nevyčíslených (chybí podklad): %d%s', $unresolved, PHP_EOL);
}
foreach ($perSupplier as $tenant => $difference) {
    printf(
        '  tenant %d: %s %s%s',
        $tenant,
        $difference >= 0 ? 'nedoplatek' : 'přeplatek',
        $money(abs($difference)),
        PHP_EOL,
    );
}
printf('Nedoplatky celkem: %s%s', $money($underpaid), PHP_EOL);
printf('Přeplatky celkem:  %s%s', $money($overpaid), PHP_EOL);
printf('Netto: %s%s%s', $totalDifference >= 0 ? '+' : '−', $money(abs($totalDifference)), PHP_EOL);
echo PHP_EOL;
echo 'Rozdíl doplňte opravnou revizí dotčeného čtvrtletí a projednejte ho s pojišťovnou.' . PHP_EOL;
echo 'Nedoplatek: § 12 odst. 9 vyhlášky č. 125/1993 Sb. ho zvyšuje o 10 % za každý započatý' . PHP_EOL;
echo 'měsíc prodlení, takže každé čtvrtletí stárne zvlášť a čekáním se prodraží.' . PHP_EOL;
echo 'Přeplatek: § 12 odst. 6 ho vrací jen na žádost plátce. Sám se nevrátí.' . PHP_EOL;
exit(1);
