<?php

declare(strict_types=1);

/**
 * Oprava dokladů ve stavu „uhrazeno", které evidované úhrady nepokrývají.
 *
 * Uhrazený smí být doklad jen tehdy, když úhradu nese evidence (platby vydaného dokladu,
 * spárovaná banka, pokladna, zápočty, zápočty dobropisů, chráněné úhrady převzaté z jiného
 * programu; u přijatého dokladu totéž přes PurchaseSettledExpr). Import souborů dřív
 * označil vydané doklady s prošlou splatností jako uhrazené bez jediné platby a převody
 * přijatých dokladů přebíraly stav ze zdroje bez krytí. Takové doklady chybí v pohledávkách,
 * závazcích, upomínkách, stárnutí i v zápočtech.
 *
 * Daňové doklady k přijaté platbě (DDKP) převedené s uhrazenou částkou bez platby převede na
 * tvar MyÚčta (odečtená záloha, k úhradě 0) {@see \MyInvoice\Service\Invoice\TaxDocumentSettlementRepair}.
 *
 * Skript vrátí doklad na neuhrazený stejně jako „Vrátit na neuhrazený" v aplikaci, ale
 * platby nemaže: skutečné částečné úhrady zůstávají. Vydaný doklad přejde na sent/issued
 * s vypnutými automatickými upomínkami a bez webhooku o vrácení úhrady (výpis je počítá),
 * přijatý na booked (zaúčtovaný) nebo received. Přijatým dokladům z Fakturoidu či iDokladu
 * uhrazeným ve zdroji jen doplní krytí úhrady. Deník ani DPH se nemění; v daňové evidenci
 * by vrácení přijatého dokladu odebralo výdaj z peněžního deníku, proto jen s
 * --include-tax-evidence. Pravidla výběru, kategorie a pojistky jsou
 * v {@see \MyInvoice\Service\Invoice\PaidWithoutPaymentRepair}.
 *
 * Kategorie ve výpisu:
 *   reset              k opravě (v deníku nevyrovnáno nebo nezaúčtováno, i mimo účetnictví firmy)
 *   locked             uzavřené nebo zamčené období, opraví se, pokud není --skip-locked
 *   ledger_closed      zaplaceno mimo MyÚčto (v deníku vyrovnáno), ponechá se, opraví jen
 *                      --include-ledger-closed
 *   manual             přijatý doklad označený jako uhrazený uživatelem, opraví jen --include-manual
 *   linked_unrecorded  spárovaná úhrada bez záznamu platby, neopravuje se (patří do párování)
 *   advance_drawn      záloha čerpaná konečnou fakturou, neopravuje se
 *   source_paid        přijatý doklad uhrazený ve Fakturoidu či iDokladu, doplní se krytí úhrady
 *   migration_source   přijatý doklad převedený z účetního programu, neopravuje se (krytí doplní
 *                      Kontrola dat po převodu, purchase_settled_uncovered)
 *   tax_evidence       přijatý doklad firmy v daňové evidenci, opraví jen --include-tax-evidence
 *   other_item         uhrazeno úhradami ostatní pohledávky, neopravuje se
 *   tax_document_settle  DDKP, který nese úplatu uhrazenou částkou bez platby: převede se na
 *                      odečtenou zálohu (k úhradě 0) jako DDKP vystavený v MyÚčtu, stav se nemění
 *   foreign_pending    DDKP čekající na převod do měny dokladu (POHODA), neopravuje se
 *   unbacked_paid_total  uhrazená částka převzatá bez záznamu platby (paid_total nad platbami),
 *                      stav se nevrací nikdy, evidenci úhrady doplní
 *                      api/bin/repair-issued-remainders.php a repair-pohoda-prior-year-payments.php
 *
 * Krytí úhrady (úhrada mimo evidenci) doplněné přijatým dokladům z Fakturoidu či iDokladu
 * snižuje i otevřené závazky v přehledu k přechodu z daňové evidence (TransitionReportService
 * odečítá `settled_outside_amount` bez ohledu na datum). Krytí se nedoplňuje za zámkem
 * účtování k datu úhrady ani v podaném období DPH.
 *
 * Výchozí je zkouška nanečisto s výpisem, zápis jen s --commit. Idempotentní.
 *
 * Použití:
 *   php api/bin/repair-paid-without-payment.php --all                              # nanečisto, vydané doklady všech firem
 *   php api/bin/repair-paid-without-payment.php --all --purchase --quiet           # i přijaté doklady, jen souhrn po firmách
 *   php api/bin/repair-paid-without-payment.php --supplier=12 --supplier=14        # vybrané firmy
 *   php api/bin/repair-paid-without-payment.php --supplier=12 --commit             # zápis
 *   php api/bin/repair-paid-without-payment.php --all --purchase --report=/tmp/paid.json
 *
 * Volby:
 *   --purchase               zpracovat i přijaté doklady
 *   --include-ledger-closed  opravit i doklady vyrovnané v deníku
 *   --include-manual         opravit i přijaté doklady označené jako uhrazené uživatelem
 *   --include-tax-evidence   vrátit i přijaté doklady firem v daňové evidenci (odebere výdaj
 *                            z peněžního deníku, i v podaném období)
 *   --skip-locked            neopravovat doklady v uzavřeném nebo zamčeném období
 *   --report=SOUBOR          uložit výsledek jako JSON
 *   --quiet                  bez výpisu jednotlivých dokladů
 */

use MyInvoice\Bootstrap;
use MyInvoice\Service\Invoice\PaidWithoutPaymentRepair;
use MyInvoice\Service\Invoice\TaxDocumentSettlementRepair;

if (PHP_SAPI !== 'cli') {
    exit("Jen z příkazové řádky.\n");
}

$usage = 'Použití: php api/bin/repair-paid-without-payment.php --supplier=ID|--all [--purchase] [--commit] '
    . '[--include-ledger-closed] [--include-manual] [--skip-locked] [--report=SOUBOR] [--quiet]';
$flags = ['--all' => false, '--purchase' => false, '--commit' => false, '--include-ledger-closed' => false, '--include-tax-evidence' => false,
    '--include-manual' => false, '--skip-locked' => false, '--quiet' => false];
$suppliers = [];
$reportFile = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help') {
        echo $usage . PHP_EOL;
        exit(0);
    }
    if (array_key_exists($arg, $flags)) {
        $flags[$arg] = true;
    } elseif (str_starts_with($arg, '--supplier=')) {
        $id = filter_var(substr($arg, 11), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
        if (!is_int($id)) {
            fwrite(STDERR, 'Neplatné --supplier.' . PHP_EOL . $usage . PHP_EOL);
            exit(2);
        }
        $suppliers[$id] = $id;
    } elseif (str_starts_with($arg, '--report=') && strlen($arg) > 9) {
        $reportFile = substr($arg, 9);
    } else {
        fwrite(STDERR, 'Neplatný parametr: ' . $arg . PHP_EOL . $usage . PHP_EOL);
        exit(2);
    }
}
if ($flags['--all'] === ($suppliers !== [])) {
    fwrite(STDERR, 'Zadejte --supplier=ID (i opakovaně), nebo --all.' . PHP_EOL . $usage . PHP_EOL);
    exit(2);
}

require __DIR__ . '/../vendor/autoload.php';

$commit = $flags['--commit'];
$options = [
    'include_ledger_closed' => $flags['--include-ledger-closed'],
    'include_manual' => $flags['--include-manual'],
    'skip_locked' => $flags['--skip-locked'],
    'include_tax_evidence' => $flags['--include-tax-evidence'],
];
$docTypes = $flags['--purchase'] ? ['invoice', 'tax_document', 'purchase_invoice'] : ['invoice', 'tax_document'];
$labels = [
    PaidWithoutPaymentRepair::RESET => 'k opravě',
    PaidWithoutPaymentRepair::LOCKED => 'zamčené období',
    PaidWithoutPaymentRepair::LEDGER_CLOSED => 'zaplaceno mimo MyÚčto, ponechat',
    PaidWithoutPaymentRepair::MANUAL => 'označeno uživatelem',
    PaidWithoutPaymentRepair::LINKED_UNRECORDED => 'spárováno bez záznamu platby',
    PaidWithoutPaymentRepair::OTHER_ITEM => 'ostatní pohledávka',
    PaidWithoutPaymentRepair::ADVANCE_DRAWN => 'záloha čerpaná konečnou fakturou, ponechat',
    PaidWithoutPaymentRepair::SOURCE_PAID => 'uhrazeno ve Fakturoidu/iDokladu, doplnit krytí',
    PaidWithoutPaymentRepair::MIGRATION_SOURCE => 'převod z účetního programu, krytí doplní Kontrola dat po převodu',
    PaidWithoutPaymentRepair::TAX_EVIDENCE => 'daňová evidence, ponechat (--include-tax-evidence)',
    PaidWithoutPaymentRepair::UNBACKED_PAID_TOTAL => 'uhrazeno bez záznamu platby, doplnit evidenci (oprava převodu)',
    TaxDocumentSettlementRepair::SETTLE => 'DDKP k převedení na odečtenou zálohu',
    TaxDocumentSettlementRepair::FOREIGN_PENDING => 'DDKP čeká na převod měny, ponechat',
];
$docLabels = ['invoice' => 'Vydané', 'tax_document' => 'DDKP', 'purchase_invoice' => 'Přijaté'];
$fmt = static fn (float $v): string => number_format($v, 2, ',', ' ');
// DDKP nic nedluží: jeho částka k úhradě se převede na odečtenou zálohu, nic nechybí.
$amountLabel = static fn (string $docType): string => $docType === 'tax_document' ? 'k úhradě převedeno na zálohu' : 'chybí';

try {
    $container = Bootstrap::buildContainer();
    $repair = $container->get(PaidWithoutPaymentRepair::class);
    $taxDocuments = $container->get(TaxDocumentSettlementRepair::class);
    $all = $repair->suppliers();
    if (!$flags['--all']) {
        $known = array_column($all, null, 'id');
        foreach ($suppliers as $id) {
            if (!isset($known[$id])) {
                throw new RuntimeException('Firma #' . $id . ' neexistuje.');
            }
        }
        $all = array_values(array_filter($all, static fn (array $s): bool => isset($suppliers[$s['id']])));
    }

    echo ($commit ? '' : '[NANEČISTO] ') . 'Doklady uhrazené bez evidované úhrady' . PHP_EOL;
    $report = ['generated_at' => date('c'), 'commit' => $commit, 'options' => $options, 'doc_types' => $docTypes, 'suppliers' => []];
    $grand = [];
    $effects = ['reminders_disabled' => 0, 'webhook_suppressed' => 0];
    foreach ($all as $supplier) {
        $firm = ['id' => $supplier['id'], 'name' => $supplier['name'], 'summary' => [], 'documents' => []];
        foreach ($docTypes as $docType) {
            $rows = $docType === 'tax_document'
                ? $taxDocuments->run($supplier['id'], $commit, $options)
                : $repair->run($supplier['id'], $docType, $commit, $options);
            foreach ($rows as $row) {
                $key = $docType . '|' . $row['category'];
                $firm['summary'][$key] ??= ['doc_type' => $docType, 'category' => $row['category'], 'count' => 0, 'missing_czk' => 0.0, 'reset' => 0, 'failed' => 0];
                $firm['summary'][$key]['count']++;
                $firm['summary'][$key]['missing_czk'] = round($firm['summary'][$key]['missing_czk'] + $row['missing_czk'], 2);
                $firm['summary'][$key]['reset'] += (int) in_array($row['outcome'], ['reset', 'covered'], true);
                $effects['reminders_disabled'] += (int) (($row['reminders_disabled'] ?? false) && in_array($row['outcome'], ['reset', 'would_reset'], true));
                $effects['webhook_suppressed'] += (int) (($row['webhook_suppressed'] ?? false) && in_array($row['outcome'], ['reset', 'would_reset'], true));
                $firm['summary'][$key]['failed'] += (int) ($row['outcome'] === 'failed');
                $firm['documents'][] = $row;
            }
        }
        if ($firm['documents'] === []) {
            continue;
        }
        ksort($firm['summary']);
        $firm['summary'] = array_values($firm['summary']);
        printf("%sFirma #%d %s%s", PHP_EOL, $firm['id'], $firm['name'], PHP_EOL);
        foreach ($firm['summary'] as $s) {
            printf("  %s, %s: %d dokladů, %s %s Kč%s%s\n", $docLabels[$s['doc_type']], $labels[$s['category']], $s['count'], $amountLabel($s['doc_type']), $fmt($s['missing_czk']),
                $s['reset'] > 0 ? ', opraveno ' . $s['reset'] : '', $s['failed'] > 0 ? ', chyba ' . $s['failed'] : '');
            $g = $s['doc_type'] . '|' . $s['category'];
            $grand[$g] ??= ['doc_type' => $s['doc_type'], 'category' => $s['category'], 'count' => 0, 'missing_czk' => 0.0, 'reset' => 0, 'failed' => 0, 'suppliers' => 0];
            foreach (['count', 'reset', 'failed'] as $k) {
                $grand[$g][$k] += $s[$k];
            }
            $grand[$g]['missing_czk'] = round($grand[$g]['missing_czk'] + $s['missing_czk'], 2);
            $grand[$g]['suppliers']++;
        }
        if (!$flags['--quiet']) {
            foreach ($firm['documents'] as $d) {
                printf("    %s #%d %-20s %-12s %s  k úhradě %s %s  uhrazeno %s  %s %s  deník %s  původ %s  %s%s\n",
                    ['invoice' => 'FV', 'tax_document' => 'DDKP', 'purchase_invoice' => 'FP'][$d['doc_type']], $d['id'], $d['document_no'], $d['invoice_type'], $d['issue_date'],
                    $fmt($d['amount_to_pay']), $d['currency'], $fmt($d['settled']), $amountLabel($d['doc_type']), $fmt($d['missing']), $d['ledger'], $d['origin'],
                    $labels[$d['category']], match ($d['outcome']) {
                        'reset' => ', opraveno',
                        'covered' => ', krytí doplněno',
                        'would_reset' => ', k vrácení',
                        'would_cover' => ', k doplnění krytí',
                        'failed' => ', chyba: ' . $d['reason'],
                        'skipped' => ', mezitím změněno',
                        default => ', ponecháno',
                    });
            }
        }
        $report['suppliers'][] = $firm;
    }

    ksort($grand);
    $report['summary'] = array_values($grand);
    echo PHP_EOL . 'Celkem' . PHP_EOL;
    if ($grand === []) {
        echo '  Žádný doklad uhrazený bez úhrady.' . PHP_EOL;
    }
    $failed = 0;
    foreach ($report['summary'] as $s) {
        printf("  %s, %s: %d dokladů v %d firmách, %s %s Kč%s\n", $docLabels[$s['doc_type']], $labels[$s['category']], $s['count'], $s['suppliers'],
            $amountLabel($s['doc_type']), $fmt($s['missing_czk']), $commit ? ', opraveno ' . $s['reset'] . ($s['failed'] > 0 ? ', chyba ' . $s['failed'] : '') : '');
        $failed += $s['failed'];
    }
    printf("%s: %d vydaných dokladů dostane vypnuté automatické upomínky, %d webhooků o vrácení úhrady se nevydá.\n",
        $commit ? 'Provedeno' : 'Při zápisu', $effects['reminders_disabled'], $effects['webhook_suppressed']);
    $report['effects'] = $effects;
    if ($reportFile !== null) {
        if (file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL) === false) {
            throw new RuntimeException('Report nejde zapsat do ' . $reportFile . '.');
        }
        echo 'Report: ' . $reportFile . PHP_EOL;
    }
    if (!$commit && $grand !== []) {
        echo PHP_EOL . 'Nic nezměněno. Zápis provede spuštění s --commit.' . PHP_EOL;
    }
    exit($failed > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
