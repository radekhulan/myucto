<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Repository\SupplierBankAccountRepository;
use MyInvoice\Service\Bank\StatementBalanceService;
use MyInvoice\Service\Migration\Shared\BankAccountRegistrar;
use MyInvoice\Service\Migration\Shared\BankStatementImportWriter;
use MyInvoice\Service\Migration\Shared\BankSymbols;
use MyInvoice\Service\Migration\Shared\MigratedCashNumber;
use MyInvoice\Service\Migration\Shared\MigratedDocumentItem;
use PDO;

/**
 * Pokladny a pokladní doklady (`SzUcPokl` typ P, `PoklKnih`), bankovní účty a pohyby
 * (`SzUcPokl` typ U, `BankKnih`).
 *
 * Money výpis jako soubor nemá, jen jednotlivé bankovní doklady. Na účet a rok proto
 * vznikne jeden souhrnný výpis se zdrojem `import` (migrace 1771). Žádná jiná hodnota
 * není pravdivá: `gpc` slibuje nahraný soubor, `pdf` rozparsovaný výpis a `bank_api`
 * má vedlejší účinek — podle něj se hledají účty s aktivním napojením na banku. Cesta
 * „složit GPC a pustit ho přes importér výpisů" je záměrně zavřená: importér
 * rekonstruovaný výpis odmítá, protože výpis v systému má být bankou potvrzený originál.
 */
final class CashBankImporter
{
    public const STEP_CASH = 'cash';
    public const STEP_BANK = 'bank';

    /**
     * Pole bankovní knihy (`BankKnih`), se kterými pracuje převod pohybů: pohyby všech let
     * leží v paměti naráz (výpisy po účtech a letech), tak jen s nimi.
     */
    private const BANK_FIELDS = ['Cislo', 'Doklad', 'Ucet', 'DatPlat', 'DatUcPr', 'Celkem', 'ValutyKUhr', 'ValutyZak0', 'Vydej', 'Kurs', 'PocetJedn', 'AdNazev', 'VarSym', 'Popis'];

    public function __construct(
        private readonly Connection $db,
        private readonly MoneyS3ImportRepository $map,
        private readonly SupplierBankAccountRepository $bankAccounts,
        private readonly MigratedCashNumber $cashNumbers,
    ) {}

    public function importCash(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        $pdo = $this->db->pdo();
        // Název a účet pokladny bere převod z prvního roku, měnu z posledního (`Mena`,
        // prázdná = Kč) - pozdější rok přepíše dřívější stejně jako u bankovních účtů.
        $meta = [];
        // Počáteční stav valutové pokladny ve valutě (`PocStav`) z prvního převáděného roku:
        // doklady převod bere až od něj, starší peníze v pokladně by valutový zůstatek neznal.
        $firstYear = $ctx->periods !== [] ? min(array_keys($ctx->periods)) : null;
        foreach ($ctx->backup->rowsAcrossYears('SzUcPokl') as $r) {
            $code = trim((string) ($r['Zkrat'] ?? ''));
            if ($code === '' || strtoupper(trim((string) ($r['UcPokl'] ?? ''))) !== 'P') {
                continue;
            }
            $meta[$code] ??= ['name' => trim((string) ($r['Popis'] ?? '')) ?: $code, 'primary' => (string) ($r['PrimUcet'] ?? ''), 'opening' => null];
            $meta[$code]['currency'] = self::currency((string) ($r['Mena'] ?? ''));
            $year = $ctx->yearOf($r);
            if ($year !== null && $firstYear !== null && $year >= $firstYear
                && ($meta[$code]['opening'] === null || $year < $meta[$code]['opening']['year'])) {
                $meta[$code]['opening'] = [
                    'year' => $year,
                    'amount' => round((float) ($r['PocStav'] ?? 0), 2),
                    'date' => (string) ($ctx->periods[$year]['starts_on'] ?? ($year . '-01-01')),
                ];
            }
        }
        $registers = [];
        // Počáteční stav zadaný v MyÚčtu převod nepřepisuje.
        $setOpening = $pdo->prepare(
            'UPDATE cash_registers SET opening_foreign_amount = ?, opening_foreign_date = ?
              WHERE id = ? AND supplier_id = ? AND currency_code <> "CZK" AND opening_foreign_date IS NULL'
        );
        foreach ($meta as $code => $m) {
            $registers[$code] = $this->ensureRegister($ctx, (string) $code, $m['name'], $m['primary'], $m['currency']);
            if ($registers[$code] !== null && $registers[$code]['currency'] !== 'CZK' && $m['opening'] !== null) {
                $setOpening->execute([$m['opening']['amount'], $m['opening']['date'], $registers[$code]['id'], $ctx->supplierId]);
                if ($setOpening->rowCount() > 0) {
                    $p->count(self::STEP_CASH, 'opening_foreign');
                }
            }
        }

        $existing = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_CASH_DOCUMENT);
        $ruleExists = $pdo->prepare('SELECT 1 FROM posting_rules WHERE supplier_id = ? AND rule_key = ? LIMIT 1');
        $insert = $pdo->prepare(
            'INSERT INTO cash_documents
                (supplier_id, register_id, doc_type, purpose, doc_number, issue_date, tax_date,
                 partner_name, partner_ic, partner_dic, description, vat_mode, total_amount, currency_code,
                 fx_rate, amount_foreign, rule_key, external_barcode, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "posted", ?)'
        );
        // Doklad valutové pokladny z dřívějšího převodu (před převzetím valut) dostane měnu,
        // kurz a částku ve valutě dodatečně; korunová částka dokladu se nemění.
        $fillForeign = $pdo->prepare(
            'UPDATE cash_documents SET currency_code = ?, fx_rate = ?, amount_foreign = ?
              WHERE id = ? AND supplier_id = ? AND register_id = ?
                AND (currency_code <> ? OR fx_rate <> ? OR amount_foreign IS NULL OR amount_foreign <> ?)'
        );
        $insertVat = $pdo->prepare(
            'INSERT INTO cash_document_vat_lines (cash_document_id, vat_rate, base_amount, vat_amount, vat_deduction, is_fixed_asset) VALUES (?, ?, ?, ?, ?, ?)'
        );
        // Každá pokladna má vlastní číselnou řadu — stejné číslo v další pokladně téhož
        // roku dostane klíč s kódem pokladny (první si ponechá „rok|číslo").
        $owner = [];
        $seriesDocs = [];
        foreach ($ctx->backup->rowsAcrossYears('PoklKnih') as $r) {
            $year = $ctx->yearOf($r);
            $docNo = trim((string) ($r['Doklad'] ?? ''));
            if ($year === null || $docNo === '') {
                continue;
            }
            $registerCode = trim((string) ($r['Pokl'] ?? ''));
            $seriesDocs[$registerCode][((int) ($r['Vydej'] ?? 0)) === 1 ? 'cash_out' : 'cash_in'][$year][] = $docNo;
            $plain = $year . '|' . $docNo;
            $owner[$plain] ??= $registerCode;
            $key = $owner[$plain] === $registerCode ? $plain : $plain . '|' . $registerCode;
            $movementKey = $r['__dir'] . '|' . (int) ($r['Cislo'] ?? 0);
            $register = $registers[$registerCode] ?? null;
            if (isset($existing[$key])) {
                $ctx->cashDocuments[$key] = $existing[$key];
                if ($ctx->taxRecords) {
                    $ctx->movements['cash'][$movementKey] = $existing[$key];
                }
                $p->count(self::STEP_CASH, 'existing');
                // Valuty se doplní i do uzavřeného roku: pohyb v deníku se nemění, jde jen
                // o souběžnou částku ve valutě, ze které pokladna počítá valutový zůstatek.
                $fx = $register !== null ? $this->documentForeign($ctx, $r, $register['currency'], $docNo, $year) : null;
                if ($register !== null && $fx !== null) {
                    $fillForeign->execute([$fx['currency'], $fx['rate'], $fx['foreign'], $existing[$key], $ctx->supplierId, $register['id'],
                        $fx['currency'], $fx['rate'], $fx['foreign']]);
                    if ($fillForeign->rowCount() > 0) {
                        $p->count(self::STEP_CASH, 'foreign_filled');
                    }
                }
                continue;
            }
            if ($ctx->skipsNew(self::STEP_CASH)) {
                continue;
            }
            if ($ctx->isLocked($year)) {
                $p->warn(self::STEP_CASH, 'year_locked', "Pokladní doklad {$docNo}: rok {$year} je uzavřený, doklad nepřevzat.");
                continue;
            }
            $registerId = $register['id'] ?? null;
            $issue = InvoiceImporter::date($r, ['DatVyst', 'DatUcPr']);
            if ($registerId === null || $issue === null) {
                $p->warn(self::STEP_CASH, 'register_or_date_missing', "Pokladní doklad {$docNo} nemá pokladnu nebo datum, nepřevzat.");
                continue;
            }
            $amount = round((float) ($r['Celkem'] ?? 0), 2);
            if ($amount === 0.0) {
                // Nulový doklad nemá v pokladně účinek a MyÚčto ho nepřijme (částka > 0).
                $p->count(self::STEP_CASH, 'zero_amount');
                continue;
            }
            $fx = $this->documentForeign($ctx, $r, $register['currency'] ?? 'CZK', $docNo, $year);
            $number = $this->cashNumbers->allocate($ctx->supplierId, [$docNo, $docNo . '/' . $year], $p, self::STEP_CASH, $docNo . ' (' . $year . ')');
            // Záporný příjem je výdej a naopak (vratka v pokladně) — jen tak sedí 211 na deník.
            $isOut = (((int) ($r['Vydej'] ?? 0)) === 1) !== ($amount < 0);
            $vatLines = self::vatLines($r, $amount < 0);
            $cleneni = trim((string) ($r['Cleneni'] ?? ''));
            // Pokladní doklad je tuzemské plnění (řádky 1/2, 40/41) — stejné členění jako
            // u faktur ({@see Ms3VatCode}), krácený odpočet § 76 včetně.
            $vatClass = Ms3VatCode::resolve($cleneni, !$isOut);
            if ($vatLines !== [] && $isOut && $vatClass !== null && !$vatClass['in_return']) {
                // Výdej mimo přiznání: daň je součástí nákladu, odpočet se neuplatnil.
                $vatLines = [];
            } elseif ($vatLines !== [] && ($vatClass === null || !$vatClass['in_return'] || $vatClass['code'] !== null)) {
                // Mimo tuzemské plnění (PDP, EU, bez nároku) převod DPH neodhaduje — doklad
                // zůstane bez DPH a účetní ho doplní; v deníku z Money DPH je.
                $p->warn(self::STEP_CASH, 'cash_vat_review', "Pokladní doklad {$docNo} ({$year}): členění DPH „{$cleneni}“ převod nepřebírá, DPH doplňte ručně.", ['document_no' => $docNo, 'year' => $year]);
                $vatLines = [];
            }
            $vatDeduction = $vatClass['deduction'] ?? 'full';
            $vatAsset = $isOut && ($vatClass['fixed_asset'] ?? false);
            $purpose = $isOut ? 'purchase' : 'sale';
            if ($ctx->taxRecords) {
                [$purpose, $keepVat] = self::taxRecordsPurpose($ctx, $r, $isOut);
                if (!$keepVat) {
                    $vatLines = [];
                }
            }
            $rule = mb_substr(trim((string) ($r['PrKont'] ?? '')), 0, 64);
            $ruleKey = null;
            if ($rule !== '') {
                $ruleExists->execute([$ctx->supplierId, $rule]);
                $ruleKey = $ruleExists->fetchColumn() !== false ? $rule : null;
            }
            $insert->execute([
                $ctx->supplierId,
                $registerId,
                $isOut ? 'out' : 'in',
                $purpose,
                $number,
                $issue,
                InvoiceImporter::date($r, ['DatUplDPH']) ?? $issue,
                mb_substr(trim((string) ($r['AdNazev'] ?? '')), 0, 255) ?: null,
                mb_substr(CodebookImporter::ico((string) ($r['AdICO'] ?? '')), 0, 20) ?: null,
                mb_substr(strtoupper(str_replace(' ', '', trim((string) ($r['AdDIC'] ?? '')))), 0, 20) ?: null,
                mb_substr(trim((string) ($r['Popis'] ?? '')) ?: $docNo, 0, 255),
                $vatLines === [] ? 'none' : 'vat',
                abs($amount),
                $fx['currency'] ?? 'CZK',
                $fx['rate'] ?? 1,
                $fx['foreign'] ?? null,
                $ruleKey,
                mb_substr(trim((string) ($r['BarCode'] ?? '')), 0, 64) ?: null,
                $ctx->userId > 0 ? $ctx->userId : null,
            ]);
            $id = (int) $pdo->lastInsertId();
            foreach ($vatLines as $line) {
                $insertVat->execute([$id, $line['rate'], $line['base'], $line['vat'], $vatDeduction,
                    MigratedDocumentItem::fixedAssetLine($vatAsset, (float) $line['rate'], (float) $line['vat'], null) ? 1 : 0]);
            }
            if ($vatLines !== []) {
                $p->count(self::STEP_CASH, 'with_vat');
            }
            $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_CASH_DOCUMENT, $key, $id, $ctx->runId);
            $ctx->cashDocuments[$key] = $id;
            if ($ctx->taxRecords) {
                $ctx->movements['cash'][$movementKey] = $id;
            }
            $p->count(self::STEP_CASH, 'created');
        }
        if (!$ctx->options->relinkOnly) {
            $this->adoptRegisterSeries($ctx, $registers, $seriesDocs);
        }
        $p->finish(self::STEP_CASH);
    }

    /**
     * Číslovala-li Money každou pokladnu vlastní řadou (`CisRady`: CPrr/CVrr pro Kč,
     * CPErr/CVErr pro EUR …), dostane pokladna v MyÚčtu vlastní číselnou řadu se stejným
     * prefixem, tvarem a čítačem. Bez toho by valutová pokladna po převodu číslovala
     * z firemní řady a po roce s převzatými doklady už ji uživatel přepnout nemůže.
     *
     * Řada se pokladně přiřadí podle čísel jejích dokladů; druh bez dokladu (pokladna jen
     * s výdeji) dostane nevyužitou řadu téhož druhu lišící se jedním znakem (CVH → CPH).
     * Sdílí-li dvě pokladny jednu řadu, Money číslovalo společně a nic se nemění; stejně tak
     * u pokladny, která už vlastní řadu má nebo ji uživatel zapnul.
     *
     * @param array<string, array{id:int, currency:string}|null> $registers
     * @param array<string, array<string, array<int, list<string>>>> $docs
     */
    private function adoptRegisterSeries(ImportContext $ctx, array $registers, array $docs): void
    {
        $registers = array_filter($registers, static fn ($r, $code) => $r !== null && isset($docs[$code]), ARRAY_FILTER_USE_BOTH);
        if (count($registers) < 2 || $ctx->dirYears === []) {
            return;
        }
        $year = max($ctx->dirYears);
        // Řady z nejnovějšího roku, který CisRady má; čítač (`Cislo` = příští číslo) platí
        // jen pro rok, do kterého se řada zakládá.
        $byYear = [];
        foreach ($ctx->backup->rowsAcrossYears('CisRady', ['Rada', 'Cislo', 'PocetMist', 'PP', 'VP']) as $r) {
            $rowYear = $ctx->yearOf($r);
            if ($rowYear === null || preg_match('/^([A-Z0-9]{1,10})rr$/', trim((string) ($r['Rada'] ?? '')), $m) !== 1) {
                continue;
            }
            foreach (['cash_in' => 'PP', 'cash_out' => 'VP'] as $code => $flag) {
                if ((int) ($r[$flag] ?? 0) === 1) {
                    $byYear[$rowYear][$code][$m[1]] = [
                        'digits' => max(1, min(9, (int) ($r['PocetMist'] ?? 4))),
                        'next' => $rowYear === $year ? max(1, (int) ($r['Cislo'] ?? 1)) : 1,
                    ];
                }
            }
        }
        if ($byYear === []) {
            return;
        }
        $series = $byYear[max(array_keys($byYear))] + ['cash_in' => [], 'cash_out' => []];

        $assigned = [];
        $claimed = [];
        foreach ($registers as $registerCode => $register) {
            foreach (['cash_in', 'cash_out'] as $code) {
                $found = null;
                foreach ($docs[$registerCode][$code] ?? [] as $docYear => $numbers) {
                    foreach ($series[$code] as $prefix => $s) {
                        $pattern = '/^' . $prefix . sprintf('%02d', $docYear % 100) . '\d{' . $s['digits'] . '}$/';
                        if (preg_grep($pattern, $numbers) !== []) {
                            $found = $prefix;
                        }
                    }
                }
                if ($found !== null) {
                    if (isset($claimed[$code][$found]) && $claimed[$code][$found] !== $registerCode) {
                        return;
                    }
                    $claimed[$code][$found] = $registerCode;
                    $assigned[$registerCode][$code] = $found;
                }
            }
        }
        foreach ($assigned as $registerCode => $pair) {
            foreach (['cash_in' => 'cash_out', 'cash_out' => 'cash_in'] as $code => $other) {
                if (isset($pair[$code]) || !isset($pair[$other])) {
                    continue;
                }
                $candidates = array_filter(
                    array_keys($series[$code]),
                    static fn ($prefix) => !isset($claimed[$code][$prefix])
                        && strlen((string) $prefix) === strlen($pair[$other])
                        && levenshtein((string) $prefix, $pair[$other]) === 1,
                );
                if (count($candidates) === 1) {
                    $prefix = (string) reset($candidates);
                    $claimed[$code][$prefix] = $registerCode;
                    $assigned[$registerCode][$code] = $prefix;
                }
            }
        }

        $pdo = $this->db->pdo();
        $hasOwn = $pdo->prepare(
            "SELECT (SELECT own_series FROM cash_registers WHERE id = ? AND supplier_id = ?)
                 OR EXISTS (SELECT 1 FROM accounting_document_series WHERE supplier_id = ? AND register_id = ? AND series_code IN ('cash_in', 'cash_out'))"
        );
        $taken = $pdo->prepare(
            "SELECT id, register_id, fiscal_year, series_code FROM accounting_document_series
              WHERE supplier_id = ? AND prefix = ? AND series_code IN ('cash_in', 'cash_out')"
        );
        $maxUsed = $pdo->prepare('SELECT doc_number FROM cash_documents WHERE supplier_id = ? AND YEAR(issue_date) = ? AND doc_number LIKE ?');
        $moveCompany = $pdo->prepare('UPDATE accounting_document_series SET register_id = ?, number_format = ?, next_number = GREATEST(next_number, ?) WHERE id = ?');
        $insert = $pdo->prepare(
            'INSERT INTO accounting_document_series (supplier_id, series_code, fiscal_year, register_id, prefix, number_format, next_number)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $enable = $pdo->prepare('UPDATE cash_registers SET own_series = 1 WHERE id = ? AND supplier_id = ?');

        foreach ($assigned as $registerCode => $pair) {
            $registerId = (int) $registers[$registerCode]['id'];
            if (count($pair) < 2) {
                $ctx->protocol->warn(self::STEP_CASH, 'register_series_incomplete', sprintf(
                    'Pokladna %s: v Money má řadu jen pro %s, vlastní číselnou řadu nastavte ručně.',
                    $registerCode, isset($pair['cash_in']) ? 'příjmy' : 'výdeje',
                ), ['register' => $registerCode]);
                continue;
            }
            $hasOwn->execute([$registerId, $ctx->supplierId, $ctx->supplierId, $registerId]);
            if ((bool) $hasOwn->fetchColumn()) {
                continue;
            }
            $rows = [];
            foreach ($pair as $code => $prefix) {
                $digits = $series[$code][$prefix]['digits'];
                $next = $series[$code][$prefix]['next'];
                $maxUsed->execute([$ctx->supplierId, $year, $prefix . sprintf('%02d', $year % 100) . '%']);
                foreach ($maxUsed->fetchAll(PDO::FETCH_COLUMN) as $number) {
                    if (preg_match('/^' . $prefix . '\d{2}(\d{' . $digits . '})$/', (string) $number, $m) === 1) {
                        $next = max($next, (int) $m[1] + 1);
                    }
                }
                $taken->execute([$ctx->supplierId, $prefix]);
                $move = null;
                foreach ($taken->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    // Firemní řadu téhož roku se stejným prefixem (uživatel ji v MyÚčtu nastavil
                    // podle Money) pokladna převezme, jinak by dvě řady vydaly totéž číslo.
                    if ((int) $row['register_id'] === 0 && (int) $row['fiscal_year'] === $year && $row['series_code'] === $code) {
                        $move = (int) $row['id'];
                        continue;
                    }
                    $ctx->protocol->warn(self::STEP_CASH, 'register_series_prefix_taken', sprintf(
                        'Pokladna %s: prefix %s už používá jiná pokladní řada, vlastní číselnou řadu nastavte ručně.', $registerCode, $prefix,
                    ), ['register' => $registerCode, 'prefix' => $prefix]);
                    continue 3;
                }
                $rows[$code] = [$prefix, '{PREFIX}{YY}{' . str_repeat('C', $digits) . '}', $next, $move];
            }
            foreach ($rows as $code => [$prefix, $format, $next, $move]) {
                if ($move !== null) {
                    $moveCompany->execute([$registerId, $format, $next, $move]);
                } else {
                    $insert->execute([$ctx->supplierId, $code, $year, $registerId, $prefix, $format, $next]);
                }
            }
            $enable->execute([$registerId, $ctx->supplierId]);
            $ctx->protocol->info(self::STEP_CASH, 'register_series_adopted', sprintf(
                'Pokladna %s čísluje vlastní řadou %s/%s jako v Money.', $registerCode, $pair['cash_in'], $pair['cash_out'],
            ), ['register' => $registerCode]);
            $ctx->protocol->count(self::STEP_CASH, 'registers_own_series');
        }
    }

    public function importBank(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        $accounts = [];
        foreach ($ctx->backup->rowsAcrossYears('SzUcPokl') as $r) {
            $code = trim((string) ($r['Zkrat'] ?? ''));
            if ($code !== '' && strtoupper(trim((string) ($r['UcPokl'] ?? ''))) === 'U') {
                // Kurz počátečního stavu roku (`PSKurz` za `PSMnozstvi` jednotek) — kotva
                // počátečního stavu účtu v cizí měně ({@see anchorForeignOpenings()}).
                $rates = $accounts[$code]['rates'] ?? [];
                $year = (int) ($ctx->yearOf($r) ?? 0);
                $rate = (float) ($r['PSKurz'] ?? 0) / max(1.0, (float) ($r['PSMnozstvi'] ?? 1));
                if ($year > 0 && $rate > 0) {
                    $rates[$year] = $rate;
                }
                // Počáteční stav účtu roku (`PocStav`) — kotva zůstatků výpisů v daňové
                // evidenci, která deník s otevíracím zápisem nemá.
                $openings = $accounts[$code]['openings'] ?? [];
                if ($year > 0) {
                    $openings[$year] = StatementBalanceService::cents(number_format((float) ($r['PocStav'] ?? 0), 2, '.', ''));
                }
                // Pozdější rok přepíše dřívější — firma mohla banku mezitím změnit.
                $accounts[$code] = [
                    'rates' => $rates,
                    'openings' => $openings,
                    'label' => trim((string) ($r['Popis'] ?? '')),
                    'number' => trim((string) ($r['Ucet'] ?? '')),
                    'bank' => trim((string) ($r['BKod'] ?? '')),
                    'iban' => trim((string) ($r['IBAN'] ?? '')),
                    'primary' => trim((string) ($r['PrimUcet'] ?? '')),
                    'currency' => self::currency((string) ($r['Mena'] ?? '')),
                    'year' => (int) ($ctx->yearOf($r) ?? 0),
                ];
            }
        }
        $byStatement = [];
        foreach ($ctx->backup->rowsAcrossYears('BankKnih', self::BANK_FIELDS) as $r) {
            $year = $ctx->yearOf($r);
            if ($year === null || trim((string) ($r['Doklad'] ?? '')) === '') {
                continue;
            }
            $code = trim((string) ($r['Ucet'] ?? '')) ?: ((string) (array_key_first($accounts) ?? 'BANKA'));
            $byStatement[$year . '|' . $code][] = $r;
        }

        ksort($byStatement);
        $txKeys = self::documentKeys($byStatement);

        // Zkouška nanečisto účet firmy nedoplňuje: zámek řádku měny by v její transakci
        // blokoval vystavování dokladů firmy (cizí klíč na měnu) až do konce zkoušky.
        // Opravný převod účty firmy, výpisy ani pohyby nezakládá a stavy výpisů nemění.
        $relink = $ctx->options->relinkOnly;
        if (!$ctx->options->isDryRun() && !$relink) {
            $registrar = new BankAccountRegistrar($this->db, $this->bankAccounts);
            $this->fillOwnAccount($ctx->supplierId, $accounts, $registrar);
            $companyAccounts = self::companyAccounts($accounts);
            $registered = $registrar->register($ctx->supplierId, $companyAccounts);
            $used = [];
            foreach (array_keys($byStatement) as $statementKey) {
                $used[explode('|', (string) $statementKey, 2)[1]] = true;
            }
            $registrar->linkCompanyAccounts($ctx->supplierId, $companyAccounts, array_keys($used), $registered, $p, self::STEP_BANK, true);
        }

        $existingStatements = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_BANK_STATEMENT);
        $existingTx = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_BANK_TRANSACTION);
        // Výpis převzatý z Money je měsíční výpis se stavem účtu (zdroj `import`): záložka
        // Stavy na účtech ho bere jako kotvu zůstatku a jde z něj vytvořit GPC.
        // Stav korunového účtu na začátku roku = otevírací zápis účtu banky v deníku; dál
        // se počítá po pohybech výpisů. Účet v cizí měně deník v té měně nevede — počáteční
        // stav je součet pohybů účtu ze všech předchozích let zálohy ({@see foreignOpenings()}).
        $writer = new BankStatementImportWriter($this->db, 'money-s3');
        $foreignOpenings = $relink ? [] : $this->anchorForeignOpenings($ctx, $accounts, self::foreignOpenings($ctx, $accounts), $writer);

        foreach ($byStatement as $statementKey => $rows) {
            [$yearText, $code] = explode('|', $statementKey, 2);
            $year = (int) $yearText;
            if ($ctx->isLocked($year) || $relink) {
                if ($ctx->isLocked($year)) {
                    $p->info(self::STEP_BANK, 'year_locked', "Bankovní pohyby účtu {$code} za rok {$year}: rok je uzavřený, nepřebírají se.");
                }
                $lockedCurrency = ($accounts[$code] ?? ['currency' => 'CZK'])['currency'];
                foreach ($rows as $i => $r) {
                    $txKey = $txKeys[$statementKey][$i];
                    if (isset($existingTx[$txKey])) {
                        $ctx->bankTransactions[$txKey] = $existingTx[$txKey];
                        if ($ctx->taxRecords) {
                            $ctx->movements['bank'][$r['__dir'] . '|' . (int) ($r['Cislo'] ?? 0)] = $existingTx[$txKey];
                        }
                        // Párování úhrad porovnává částky v Kč i u pohybů uzavřených let.
                        if ($lockedCurrency !== 'CZK') {
                            $ctx->bankTransactionCzk[$existingTx[$txKey]] = self::transactionAmount($r, $lockedCurrency)[1];
                        }
                    } elseif (!$ctx->isLocked($year)) {
                        $ctx->skipsNew(self::STEP_BANK);
                    }
                }
                continue;
            }
            $meta = $accounts[$code] ?? ['number' => '', 'bank' => '', 'iban' => '', 'primary' => '', 'currency' => 'CZK', 'year' => 0];
            $currency = $meta['currency'];
            $running = null;
            if ($ctx->taxRecords) {
                $running = $currency === 'CZK' ? ($meta['openings'][$year] ?? null) : null;
            } elseif ($currency === 'CZK') {
                $primary = AccountCode::fromMoney($meta['primary']);
                $accountId = $primary !== null ? ($ctx->accountIds[$primary] ?? null) : null;
                $periodId = $ctx->periods[$year]['id'] ?? null;
                if ($accountId !== null && $periodId !== null) {
                    $running = StatementBalanceService::cents(number_format($writer->ledgerOpening($ctx->supplierId, $periodId, $accountId), 2, '.', ''));
                }
            } elseif (isset($foreignOpenings[$code][$year])) {
                $running = $foreignOpenings[$code][$year];
            }

            // Výpis za měsíc: Money čísluje výpisy (`Vypis`) typicky po dnech, jak je banka
            // posílá — pro přehled účtu i GPC se pohyby skládají do měsíčních výpisů.
            // Měsíce jdou v pořadí účetního roku firmy (u hospodářského roku od srpna:
            // srpen … červenec), zůstatky výpisů na sebe tak navazují; pohyb bez data jde
            // do posledního měsíce roku.
            $startMonth = $ctx->calendar->startMonth();
            $groups = [];
            foreach ($rows as $i => $r) {
                $d = InvoiceImporter::date($r, ['DatPlat', 'DatUcPr']);
                $groups[$d !== null ? (int) substr($d, 5, 2) : ($startMonth + 10) % 12 + 1][] = $i;
            }
            uksort($groups, static fn (int $a, int $b): int => ($a - $startMonth + 12) % 12 <=> ($b - $startMonth + 12) % 12);
            // Výpis z dřívějšího převodu (jeden za rok) zůstává — pohyby už jsou v něm.
            $legacyStatementId = $existingStatements[$statementKey] ?? null;
            foreach ($groups as $month => $indexes) {
                $dates = [];
                foreach ($indexes as $i) {
                    $d = InvoiceImporter::date($rows[$i], ['DatPlat', 'DatUcPr']);
                    if ($d !== null) {
                        $dates[] = $d;
                    }
                }
                sort($dates);
                // Kalendářní rok měsíce: u hospodářského roku leží měsíce před jeho začátkem
                // v dalším kalendářním roce (rok 2024 od srpna: leden = leden 2025).
                $monthYear = $month < $startMonth ? $year + 1 : $year;
                $lastDate = $dates === []
                    ? (new \DateTimeImmutable(sprintf('%04d-%02d-01', $monthYear, $month)))->format('Y-m-t')
                    : $dates[count($dates) - 1];

                $mapKey = $statementKey . '|m' . $month;
                $statementId = $legacyStatementId ?? ($existingStatements[$mapKey] ?? null);
                if ($statementId === null) {
                    $statementId = $writer->createStatement(
                        $ctx->supplierId, $mapKey, sprintf('%s/%d/%02d', $code, $monthYear, $month), $meta['number'] !== '' ? $meta['number'] : $code,
                        $meta['bank'], $currency, $lastDate, $ctx->userId > 0 ? $ctx->userId : null,
                    );
                    $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_BANK_STATEMENT, $mapKey, $statementId, $ctx->runId);
                    $p->count(self::STEP_BANK, 'statements');
                }

                $added = 0;
                $credit = 0;
                $debit = 0;
                foreach ($indexes as $i) {
                    $r = $rows[$i];
                    $docNo = trim((string) $r['Doklad']);
                    $txKey = $txKeys[$statementKey][$i];
                    [$amount, $czk] = self::transactionAmount($r, $currency);
                    $cents = StatementBalanceService::cents(number_format($amount, 2, '.', ''));
                    if ($cents >= 0) {
                        $credit += $cents;
                    } else {
                        $debit -= $cents;
                    }
                    $movementKey = $r['__dir'] . '|' . (int) ($r['Cislo'] ?? 0);
                    if (isset($existingTx[$txKey])) {
                        $ctx->bankTransactions[$txKey] = $existingTx[$txKey];
                        if ($ctx->taxRecords) {
                            $ctx->movements['bank'][$movementKey] = $existingTx[$txKey];
                        }
                        if ($currency !== 'CZK') {
                            $ctx->bankTransactionCzk[$existingTx[$txKey]] = $czk;
                        }
                        $p->count(self::STEP_BANK, 'existing');
                        continue;
                    }
                    [$vs, $description] = self::symbolAndDescription($r);
                    $id = $writer->insertTransaction($ctx->supplierId, $statementId, $txKey, [
                        'source_ref' => mb_substr($docNo, 0, 190),
                        'posted_at' => InvoiceImporter::date($r, ['DatPlat', 'DatUcPr']) ?? $lastDate,
                        'amount' => number_format($amount, 2, '.', ''),
                        'currency' => $currency,
                        'variable_symbol' => $vs,
                        'counterparty_name' => mb_substr(trim((string) ($r['AdNazev'] ?? '')), 0, 190) ?: null,
                        'description' => $description,
                        'bank_ref' => mb_substr($docNo, 0, 40),
                    ]);
                    $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_BANK_TRANSACTION, $txKey, $id, $ctx->runId);
                    $ctx->bankTransactions[$txKey] = $id;
                    if ($ctx->taxRecords) {
                        $ctx->movements['bank'][$movementKey] = $id;
                    }
                    if ($currency !== 'CZK') {
                        $ctx->bankTransactionCzk[$id] = $czk;
                    }
                    $added++;
                    $p->count(self::STEP_BANK, 'transactions');
                }
                if ($added > 0) {
                    $writer->touchStatement($ctx->supplierId, $statementId, $added, $lastDate);
                }
                if ($running !== null && $legacyStatementId === null) {
                    $closing = $running + $credit - $debit;
                    $writer->setBalancesInCents($ctx->supplierId, $statementId, $running, $closing, $credit, $debit);
                    $running = $closing;
                    $p->count(self::STEP_BANK, 'balances');
                }
            }
        }
        $p->finish(self::STEP_BANK);
    }

    /**
     * Částka pohybu v měně účtu a v Kč. Výdej = odchozí platba (minus). Záporná částka směr
     * otáčí: storno výdeje (vrácený poplatek, `Vydej` a záporné `Celkem`) jsou peníze, které
     * na účet přišly — tak je zaúčtuje i deník Money. Pohyb účtu v cizí měně nese Money ve
     * valutách (`ValutyKUhr`), `Celkem` je přepočet v Kč.
     *
     * @param array<string,mixed> $r
     * @return array{0:float,1:float} [částka v měně účtu, částka v Kč]
     */
    public static function transactionAmount(array $r, string $currency): array
    {
        $celkem = (float) ($r['Celkem'] ?? 0);
        $negative = $celkem < 0 || ($celkem === 0.0 && (float) ($r['ValutyKUhr'] ?? 0) < 0);
        $sign = (((int) ($r['Vydej'] ?? 0)) === 1 ? -1 : 1) * ($negative ? -1 : 1);
        $czk = round(abs($celkem), 2);
        if ($currency === 'CZK') {
            return [$sign * $czk, $sign * $czk];
        }
        $foreign = abs((float) ($r['ValutyKUhr'] ?? 0)) ?: abs((float) ($r['ValutyZak0'] ?? 0));
        if ($foreign === 0.0 && (float) ($r['Kurs'] ?? 0) > 0) {
            $foreign = $czk / (float) $r['Kurs'] * max(1.0, (float) ($r['PocetJedn'] ?? 1));
        }
        return [$sign * round($foreign, 2), $sign * $czk];
    }

    /**
     * Počáteční stav účtů v cizí měně po letech (v haléřích té měny): součet pohybů účtu
     * ze všech dřívějších let zálohy, včetně let, které se nepřevádějí.
     *
     * @param array<string,array{currency:string}> $accounts
     * @return array<string,array<int,int>> kód účtu => rok => počáteční stav
     */
    private static function foreignOpenings(ImportContext $ctx, array $accounts): array
    {
        $byYear = [];
        foreach ($ctx->backup->rowsAcrossYears('BankKnih', self::BANK_FIELDS) as $r) {
            $code = trim((string) ($r['Ucet'] ?? ''));
            // Roky před převáděným obdobím v mapě adresářů nejsou — rok dá datum pohybu.
            $date = InvoiceImporter::date($r, ['DatUcPr', 'DatPlat']);
            $year = $ctx->dirYears[$r['__dir']] ?? ($date !== null ? $ctx->calendar->fiscalYearOfDate($date) : null);
            $currency = $accounts[$code]['currency'] ?? 'CZK';
            if ($currency === 'CZK' || $year === null || trim((string) ($r['Doklad'] ?? '')) === '') {
                continue;
            }
            [$amount] = self::transactionAmount($r, $currency);
            $byYear[$code][$year] = ($byYear[$code][$year] ?? 0) + StatementBalanceService::cents(number_format($amount, 2, '.', ''));
        }
        $out = [];
        foreach ($byYear as $code => $years) {
            ksort($years);
            $running = 0;
            foreach ($years as $year => $sum) {
                $out[$code][$year] = $running;
                $running += $sum;
            }
        }
        return $out;
    }

    /**
     * Stav účtu z doby před první knihou zálohy (založení účtu, převod z jiného programu)
     * pohybem v bance není, součet pohybů ho proto nezná. Deník ho zná: korunový počáteční
     * stav účtu přepočtený kurzem počátečního stavu z Money (Money účet ke konci roku
     * přeceňuje). Kotví se poslední rok, pro který deník i kurz jsou — ten se účtuje dál —
     * a stejný rozdíl se přičte všem rokům, ať výpisy navazují.
     *
     * @param array<string,array{currency:string,primary:string,rates?:array<int,float>}> $accounts
     * @param array<string,array<int,int>> $openings kód účtu => rok => počáteční stav v haléřích měny
     * @return array<string,array<int,int>>
     */
    private function anchorForeignOpenings(ImportContext $ctx, array $accounts, array $openings, BankStatementImportWriter $writer): array
    {
        foreach ($openings as $code => $years) {
            $meta = $accounts[$code] ?? null;
            $primary = $meta !== null ? AccountCode::fromMoney($meta['primary']) : null;
            $accountId = $primary !== null ? ($ctx->accountIds[$primary] ?? null) : null;
            if ($meta === null || $meta['currency'] === 'CZK' || $accountId === null) {
                continue;
            }
            $offsets = [];
            foreach ($years as $year => $movementOpening) {
                $rate = $meta['rates'][$year] ?? null;
                $periodId = $ctx->periods[$year]['id'] ?? null;
                if ($rate === null || $periodId === null) {
                    continue;
                }
                $czk = $writer->ledgerOpening($ctx->supplierId, $periodId, $accountId);
                $offsets[$year] = (int) round($czk / $rate * 100) - $movementOpening;
            }
            if ($offsets === []) {
                continue;
            }
            krsort($offsets);
            $anchorYear = (int) array_key_first($offsets);
            $offset = $offsets[$anchorYear];
            foreach ($offsets as $year => $other) {
                if (abs($other - $offset) > 1000) {
                    $ctx->protocol->warn(self::STEP_BANK, 'foreign_opening_inconsistent', sprintf(
                        'Počáteční stav účtu %s v %s: podle deníku roku %d vychází o %s %s jinak než podle roku %d. Zkontrolujte stavy výpisů.',
                        $code, $meta['currency'], $year, number_format(abs($other - $offset) / 100, 2, ',', ' '), $meta['currency'], $anchorYear
                    ));
                    break;
                }
            }
            if ($offset === 0) {
                continue;
            }
            foreach ($years as $year => $opening) {
                $openings[$code][$year] = $opening + $offset;
            }
            $ctx->protocol->info(self::STEP_BANK, 'foreign_opening_anchored', sprintf(
                'Účet %s: stav %s %s z doby před první knihou zálohy doplněn podle počátečního stavu v deníku roku %d.',
                $code, number_format($offset / 100, 2, ',', ' '), $meta['currency'], $anchorYear
            ));
        }
        return $openings;
    }

    /**
     * DPH pokladního dokladu po sazbách: snížená (`ZaklSS`/`DPHSS`, sazba `SSazba`),
     * základní (`ZaklZS`/`DPHZS`, `ZSazba`) a další sazby `Zaklad_3…6`/`DPH_3…6`
     * (`SazbaDPH_3…6`). Jen řádky s daní — nulová sazba do evidence DPH nejde. Záporný
     * doklad se převádí s opačným směrem, proto se znaménko částek obrací.
     *
     * @param array<string,mixed> $r
     * @return list<array{rate:float,base:float,vat:float}>
     */
    public static function vatLines(array $r, bool $negative): array
    {
        $slots = [['ZaklSS', 'DPHSS', 'SSazba'], ['ZaklZS', 'DPHZS', 'ZSazba']];
        for ($i = 3; $i <= 6; $i++) {
            $slots[] = ['Zaklad_' . $i, 'DPH_' . $i, 'SazbaDPH_' . $i];
        }
        $sign = $negative ? -1 : 1;
        $out = [];
        foreach ($slots as [$baseField, $vatField, $rateField]) {
            $vat = round((float) ($r[$vatField] ?? 0), 2);
            $rate = (float) ($r[$rateField] ?? 0);
            if ($vat === 0.0 || $rate <= 0.0) {
                continue;
            }
            $out[] = ['rate' => $rate, 'base' => round($sign * (float) ($r[$baseField] ?? 0), 2), 'vat' => round($sign * $vat, 2)];
        }
        return $out;
    }

    /**
     * Účel pokladního dokladu daňové evidence podle jeho řádků v peněžním deníku Money
     * ({@see Ms3CashBook}) a zda si doklad nechá řádky DPH:
     *
     *   - úhrada faktury nebo pohledávky: účel ostatní bez DPH; úhradou ho udělá až
     *     navázání úhrady ({@see TaxRecordsImporter::payments()}), DPH nese faktura,
     *   - daňový příjem nebo výdaj bez dokladu: prodej nebo nákup s řádky DPH
     *     (zjednodušený daňový doklad),
     *   - průběžná položka: převod,
     *   - ostatní (vklad, výběr, nedaňové, DPH): účel ostatní mimo základ daně; vklad
     *     a výběr zařadí krok zařazení pohybů ({@see TaxRecordsImporter::classification()}).
     *
     * Doklad bez řádků deníku (nulový) zůstane prodejem nebo nákupem.
     *
     * @param array<string,mixed> $r
     * @return array{0:string,1:bool} [účel, ponechat řádky DPH]
     */
    private static function taxRecordsPurpose(ImportContext $ctx, array $r, bool $isOut): array
    {
        $movement = ($ctx->cashBooks[(string) ($r['__dir'] ?? '')] ?? null)?->movements[Ms3CashBook::key('PK', (int) ($r['Cislo'] ?? 0))] ?? null;
        if ($movement === null) {
            return [$isOut ? 'purchase' : 'sale', true];
        }
        $buckets = [];
        foreach ($movement['parts'] as $part) {
            if ($part['link'] !== null && in_array($part['link']['table'], ['FV', 'FP', 'KP'], true)) {
                return ['other', false];
            }
            if ($part['bucket'] !== null && $part['bucket'] !== Ms3CashBook::VAT) {
                $buckets[$part['bucket']] = true;
            }
        }
        if (count($buckets) === 1) {
            return match ((string) array_key_first($buckets)) {
                'income_taxable' => ['sale', true],
                'expense_taxable' => ['purchase', true],
                'transfer' => ['transfer', false],
                default => ['other', false],
            };
        }
        return ['other', false];
    }

    /**
     * Klíč bankovního dokladu v mapě převodu. Každý účet má v Money vlastní číselnou řadu,
     * takže stejné číslo dokladu na dalším účtu téhož roku je běžné: první účet (podle
     * kódu) si ponechá klíč „rok|číslo" — mapy dřívějších převodů platí dál — další účet
     * dostane „rok|číslo|kód účtu".
     *
     * @param array<string,list<array<string,mixed>>> $byStatement "rok|kód účtu" => řádky
     * @return array<string,array<int,string>>
     */
    private static function documentKeys(array $byStatement): array
    {
        $owner = [];
        $used = [];
        $keys = [];
        foreach ($byStatement as $statementKey => $rows) {
            [$year, $code] = explode('|', $statementKey, 2);
            foreach ($rows as $i => $r) {
                $plain = $year . '|' . trim((string) $r['Doklad']);
                $owner[$plain] ??= $code;
                $key = $owner[$plain] === $code ? $plain : $plain . '|' . $code;
                $used[$key] = ($used[$key] ?? 0) + 1;
                $keys[$statementKey][$i] = $used[$key] === 1 ? $key : $key . '#' . $used[$key];
            }
        }
        return $keys;
    }

    /**
     * Pokladna na stejném účtu, kterou firma už má, se použije — pokladna je v MyÚčtu
     * vázaná na účet (unikátní na firmu), druhá na týž účet nevznikne. Valutová pokladna
     * Money (`Mena`) vznikne ve své měně; už převedenou korunovou pokladnu převod na měnu
     * z Money přepne ({@see alignRegisterCurrency()}).
     *
     * @return array{id:int,currency:string}|null
     */
    private function ensureRegister(ImportContext $ctx, string $code, string $name, string $primaryAccount, string $currency): ?array
    {
        $mapped = $this->map->get($ctx->supplierId, MoneyS3ImportRepository::KIND_CASH_REGISTER, $code);
        if ($mapped !== null) {
            return ['id' => $mapped, 'currency' => $this->alignRegisterCurrency($ctx, $mapped, $code, $currency)];
        }
        $pdo = $this->db->pdo();
        $account = AccountCode::fromMoney($primaryAccount);
        $account = $account !== null && isset($ctx->accountIds[$account]) ? $account : null;
        if ($account === null && $ctx->taxRecords) {
            // Daňová evidence osnovu nemá: první pokladna Money je pokladna firmy na 211
            // (stejně jako pokladna založená v aplikaci), další dostanou volnou analytiku.
            $account = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_CASH_REGISTER) === [] ? '211' : $this->freeCashAccount($ctx->supplierId);
        }
        if ($account !== null) {
            $stmt = $pdo->prepare('SELECT id FROM cash_registers WHERE supplier_id = ? AND account_code = ? LIMIT 1');
            $stmt->execute([$ctx->supplierId, $account]);
            $found = $stmt->fetchColumn();
            if ($found !== false) {
                $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_CASH_REGISTER, $code, (int) $found, $ctx->runId);
                return ['id' => (int) $found, 'currency' => $this->alignRegisterCurrency($ctx, (int) $found, $code, $currency)];
            }
        }
        if ($ctx->skipsNew(self::STEP_CASH)) {
            return null;
        }
        $nameTaken = $pdo->prepare('SELECT 1 FROM cash_registers WHERE supplier_id = ? AND name = ? LIMIT 1');
        $nameTaken->execute([$ctx->supplierId, mb_substr($name, 0, 100)]);
        if ($nameTaken->fetchColumn() !== false) {
            $name .= ' (Money S3 ' . $code . ')';
        }
        $pdo->prepare(
            'INSERT INTO cash_registers (supplier_id, name, account_code, currency_code, is_active) VALUES (?, ?, ?, ?, 1)'
        )->execute([$ctx->supplierId, mb_substr($name, 0, 100), $account, $currency]);
        $id = (int) $pdo->lastInsertId();
        $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_CASH_REGISTER, $code, $id, $ctx->runId);
        $ctx->protocol->count(self::STEP_CASH, 'registers');
        return ['id' => $id, 'currency' => $currency];
    }

    /**
     * Pokladnu vedenou v Money ve valutě, kterou dřívější převod založil jako korunovou,
     * přepne na měnu z Money. Jen když v ní nejsou doklady zadané v MyÚčtu mimo převod:
     * ty vznikly v korunách a změna měny pokladny by jim měnu nepravdivě přisoudila. Pak
     * pokladna zůstane, jak je, a protokol to ohlásí. Vrací měnu, ve které pokladna je.
     */
    private function alignRegisterCurrency(ImportContext $ctx, int $registerId, string $code, string $currency): string
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT currency_code FROM cash_registers WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$registerId, $ctx->supplierId]);
        $current = strtoupper(trim((string) ($stmt->fetchColumn() ?: 'CZK')));
        if ($current === $currency) {
            return $current;
        }
        if ($current !== 'CZK' || $currency === 'CZK') {
            $ctx->protocol->warn(self::STEP_CASH, 'register_currency_differs', sprintf(
                'Pokladna %s je v MyÚčtu vedená v %s, v Money v %s. Převod měnu pokladny nemění, zkontrolujte ji.', $code, $current, $currency
            ), ['register' => $code]);
            return $current;
        }
        $own = $pdo->prepare(
            "SELECT COUNT(*) FROM cash_documents d
              WHERE d.supplier_id = ? AND d.register_id = ?
                AND NOT EXISTS (SELECT 1 FROM money_s3_import_map m
                                 WHERE m.supplier_id = d.supplier_id AND m.kind = ? AND m.target_id = d.id)"
        );
        $own->execute([$ctx->supplierId, $registerId, MoneyS3ImportRepository::KIND_CASH_DOCUMENT]);
        $ownCount = (int) $own->fetchColumn();
        if ($ownCount > 0) {
            $ctx->protocol->warn(self::STEP_CASH, 'register_currency_kept', sprintf(
                'Pokladna %s je v Money vedená v %s, v MyÚčtu v Kč a má %d dokladů zadaných mimo převod. Měna pokladny zůstává, valuty dokladů z Money se nedoplní.',
                $code, $currency, $ownCount
            ), ['register' => $code, 'currency' => $currency, 'documents' => $ownCount]);
            return $current;
        }
        $pdo->prepare('UPDATE cash_registers SET currency_code = ? WHERE id = ? AND supplier_id = ?')
            ->execute([$currency, $registerId, $ctx->supplierId]);
        $ctx->protocol->info(self::STEP_CASH, 'register_currency_changed', sprintf(
            'Pokladna %s je v Money vedená v %s: převedená korunová pokladna přepnuta na %s.', $code, $currency, $currency
        ), ['register' => $code, 'currency' => $currency]);
        $ctx->protocol->count(self::STEP_CASH, 'registers_currency');
        return $currency;
    }

    /**
     * Valuta pokladního dokladu v pokladně vedené v cizí měně: měna dokladu (`Mena`),
     * kurz za jednotku (`Kurs` za `PocetJedn` jednotek, HUF bývá za 100) a částka ve
     * valutě (`ValutyKUhr`, záložně `ValutyZak0`). Korunová částka dokladu zůstává
     * z `Celkem` - Money je autorita. Korunová pokladna a doklad bez valut vrací null.
     *
     * @param array<string,mixed> $r
     * @return array{currency:string,rate:float,foreign:float}|null
     */
    private function documentForeign(ImportContext $ctx, array $r, string $registerCurrency, string $docNo, int $year): ?array
    {
        if ($registerCurrency === 'CZK') {
            return null;
        }
        $fx = self::cashForeign($r);
        if ($fx === null || $fx['currency'] !== $registerCurrency) {
            $ctx->protocol->warn(self::STEP_CASH, 'cash_currency_mismatch', sprintf(
                'Pokladní doklad %s (%d): pokladna je vedená v %s, doklad %s. Valutu dokladu doplňte ručně.',
                $docNo, $year, $registerCurrency, $fx === null ? 'valutu nenese' : 'je v ' . $fx['currency']
            ), ['document_no' => $docNo, 'year' => $year]);
            return null;
        }
        return $fx;
    }

    /**
     * Valuta pokladního dokladu z Money, nebo null, když doklad v cizí měně není nebo
     * částku ve valutě ani kurz nenese.
     *
     * @param array<string,mixed> $r
     * @return array{currency:string,rate:float,foreign:float}|null
     */
    public static function cashForeign(array $r): ?array
    {
        $currency = self::currency((string) ($r['Mena'] ?? ''));
        if ($currency === 'CZK') {
            return null;
        }
        $czk = abs(round((float) ($r['Celkem'] ?? 0), 2));
        $foreign = abs(round((float) ($r['ValutyKUhr'] ?? 0), 2)) ?: abs(round((float) ($r['ValutyZak0'] ?? 0), 2));
        $rate = (float) ($r['Kurs'] ?? 0) / max(1.0, (float) ($r['PocetJedn'] ?? 1));
        if ($foreign === 0.0 && $rate > 0) {
            $foreign = round($czk / $rate, 2);
        }
        if ($rate <= 0 && $foreign > 0) {
            $rate = $czk / $foreign;
        }
        if ($foreign <= 0 || $rate <= 0) {
            return null;
        }
        return ['currency' => $currency, 'rate' => round($rate, 6), 'foreign' => $foreign];
    }

    /** První volná analytika pokladny 211.nnn firmy (účet pokladny je ve firmě unikátní). */
    private function freeCashAccount(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT account_code FROM cash_registers WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $used = array_flip(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        for ($n = 1; $n < 1000; $n++) {
            $code = sprintf('211.%03d', $n);
            if (!isset($used[$code])) {
                return $code;
            }
        }
        throw new MoneyS3Exception('cash_register_accounts', 'Firma nemá volnou analytiku pokladny 211.');
    }

    /**
     * Skutečné číslo účtu firmy zná Money. Na měnový účet firmy (CZK, EUR…) se doplní účet
     * v té měně z POSLEDNÍHO roku agendy (banku mohla firma změnit) jen tehdy, když tam žádné
     * není — vyplněný účet převod nepřepisuje.
     *
     * @param array<string,array{number:string,bank:string,iban:string,currency:string,year:int}> $accounts
     */
    private function fillOwnAccount(int $supplierId, array $accounts, BankAccountRegistrar $registrar): void
    {
        $best = [];
        foreach ($accounts as $a) {
            if ($a['number'] === '') {
                continue;
            }
            $current = $best[$a['currency']] ?? null;
            if ($current === null || $a['year'] > $current['year']) {
                $best[$a['currency']] = $a;
            }
        }
        foreach ($best as $currency => $a) {
            $registrar->fillCurrencyAccount(
                $supplierId,
                $currency,
                mb_substr($a['number'], 0, 30),
                $a['bank'] !== '' ? mb_substr($a['bank'], 0, 4) : null,
                $a['iban'] !== '' ? mb_substr($a['iban'], 0, 34) : null,
            );
        }
    }

    /**
     * Vlastní bankovní účty z Money pro evidenci účtů firmy ({@see BankAccountRegistrar}):
     * analytika 221.xxx z primárního účtu Money (`PrimUcet`), název z popisu účtu, jinak
     * „Účet {kód}". Mezi účty firmy v měnách se dostane každý účet s pohyby; jen první
     * doplní prázdnou výchozí měnu ({@see fillOwnAccount()}).
     *
     * @param array<string,array{number:string,bank:string,iban:string,primary:string,currency:string,label:string}> $accounts
     * @return array<string,array{number:string,bank:string,iban:string,currency:string,label:string,suffix:?string}>
     */
    private static function companyAccounts(array $accounts): array
    {
        $out = [];
        foreach ($accounts as $code => $a) {
            $primary = AccountCode::fromMoney($a['primary']);
            $out[$code] = [
                'number' => $a['number'],
                'bank' => $a['bank'],
                'iban' => $a['iban'],
                'currency' => $a['currency'],
                'label' => $a['label'] !== '' ? $a['label'] : 'Účet ' . $code,
                'suffix' => $primary !== null && str_starts_with($primary, '221.') ? substr($primary, 4) : null,
            ];
        }
        return $out;
    }

    /**
     * Variabilní symbol a popis bankovního pohybu z Money ({@see BankSymbols::variableSymbolAndDescription()}).
     * Money do pole ukládá i reference plateb kartou (13 až 15 číslic) nebo text; ty VS nejsou.
     *
     * @param array<string,mixed> $r
     * @return array{0:?string,1:?string} [variabilní symbol, popis]
     */
    private static function symbolAndDescription(array $r): array
    {
        return BankSymbols::variableSymbolAndDescription(trim((string) ($r['VarSym'] ?? '')), trim((string) ($r['Popis'] ?? '')), false);
    }

    /** Měna pokladny/účtu z Money (prázdná = domácí). */
    private static function currency(string $mena): string
    {
        $m = strtoupper(trim($mena));
        return in_array($m, ['', 'KČ', 'CZK'], true) ? 'CZK' : $m;
    }
}
