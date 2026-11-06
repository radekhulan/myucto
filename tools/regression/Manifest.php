<?php

declare(strict_types=1);

namespace MyUctoRegression;

final class Manifest
{
    public static function discover(\PDO $pdo, string $asOf): array
    {
        $suppliers = $pdo->query('SELECT id, taxpayer_type, accounting_mode, supplier_group_id FROM supplier ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        $periods = $pdo->query('SELECT p.id, p.supplier_id, p.fiscal_year, p.starts_on, p.ends_on FROM accounting_periods p ORDER BY p.supplier_id, p.starts_on, p.id')->fetchAll(\PDO::FETCH_ASSOC);
        $types = $pdo->query('SELECT id, supplier_id, supplier_group_id FROM dimension_types ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        $values = $pdo->query('SELECT id, type_id FROM dimension_values ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        $starts = $pdo->query("SELECT supplier_id, MIN(day) AS first_day FROM (
            SELECT supplier_id, starts_on AS day FROM accounting_periods
            UNION ALL SELECT supplier_id, issue_date FROM invoices
            UNION ALL SELECT supplier_id, tax_date FROM invoices
            UNION ALL SELECT supplier_id, issue_date FROM purchase_invoices
            UNION ALL SELECT supplier_id, tax_date FROM purchase_invoices
            UNION ALL SELECT supplier_id, issue_date FROM cash_documents
            UNION ALL SELECT bs.supplier_id, bt.posted_at FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id
        ) activity GROUP BY supplier_id")->fetchAll(\PDO::FETCH_KEY_PAIR);
        $modeHistory = $pdo->query('SELECT supplier_id, effective_from, accounting_mode FROM supplier_accounting_modes ORDER BY supplier_id, effective_from')->fetchAll(\PDO::FETCH_ASSOC);
        $storedTypes = $pdo->query('SELECT supplier_id, year, taxpayer_type FROM income_tax_returns ORDER BY supplier_id, year, taxpayer_type')->fetchAll(\PDO::FETCH_ASSOC);
        $filed = $pdo->query("SELECT supplier_id, form_code, period_year, period_month, period_quarter, form_variant, xml_content, xml_sha256 FROM tax_submissions WHERE status IN ('submitted','accepted') AND form_code IN ('dphdp3','dphkh1','dphshv') ORDER BY supplier_id, form_code, period_year, period_month, period_quarter, id")->fetchAll(\PDO::FETCH_ASSOC);
        $cases = [];
        $years = [];
        $periodSuppliers = [];
        $future = 0;
        foreach ($periods as $p) {
            $sid = (int) $p['supplier_id'];
            $pid = (int) $p['id'];
            $periodSuppliers[$sid] = true;
            $years[$sid][(int) $p['fiscal_year']] = true;
            $from = (string) $p['starts_on'];
            $end = (string) $p['ends_on'];
            $dates = $from <= $asOf ? [min($end, $asOf)] : [];
            if ($end > $asOf) {
                $dates[] = $end;
                ++$future;
            }
            foreach ($dates as $to) {
                foreach (['balance_sheet', 'income_statement', 'income_statement_function'] as $kind) {
                    foreach (['full', 'small', 'micro'] as $scope) {
                        self::add($cases, $kind, [$sid, $pid, $to, $scope, null]);
                    }
                }
                foreach ([false, true] as $analytics) {
                    foreach ([false, true] as $closed) {
                        self::add($cases, 'trial_balance', [$sid, $pid, $from, $to, $analytics, $closed, null]);
                        self::add($cases, 'general_ledger', [$sid, $pid, $from, $to, $analytics, [], $closed]);
                    }
                }
                foreach ($types as $type) {
                    if (!self::visible($type, $sid, $suppliers)) {
                        continue;
                    }
                    $tid = (int) $type['id'];
                    $members = array_map(static fn (array $s): int => (int) $s['id'], array_values(array_filter($suppliers, static fn (array $s): bool => self::visible($type, (int) $s['id'], $suppliers))));
                    self::add($cases, 'dimension_profit', [$sid, $tid, $from, $to, [$sid], ['accounts' => true, 'companies' => true]]);
                    if (count($members) > 1) {
                        self::add($cases, 'dimension_profit', [$sid, $tid, $from, $to, $members, ['accounts' => true, 'companies' => true]]);
                    }
                    foreach ($values as $value) {
                        if ((int) $value['type_id'] !== $tid) {
                            continue;
                        }
                        foreach ([false, true] as $descendants) {
                            $filter = ['value_id' => (int) $value['id'], 'descendants' => $descendants];
                            foreach (['balance_sheet', 'income_statement', 'income_statement_function'] as $kind) {
                                self::add($cases, $kind, [$sid, $pid, $to, 'full', null], $filter);
                            }
                            foreach ([false, true] as $closed) {
                                self::add($cases, 'trial_balance', [$sid, $pid, $from, $to, true, $closed, null], $filter);
                            }
                            self::add($cases, 'dimension_cash_flow', [$from, $to, $members], $filter);
                        }
                    }
                }
            }
            self::add($cases, 'closing_pl_balances', [$sid, $pid, $from, $end]);
            self::add($cases, 'closing_bs_balances', [$sid, $pid, $end]);
            self::add($cases, 'opening_balances', [$sid, $pid]);
            self::add($cases, 'closing_state', [$sid, $pid]);
            self::add($cases, 'closing_income_tax', [$sid, $pid]);
            foreach (['closing_entry_projection', 'closing_inventory', 'closing_stock', 'closing_fx', 'closing_small_asset_accrual', 'closing_prepaid_accrual', 'closing_deferred_revenue', 'closing_provisions', 'closing_deferred_tax'] as $kind) self::add($cases, $kind, [$sid, $pid, $from, $end]);
        }
        foreach ($pdo->query('SELECT supplier_id, year FROM income_tax_returns UNION SELECT supplier_id, year FROM tax_profiles ORDER BY supplier_id, year')->fetchAll(\PDO::FETCH_ASSOC) as $return) {
            $years[(int) $return['supplier_id']][(int) $return['year']] = true;
        }
        $withoutPeriods = 0;
        $quarterlyKhPo = 0;
        $forecastYearUnsupported = 0;
        foreach ($suppliers as $supplier) {
            $sid = (int) $supplier['id'];
            $years[$sid][(int) substr($asOf, 0, 4)] = true;
            $withoutPeriods += isset($periodSuppliers[$sid]) ? 0 : 1;
            foreach ($modeHistory as $mode) if ((int) $mode['supplier_id'] === $sid) {
                $years[$sid][(int) substr($mode['effective_from'], 0, 4)] = true;
                foreach ([(int) substr($mode['effective_from'], 0, 4) - 1, (int) substr($mode['effective_from'], 0, 4)] as $modeYear) self::add($cases, 'accounting_mode_year', [$sid, $modeYear]);
            }
            self::add($cases, 'accounting_mode_year', [$sid, (int) substr($asOf, 0, 4)]);
            $first = (string) ($starts[$sid] ?? substr($asOf, 0, 4) . '-01-01');
            for ($year = (int) substr($first, 0, 4); $year <= (int) substr($asOf, 0, 4); ++$year) {
                $years[$sid][$year] = true;
            }
            $month = new \DateTimeImmutable(substr($first, 0, 7) . '-01');
            $quarters = [];
            while ($month->format('Y-m-t') <= $asOf) {
                $year = (int) $month->format('Y');
                $m = (int) $month->format('m');
                self::add($cases, 'vat_ledger', [$sid, $month->format('Y-m-d'), $month->format('Y-m-t'), false]);
                self::add($cases, 'vat_return_ledger', [$sid, $month->format('Y-m-d'), $month->format('Y-m-t'), false]);
                foreach (['vat_book', 'vat_return', 'control_statement', 'recapitulative_statement'] as $kind) {
                    self::add($cases, $kind, [$sid, $year, $m, 'monthly']);
                }
                $qEnd = (int) ceil($m / 3) * 3;
                if ((new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $qEnd)))->format('Y-m-t') <= $asOf) {
                    $quarters[$year . ':' . $qEnd] = [$year, $qEnd];
                }
                $month = $month->modify('+1 month');
            }
            foreach ($quarters as [$year, $qEnd]) {
                foreach (['vat_book', 'vat_return', 'control_statement', 'recapitulative_statement'] as $kind) {
                    if ($kind === 'control_statement' && $supplier['taxpayer_type'] === 'po') {
                        ++$quarterlyKhPo;
                        continue;
                    }
                    self::add($cases, $kind, [$sid, $year, $qEnd, 'quarterly']);
                }
            }
            foreach (array_keys($years[$sid] ?? []) as $year) {
                $type = $supplier['taxpayer_type'] === 'po' ? 'po' : 'fo';
                $prefix = $type === 'po' ? 'dppo' : 'dpfo';
                foreach (['preview', 'inputs', 'balance_due'] as $suffix) {
                    self::add($cases, $prefix . '_' . $suffix, [$sid, $year, $type]);
                }
                self::add($cases, $prefix . '_data', [$sid, $year]);
                self::add($cases, $prefix . '_xml', [$sid, $year, $type]);
                self::add($cases, 'accounting_mode_year', [$sid, $year]);
                foreach ($storedTypes as $stored) if ((int) $stored['supplier_id'] === $sid && (int) $stored['year'] === $year && $stored['taxpayer_type'] !== $type) {
                    $historical = $stored['taxpayer_type'];
                    if (!in_array($historical, ['po', 'fo'], true)) throw new \RuntimeException('invalid_historical_taxpayer_type');
                    foreach (['preview', 'inputs', 'balance_due', 'xml'] as $suffix) self::add($cases, ($historical === 'po' ? 'dppo_' : 'dpfo_') . $suffix, [$sid, $year, $historical]);
                }
                if ($type === 'po') {
                    if ($year >= 2020 && $year <= 2100) {
                        self::add($cases, 'dppo_forecast', [$sid, $year]);
                    } else {
                        ++$forecastYearUnsupported;
                    }
                }
                $modes = [$supplier['accounting_mode']];
                foreach ($modeHistory as $mode) if ((int) $mode['supplier_id'] === $sid && $mode['effective_from'] <= $year . '-12-31') $modes[] = $mode['accounting_mode'];
                if (in_array('tax_evidence', $modes, true)) {
                    self::add($cases, 'cash_journal', [$sid, $year . '-01-01', max($year . '-01-01', min($year . '-12-31', $asOf)), ['year' => $year]]);
                    self::add($cases, 'tax_estimate', [$sid, $year, $asOf]);
                }
            }
        }
        $amendmentPeriods = [];
        $futureFiled = 0;
        foreach ($filed as $submission) {
            $form = $submission['form_code'];
            $year = (int) $submission['period_year'];
            $quarter = $submission['period_quarter'] === null ? null : (int) $submission['period_quarter'];
            $month = $quarter === null ? (int) $submission['period_month'] : $quarter * 3;
            if ($year < 1 || $month < 1 || $month > 12 || ($quarter !== null && ($quarter < 1 || $quarter > 4))) throw new \RuntimeException('invalid_filed_vat_period');
            if ((new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('Y-m-t') > $asOf) { ++$futureFiled; continue; }
            self::assertFiledVatXml($submission);
            $kind = ['dphdp3' => 'vat_return', 'dphkh1' => 'control_statement', 'dphshv' => 'recapitulative_statement'][$form];
            $variants = $form === 'dphdp3' ? ['opravne', 'dodatecne'] : ($form === 'dphkh1' ? ['opravne', 'nasledne', 'nasledne_opravne'] : ['nasledne']);
            $sid = (int) $submission['supplier_id'];
            foreach ($variants as $variant) self::add($cases, $kind, [$sid, $year, $month, $quarter === null ? 'monthly' : 'quarterly', $variant, $asOf]);
            $amendmentPeriods[$sid . ':' . $form . ':' . $year . ':' . $month . ':' . ($quarter ?? '')] = true;
        }
        $noFiledPredecessor = 0;
        foreach ($cases as $case) {
            $kind = $case['kind']; $args = $case['args'];
            $form = ['vat_return' => 'dphdp3', 'control_statement' => 'dphkh1', 'recapitulative_statement' => 'dphshv'][$kind] ?? null;
            if ($form === null || count($args) !== 4) continue;
            $quarter = $args[3] === 'quarterly' ? (int) ceil($args[2] / 3) : null;
            if (!isset($amendmentPeriods[$args[0] . ':' . $form . ':' . $args[1] . ':' . $args[2] . ':' . ($quarter ?? '')])) ++$noFiledPredecessor;
        }
        ksort($cases);
        return ['cases' => $cases, 'coverage' => [
            'complete' => false,
            'manifest_complete' => true,
            'scope' => 'native_500_and_annual_tax_evidence; unsupported frameworks fail rather than count as covered',
            'outside_scope' => ['daily_accounting_mode_boundaries', 'native_504_closing_opening'],
            'implemented' => ['accounting_period_reports', 'full_period_future_entries', 'dppo_read_only', 'dppo_forecast', 'dpfo_read_only', 'tax_evidence', 'dimension_filters', 'joint_dimensions', 'closing_read_only_balances_state_income_tax', 'monthly_vat', 'quarterly_vat', 'vat_xml_numeric_projection', 'suppliers_without_accounting_periods', 'joint_dimension_closing_opening_preview_500', 'closing_inventory_fx_accrual_provisions_deferred_tax_previews', 'tax_return_xml_numeric_projection', 'vat_amendments_for_filed_periods', 'historical_annual_taxpayer_mode_transitions'],
            'not_implemented' => [],
            'not_applicable' => ['po_quarterly_control_statement' => $quarterlyKhPo, 'dppo_forecast_year_outside_2020_2100' => $forecastYearUnsupported, 'vat_amendments_without_filed_predecessor' => $noFiledPredecessor, 'filed_vat_period_after_as_of' => $futureFiled],
            'future_or_partial_periods_full_year_included' => $future,
            'suppliers_without_accounting_periods' => $withoutPeriods,
            'period_count' => count($periods),
            'filed_vat_amendment_periods' => count($amendmentPeriods),
            'amendment_applicability' => 'Only periods with an existing filed submission; without a filed predecessor corrective/additional VAT submissions have no legal baseline. Invalid/incomplete filed predecessors remain failing cases.',
            'historical_taxpayer_scope' => 'Stored return types are invoked explicitly; current supplier-type mismatch remains an error. No historical type is inferred without stored evidence. Mode comparisons use native forYear at year-end; daily boundaries are outside this parity scope.',
        ]];
    }

    private static function assertFiledVatXml(array $submission): void
    {
        $xml = (string) $submission['xml_content'];
        if ($xml === '' || !hash_equals((string) $submission['xml_sha256'], hash('sha256', $xml)) || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) throw new \RuntimeException('invalid_filed_vat_xml');
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            if (!$document->loadXML($xml, LIBXML_NONET)) throw new \RuntimeException('invalid_filed_vat_xml');
            $xpath = new \DOMXPath($document);
            $formPath = '/Pisemnost/' . strtoupper($submission['form_code']);
            if ($xpath->query($formPath)->length !== 1 || $xpath->query($formPath . '/VetaP')->length !== 1) throw new \RuntimeException('invalid_filed_vat_xml');
            $dates = $xpath->query($formPath . '/VetaD');
            if ($dates->length !== 1) throw new \RuntimeException('invalid_filed_vat_xml');
            $date = $dates->item(0);
            $periodKey = $submission['period_quarter'] === null ? 'mesic' : 'ctvrt';
            $periodValue = $submission['period_quarter'] ?? $submission['period_month'];
            if ((int) $date->getAttribute('rok') !== (int) $submission['period_year'] || (int) $date->getAttribute($periodKey) !== (int) $periodValue) throw new \RuntimeException('invalid_filed_vat_period');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function visible(array $type, int $supplierId, array $suppliers): bool
    {
        if ((int) ($type['supplier_id'] ?? 0) === $supplierId) {
            return true;
        }
        foreach ($suppliers as $supplier) {
            if ((int) $supplier['id'] === $supplierId && $type['supplier_group_id'] !== null && (int) $supplier['supplier_group_id'] === (int) $type['supplier_group_id']) {
                return true;
            }
        }
        return false;
    }

    private static function add(array &$cases, string $kind, array $args, ?array $dimension = null): void
    {
        $case = ['kind' => $kind, 'args' => $args];
        if ($dimension !== null) {
            $case['dimension'] = $dimension;
        }
        $cases[substr(hash('sha256', json_encode($case, JSON_THROW_ON_ERROR)), 0, 24)] = $case;
    }

    public static function invoke(object $container, array $case): mixed
    {
        if (str_starts_with($case['kind'], 'closing_') && !in_array($case['kind'], ['closing_pl_balances', 'closing_bs_balances', 'closing_state', 'closing_income_tax'], true)) return CaseProjection::closing($container, $case);
        $accounting = 'MyInvoice\\Service\\Accounting\\Reports\\';
        $tax = 'MyInvoice\\Service\\Tax\\Return\\';
        $vat = 'MyInvoice\\Service\\Report\\';
        [$class, $method] = match ($case['kind']) {
            'balance_sheet' => [$accounting . 'FinancialStatementService', 'balanceSheet'],
            'income_statement' => [$accounting . 'FinancialStatementService', 'incomeStatement'],
            'income_statement_function' => [$accounting . 'FinancialStatementService', 'incomeStatementByFunction'],
            'trial_balance' => [$accounting . 'TrialBalanceService', 'build'],
            'general_ledger' => [$accounting . 'GeneralLedgerService', 'build'],
            'accounting_mode_year' => ['MyInvoice\\Repository\\AccountingModeRepository', 'forYear'],
            'dppo_xml', 'dpfo_xml' => [$tax . 'TaxReturnService', 'reportSource'],
            'dppo_preview', 'dpfo_preview' => [$tax . 'TaxReturnService', 'previewReadOnly'],
            'dppo_inputs', 'dpfo_inputs' => [$tax . 'TaxReturnService', 'readOnlyInputs'],
            'dppo_balance_due', 'dpfo_balance_due' => [$tax . 'TaxReturnService', 'balanceDueReadOnly'],
            'closing_pl_balances' => ['MyInvoice\\Repository\\ClosingRepository', 'plBalances'],
            'closing_bs_balances' => ['MyInvoice\\Repository\\ClosingRepository', 'bsBalances'],
            'opening_balances' => ['MyInvoice\\Repository\\ClosingRepository', 'openingBalancesInPeriod'],
            'closing_state' => ['MyInvoice\\Service\\Accounting\\Closing\\ClosingService', 'state'],
            'closing_income_tax' => ['MyInvoice\\Service\\Accounting\\Closing\\ClosingService', 'incomeTaxPreview'],
            'dimension_profit' => [$accounting . 'DimensionProfitService', 'build'],
            'dimension_cash_flow' => [$accounting . 'DimensionCashFlowService', 'build'],
            'cash_journal' => ['MyInvoice\\Service\\TaxEvidence\\CashJournalService', 'build'],
            'tax_estimate' => ['MyInvoice\\Service\\TaxEvidence\\TaxEstimateService', 'estimate'],
            'dpfo_data' => [$tax . 'DpfoReturnDataProvider', 'gather'],
            'dppo_forecast' => ['MyInvoice\\Service\\Accounting\\Obligations\\ExistingObligationSourceService', 'dppoForecastForYear'],
            'dppo_data' => [$tax . 'DppoReturnDataProvider', 'gather'],
            'vat_ledger' => [$vat . 'VatLedgerService', 'rows'],
            'vat_return_ledger' => [$vat . 'VatLedgerService', 'returnRows'],
            'vat_book' => [$vat . 'DphBookBuilder', 'build'],
            'vat_return' => [$vat . 'DphPriznaniBuilder', 'build'],
            'control_statement' => [$vat . 'KontrolniHlaseniBuilder', 'build'],
            'recapitulative_statement' => [$vat . 'SouhrnneHlaseniBuilder', 'build'],
            default => throw new \RuntimeException('unknown_manifest_case'),
        };
        $args = $case['args'];
        if (in_array($case['kind'], ['dppo_data', 'dpfo_data'], true)) {
            $args[] = $container->get($tax . 'TaxReturnService')->readOnlyInputs($args[0], $args[1], $case['kind'] === 'dppo_data' ? 'po' : 'fo')['inputs'];
        }
        if (isset($case['dimension'])) {
            $dimensions = $container->get('MyInvoice\\Service\\Accounting\\Dimension\\DimensionService');
            $filter = $case['dimension'];
            if ($case['kind'] === 'dimension_cash_flow') {
                $filters = [];
                foreach ($args[2] as $sid) {
                    $filters[$sid] = $dimensions->filter($sid, $filter['value_id'], $filter['descendants']);
                }
                $args[2] = $filters;
            } else {
                $args[count($args) - 1] = $dimensions->filter($args[0], $filter['value_id'], $filter['descendants']);
            }
        }
        $data = $container->get($class)->$method(...$args);
        if (is_array($data) && isset($data['xml'])) {
            $data['xml_numeric'] = self::xmlNumericProjection($data['xml']);
        }
        return $data;
    }

    public static function xmlNumericProjection(string $xml): array
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new \RuntimeException('xml_external_declaration');
        }
        $document = new \DOMDocument();
        if (!$document->loadXML($xml, LIBXML_NONET)) {
            throw new \RuntimeException('invalid_report_xml');
        }
        $values = [];
        foreach ((new \DOMXPath($document))->query('//@*') as $attribute) {
            if (preg_match('/^(?:kc_|da_|uhrn_|prijmy|vydaje|rozdil|pr_prij|pr_vyd|pr_sazba|sazba_dal|ost_trans_sl|zakl|dan|odp|nar|kr_|uprav|pln|obrat|hodnota|celk|sum|vypor|koef)/', $attribute->nodeName)
                && preg_match('/^[+-]?\d+(?:\.\d+)?$/D', $attribute->nodeValue)) {
                $values[$attribute->getNodePath()] = $attribute->nodeValue;
            }
        }
        ksort($values);
        return $values;
    }

    public static function normalize(mixed $data, string $asOf, string $path = ''): mixed
    {
        if (!is_array($data)) {
            if (is_object($data)) {
                throw new \RuntimeException('unreviewed_result_object');
            }
            return $data;
        }
        if (in_array($data['type'] ?? null, ['fx_rate_missing', 'fx_rate_approximate', 'fx_rate_backlog'], true)) {
            throw new \RuntimeException('missing_exchange_rates');
        }
        foreach ($data as $key => $value) {
            if ($key === 'missing_rates' && $value !== []) {
                throw new \RuntimeException('missing_exchange_rates');
            }
            // Only report presentation metadata is normalized. Dates in financial data remain intact.
            if ($key === 'prepared_at' && $path === '/entity') {
                $data[$key] = $asOf . ' 00:00';
            } else {
                $data[$key] = self::normalize($value, $asOf, $path . '/' . $key);
            }
        }
        return $data;
    }
}
