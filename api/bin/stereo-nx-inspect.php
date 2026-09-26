<?php

declare(strict_types=1);

use MyInvoice\Service\Migration\StereoNx\StereoNxBackup;
use MyInvoice\Service\Migration\StereoNx\StereoNxAccountingJournalPlan;
use MyInvoice\Service\Migration\StereoNx\StereoNxPayrollPlan;
use MyInvoice\Service\Migration\StereoNx\StereoNxException;
use MyInvoice\Service\Migration\StereoNx\StereoNxPaymentReconciliation;
use MyInvoice\Service\Migration\StereoNx\StereoNxPurchaseRecap;
use MyInvoice\Service\Migration\StereoNx\StereoNxVat;

require __DIR__ . '/../vendor/autoload.php';

// Heslo lze poslat standardním vstupem. Není argumentem procesu ani součástí výstupu.
$options = getopt('', ['archive:', 'company:', 'password-stdin', 'purchases', 'accounting']);
if (!isset($options['archive'], $options['company']) || !preg_match('/^(0|[1-9][0-9]{0,8})$/D', (string) $options['company'])) {
    fwrite(STDERR, "Usage: php api/bin/stereo-nx-inspect.php --archive=backup.zip --company=0 [--password-stdin] [--purchases] [--accounting]\n");
    exit(2);
}
$password = null;
if (array_key_exists('password-stdin', $options)) {
    $line = fgets(STDIN, 4097);
    if ($line === false || strlen($line) > 4096) {
        fwrite(STDERR, "Heslo nebylo předáno standardním vstupem.\n");
        exit(2);
    }
    $password = rtrim($line, "\r\n");
}
try {
    $backup = StereoNxBackup::open((string) $options['archive'], (int) $options['company'], $password);
    unset($password);
    $tables = $backup->inventory();
    $readable = !array_any($tables, static fn (array $table): bool => $table['status'] !== 'ok');
    $payments = $readable ? StereoNxPaymentReconciliation::check($backup->rows('Cpz'), $backup->rows('CBankap'), $backup->rows('CPokl')) : null;
    $accounting = null;
    $payroll = null;
    if (array_key_exists('accounting', $options) && $readable) {
        $journalPlan = StereoNxAccountingJournalPlan::build(
            iterator_to_array($backup->rows('Cdenik'), false),
            iterator_to_array($backup->rows('Lrozvrh'), false),
        );
        // Do diagnostiky patří pouze souhrny, nikdy čísla dokladů nebo řádky deníku.
        $accounting = ['ok' => $journalPlan['ok'], 'summary' => $journalPlan['summary'],
            'blocker_counts' => array_count_values(array_column($journalPlan['blockers'], 'code')),
            'warning_counts' => array_count_values(array_column($journalPlan['warnings'], 'code'))];
        unset($journalPlan);
        $payroll = StereoNxPayrollPlan::build($backup);
    }
    $purchases = null;
    if (array_key_exists('purchases', $options) && $readable) {
        $mapper = new StereoNxPurchaseRecap(new StereoNxVat($backup->rows('Lsdph')));
        $withItems = [];
        foreach ($backup->rows('Spfp') as $item) {
            $withItems[json_encode([$item['DoklSRada'], $item['DoklSCislo']], JSON_THROW_ON_ERROR)] = true;
        }
        $purchases = ['planned' => 0, 'requires_draft' => 0, 'self_assessed' => 0, 'with_items_unmapped' => 0, 'review_codes' => [], 'errors' => []];
        foreach ($backup->rows('SPFH') as $header) {
            if (isset($withItems[json_encode([$header['DoklSRada'], $header['DoklSCislo']], JSON_THROW_ON_ERROR)])) {
                $purchases['with_items_unmapped']++;
                continue;
            }
            try {
                $plan = $mapper->plan($header);
                $purchases['planned']++;
                $purchases['requires_draft'] += (int) $plan['requires_draft'];
                $purchases['self_assessed'] += (int) $plan['reverse_charge'];
                foreach ($plan['review_codes'] as $code) {
                    $purchases['review_codes'][$code] = ($purchases['review_codes'][$code] ?? 0) + 1;
                }
            } catch (StereoNxException $e) {
                $purchases['errors'][$e->errorCode] = ($purchases['errors'][$e->errorCode] ?? 0) + 1;
            }
        }
    }
    echo json_encode([
        'mode' => 'source_inspection',
        'database_writes' => false,
        'ready_for_import' => false,
        'company_index' => (int) $options['company'],
        'tables' => $tables,
        'payments' => $payments,
        'purchase_recap' => $purchases,
        'accounting_journal' => $accounting,
        'payroll_relations' => $payroll,
        'limitations' => ['Kontrola zdroje není zkouška importu nanečisto. Databázovou zkoušku a převod spusťte v průvodci Stereo NX v aplikaci.'],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit($readable && ($payments['ok'] ?? false) && ($accounting === null || $accounting['ok'])
        && ($payroll === null || $payroll['ok'])
        && ($purchases === null || ($purchases['errors'] === [] && $purchases['with_items_unmapped'] === 0)) ? 0 : 1);
} catch (StereoNxException $e) {
    fwrite(STDERR, $e->errorCode . ': ' . $e->getMessage() . PHP_EOL);
    exit(1);
} catch (Throwable) {
    // Reader může mít v diagnostice hodnotu pole; firemní data do CLI chyb nepouštíme.
    fwrite(STDERR, "Zálohu nelze ověřit. Nepodporovaný nebo poškozený formát NX1.\n");
    exit(1);
}
