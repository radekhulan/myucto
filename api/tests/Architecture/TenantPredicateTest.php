<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Epic F0 — architektonická pojistka tenant izolace (static source scan).
 *
 * Každý SQL statement v Repository vrstvě, který čte/mění tenant tabulku
 * (tabulky se sloupcem supplier_id — odvozeno z migrací, viz 0115 + 0118 + 0124
 * + 1000), musí ve STEJNÉM statementu obsahovat 'supplier_id' — tj. predikát
 * (WHERE/JOIN ON), insert sloupec nebo scoped subselect. Chybějící predikát =
 * potenciální cross-tenant únik dat.
 *
 * "Statement" aproximujeme úsekem PHP zdrojáku mezi středníky — SQL literály
 * v repozitářích středník neobsahují, takže úsek spolehlivě pokrývá celý
 * prepare(...) call včetně řetězení. Dynamicky skládané SQL přes více PHP
 * statementů ($sql .= ...) je potřeba buď psát se supplier_id v témže úseku,
 * nebo vědomě whitelistnout níže s odůvodněním.
 *
 * Test je záměrně přísný na FROM/JOIN/UPDATE/DELETE (čtení a mutace); INSERT
 * neřešíme (bez WHERE, sloupce vidí schema FK). Whitelist = explicitní,
 * zdůvodněné výjimky — každá nová položka musí mít komentář PROČ je bezpečná.
 */
final class TenantPredicateTest extends TestCase
{
    /**
     * Tenant tabulky = tabulky se sloupcem supplier_id (migrace 0001…1000).
     * Zdroj: 0115_supplier_id_int.sql (kompletní výčet k 0115) + novější
     * 0118 (sample_data_entries), 0124 (email_profiles). Tabulku user_suppliers
     * (1000) hlídáme taky — je to samotná membership tabulka.
     */
    private const TENANT_TABLES = [
        'activity_log',
        'api_tokens',
        'bank_email_account_mappings',
        'bank_email_imap_settings',
        'bank_email_notice_providers',
        'bank_email_processed_messages',
        'cars',
        'clients',
        'crm_action_item_dismissals',
        'crm_monthly_summary',
        'currencies',
        'documents',
        'document_folders',
        'document_tags',
        'email_profiles',
        'expense_categories',
        'fuelings',
        'import_jobs',
        'invoices',
        'invoice_counters',
        'invoice_payments',
        'logbook_fuel_scans',
        'payment_matches',
        'payment_orders',
        'pdf_signature_output_settings',
        'purchase_invoices',
        'purchase_invoice_counters',
        'recurring_invoice_templates',
        'revenue_categories',
        'sample_data_entries',
        'signature_document_overrides',
        'signature_role_profiles',
        'signature_user_profiles',
        'signing_profiles',
        'signing_settings',
        'tax_profiles',
        'tax_submissions',
        'trips',
        'trip_categories',
        'user_suppliers',
        'vat_classifications',
        'work_report_links',
        // Epic SKLAD (1022_stock.sql) — nové tenant tabulky.
        'warehouses',
        'stock_items',
        'stock_levels',
        'stock_documents',
        'stock_document_lines',
        'stock_landed_costs',
        'stock_takes',
        'stock_take_lines',
        // Epic ESHOP (1028_eshop.sql) — nové tenant tabulky.
        'stock_item_i18n',
        'manufacturers',
        'stock_categories',
        'stock_category_i18n',
        'stock_item_categories',
        'stock_tags',
        'stock_item_tags',
        'stock_attributes',
        'stock_attribute_options',
        'stock_attribute_i18n',
        'stock_item_attribute_values',
        'stock_fee_types',
        'stock_item_fees',
        'stock_item_prices',
        'stock_item_vendors',
        'stock_media',
        // Epic DP (1030_income_tax.sql, issue #18) — perzistence přiznání daně z příjmů.
        'income_tax_returns',
        // Jádro PÚ (Epic F1–F7, migrace 1003–1038) — dřív v seznamu chybělo úplně
        // (audit 2026-07, nález "Architektonický test tenant izolace nepokrývá
        // žádnou jádrovou účetní tabulku"). Doplněno podle information_schema
        // (sloupec supplier_id), ne jen ručně vybraných jmen z auditu.
        'chart_of_accounts',
        'accounting_periods',
        'accounting_supplier_settings',
        'journal_entries',
        'journal_entry_lines',
        'journal_entry_attachments',
        'journal_integrity_findings',
        'posting_rules',
        'assets',
        'asset_improvements',
        'depreciation_entries',
        'accounting_document_series',
        'accounting_closing_steps',
        'accounting_archives',
        'cash_registers',
        'cash_documents',
        'bank_posting_rules',
        'bank_posting_suggestions',
        'supplier_bank_accounts',
        'bank_transfer_matches',
        'de_movement_classification',
        'document_embeddings',
        'document_files',
        'vat_coefficients',
        'purchase_invoice_vat_allocations',
        'saved_filters',
        // Epic D5 (1040_entity_category_history.sql).
        'entity_category_history',
        // Epic E2 (1042_tax_losses.sql) — daňové ztráty a jejich uplatnění.
        'tax_losses',
        'tax_loss_applications',
        // Epic E9 (1044/1045_tax_advance_schedules*.sql) — plán daňových záloh.
        'tax_advance_schedules',
        'ai_suggestions',
        'ai_jobs',
        'ai_metrics',
        'ai_source_mutes',
        'ai_embeddings',
        'ai_daily_usage',
        // §DM (1093_expense_classification_rules.sql) — per-tenant pravidla klasifikace
        // druhu výdaje.
        'expense_classification_rules',
        // §DM (1094_small_assets.sql) — karty evidence drobného majetku (§28/5 ZoÚ).
        'small_assets',
        // NEMPRI/HZUPN (1654, 1655) — případy dávek nemocenského pojištění.
        // Řádek nese rodné číslo pojištěnce a údaje o exekuci a insolvenci,
        // takže únik mezi firmami by byl únik zvlášť citlivých osobních údajů.
        'payroll_sickness_cases',
        'payroll_sickness_case_work_days',
    ];

    /**
     * Zdůvodněné výjimky — klíč 'Soubor.php:tabulka'. KAŽDÁ položka musí mít
     * komentář proč je bez supplier_id predikátu bezpečná.
     *
     * @var array<string, true>
     */
    private const WHITELIST = [
        // user_suppliers je scoped per user_id (PK = user_id+supplier_id); dotazy
        // v UserSupplierRepository filtrují WHERE user_id = ? — supplier_id je tu
        // datový sloupec, ne tenant predikát. Membership tabulku navíc čte jen
        // resolver/admin akce, ne business data.
        'UserSupplierRepository.php:user_suppliers' => true,

        // RoleRepository agreguje využití rolí napříč firmami pro globální
        // superadmin správu a při mazání ověřuje, zda roli používá libovolné
        // členství. Scope je záměrně globální, business data se zde nečtou.
        'RoleRepository.php:user_suppliers' => true,

        // Dynamický $where builder: 'c.supplier_id = ?' se přidává v PŘEDCHOZÍM
        // PHP statementu (ClientRepository::list, řádek ~66) — predikát je mimo
        // chunk se samotným SELECT. Caller (ListClientsAction) vždy předává
        // ATTR_CURRENT_ID do $filters['supplier_id'].
        'ClientRepository.php:clients' => true,

        // FuelingRepository::list — $where INIT = ['f.supplier_id = ?'] (řádek 26),
        // finální SQL je v jiném chunku; cars/clients/purchase_invoices jsou jen
        // LEFT JOIN dekorace řádků již scoped přes f.supplier_id.
        'FuelingRepository.php:fuelings' => true,
        'FuelingRepository.php:cars' => true,
        'FuelingRepository.php:clients' => true,
        'FuelingRepository.php:purchase_invoices' => true,

        // ImportJobRepository — list() staví dynamický $where (supplier v jiném
        // chunku); findNextQueued() je VĚDOMĚ cross-tenant: worker cron zpracovává
        // frontu všech firem, supplier_id nese samotný job řádek.
        'ImportJobRepository.php:import_jobs' => true,

        // InvoiceRepository — list()/listApprovals() staví dynamický $whereSql
        // ('i.supplier_id = ?' přidáno na ř. ~498 / ~1322 v jiném chunku);
        // findByApprovalToken() je token-capability lookup pro PUBLIC schvalovací
        // link (128bit token = autorizace, klient nemá session ani supplier).
        // clients/currencies jsou JOIN dekorace v týchž list dotazech.
        'InvoiceRepository.php:invoices' => true,
        'InvoiceRepository.php:clients' => true,
        'InvoiceRepository.php:currencies' => true,

        // ProjectRepository::list — dynamický $whereSql, 'c.supplier_id = ?'
        // přidáno na ř. ~57 (projects nemají vlastní supplier_id — scope jde
        // přes JOIN clients).
        'ProjectRepository.php:clients' => true,
        'ProjectRepository.php:currencies' => true,

        // PurchaseInvoiceRepository::listPayables — $where INIT obsahuje
        // 'pi.supplier_id = ?' (ř. 571), SELECT je v následujícím chunku;
        // clients/currencies jsou JOIN dekorace.
        'PurchaseInvoiceRepository.php:purchase_invoices' => true,
        'PurchaseInvoiceRepository.php:clients' => true,
        'PurchaseInvoiceRepository.php:currencies' => true,

        // RecurringTemplateRepository — list()/listCurrencies() dynamický $where
        // ('t.supplier_id = ?' na ř. ~87/~239 v jiném chunku); generátor (cron)
        // vědomě skenuje šablony všech firem a per-šablonu používá její supplier_id.
        'RecurringTemplateRepository.php:recurring_invoice_templates' => true,
        'RecurringTemplateRepository.php:currencies' => true,

        // SigningProfileRepository::profileSelectSql() je SQL FRAGMENT bez WHERE
        // (SELECT+JOIN); supplier_id predikát doplňují všechny call-sites.
        'SigningProfileRepository.php:signing_profiles' => true,

        // TripRepository::list — $where INIT = ['t.supplier_id = ?'] (ř. 23),
        // SELECT v jiném chunku; cars/trip_categories jsou JOIN dekorace.
        'TripRepository.php:trips' => true,
        'TripRepository.php:cars' => true,
        'TripRepository.php:trip_categories' => true,

        // WorkReportLinkRepository::findActiveByToken — token-capability lookup
        // pro PUBLIC schvalovací link výkazu (token = autorizace, bez session).
        'WorkReportLinkRepository.php:work_report_links' => true,

        // Epic SKLAD — StockItemRepository::list() — dynamický $where builder
        // ('si.supplier_id = ?' na ř. 51) v PŘEDCHOZÍM PHP statementu; finální
        // SELECT (ř. 88) je jiný chunk. (stock_levels JOIN v only_below_min
        // subquery má vlastní 'WHERE supplier_id = ?' v TÉMŽE chunku, netřeba whitelist.)
        'StockItemRepository.php:stock_items' => true,

        // Epic SKLAD — StockTakeRepository::list() — dynamický $where builder
        // ('supplier_id = ?' na ř. 43) v PŘEDCHOZÍM PHP statementu; finální
        // SELECT je jiný chunk.
        'StockTakeRepository.php:stock_takes' => true,

        // Audit 2026-07 (nález "Architektonický test tenant izolace nepokrývá
        // žádnou jádrovou účetní tabulku") — po doplnění TENANT_TABLES o jádro PÚ
        // se ukázaly stejné false-positivy z chunkingu jako u výše uvedených
        // repozitářů, ověřeno ručně v každém souboru:

        // JournalEntryRepository::paginate/forExport — buildWhere() (ř. ~586)
        // sestaví $where = ['je.supplier_id = ?', ...] ve VLASTNÍM PHP statementu
        // (samostatná privátní metoda); volající chunk pak SQL skládá jen přes
        // interpolovanou proměnnou {$whereSql}, takže literál 'supplier_id' v něm
        // není. journal_entry_lines se objevuje jen přes AMOUNT_SUBQUERY konstantu
        // (korelovaný subselect 'jel.entry_id = je.id') — je.id je už scoped stejným
        // buildWhere() predikátem v obalující SQL.
        'JournalEntryRepository.php:journal_entries' => true,
        'JournalEntryRepository.php:journal_entry_lines' => true,

        // BankPostingRuleRepository::listForTenant — $where INIT = ['r.supplier_id = ?']
        // (ř. 54) v PŘEDCHOZÍM PHP statementu; finální SELECT (ř. 68) je jiný chunk.
        'BankPostingRuleRepository.php:bank_posting_rules' => true,

        // BankPostingSuggestionRepository::paginate — $where INIT =
        // ['s.supplier_id = ?', ...] (ř. 208) v PŘEDCHOZÍM PHP statementu; COUNT
        // (ř. 218) i finální SELECT (ř. 238) jsou jiné chunky a odkazují jen na
        // interpolovanou proměnnou {$whereSql}.
        'BankPostingSuggestionRepository.php:bank_posting_suggestions' => true,
    ];

    public function testRepositoryTenantTableStatementsCarrySupplierPredicate(): void
    {
        $dir = dirname(__DIR__, 2) . '/src/Repository';
        $files = glob($dir . '/*.php');
        self::assertNotEmpty($files, 'Repository adresář nenalezen.');

        // Regex per tabulka: FROM/JOIN/UPDATE/DELETE FROM následované jménem tabulky.
        $patterns = [];
        foreach (self::TENANT_TABLES as $table) {
            $patterns[$table] = '/\b(?:FROM|JOIN|UPDATE)\s+`?' . preg_quote($table, '/') . '`?\b/i';
        }

        $violations = [];
        foreach ($files as $file) {
            $raw = file_get_contents($file);
            self::assertIsString($raw);
            $code = $this->stripComments($raw);
            $base = basename($file);

            // Statement ≈ úsek mezi PHP středníky (SQL literály ';' neobsahují).
            foreach (explode(';', $code) as $chunk) {
                // Strukturální výjimka 1: SHOW COLUMNS/INDEX — introspekce schématu
                // (feature-detekce migrací), nečte tenant data.
                if (preg_match('/\bSHOW\s+(?:COLUMNS|INDEX|TABLES)\b/i', $chunk) === 1) {
                    continue;
                }
                // Strukturální výjimka 2: přístup striktně přes primární klíč
                // (WHERE [alias.]id = ?/:param nebo IN (...)). Konvence codebase:
                // fetch/update by PK + SupplierGuard::owns() v Action vrstvě → 404
                // pro cizí entity (viz Http/SupplierGuard). PK samo o sobě
                // neumožňuje enumeraci — guard je povinný na hranici HTTP.
                if (preg_match('/\b(?:WHERE|AND)\s+\(?\s*(?:`?\w+`?\.)?`?id`?\s*(?:=\s*(?:\?|:\w+)|IN\s*\()/i', $chunk) === 1) {
                    continue;
                }
                // Strukturální výjimka 3: scope přes parametrizovaný FK rodiče
                // (WHERE car_id = ? / client_id = ? / parent_invoice_id = ? …).
                // Rodičovská entita prošla guardem (fetch by PK + SupplierGuard),
                // takže dotaz nemůže uniknout do cizí firmy, pokud FK hodnota
                // pochází z guardnutého řádku. Trade-off: test tím primárně chytá
                // klasický leak „SELECT ... FROM invoices WHERE status = ...'
                // (agregace/reporty bez jakéhokoliv parametru) — což je přesně
                // vzor, kterým cross-tenant úniky vznikají.
                if (preg_match('/\b(?:WHERE|AND)\s+\(?\s*(?:`?\w+`?\.)?\w+_id\s*(?:=\s*(?:\?|:\w+)|IN\s*\()/i', $chunk) === 1) {
                    continue;
                }
                foreach ($patterns as $table => $pattern) {
                    if (preg_match($pattern, $chunk) !== 1) {
                        continue;
                    }
                    if (isset(self::WHITELIST[$base . ':' . $table])) {
                        continue;
                    }
                    if (stripos($chunk, 'supplier_id') !== false) {
                        continue;
                    }
                    $line = substr_count(substr($code, 0, (int) strpos($code, $chunk)), "\n") + 1;
                    $violations[] = "$base:~$line — SQL na tenant tabulce '$table' bez supplier_id predikátu";
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "Tenant tabulky musí mít supplier_id predikát ve stejném statementu (Epic F0).\n"
            . "Buď doplň WHERE/JOIN supplier_id, nebo přidej ZDŮVODNĚNOU výjimku do WHITELIST.\n\n"
            . implode("\n", $violations),
        );
    }

    /**
     * Odstraní komentáře (T_COMMENT/T_DOC_COMMENT) — "JOIN clients" v doc-commentu
     * by jinak generoval falešný nález. Newlines z komentářů zachováváme kvůli
     * přibližným číslům řádků v hlášení.
     */
    private function stripComments(string $code): string
    {
        $out = '';
        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                [$id, $text] = $token;
                if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                    $out .= str_repeat("\n", substr_count($text, "\n"));
                    continue;
                }
                $out .= $text;
                continue;
            }
            $out .= $token;
        }
        return $out;
    }

    /**
     * Service/Action vrstva — whitelist metod, které nad tenant tabulkou dotazují
     * ZÁMĚRNĚ bez `supplier_id`. Každá položka musí mít důvod; nová se přidává jen
     * tehdy, když je globální rozsah skutečně správně.
     *
     * @var array<string,string> "Soubor.php::metoda" => důvod
     */
    private const CROSS_LAYER_WHITELIST = [
        // Skládají jen FRAGMENT predikátu (řetězec do WHERE), ne celý dotaz —
        // scope doplňuje volající, který si fragment interpoluje.
        'PurchaseSummaryAction.php::advanceCostExclude'   => 'vrací SQL fragment, ne dotaz',
        'SummaryAction.php::advanceCostExclude'           => 'vrací SQL fragment, ne dotaz',
        'SummaryAction.php::receivableDocTypeSql'         => 'vrací SQL fragment, ne dotaz',
        'CrmAggregationService.php::advanceCostExclude'   => 'vrací SQL fragment, ne dotaz',
        'CrmAggregationService.php::receivableDocTypeSql' => 'vrací SQL fragment, ne dotaz',

        // Instance-wide ZÁMĚRNĚ: kontrolují integritu CELÉ instance, ne jedné firmy.
        'LedgerInvariantService.php::ledgerIsEmpty' => 'invariant nad deníkem celé instance (viz docblock služby)',
        'LedgerInvariantService.php::i25AccumulatedNotAboveInputPrice' => 'invariant nad veškerým majetkem instance',
        'ActivityLogHashChain.php::verify' => 'hash chain auditního logu je jeden pro celou instanci',
        // Vrací POUZE bool „existuje někde práce?", žádná data nepřecházejí mezi
        // tenanty. Protějšek AiWorker::run(), který stejně tak jede přes všechny
        // dodavatele s opt-inem — cron nemá uživatelský kontext, na který by se
        // dal predikát navěsit. Zúžit ho na jednoho dodavatele by bránu rozbilo:
        // přestala by pouštět práci ostatním.
        'CronPreflight.php::hasAiWork' => 'instance-wide brána cronu, vrací jen bool',

        // Licence platí pro CELOU instalaci, ne pro jednu firmu: účet s právem
        // zápisu nad pěti firmami je jedno licenční místo. Zúžit počítání na
        // jednu firmu by limit rozbilo — každá firma by měla vlastní počet.
        'SeatPolicy.php::seatConditionSql' => 'licenční místa se počítají za celou instalaci',

        // Superadmin-only endpointy (jinak 403) — globální rozsah je jejich smysl.
        'ListSentEmailsAction.php::__invoke'         => 'superadmin přehled odeslaných e-mailů napříč instancí',
        'SetupSampleAction.php::__invoke'            => 'setup wizard nad prázdnou DB, superadmin-only',
        'BankRuleTemplateAdminAction.php::templates' => 'globální šablony pravidel (supplier_id IS NULL)',
        'BankRuleTemplateAdminAction.php::find'      => 'globální šablony pravidel (supplier_id IS NULL)',
        'SmtpLogAnalyzer.php::loadSentIndex'         => 'analýza SMTP logu instance (superadmin diagnostika)',
        'SmtpLogAnalyzer.php::fillFromSubject'       => 'analýza SMTP logu instance (superadmin diagnostika)',

        // Globální číselník: `countries` nemá supplier_id a `uses_count` blokuje smazání
        // země. Součet MUSÍ být přes celou instanci — jinak by jedna firma smazala zemi,
        // kterou používá jiná.
        'SettingsAction.php::listCountries' => 'interlock mazání globálního číselníku zemí',

        // Feature-detekce schématu, data nečte.
        'DemoProvisioner.php::assertSchemaReady' => 'kontrola existence tabulky, bez čtení dat',
    ];

    /**
     * Tentýž požadavek jako u repozitářů, ale pro Service/Action vrstvu a na
     * granularitě METODY.
     *
     * Per-statement heuristika (úsek mezi středníky) je tady nepoužitelná: tahle
     * vrstva skládá dotaz přes víc PHP příkazů — `$where = ['i.supplier_id = ?'];`
     * v jednom, `$sql = "... {$whereSql}"` v dalším. Per-statement scan hlásil 54
     * nálezů, drtivou většinu planých; per metodu jich zbylo 22 a všechny jsou
     * legitimní (viz whitelist výše).
     *
     * Pravidlo: obsahuje-li tělo metody SQL nad tenant tabulkou, musí TÁŽ metoda
     * obsahovat `supplier_id` — ať už přímo v predikátu, nebo v poli podmínek.
     */
    public function testServiceAndActionTenantTableMethodsCarrySupplierPredicate(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $files = [];
        foreach (['/Service', '/Action'] as $sub) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . $sub));
            foreach ($iterator as $entry) {
                if ($entry->getExtension() === 'php') {
                    $files[] = $entry->getPathname();
                }
            }
        }
        self::assertNotEmpty($files, 'Service/Action adresáře nenalezeny.');
        sort($files);

        $patterns = [];
        foreach (self::TENANT_TABLES as $table) {
            $patterns[$table] = '/\b(?:FROM|JOIN|UPDATE)\s+`?' . preg_quote($table, '/') . '`?\b/i';
        }

        $violations = [];
        foreach ($files as $file) {
            $base = basename($file);
            foreach ($this->methodBodies((string) file_get_contents($file)) as $method) {
                $body = $method['body'];
                if (stripos($body, 'supplier_id') !== false) {
                    continue;
                }
                if (isset(self::CROSS_LAYER_WHITELIST[$base . '::' . $method['name']])) {
                    continue;
                }
                if (preg_match('/\b(?:WHERE|AND)\s+\(?\s*(?:`?\w+`?\.)?`?id`?\s*(?:=\s*(?:\?|:\w+)|IN\s*\()/i', $body) === 1) {
                    continue;
                }
                if (preg_match('/\b(?:WHERE|AND)\s+\(?\s*(?:`?\w+`?\.)?\w+_id\s*(?:=\s*(?:\?|:\w+)|IN\s*\()/i', $body) === 1) {
                    continue;
                }
                foreach ($patterns as $table => $pattern) {
                    if (preg_match($pattern, $body) !== 1) {
                        continue;
                    }
                    $violations[] = $base . '::' . $method['name'] . "() — SQL na tenant tabulce '" . $table . "' bez supplier_id";
                }
            }
        }

        self::assertSame([], $violations, "Cross-tenant riziko ve Service/Action vrstvě:\n  " . implode("\n  ", $violations));
    }

    /**
     * Rozseká zdroják na těla metod přes tokenizer — regex by na vnořených složených
     * závorkách a na složených závorkách uvnitř řetězců selhal.
     *
     * @return list<array{name:string, body:string}>
     */
    private function methodBodies(string $code): array
    {
        $tokens = token_get_all($code);
        $count = count($tokens);
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }
            $name = '?';
            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $name = $tokens[$j][1];
                    break;
                }
                if ($tokens[$j] === '(') {
                    break;
                }
            }
            $depth = 0;
            $started = false;
            $body = '';
            for ($j = $i + 1; $j < $count; $j++) {
                $text = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                if ($text === '{') {
                    $depth++;
                    $started = true;
                }
                if ($started) {
                    $body .= $text;
                }
                if ($text === '}') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
            }
            if ($started) {
                $out[] = ['name' => $name, 'body' => $body];
            }
        }
        return $out;
    }
}
