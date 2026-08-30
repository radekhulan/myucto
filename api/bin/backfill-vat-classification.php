<?php

declare(strict_types=1);

/**
 * Backfill chybějících `vat_classification_code` na purchase_invoice_items
 * (a fallback na purchase_invoices) podle vat_rate_snapshot.
 *
 * Use case: faktury importované přes AI / Pohoda XML / ručně vytvořené před
 * existencí auto-klasifikace nemají `vat_classification_code`. VatClassificationMapper
 * tyhle řádky SKIPNE → faktury nedorazí do DPH přiznání ani KH.
 *
 * Mapování (purchase, tuzemsko, s nárokem na odpočet):
 *   21% → '40'
 *   12% → '41'
 *   0%  → ponechat NULL (nelze rozumně guessnout)
 *
 * Pro EU acquire / RC / dovoz si uživatel musí kód změnit ručně v UI — defaultem
 * je tuzemsko (nejčastější případ pro CZ tenanta).
 *
 * Použití:
 *   php api/bin/backfill-vat-classification.php                # dry-run, jen NULL řádky
 *   php api/bin/backfill-vat-classification.php --apply        # zápis NULL řádků
 *   php api/bin/backfill-vat-classification.php --force        # dry-run, VŠECHNY řádky (i s existujícím kódem)
 *   php api/bin/backfill-vat-classification.php --force --apply# přepíše VŠECHNY řádky kde derived != current
 */

require __DIR__ . '/../vendor/autoload.php';

$dryRun = !in_array('--apply', $argv, true);
$force  = in_array('--force', $argv, true);

$app = \MyInvoice\Bootstrap::buildApp();
$container = $app->getContainer();
$pdo = $container->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo();
// Základní sazba § 47 ZDPH podle ROKU DOKLADU z číselníku daňových konstant — backfill
// sahá do historie, takže dnešní sazba je pro starý doklad špatná odpověď (symetricky
// s backfill-vat-classification-invoices.php na vystavené straně).
$taxConstants = $container->get(\MyInvoice\Repository\TaxConstantsRepository::class);
$standardRateByYear = [];

// JOIN přes vendor → země pro správný code (EU → '24', non-EU → '25', CZ → '40'/'41' atd.)
// Bez --force: jen NULL řádky. S --force: všechny (porovná derived vs current, přepíše rozdíly).
$whereCode = $force ? "" : "AND pii.vat_classification_code IS NULL";
$stmt = $pdo->query(
    "SELECT pii.id, pii.purchase_invoice_id, pii.vat_rate_snapshot, pii.vat_classification_code AS current_code,
            pi.supplier_id, pi.vendor_invoice_number, pi.status, pi.reverse_charge,
            COALESCE(pi.tax_date, pi.issue_date) AS doc_date,
            co.iso2 AS vendor_country
       FROM purchase_invoice_items pii
       JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id
       JOIN clients c            ON c.id  = pi.vendor_id
       JOIN countries co         ON co.id = c.country_id
      WHERE pi.status != 'cancelled'
        {$whereCode}
      ORDER BY pi.supplier_id, pi.id, pii.id"
);
$items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

if (empty($items)) {
    echo "Žádné řádky " . ($force ? "" : "bez vat_classification_code ") . "— nic k doplnění.\n";
    exit(0);
}

$mode = ($dryRun ? '[DRY-RUN] ' : '') . ($force ? '[FORCE] ' : '');
echo "{$mode}Procházím " . count($items) . " purchase_invoice_items:\n\n";

$counts = [];
$updateStmt = $pdo->prepare(
    "UPDATE purchase_invoice_items SET vat_classification_code = ? WHERE id = ?"
);

$skipped = 0;
foreach ($items as $it) {
    $rate = (float) $it['vat_rate_snapshot'];
    $current = $it['current_code'];
    $docYear = !empty($it['doc_date']) ? (int) substr((string) $it['doc_date'], 0, 4) : (int) date('Y');
    $standardRateByYear[$docYear] ??= $taxConstants->vatRateStandard($docYear);
    $derived = \MyInvoice\Repository\PurchaseInvoiceRepository::defaultClassificationCode(
        $rate,
        (bool) $it['reverse_charge'],
        (string) $it['vendor_country'],
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
        "  item#%-6d pi#%-6d tenant=%-2d  %-9s  rate=%5.2f%%  ccy=%-3s  vendor#=%s  %s",
        $it['id'],
        $it['purchase_invoice_id'],
        $it['supplier_id'],
        $it['status'],
        $rate,
        $it['vendor_country'],
        $it['vendor_invoice_number'] ?: '(none)',
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
