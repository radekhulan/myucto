<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Http\TenantReferenceGuard;
use PHPUnit\Framework\TestCase;

/**
 * Architektonická pojistka proti CWE-639 / BOLA na cizích klíčích z TĚLA requestu.
 *
 * ## Proč nestačilo, co tu už bylo
 *
 * `TenantPredicateTest` hlídá, že SQL nad tenant tabulkou nese `supplier_id`. Externí
 * security report 2026-08 (R2) přesto našel devět cross-tenant nálezů — a ani jeden
 * z nich by ten test nechytil, protože **žádná z devíti Action SQL nespouští**. Vzorec
 * byl jiný: Action ověří rodičovský objekt z URL přes `SupplierGuard::owns()`, ale
 * `*_id` z těla requestu pošle do repozitáře nevázaný a read-back JOIN pak cizí řádek
 * vrátí v odpovědi útočníka.
 *
 * ## Dvě části, dva různé úkoly
 *
 * **(1) Discovery** — každá Action, která čte tenant-scoped FK z těla requestu, musí
 * ten sloupec buď protáhnout `TenantReferenceGuard`em, nebo mít v `ALTERNATIVE_GUARDS`
 * citovanou jinou vazbu. Tohle drží NOVÝ kód v systému.
 *
 * **(2) Inventura** — devět opravených míst má tady zapsaný svůj FK povrch a test
 * ověřuje, že guard opravdu volají. Tahle část existuje proto, že diskovery sken
 * ze své podstaty nedosáhne na hlavní variantu chyby: většina z devíti nálezů ten
 * sloupec NIKDY nečte jménem — předává celé `$body` do repozitáře
 * (`$this->repo->createDraft($body, ...)`) a jméno sloupce se objeví až v INSERTu
 * o dvě vrstvy níž. Staticky to dohledat nejde, takže se povrch drží ručně; hodnota
 * je v tom, že smazání guardu z kterékoli z devíti Action rozsvítí test červeně.
 */
final class ActionTenantReferenceTest extends TestCase
{
    /**
     * Známý FK povrch opravených Action (security report 2026-08, R2 #1–#9).
     *
     * Klíč = jméno souboru v `src/Action`, hodnota = sloupce z těla requestu, které
     * ta Action zapisuje do DB a musí je proto předat guardu.
     *
     * Sloupce, které mají vlastní ekvivalentní vazbu, tu ZÁMĚRNĚ nejsou:
     *   - `vendor_id` u přijatých faktur (SupplierGuard::owns nad řádkem klienta —
     *     Action ten řádek stejně potřebuje kvůli markAsVendor/DIČ),
     *   - `car_id` v knize jízd a tankování (`cars->find($carId, $supplierId)`),
     *   - `branding_profile_id` (repozitáře ho pouštějí přes
     *     `resolveBrandingProfileId($value, $supplierId)`).
     *
     * @var array<string, list<string>>
     */
    private const REQUIRED_GUARDED_COLUMNS = [
        // R2 #1 — PUT /api/invoices/{id}
        'UpdateInvoiceAction.php' => ['client_id', 'project_id', 'currency_id', 'revenue_category_id'],
        // R2 #4 / sweep F4 — POST /api/invoices
        'CreateInvoiceAction.php' => ['client_id', 'project_id', 'currency_id', 'revenue_category_id'],
        // R2 #2 + #3 — POST /api/recurring, PUT /api/recurring/{id}
        'RecurringTemplateAction.php' => ['client_id', 'project_id', 'currency_id', 'revenue_category_id'],
        // R2 #5 / sweep F5 — POST + PUT /api/purchase-invoices
        'CreatePurchaseInvoiceAction.php' => ['expense_category_id', 'currency_id', 'payment_currency_id'],
        'UpdatePurchaseInvoiceAction.php' => ['expense_category_id', 'currency_id', 'payment_currency_id'],
        // R2 #6 / sweep F1 — POST + PUT /api/logbook/fuelings
        'FuelingsAction.php' => ['vendor_id', 'source_purchase_invoice_id', 'source_cash_document_id', 'source_journal_entry_id'],
        // Kniha jízd (migrace 1802) — řidič vozidla z těla requestu.
        'CarsAction.php' => ['driver_employee_id'],
        // R2 #7 / sweep F3 — POST + PUT /api/logbook/trips
        'TripsAction.php' => ['category_id'],
        // R2 #8 / sweep F2 — POST /api/reports/related-parties/adjustments
        'RelatedPartyAction.php' => ['client_id'],
        // R2 #9 / sweep F6 — PUT /api/invoices/{id}/work-report/materials
        'SaveWorkReportMaterialsAction.php' => ['project_id'],
        // Revize invoice_id/purchase_invoice_id (2026-08-04) — POST /api/reports/s79.
        // Jediný nález z pěti prověřených Action: obě vazby šly z těla rovnou do INSERTu
        // v Section79Service::register() a `vat_registration_corrections` na nich nemá FK,
        // takže neexistovala ani databázová záchranná síť.
        'Section79Action.php' => ['purchase_invoice_id', 'asset_id'],
    ];

    /**
     * Action, které tenant-scoped FK z těla čtou, ale váží ho jinak než guardem.
     * Klíč 'Soubor.php:sloupec', hodnota = citace té vazby. Ověřeno ručně proti
     * BOLA sweepu (`private/SecurityReport/supplementary/`).
     *
     * @var array<string, string>
     */
    private const ALTERNATIVE_GUARDS = [
        // Sweep S049/S050: `client_id` je tu OAuth client-id ŘETĚZEC iDokladu/Fakturoidu,
        // ne id řádku v `clients`. Navíc /api/admin/* = superadmin.
        'IdokladCredentialsAction.php:client_id'   => 'OAuth client id (string), není FK',
        'FakturoidCredentialsAction.php:client_id' => 'OAuth client id (string), není FK',

        // Sweep S099: FuelInvoicesAction::assign — cars->find($carId, $supplierId) na ř. ~94.
        'FuelInvoicesAction.php:car_id' => 'cars->find($carId, $supplierId)',
        // Tankování z pokladních dokladů — stejný idiom jako FuelInvoicesAction::assign
        // (FuelCashDocumentsAction::assign, cars->find($carId, $supplierId) → 404).
        'FuelCashDocumentsAction.php:car_id' => 'cars->find($carId, $supplierId) v assign()',

        // Kniha jízd — `car_id` má vlastní kontrolu odjakživa (a byl to jediný vázaný FK
        // v obou Action, viz sweep F1/F3): TripsAction::prepare() a FuelingsAction::validate()
        // volají cars->find($carId, $supplierId). Guardem duplicitně netaháme.
        'TripsAction.php:car_id'     => 'cars->find($carId, $supplierId) v prepare()',
        'FuelingsAction.php:car_id'  => 'cars->find($carId, $supplierId) ve validate()',

        // Přijaté faktury — `vendor_id` váže SupplierGuard::owns() nad řádkem klienta
        // (CreatePurchaseInvoiceAction ř. ~68, UpdatePurchaseInvoiceAction ř. ~156).
        // Action ten řádek stejně potřebuje (markAsVendor, DIČ pro CRPDPH), takže
        // duplikovat kontrolu guardem by znamenalo dotaz navíc bez užitku.
        'CreatePurchaseInvoiceAction.php:vendor_id' => 'SupplierGuard::owns($request, vendor)',
        'UpdatePurchaseInvoiceAction.php:vendor_id' => 'SupplierGuard::owns($request, vendor)',

        // Sweep S009/S010: obě projektové Action váží client_id přes SupplierGuard::owns()
        // (create ř. ~37); update navíc client_id z uloženého řádku přepíše.
        'CreateProjectAction.php:client_id' => 'SupplierGuard::owns($request, client)',
        'UpdateProjectAction.php:client_id' => 'SupplierGuard::owns + client_id z uloženého řádku',

        // Sweep S024: SaveWorkReportAction ř. ~88 — SupplierGuard::owns nad projektem
        // (varianta MS-P1-1), plus kontrola shody klienta na ř. ~93. Právě tuhle kontrolu
        // sesterský SaveWorkReportMaterialsAction neměl = nález F6.
        'SaveWorkReportAction.php:project_id' => 'SupplierGuard::owns($request, project) + client match',

        // ── revize invoice_id / purchase_invoice_id (2026-08-04) ─────────────────────
        //
        // Doplnění obou sloupců do SCOPES rozsvítilo pět Action. Revize je prošla celým
        // zápisovým povrchem VČETNĚ service vrstvy (poučení z minulého kola: reporterova
        // extrakce četla jen Action a kvůli tomu minula čtyři nálezy) — čtyři z pěti vazbu
        // mají, jen ji sken nevidí, protože je o vrstvu níž nebo v jiném idiomu.
        //
        // Každá položka níž má ŽIVÝ dvoutenantní protějšek v
        // tests/Integration/Security/ReportTenantReferenceIdorTest.php. Bez něj je zápis
        // do whitelistu jen tvrzení — a nekontrolované tvrzení je přesně to, co z mapy
        // dělá bezcennou dekoraci.

        // AssetService::addImprovement() ř. ~458 → assertPurchaseInvoiceOwned() ř. ~1361.
        // (`sale_invoice_id` v dispose() váže vlastní SELECT ř. ~539; v SCOPES není.)
        'AssetLifecycleAction.php:purchase_invoice_id' => 'AssetService::assertPurchaseInvoiceOwned() (AssetService:458)',

        // SmallAssetService::create() ř. ~41 → assertSourceBelongsToTenant() ř. ~466.
        // Sesterský `sale_invoice_id` v sell() má vlastní kontrolu na ř. ~103.
        'SmallAssetAction.php:purchase_invoice_id' => 'SmallAssetService::assertSourceBelongsToTenant() (SmallAssetService:466)',

        // manualMatch() ř. ~2464 — invoices->find() + SupplierGuard::owns($request, $invoice);
        // manualMatchPurchase() ř. ~2978 — shoda `pi.supplier_id` s SupplierGuard::currentId().
        // Obě Action ten řádek stejně potřebuje (stav dokladu, částka k úhradě), takže
        // duplikovat kontrolu guardem by byl dotaz navíc bez užitku.
        'BankStatementAction.php:invoice_id'          => 'SupplierGuard::owns($request, $invoice) (BankStatementAction:2465)',
        'BankStatementAction.php:purchase_invoice_id' => 'shoda pi.supplier_id v manualMatchPurchase() (BankStatementAction:2978)',

        // Section46Service::registerCorrection() ř. ~145 → fetchInvoice($supplierId, $invoiceId),
        // jehož SELECT má `supplier_id = ?` v predikátu (Section46Service:477–480) — cizí
        // doklad se ani nenačte a skončí na 'Doklad #N nenalezen.'.
        'Section46Action.php:invoice_id' => 'Section46Service::fetchInvoice($supplierId, …) (Section46Service:477)',

        // Nabídky dodavatelů („u dodavatele") — VendorOfferAction::create() ř. ~123 pouští
        // `client_id` přes StockItemVendorRepository::filterOwnedVendors($supplierId, […]),
        // jehož SELECT má v predikátu `supplier_id = ?` A NAVÍC `is_vendor = 1`
        // (StockItemVendorRepository:313–323). Je to tedy PŘÍSNĚJŠÍ vazba než guard:
        // cizí klient neprojde a vlastní klient bez příznaku dodavatele taky ne,
        // obojí jako 422 `vendor_invalid`. Guardem duplicitně netaháme — byl by to
        // dotaz navíc se slabší podmínkou.
        // Dvoutenantní protějšek: VendorOfferTest::testVendorMustBeMarkedAsVendorAndOwned()
        // a ::testForeignTenantSeesNothing().
        'VendorOfferAction.php:client_id' => 'StockItemVendorRepository::filterOwnedVendors($supplierId, …) (StockItemVendorRepository:313)',
    ];

    /**
     * (1) Discovery — Action nesmí číst tenant-scoped FK z těla requestu bez vazby.
     */
    public function testActionsReadingBodyForeignKeysBindThemToTenant(): void
    {
        $violations = [];

        foreach ($this->actionFiles() as $file) {
            $base = basename($file);
            $code = self::stripComments((string) file_get_contents($file));

            $bodyVars = self::parsedBodyVariables($code);
            if ($bodyVars === []) {
                continue;
            }

            $guarded = self::guardedColumns($code);

            foreach (array_keys(TenantReferenceGuard::SCOPES) as $column) {
                if (!self::readsColumnFromBody($code, $bodyVars, $column)) {
                    continue;
                }
                if (in_array($column, $guarded, true)) {
                    continue;
                }
                if (isset(self::ALTERNATIVE_GUARDS[$base . ':' . $column])) {
                    continue;
                }
                $violations[] = "{$base} čte '{$column}' z těla requestu bez vazby na tenanta";
            }
        }

        sort($violations);
        self::assertSame(
            [],
            $violations,
            "Cizí klíč z těla requestu musí být vázaný na volajícího (CWE-639 / BOLA).\n"
            . "Buď ho protáhni TenantReferenceGuard::violations(), nebo — máš-li ekvivalentní\n"
            . "kontrolu — doplň ZDŮVODNĚNOU položku do ALTERNATIVE_GUARDS s citací file:line.\n",
        );
    }

    /**
     * (2) Inventura — devět opravených míst musí guard dál volat.
     */
    public function testKnownBodyForeignKeySurfaceStaysGuarded(): void
    {
        $byBasename = [];
        foreach ($this->actionFiles() as $file) {
            $byBasename[basename($file)] = $file;
        }

        $violations = [];
        foreach (self::REQUIRED_GUARDED_COLUMNS as $base => $required) {
            if (!isset($byBasename[$base])) {
                $violations[] = "{$base} v src/Action neexistuje — přejmenování? Aktualizuj inventuru.";
                continue;
            }
            $guarded = self::guardedColumns(self::stripComments((string) file_get_contents($byBasename[$base])));
            foreach ($required as $column) {
                if (!in_array($column, $guarded, true)) {
                    $violations[] = "{$base} už nepředává '{$column}' do TenantReferenceGuard::violations()";
                }
            }
        }

        sort($violations);
        self::assertSame(
            [],
            $violations,
            "Regrese opravy z externího security reportu 2026-08 (R2).\n"
            . "Tyhle Action zapisují uvedené FK z těla requestu do DB a guard je povinný.\n",
        );
    }

    /**
     * Pojistka proti překlepu v názvu sloupce: guard by na neznámý sloupec sice
     * spadl výjimkou, ale až za běhu na produkční cestě.
     */
    public function testGuardCallSitesUseKnownColumns(): void
    {
        $violations = [];
        foreach ($this->actionFiles() as $file) {
            $code = self::stripComments((string) file_get_contents($file));
            foreach (self::guardCallArguments($code) as $argument) {
                preg_match_all('/[\'"](\w+_id)[\'"]/', $argument, $m);
                foreach ($m[1] as $column) {
                    if (!isset(TenantReferenceGuard::SCOPES[$column])) {
                        $violations[] = basename($file) . ": neznámý sloupec '{$column}'";
                    }
                }
            }
        }

        sort($violations);
        self::assertSame(
            [],
            array_values(array_unique($violations)),
            'Sloupce předávané guardu musí být v TenantReferenceGuard::SCOPES.',
        );
    }

    /** @return list<string> */
    private function actionFiles(): array
    {
        $dir = dirname(__DIR__, 2) . '/src/Action';
        self::assertDirectoryExists($dir);

        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $out[] = $entry->getPathname();
            }
        }
        sort($out);
        self::assertNotEmpty($out);

        return $out;
    }

    public function testGuardScannerExcludesRemappedBodyAliasesAndStockReferences(): void
    {
        $code = <<<'PHP'
<?php
$tenant->violations(
    SupplierGuard::currentId($request),
    ['currency_id' => $body['default_currency_id'], 'note' => '), [ignored_id]'],
    ['currency_id'],
);
$stock->violations($supplierId, ['purchase_invoice_item_id' => [$body['source_item_id']]]);
PHP;
        self::assertSame(["['currency_id']"], self::guardCallArguments($code));
        self::assertSame(['currency_id'], self::guardedColumns($code));
    }

    public function testGuardScannerKeepsUnknownColumnsInThirdArgument(): void
    {
        $code = <<<'PHP'
<?php
$guard -> violations (
    currentSupplier($request, [1, 2]),
    (static function () use ($body) { return array_merge($body, ['ignored_id' => '),{}']); })(),
    array_merge(['client_id'], ['clinet_id']),
);
PHP;
        $columns = self::guardedColumns($code);
        self::assertSame(['client_id', 'clinet_id'], $columns);
        self::assertSame(['clinet_id'], array_values(array_diff($columns, array_keys(TenantReferenceGuard::SCOPES))));
    }

    public function testGuardScannerIgnoresCallsInsideStringsAndComments(): void
    {
        $code = <<<'PHP'
<?php
$text = '$guard->violations(1, [], ["ignored_id"])';
// $guard->violations(1, [], ['comment_id']);
$guard->violations($id, ["note" => "literal ), [ ] { }, {$body['note']}"], ['vendor_id']);
PHP;
        self::assertSame(["['vendor_id']"], self::guardCallArguments($code));
    }

    /**
     * Jména proměnných, do kterých se v souboru přiřazuje tělo requestu.
     *
     * @return list<string>
     */
    private static function parsedBodyVariables(string $code): array
    {
        preg_match_all('/\$(\w+)\s*=\s*[^;]*getParsedBody\s*\(/', $code, $m);

        return array_values(array_unique($m[1]));
    }

    /** @param list<string> $bodyVars */
    private static function readsColumnFromBody(string $code, array $bodyVars, string $column): bool
    {
        foreach ($bodyVars as $var) {
            $pattern = '/\$' . preg_quote($var, '/') . '\s*\[\s*[\'"]' . preg_quote($column, '/') . '[\'"]\s*\]/';
            if (preg_match($pattern, $code) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sloupce, které soubor předává TenantReferenceGuard::violations().
     *
     * @return list<string>
     */
    private static function guardedColumns(string $code): array
    {
        $out = [];
        foreach (self::guardCallArguments($code) as $argument) {
            preg_match_all('/[\'"](\w+_id)[\'"]/', $argument, $m);
            foreach ($m[1] as $column) {
                $out[] = $column;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Třetí argument volání `->violations(...)`; dvouargumentový StockReferenceGuard
     * má jiný kontrakt. Tokeny oddělují syntaxi od závorek uvnitř řetězců.
     *
     * @return list<string>
     */
    private static function guardCallArguments(string $code): array
    {
        $out = [];
        $tokens = array_values(array_filter(
            token_get_all($code),
            static fn (array|string $token): bool => !is_array($token)
                || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $count = count($tokens);
        for ($i = 0; $i < $count - 2; $i++) {
            if (!is_array($tokens[$i])
                || !in_array($tokens[$i][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                || !is_array($tokens[$i + 1])
                || $tokens[$i + 1][0] !== T_STRING
                || strtolower($tokens[$i + 1][1]) !== 'violations'
                || $tokens[$i + 2] !== '('
            ) {
                continue;
            }
            $stack = ['('];
            $arguments = [];
            $argument = '';
            $quote = null;
            $heredoc = false;
            for ($j = $i + 3; $j < $count; $j++) {
                $token = $tokens[$j];
                $text = is_array($token) ? $token[1] : $token;
                if ($heredoc) {
                    $argument .= $text;
                    if (is_array($token) && $token[0] === T_END_HEREDOC) $heredoc = false;
                    continue;
                }
                if ($quote !== null) {
                    $argument .= $text;
                    if ($token === $quote) $quote = null;
                    continue;
                }
                if (is_array($token)) {
                    if ($token[0] === T_START_HEREDOC) $heredoc = true;
                    $argument .= $text;
                    continue;
                }
                if ($token === '"' || $token === '`') {
                    $quote = $token;
                } elseif (in_array($token, ['(', '[', '{'], true)) {
                    $stack[] = $token;
                } elseif (in_array($token, [')', ']', '}'], true)) {
                    $opening = array_pop($stack);
                    if ($opening !== [')' => '(', ']' => '[', '}' => '{'][$token]) break;
                    if ($stack === []) {
                        if ($argument !== '') $arguments[] = $argument;
                        if (count($arguments) === 3) $out[] = $arguments[2];
                        break;
                    }
                } elseif ($token === ',' && count($stack) === 1) {
                    $arguments[] = $argument;
                    $argument = '';
                    continue;
                }
                $argument .= $text;
            }
        }
        return $out;
    }

    /** Komentáře pryč — jinak by je discovery sken četl jako kód. */
    private static function stripComments(string $code): string
    {
        $out = '';
        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    $out .= str_repeat("\n", substr_count($token[1], "\n"));
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }

        return $out;
    }
}
