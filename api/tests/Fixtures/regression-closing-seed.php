<?php

declare(strict_types=1);

use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;

if (!isset($pdo, $container, $sid, $user, $currency, $rate) || getenv('MYINVOICE_REGRESSION_SYNTHETIC_DB') !== '1') throw new RuntimeException('Synthetic capture setup only.');
$pdo->exec("DELETE FROM statement_versions WHERE framework='cz_504'");
if ((int) $pdo->query("SELECT COUNT(*) FROM statement_versions WHERE framework='cz_504'")->fetchColumn() !== 0 || (int) $pdo->query("SELECT COUNT(*) FROM statement_versions WHERE framework='cz_500'")->fetchColumn() === 0) throw new RuntimeException('Shared native 500 reference seed required.');
$jointTypeId = $dimensions->createType($sid, false, ['code' => 'JOINT', 'name' => 'Joint closing axis', 'kind' => 'custom']);
$jointType = $dimensions->findType($sid, $jointTypeId);
$jointLeft = $dimensions->createValue($jointType, ['code' => 'L', 'name' => 'Left']);
$jointRight = $dimensions->createValue($jointType, ['code' => 'R', 'name' => 'Right']);
$jointLines = $pdo->prepare("SELECT l.id FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id WHERE l.supplier_id=? AND a.account_code='311' ORDER BY l.id");
$jointLines->execute([$sid]);
foreach ($jointLines->fetchAll(PDO::FETCH_COLUMN) as $lineId) $assignments->insertLineSplits($sid, (int) $lineId, [$jointTypeId => [$jointLeft => 0.3, $jointRight => 0.7]]);
$periods = $container->get(AccountingPeriodRepository::class);
$previous = $periods->findByYear($sid, 2025);
$next = $periods->findByYear($sid, 2026);
$closingRepo = $container->get(\MyInvoice\Repository\ClosingRepository::class);
$entryBuilder = $container->get(\MyInvoice\Service\Accounting\Closing\ClosingEntryBuilder::class);
$pl = $closingRepo->plBalances($sid, (int) $previous['id'], $previous['starts_on'], $previous['ends_on']);
$bs = $closingRepo->bsBalances($sid, (int) $previous['id'], $previous['ends_on']);
$nativeClosing = $entryBuilder->closingLines($pl, $bs);
$posting->postDocument($sid, 'closing', (int) $previous['id'], $nativeClosing['lines'], ['entry_date' => '2025-12-31', 'posted_by' => $user, 'user_id' => $user]);
$posting->postDocument($sid, 'opening', (int) $next['id'], $entryBuilder->openingLines($bs, $nativeClosing['profit']), ['entry_date' => '2026-01-01', 'posted_by' => $user, 'user_id' => $user]);
$cz = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ'")->fetchColumn();
$pdo->prepare("INSERT INTO supplier (company_name,street,city,zip,country_id,email,default_currency_id,default_vat_rate_id,taxpayer_type,accounting_mode,is_vat_payer,stock_enabled,ic,dic,financial_office_code,cz_nace_code,opr_jmeno,opr_prijmeni,opr_postaveni)
    VALUES ('Synthetic closing projections','Test 1','Fixture','11000',?,'closing@example.invalid',?,?,'po','double_entry',1,1,'12345680','CZ12345680','451','62020','Test','Fixture','jednatel')")->execute([$cz, $currency, $rate]);
$closingSupplier = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO currencies (supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default,account_number,bank_code) VALUES (?,'CZK','Synthetic CZK','Kč','CZK','CZK',2,1,1,'1000000005','0100')")->execute([$closingSupplier]);
$ownCurrency = (int) $pdo->lastInsertId();
$pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([$ownCurrency, $closingSupplier]);
$container->get(AccountingModeRepository::class)->record($closingSupplier, '2025-01-01', 'double_entry');
$container->get(ChartOfAccountsSeeder::class)->seedForSupplier($closingSupplier);
$closingPeriod = $container->get(AccountingPeriodRepository::class)->create($closingSupplier, 2025, '2025-12-01', '2025-12-31');
$pdo->prepare("UPDATE accounting_periods SET small_asset_accrual_mode = 'pro_rata' WHERE id = ?")->execute([$closingPeriod]);
$container->get(\MyInvoice\Repository\StatementDefinitionRepository::class)->setFunctionMapping($closingSupplier, '518', 'administration', $user);
$clientFor = static function (string $country, string $dic) use ($pdo, $closingSupplier, $ownCurrency): int {
    $q = $pdo->prepare('SELECT id FROM countries WHERE iso2 = ?'); $q->execute([$country]);
    $pdo->prepare("INSERT INTO clients (supplier_id,company_name,street,city,zip,country_id,dic,main_email,language,currency_default_id,is_customer,is_vendor) VALUES (?,'Synthetic closing customer','Test 1','Fixture','11000',?,?,'fixture@example.invalid','cs',?,1,1)")->execute([$closingSupplier, $q->fetchColumn(), $dic, $ownCurrency]);
    return (int) $pdo->lastInsertId();
};
$vendor = $clientFor('CZ', 'CZ12345681');
$euClient = $clientFor('DE', 'DE123456789');
$invoices = [];
foreach ([[$vendor, '1', 21, 12000], [$euClient, '20', 0, 15000]] as $i => [$clientId, $classification, $percent, $net]) {
    $vat = $net * $percent / 100;
    $pdo->prepare("INSERT INTO invoices (supplier_id,varsymbol,invoice_type,client_id,issue_date,tax_date,due_date,currency_id,exchange_rate,total_without_vat,total_vat,total_with_vat,status,vat_classification_code,created_by)
        VALUES (?,?,'invoice',?,'2025-12-15','2025-12-15','2025-12-20',?,1,?,?,?,'issued',?,?)")->execute([$closingSupplier, '20259900' . $i, $clientId, $ownCurrency, $net, $vat, $net + $vat, $classification, $user]);
    $id = (int) $pdo->lastInsertId(); $invoices[] = $id;
    $pdo->prepare("INSERT INTO invoice_items (invoice_id,description,quantity,unit,unit_price_without_vat,vat_rate_id,vat_rate_snapshot,total_without_vat,total_vat,total_with_vat,order_index,vat_classification_code,accrual_from,accrual_to)
        VALUES (?,'Synthetic annual service',1,'ks',?,?,?,?,?,?,0,?,'2025-12-01','2026-11-30')")->execute([$id, $net, $rate, $percent, $net, $vat, $net + $vat, $classification]);
    $lines = [['account_code' => '311', 'side' => 'debit', 'amount' => $net + $vat], ['account_code' => '602', 'side' => 'credit', 'amount' => $net]];
    if ($vat !== 0) $lines[] = ['account_code' => '343', 'side' => 'credit', 'amount' => $vat];
    $posting->postDocument($closingSupplier, 'invoice', $id, $lines, ['entry_date' => '2025-12-15', 'posted_by' => $user, 'user_id' => $user]);
}
$pdo->prepare("INSERT INTO purchase_invoices (supplier_id,vendor_id,vendor_invoice_number,document_kind,issue_date,tax_date,due_date,received_at,currency_id,exchange_rate,vendor_snapshot,total_without_vat,total_vat,total_with_vat,status,vat_classification_code,vat_deduction,created_by)
    VALUES (?,?,'SYN-PREPAID','invoice','2025-12-15','2025-12-15','2025-12-20','2025-12-15',?,1,'{}',12000,0,12000,'received','40','full',?)")->execute([$closingSupplier, $vendor, $ownCurrency, $user]);
$purchase = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO purchase_invoice_items (purchase_invoice_id,description,quantity,unit,unit_price_without_vat,vat_rate_id,vat_rate_snapshot,total_without_vat,total_vat,total_with_vat,order_index,expense_account_code,accrual_from,accrual_to)
    VALUES (?,'Synthetic annual expense',1,'ks',12000,?,0,12000,0,12000,0,'518','2025-12-01','2026-11-30')")->execute([$purchase, $rate]);
$posting->postDocument($closingSupplier, 'purchase_invoice', $purchase, [['account_code' => '518', 'side' => 'debit', 'amount' => 12000], ['account_code' => '321', 'side' => 'credit', 'amount' => 12000]], ['entry_date' => '2025-12-15', 'posted_by' => $user, 'user_id' => $user]);
$small = $container->get(\MyInvoice\Repository\SmallAssetRepository::class)->insert($closingSupplier, ['name' => 'Synthetic small asset', 'acquisition_date' => '2025-12-01', 'price' => 24000, 'quantity' => 1, 'unit_price' => 24000, 'status' => 'in_use'], $user);
$pdo->prepare("UPDATE small_assets SET put_into_use_date = '2025-12-01', useful_months = 24 WHERE id = ?")->execute([$small]);
$pdo->prepare("INSERT INTO assets (supplier_id,name,inventory_number,acquisition_date,input_price) VALUES (?,'Synthetic deferred asset','SYN-DEFERRED','2025-01-15',1000000)")->execute([$closingSupplier]);
$asset = (int) $pdo->lastInsertId();
foreach (['accounting' => 900000, 'tax' => 800000] as $kind => $residual) $pdo->prepare("INSERT INTO depreciation_entries (supplier_id,asset_id,kind,fiscal_year,amount,full_amount,residual_value_end,status) VALUES (?,?,?,2025,100000,100000,?,'confirmed')")->execute([$closingSupplier, $asset, $kind, $residual]);
$warehouse = $container->get(\MyInvoice\Repository\WarehouseRepository::class)->insert($closingSupplier, ['code' => 'SYN', 'name' => 'Synthetic stock', 'is_default' => true, 'is_active' => true]);
$item = $container->get(\MyInvoice\Repository\StockItemRepository::class)->insert($closingSupplier, ['sku' => 'SYN', 'name' => 'Synthetic goods', 'item_type' => 'goods', 'unit' => 'ks', 'is_active' => true]);
$documents = $container->get(\MyInvoice\Service\Stock\StockDocumentService::class);
$draft = $documents->create($closingSupplier, ['doc_type' => 'receipt', 'origin' => 'manual', 'warehouse_id' => $warehouse, 'doc_date' => '2025-12-15', 'description' => 'Synthetic stock', 'lines' => [['stock_item_id' => $item, 'qty' => '10.000', 'unit_cost' => '100.000000']]], $user);
$documents->post($closingSupplier, (int) $draft['id'], $user);
$pdo->prepare("INSERT INTO exchange_rates (rate_date,currency_code,rate) VALUES ('2025-12-31','EUR',26) ON DUPLICATE KEY UPDATE rate=VALUES(rate)")->execute();
$posting->postDocument($closingSupplier, 'manual', null, [['account_code' => '221', 'side' => 'debit', 'amount' => 25000, 'currency_code' => 'EUR', 'amount_foreign' => 1000, 'fx_rate' => 25], ['account_code' => '411', 'side' => 'credit', 'amount' => 25000]], ['entry_date' => '2025-12-15', 'posted_by' => $user, 'user_id' => $user]);
$archive = $container->get(TaxSubmissionRepository::class);
foreach ([['DphPriznaniBuilder', 'dphdp3', 'B'], ['KontrolniHlaseniBuilder', 'dphkh1', 'B'], ['SouhrnneHlaseniBuilder', 'dphshv', 'R']] as [$builder, $form, $variant]) {
    $built = $container->get('MyInvoice\\Service\\Report\\' . $builder)->build($closingSupplier, 2025, 12, 'monthly');
    $id = $archive->archive($closingSupplier, $form, 2025, 12, null, $built['xml'], $built['summary'], 'passed', [], $user, $variant, 'submitted');
    $pdo->prepare("UPDATE tax_submissions SET submitted_at = '2026-01-10 12:00:00' WHERE id = ?")->execute([$id]);
}
foreach ($invoices as $id) {
    $pdo->prepare('UPDATE invoice_items SET unit_price_without_vat = unit_price_without_vat + 1000, total_without_vat = total_without_vat + 1000, total_vat = total_vat + 1000 * vat_rate_snapshot / 100, total_with_vat = total_with_vat + 1000 + 1000 * vat_rate_snapshot / 100 WHERE invoice_id = ?')->execute([$id]);
}
$container->get(AccountingModeRepository::class)->record($second, '2026-01-01', 'double_entry');
$pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$second]);
