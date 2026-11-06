<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Repository\SupplierBankAccountRepository;
use MyInvoice\Service\Bank\StatementBalanceService;
use MyInvoice\Service\Migration\MoneyS3\AccountCode;
use MyInvoice\Service\Migration\Shared\BankAccountRegistrar;
use MyInvoice\Service\Migration\Shared\BankStatementImportWriter;
use MyInvoice\Service\Migration\Shared\BankSymbols;
use MyInvoice\Service\Migration\Shared\MigratedCashNumber;
use MyInvoice\Service\Migration\Shared\MigratedDocumentItem;
use PDO;

/**
 * Pokladny a pokladní doklady (`32_pokladny.xml`, `28_pokladna.xml`), bankovní účty
 * a pohyby (`31_bankovni_ucty.xml`, `29_banka.xml`).
 *
 * Pohoda na rozdíl od Money vede skutečné bankovní výpisy (`statementNumber`), takže
 * výpis v MyÚčtu = výpis z Pohody (zdroj `import`). Stav účtu na začátku roku je
 * otevírací zápis analytiky 221 v deníku, dál se počítá po pohybech.
 */
final class CashBankImporter
{
    public const STEP_CASH = 'cash';
    public const STEP_BANK = 'bank';

    private const BUCKETS = ['Low' => 12.0, 'High' => 21.0, '3' => 10.0];

    public function __construct(
        private readonly Connection $db,
        private readonly PohodaImportRepository $map,
        private readonly SupplierBankAccountRepository $bankAccounts,
        private readonly MigratedCashNumber $cashNumbers,
    ) {}

    public function importCash(PohodaContext $ctx): void
    {
        $p = $ctx->protocol;
        $pdo = $this->db->pdo();

        $registers = [];
        foreach ($ctx->export->records('cash_registers', 'cashRegister') as $r) {
            $h = PohodaXml::get($r, 'cashRegisterHeader');
            $code = PohodaXml::text($h, 'ids');
            if ($code !== '') {
                $registers[$code] = ['name' => PohodaXml::text($h, 'name') ?: $code, 'account' => AccountCode::fromMoney(PohodaXml::text($h, 'account/ids'))];
            }
        }
        $vouchers = iterator_to_array($ctx->export->records('cash', 'voucher'), false);
        // Pokladna bez účtu v číselníku: účet 211 z deníku jejích dokladů.
        $votes = [];
        foreach ($vouchers as $r) {
            $h = PohodaXml::get($r, 'voucherHeader');
            if ($ctx->skipsDate(PohodaXml::date($h, 'date') ?? PohodaXml::date($h, 'datePayment'))) {
                continue;
            }
            $code = PohodaXml::text($h, 'cashAccount/ids');
            $account = $ctx->cashAccountsByNumber[PohodaXml::text($h, 'number/numberRequested')] ?? null;
            if ($account !== null) {
                $votes[$code][$account] = ($votes[$code][$account] ?? 0) + 1;
            }
            $registers[$code] ??= ['name' => $code, 'account' => null];
        }
        $registerIds = [];
        foreach ($registers as $code => $reg) {
            if ($reg['account'] === null && isset($votes[$code])) {
                arsort($votes[$code]);
                $reg['account'] = (string) array_key_first($votes[$code]);
            }
            if (!isset($votes[$code]) && $this->map->get($ctx->supplierId, PohodaImportRepository::KIND_CASH_REGISTER, $code) === null) {
                continue; // pokladna bez dokladů v roce se nezakládá
            }
            $registerIds[$code] = $this->ensureRegister($ctx, $code, $reg['name'], $reg['account']);
        }

        $existing = $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_CASH_DOCUMENT);
        $ruleExists = $pdo->prepare('SELECT 1 FROM posting_rules WHERE supplier_id = ? AND rule_key = ? LIMIT 1');
        $insert = $pdo->prepare(
            'INSERT INTO cash_documents
                (supplier_id, register_id, doc_type, purpose, doc_number, issue_date, tax_date,
                 partner_name, partner_ic, partner_dic, description, vat_mode, total_amount, currency_code,
                 rule_key, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "CZK", ?, "posted", ?)'
        );
        $insertVat = $pdo->prepare(
            'INSERT INTO cash_document_vat_lines (cash_document_id, vat_rate, base_amount, vat_amount, vat_deduction, is_fixed_asset) VALUES (?, ?, ?, ?, ?, ?)'
        );

        $periodStart = $ctx->periodStart();
        foreach ($vouchers as $r) {
            $h = PohodaXml::get($r, 'voucherHeader');
            $number = PohodaXml::text($h, 'number/numberRequested');
            $issue = PohodaXml::date($h, 'date') ?? PohodaXml::date($h, 'datePayment');
            if ($number === '' || $issue === null) {
                $p->warn(self::STEP_CASH, 'number_or_date_missing', "Pokladní doklad {$number} nemá číslo nebo datum, nepřevzat.");
                continue;
            }
            // Doklad bez zaúčtování k začátku období (počáteční stav pokladny, doklady přelomu
            // roku) je v počátečních stavech - naváže se na otevírací zápis.
            $inOpening = PohodaXml::text($h, 'accounting/accountingType') === 'withoutAccounting' && $issue <= $periodStart;
            $key = 'cash|' . $number . '|' . $issue;
            if (isset($existing[$key])) {
                $ctx->cashDocuments[$number] = $existing[$key];
                if ($inOpening) {
                    $ctx->openingCash[$existing[$key]] = true;
                }
                $p->count(self::STEP_CASH, 'existing');
                continue;
            }
            if ($ctx->skipsNew(self::STEP_CASH)) {
                continue;
            }
            if ($ctx->skipsDate($issue)) {
                $p->count(self::STEP_CASH, 'later_year_skipped');
                continue;
            }
            $registerId = $registerIds[PohodaXml::text($h, 'cashAccount/ids')] ?? null;
            if ($registerId === null) {
                $p->warn(self::STEP_CASH, 'register_missing', "Pokladní doklad {$number} nemá pokladnu, nepřevzat.");
                continue;
            }
            $summary = PohodaXml::get($r, 'voucherSummary/homeCurrency');
            $lines = self::vatBuckets($summary);
            $total = round(PohodaXml::num($summary, 'priceNone') + array_sum(array_map(static fn (array $l): float => $l['base'] + $l['vat'], $lines))
                + PohodaXml::num($summary, 'round/priceRound'), 2);
            if ($total === 0.0) {
                $p->count(self::STEP_CASH, 'zero_amount');
                continue;
            }
            // Záporný příjem je výdej a naopak (vratka) - jen tak sedí 211 na deník.
            $isOut = (PohodaXml::text($h, 'voucherType') === 'expense') !== ($total < 0);
            $sign = $total < 0 ? -1 : 1;
            $classCode = PohodaXml::text($h, 'classificationVAT/ids');
            $vatLines = [];
            $deduction = 'full';
            $asset = false;
            $withVat = array_values(array_filter($lines, static fn (array $l): bool => abs($l['vat']) >= 0.005));
            if ($withVat !== []) {
                if ($isOut) {
                    $res = $ctx->vat->purchase($classCode);
                    if ($res !== null && $res['in_return'] && !$res['reverse']) {
                        $vatLines = $withVat;
                        $deduction = $res['deduction'];
                        $asset = $res['fixed_asset'];
                    } elseif ($res === null || $res['in_return']) {
                        $p->warn(self::STEP_CASH, 'cash_vat_review', "Pokladní doklad {$number}: členění DPH „{$classCode}“ převod nepřebírá, DPH doplňte ručně.", ['document_no' => $number]);
                    }
                } else {
                    $res = $ctx->vat->sale($classCode, max(array_column($withVat, 'rate')));
                    if ($res !== null && $res['in_return'] && $res['code'] === null) {
                        $vatLines = $withVat;
                    } else {
                        $p->warn(self::STEP_CASH, 'cash_vat_review', "Pokladní doklad {$number}: členění DPH „{$classCode}“ převod nepřebírá, DPH doplňte ručně.", ['document_no' => $number]);
                    }
                }
            }
            $numberText = $this->cashNumbers->allocate($ctx->supplierId, [$number, $number . '/' . substr($issue, 0, 4)], $p, self::STEP_CASH, $number . ' z ' . $issue);
            $rule = mb_substr(PohodaXml::text($h, 'accounting/ids'), 0, 64);
            $ruleKey = null;
            if ($rule !== '') {
                $ruleExists->execute([$ctx->supplierId, $rule]);
                $ruleKey = $ruleExists->fetchColumn() !== false ? $rule : null;
            }
            $partner = PartnerImporter::snapshot(PohodaXml::get($h, 'partnerIdentity'));
            // Doklad bez zaúčtování (počáteční stav pokladny, doklady přelomu roku v PS) není
            // prodej ani nákup - pokladní kniha ho potřebuje kvůli zůstatku, tržby ne.
            $withoutAccounting = PohodaXml::text($h, 'accounting/accountingType') === 'withoutAccounting';
            if ($withoutAccounting) {
                $p->count(self::STEP_CASH, 'without_accounting');
            }
            $insert->execute([
                $ctx->supplierId,
                $registerId,
                $isOut ? 'out' : 'in',
                $withoutAccounting ? 'other' : ($isOut ? 'purchase' : 'sale'),
                $numberText,
                $issue,
                PohodaXml::date($h, 'dateTax') ?? $issue,
                $partner['name'] !== '' ? mb_substr($partner['name'], 0, 255) : null,
                $partner['ico'] !== '' ? $partner['ico'] : null,
                $partner['dic'] !== '' ? mb_substr($partner['dic'], 0, 20) : null,
                mb_substr(PohodaXml::text($h, 'text') ?: $number, 0, 255),
                $vatLines === [] ? 'none' : 'vat',
                abs($total),
                $ruleKey,
                $ctx->userOrNull(),
            ]);
            $id = (int) $pdo->lastInsertId();
            foreach ($vatLines as $line) {
                $insertVat->execute([$id, $line['rate'], round($sign * $line['base'], 2), round($sign * $line['vat'], 2), $deduction,
                    MigratedDocumentItem::fixedAssetLine($asset, (float) $line['rate'], (float) $line['vat'], null) ? 1 : 0]);
            }
            if ($vatLines !== []) {
                $p->count(self::STEP_CASH, 'with_vat');
            }
            $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_CASH_DOCUMENT, $key, $id, $ctx->runId);
            $ctx->cashDocuments[$number] = $id;
            if ($inOpening) {
                $ctx->openingCash[$id] = true;
            }
            $p->count(self::STEP_CASH, 'created');
        }
        $p->finish(self::STEP_CASH);
    }

    public function importBank(PohodaContext $ctx): void
    {
        $p = $ctx->protocol;

        $accounts = [];
        foreach ($ctx->export->records('bank_accounts', 'bankAccount') as $r) {
            $h = PohodaXml::get($r, 'bankAccountHeader');
            $code = PohodaXml::text($h, 'ids');
            $number = ltrim(PohodaXml::text($h, 'numberAccount'), '0') === '' ? '' : PohodaXml::text($h, 'numberAccount');
            $accounts[$code] = [
                'number' => $number,
                'bank' => PohodaXml::text($h, 'codeBank'),
                'iban' => str_replace(' ', '', PohodaXml::text($h, 'IBAN')),
                'label' => trim(PohodaXml::text($h, 'nameBank') . ' ' . $code),
                'analytic' => AccountCode::fromMoney(PohodaXml::text($h, 'analyticAccount/ids')),
            ];
        }

        $byStatement = [];
        $openingDocs = [];
        $count = 0;
        $periodStart = $ctx->periodStart();
        foreach ($ctx->export->records('bank', 'bank') as $r) {
            $h = PohodaXml::get($r, 'bankHeader');
            $code = PohodaXml::text($h, 'account/ids') ?: 'BANKA';
            $date = PohodaXml::date($h, 'datePayment') ?? PohodaXml::date($h, 'dateStatement');
            if ($date === null) {
                $p->warn(self::STEP_BANK, 'date_missing', 'Bankovní doklad ' . PohodaXml::text($h, 'number') . ' nemá datum, nepřevzat.');
                continue;
            }
            if ($ctx->skipsDate($date)) {
                $p->count(self::STEP_BANK, 'later_year_skipped');
                continue;
            }
            if (self::isOpeningBalance($h, $date, $periodStart)) {
                $openingDocs[$code] = ($openingDocs[$code] ?? 0) + StatementBalanceService::cents(number_format(self::amount($r), 2, '.', ''));
                $p->count(self::STEP_BANK, 'opening_balances');
                continue;
            }
            $statement = PohodaXml::text($h, 'statementNumber/statementNumber') ?: ('m' . substr($date, 5, 2));
            $byStatement[$code][$statement][] = $r;
            $count++;
        }
        if (!$ctx->dryRun) {
            $this->registerAccounts($ctx, $accounts, array_keys($byStatement));
        }

        $existingStatements = $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_BANK_STATEMENT);
        $existingTx = $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_BANK_TRANSACTION);
        $writer = new BankStatementImportWriter($this->db, 'pohoda');

        $done = 0;
        $seenKeys = [];
        ksort($byStatement);
        foreach ($byStatement as $code => $statements) {
            // Zůstatky navazují chronologicky podle data výpisu (nejpozdější datum výpisu jeho
            // dokladů): výpis bez čísla (`mNN`) tak patří mezi číslované, ne za ně, a číslovaný
            // výpis s jedním starším dokladem nepředběhne předchozí výpisy.
            $sortDate = [];
            foreach ($statements as $statementNo => $rows) {
                $dates = [];
                foreach ($rows as $r) {
                    $dates[] = PohodaXml::date($r, 'bankHeader/dateStatement') ?? PohodaXml::date($r, 'bankHeader/datePayment');
                }
                $dates = array_filter($dates);
                $sortDate[$statementNo] = $dates === [] ? '9999-12-31' : max($dates);
            }
            uksort($statements, static fn (string|int $a, string|int $b): int => ($sortDate[$a] <=> $sortDate[$b]) ?: strnatcmp((string) $a, (string) $b));
            $meta = $accounts[$code] ?? ['number' => '', 'bank' => '', 'iban' => '', 'label' => $code, 'analytic' => null];
            $running = null;
            $accountId = $meta['analytic'] !== null ? ($ctx->accountIds[$meta['analytic']] ?? null) : null;
            if ($accountId !== null && $ctx->period !== null) {
                $running = StatementBalanceService::cents(number_format($writer->ledgerOpening($ctx->supplierId, (int) $ctx->period['id'], $accountId), 2, '.', ''));
                if (isset($openingDocs[$code]) && $openingDocs[$code] !== $running) {
                    $p->warn(self::STEP_BANK, 'opening_mismatch', sprintf(
                        'Počáteční stav bankovního účtu %s v Pohodě (%s Kč) nesedí s počátečním stavem jeho analytiky v deníku (%s Kč), výpisy navazují na deník.',
                        $code, number_format($openingDocs[$code] / 100, 2, ',', ' '), number_format($running / 100, 2, ',', ' '),
                    ));
                }
            } elseif (isset($openingDocs[$code])) {
                $running = $openingDocs[$code];
            } else {
                $p->warn(self::STEP_BANK, 'account_without_analytic', "Bankovní účet {$code} nemá v Pohodě analytiku 221, výpisy jsou bez počátečního stavu.");
            }
            // Stavy převzatých výpisů mohl dorovnat výpis banky ({@see \MyInvoice\Service\Bank\ImportedStatementRebase}):
            // nové výpisy navazují na první už převzatý, ne na deník.
            $firstExisting = $existingStatements['stmt|' . $code . '|' . $ctx->year() . '|' . array_key_first($statements)] ?? null;
            if ($firstExisting !== null) {
                $opening = $this->db->pdo()->prepare('SELECT prev_balance FROM bank_statements WHERE id = ? AND supplier_id = ?');
                $opening->execute([$firstExisting, $ctx->supplierId]);
                $prev = $opening->fetchColumn();
                if ($prev !== false && $prev !== null) {
                    $running = StatementBalanceService::cents((string) $prev);
                }
            }

            foreach ($statements as $statementNo => $rows) {
                $dates = [];
                foreach ($rows as $r) {
                    $dates[] = PohodaXml::date($r, 'bankHeader/dateStatement') ?? PohodaXml::date($r, 'bankHeader/datePayment');
                }
                $dates = array_filter($dates);
                sort($dates);
                $lastDate = $dates[count($dates) - 1] ?? $ctx->calendar->periodEnd($ctx->year());

                $mapKey = 'stmt|' . $code . '|' . $ctx->year() . '|' . $statementNo;
                $statementId = $existingStatements[$mapKey] ?? null;
                $isNewStatement = $statementId === null;
                if ($isNewStatement && $ctx->skipsNew(self::STEP_BANK)) {
                    continue;
                }
                if ($isNewStatement) {
                    $statementId = $writer->createStatement(
                        $ctx->supplierId, $mapKey, sprintf('%s %s/%d', $code, $statementNo, $ctx->year()), $meta['number'] !== '' ? $meta['number'] : (string) $code,
                        $meta['bank'], 'CZK', $lastDate, $ctx->userOrNull(),
                    );
                    $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_BANK_STATEMENT, $mapKey, $statementId, $ctx->runId);
                    $p->count(self::STEP_BANK, 'statements');
                }

                $added = 0;
                $credit = 0;
                $debit = 0;
                foreach ($rows as $r) {
                    $h = PohodaXml::get($r, 'bankHeader');
                    $number = PohodaXml::text($h, 'number');
                    $date = PohodaXml::date($h, 'datePayment') ?? (string) PohodaXml::date($h, 'dateStatement');
                    $amount = self::amount($r);
                    $cents = StatementBalanceService::cents(number_format($amount, 2, '.', ''));
                    if ($cents >= 0) {
                        $credit += $cents;
                    } else {
                        $debit -= $cents;
                    }
                    // Pohoda pustí dva pohyby se stejným číslem i datem (ruční doklad vedle
                    // importovaného výpisu); druhý dostane pořadí, oba jsou skutečné.
                    $txKey = 'bank|' . $code . '|' . $number . '|' . $date;
                    $seenKeys[$txKey] = ($seenKeys[$txKey] ?? 0) + 1;
                    if ($seenKeys[$txKey] > 1) {
                        $txKey .= '#' . $seenKeys[$txKey];
                        $p->count(self::STEP_BANK, 'duplicate_numbers');
                    }
                    if (isset($existingTx[$txKey])) {
                        $ctx->bankTransactions[$number][] = ['id' => $existingTx[$txKey], 'date' => $date];
                        self::rememberReferences($ctx, $r, $existingTx[$txKey]);
                        $p->count(self::STEP_BANK, 'existing');
                        continue;
                    }
                    if ($ctx->skipsNew(self::STEP_BANK)) {
                        continue;
                    }
                    [$vs, $description] = self::symbolAndDescription($h);
                    $partner = PartnerImporter::snapshot(PohodaXml::get($h, 'partnerIdentity'));
                    $counterName = $partner['name'] !== '' ? $partner['name'] : PohodaXml::text($h, 'note');
                    $constant = PohodaXml::text($h, 'symConst');
                    $specific = PohodaXml::text($h, 'symSpec');
                    $id = $writer->insertTransaction($ctx->supplierId, $statementId, $txKey, [
                        'source_ref' => mb_substr($number, 0, 190),
                        'posted_at' => $date,
                        'amount' => number_format($amount, 2, '.', ''),
                        'currency' => 'CZK',
                        'variable_symbol' => $vs,
                        'constant_symbol' => preg_match('/^\d{1,10}$/', $constant) === 1 && ltrim($constant, '0') !== '' ? $constant : null,
                        'specific_symbol' => preg_match('/^\d{1,20}$/', $specific) === 1 && ltrim($specific, '0') !== '' ? $specific : null,
                        'counterparty_account' => mb_substr(PohodaXml::text($h, 'paymentAccount/accountNo'), 0, 40) ?: null,
                        'counterparty_bank' => mb_substr(PohodaXml::text($h, 'paymentAccount/bankCode'), 0, 4) ?: null,
                        'counterparty_name' => $counterName !== '' ? mb_substr($counterName, 0, 190) : null,
                        'description' => $description,
                        'bank_ref' => mb_substr($number, 0, 40),
                    ]);
                    $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_BANK_TRANSACTION, $txKey, $id, $ctx->runId);
                    $ctx->bankTransactions[$number][] = ['id' => $id, 'date' => $date];
                    self::rememberReferences($ctx, $r, $id);
                    $added++;
                    $p->count(self::STEP_BANK, 'transactions');
                    if (++$done % 500 === 0) {
                        $ctx->report(self::STEP_BANK, $done, $count);
                    }
                }
                if ($added > 0) {
                    $writer->touchStatement($ctx->supplierId, $statementId, $added, $lastDate);
                }
                if ($running !== null && $isNewStatement) {
                    $closing = $running + $credit - $debit;
                    $writer->setBalancesInCents($ctx->supplierId, $statementId, $running, $closing, $credit, $debit);
                    $running = $closing;
                } elseif ($running !== null) {
                    $running += $credit - $debit;
                }
            }
        }
        $p->finish(self::STEP_BANK);
    }

    /**
     * Vazby pohybu, kterými POHODA sama říká, co pohyb hradí: id bankovního dokladu (na něj
     * míří úhrady dokladů) a párovací symboly hlavičky i položek (číslo hrazeného dokladu).
     * Páruje z nich {@see DocumentLinker::matchPayments()} a {@see UnbookedBankPayments}.
     */
    private static function rememberReferences(PohodaContext $ctx, array $r, int $txId): void
    {
        $h = PohodaXml::get($r, 'bankHeader');
        $pohodaId = PohodaXml::text($h, 'id');
        if ($pohodaId !== '') {
            $ctx->bankByPohodaId[$pohodaId] = $txId;
        }
        $symbols = [PohodaXml::text($h, 'symPar')];
        foreach (PohodaXml::all($r, 'bankDetail/bankItem') as $item) {
            $symbols[] = PohodaXml::text($item, 'symPar');
        }
        $out = [];
        foreach ($symbols as $symbol) {
            $symbol = trim($symbol);
            if ($symbol !== '' && ltrim($symbol, '0') !== '') {
                $out[$symbol] = true;
            }
        }
        if ($out !== []) {
            $ctx->bankSymbols[$txId] = array_map('strval', array_keys($out));
        }
    }

    /**
     * Částka pohybu: součet rekapitulace, výdej se znaménkem minus. Příjem se zápornou
     * částkou (vratka odchozí platby) zůstává záporný.
     */
    private static function amount(array $r): float
    {
        $summary = PohodaXml::get($r, 'bankSummary/homeCurrency');
        $total = PohodaXml::num($summary, 'priceNone') + PohodaXml::num($summary, 'priceLowSum')
            + PohodaXml::num($summary, 'priceHighSum') + PohodaXml::num($summary, 'price3Sum') + PohodaXml::num($summary, 'round/priceRound');
        $sign = PohodaXml::text($r, 'bankHeader/bankType') === 'expense' ? -1 : 1;
        return round($sign * $total, 2);
    }

    /**
     * Variabilní symbol pohybu ({@see BankSymbols::variableSymbolAndDescription()}); VS ze
     * samých nul je bez symbolu. Jiný obsah pole zůstane v popisu pohybu.
     *
     * @return array{0:?string,1:?string}
     */
    private static function symbolAndDescription(mixed $h): array
    {
        return BankSymbols::variableSymbolAndDescription(PohodaXml::text($h, 'symVar'), PohodaXml::text($h, 'text'), true);
    }

    /** @return list<array{rate:float,base:float,vat:float}> */
    private static function vatBuckets(mixed $summary): array
    {
        $out = [];
        foreach (self::BUCKETS as $bucket => $default) {
            $base = round(PohodaXml::num($summary, 'price' . $bucket), 2);
            $vat = round(PohodaXml::num($summary, 'price' . $bucket . 'VAT'), 2);
            $rate = PohodaXml::attr($summary, 'price' . $bucket . 'VAT', 'rate');
            if ($base !== 0.0 || $vat !== 0.0) {
                $out[] = ['rate' => is_numeric($rate) ? (float) $rate : $default, 'base' => $base, 'vat' => $vat];
            }
        }
        return $out;
    }

    private function ensureRegister(PohodaContext $ctx, string $code, string $name, ?string $account): int
    {
        $mapped = $this->map->get($ctx->supplierId, PohodaImportRepository::KIND_CASH_REGISTER, $code);
        if ($mapped !== null) {
            return $mapped;
        }
        $pdo = $this->db->pdo();
        $account = $account !== null && isset($ctx->accountIds[$account]) ? $account : null;
        if ($account !== null) {
            $stmt = $pdo->prepare('SELECT id FROM cash_registers WHERE supplier_id = ? AND account_code = ? LIMIT 1');
            $stmt->execute([$ctx->supplierId, $account]);
            $found = $stmt->fetchColumn();
            if ($found !== false) {
                return (int) $found;
            }
        }
        $nameTaken = $pdo->prepare('SELECT 1 FROM cash_registers WHERE supplier_id = ? AND name = ? LIMIT 1');
        $nameTaken->execute([$ctx->supplierId, mb_substr($name, 0, 100)]);
        if ($nameTaken->fetchColumn() !== false) {
            $name .= ' (Pohoda ' . $code . ')';
        }
        $pdo->prepare('INSERT INTO cash_registers (supplier_id, name, account_code, currency_code, is_active) VALUES (?, ?, ?, "CZK", 1)')
            ->execute([$ctx->supplierId, mb_substr($name, 0, 100), $account ?? '211']);
        $id = (int) $pdo->lastInsertId();
        $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_CASH_REGISTER, $code, $id, $ctx->runId);
        $ctx->protocol->count(self::STEP_CASH, 'registers');
        return $id;
    }

    /**
     * Doklad „Počáteční stav bankovního účtu", který POHODA zakládá na začátku roku: bez
     * čísla, bez výpisu a bez zaúčtování. Není to pohyb na účtu, ale jeho počáteční stav
     * (ten nese deník), do výpisů se proto nepřebírá.
     */
    private static function isOpeningBalance(mixed $h, string $date, string $periodStart): bool
    {
        return $date === $periodStart
            && PohodaXml::text($h, 'number') === ''
            && PohodaXml::text($h, 'statementNumber/statementNumber') === ''
            && PohodaXml::text($h, 'accounting/accountingType') === 'withoutAccounting';
    }

    /**
     * Vlastní bankovní účty z Pohody do evidence účtů firmy (se zachováním analytiky 221)
     * a mezi účty firmy v měnách: první použitý účet doplní korunovou měnu bez čísla účtu,
     * každý další použitý účet dostane vlastní řádek, jinak by ho tab Účty ani zůstatky
     * banky neukázaly.
     *
     * @param array<string,array{number:string,bank:string,iban:string,label:string,analytic:?string}> $accounts
     * @param list<string> $used kódy účtů s pohyby v roce
     */
    private function registerAccounts(PohodaContext $ctx, array $accounts, array $used): void
    {
        $registrar = new BankAccountRegistrar($this->db, $this->bankAccounts);
        $company = [];
        foreach ($accounts as $code => $a) {
            $company[$code] = [
                'number' => $a['number'],
                'bank' => $a['bank'],
                'iban' => $a['iban'],
                'currency' => 'CZK',
                'label' => $a['label'],
                'suffix' => $a['analytic'] !== null && str_starts_with($a['analytic'], '221.') ? substr($a['analytic'], 4) : null,
            ];
        }
        $registered = $registrar->register($ctx->supplierId, $company, $ctx->protocol, self::STEP_BANK);
        $primary = null;
        foreach ($used as $code) {
            if (($accounts[$code]['number'] ?? '') !== '') {
                $primary = $accounts[$code];
                break;
            }
        }
        if ($primary !== null) {
            // Na rozdíl od Money S3 bez zkrácení na délku sloupců a '0' jako kód banky = NULL.
            $registrar->fillCurrencyAccount($ctx->supplierId, 'CZK', $primary['number'], $primary['bank'] ?: null, $primary['iban'] ?: null);
        }
        $registrar->linkCompanyAccounts($ctx->supplierId, $company, $used, $registered, $ctx->protocol, self::STEP_BANK, false);
    }
}
