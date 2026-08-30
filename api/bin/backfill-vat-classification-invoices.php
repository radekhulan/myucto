<?php

declare(strict_types=1);

/**
 * Backfill chybějících `vat_classification_code` na invoice_items
 * (VYSTAVENÉ faktury) podle vat_rate_snapshot.
 *
 * Use case: vystavené faktury vytvořené před existencí auto-klasifikace
 * v InvoiceRepository::replaceItems() nemají `vat_classification_code` →
 * VatClassificationMapper SKIPNE → faktury nedorazí do DPH přiznání ani KH.
 *
 * Mapování (sale, tuzemsko):
 *   21%  → '1' (Dodání zboží/služby tuzemsko — základní)
 *   12%  → '2' (Dodání zboží/služby tuzemsko — snížená)
 *   0%   → '3' (Dodání tuzemsko osvobozeno)
 *
 * Pro dodávky do EU (kódy 20, 22) / vývoz (26) si uživatel musí kód změnit
 * ručně v UI — defaultem je tuzemsko.
 *
 * Použití:
 *   php api/bin/backfill-vat-classification-invoices.php                # dry-run, jen NULL řádky
 *   php api/bin/backfill-vat-classification-invoices.php --apply        # zápis NULL řádků
 *   php api/bin/backfill-vat-classification-invoices.php --force        # dry-run, VŠECHNY řádky
 *   php api/bin/backfill-vat-classification-invoices.php --force --apply# přepíše VŠECHNY řádky kde derived != current
 */

require __DIR__ . '/../vendor/autoload.php';

$dryRun = !in_array('--apply', $argv, true);
$force  = in_array('--force', $argv, true);

$app = \MyInvoice\Bootstrap::buildApp();
$container = $app->getContainer();
$pdo = $container->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo();
// Základní sazba § 47 ZDPH se bere podle ROKU DOKLADU z číselníku daňových konstant —
// backfill sahá do historie, takže dnešní sazba je pro starý doklad špatná odpověď.
$taxConstants = $container->get(\MyInvoice\Repository\TaxConstantsRepository::class);
$standardRateByYear = [];

// JOIN přes client → země pro správný code (EU 0% → '22' služby, vývoz → '26', CZ → '1'/'2'/'3')
// Bez --force: jen NULL řádky. S --force: všechny (přepíše rozdíly).
$whereCode = $force ? "" : "AND ii.vat_classification_code IS NULL";
$stmt = $pdo->query(
    "SELECT ii.id, ii.invoice_id, ii.vat_rate_snapshot, ii.vat_classification_code AS current_code,
            i.supplier_id, i.varsymbol, i.status, i.invoice_type, i.reverse_charge,
            COALESCE(i.tax_date, i.issue_date) AS doc_date,
            ii.unit,
            co.iso2 AS client_country
       FROM invoice_items ii
       JOIN invoices i  ON i.id  = ii.invoice_id
       JOIN clients c   ON c.id  = i.client_id
       JOIN countries co ON co.id = c.country_id
      WHERE i.status NOT IN ('draft', 'cancelled')
        AND i.invoice_type != 'proforma'
        {$whereCode}
      ORDER BY i.supplier_id, i.id, ii.id"
);
$items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

if (empty($items)) {
    echo "Žádné řádky " . ($force ? "" : "bez vat_classification_code ") . "— nic k doplnění.\n";
    exit(0);
}

$mode = ($dryRun ? '[DRY-RUN] ' : '') . ($force ? '[FORCE] ' : '');
echo "{$mode}Procházím " . count($items) . " invoice_items:\n\n";

$counts = [];
$updateStmt = $pdo->prepare(
    "UPDATE invoice_items SET vat_classification_code = ? WHERE id = ?"
);

$skipped = 0;
foreach ($items as $it) {
    $rate = (float) $it['vat_rate_snapshot'];
    $current = $it['current_code'];
    $docYear = !empty($it['doc_date']) ? (int) substr((string) $it['doc_date'], 0, 4) : (int) date('Y');
    $standardRateByYear[$docYear] ??= $taxConstants->vatRateStandard($docYear);
    $derived = \MyInvoice\Repository\InvoiceRepository::defaultSaleClassificationCode(
        $rate,
        (bool) $it['reverse_charge'],
        (string) $it['client_country'],
        ((string) ($it['unit'] ?? '')) !== '' ? (string) $it['unit'] : null,
        $standardRateByYear[$docYear],
    );

    // Pokud derived == current, nic neměníme (i s --force)
    if ($current !== null && $current === $derived) {
        $skipped++;
        continue;
    }
    if ($derived === null) {
        $skipped++;
        continue;
    }

    $arrow = $current !== null ? "{$current} → {$derived}" : "→ {$derived}";
    $line = sprintf(
        "  item#%-6d inv#%-6d tenant=%-2d  %-9s  rate=%5.2f%%  ccy=%-3s  vs=%s  %s",
        $it['id'],
        $it['invoice_id'],
        $it['supplier_id'],
        $it['status'],
        $rate,
        $it['client_country'],
        $it['varsymbol'] ?: '(none)',
        $arrow
    );

    $counts[$derived] = ($counts[$derived] ?? 0) + 1;
    if (!$dryRun) {
        $updateStmt->execute([$derived, $it['id']]);
    }
    echo $line . "\n";
}

echo "\nSouhrn změn:\n";
ksort($counts);
foreach ($counts as $k => $v) echo "  kód {$k} → {$v}\n";
echo "  beze změny: {$skipped}\n";

if ($dryRun) {
    echo "\nSpusť znovu s --apply pro skutečný zápis.\n";
} else {
    echo "\nHotovo. Po backfill spusť 'Přepočítat' v /crm dashboardu, aby se DPH přiznání aktualizovala.\n";
}
