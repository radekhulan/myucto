<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\ExpenseClassificationRuleAction;
use MyInvoice\Repository\ExpenseClassificationRuleRepository;
use MyInvoice\Repository\ExpenseKeywordCatalogRepository;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Accounting\Expense\ExpenseClassificationService;
use MyInvoice\Service\Accounting\Expense\ExpenseKind;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * §DM — perzistence a API pravidel klasifikace druhu výdaje (migrace 1093).
 *
 * Co je tu vlastně v sázce: pravidlo přepisuje účet nákladu (501 × 518 = různé řádky VZZ),
 * takže cizí pravidlo aplikované na naše doklady = špatný výkaz odeslaný do sbírky listin.
 * Tenant izolace proto není hygiena, ale funkční požadavek — testuje se na repozitáři,
 * ve službě i přes API.
 */
#[Group('integration')]
final class ExpenseClassificationRuleTest extends BankPostingTestCase
{
    private ExpenseClassificationRuleRepository $repo;
    private ExpenseClassificationService $classification;
    private ExpenseClassificationRuleAction $action;
    private int $vatRateId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = $this->container->get(ExpenseClassificationRuleRepository::class);
        $this->classification = $this->container->get(ExpenseClassificationService::class);
        $this->action = $this->container->get(ExpenseClassificationRuleAction::class);
        $this->vatRateId = (int) ($this->db->pdo()->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->vatRateId === 0) {
            self::markTestSkipped('Chybí vat_rates v DB.');
        }
    }

    // ── repozitář ────────────────────────────────────────────────────────────

    public function testCrudRoundTrip(): void
    {
        $id = $this->expenseRule(['name' => 'Alza — notebooky', 'vendor_name_contains' => 'Alza',
            'description_contains' => 'notebook', 'expense_kind' => 'small_asset', 'priority' => 10]);

        $row = $this->repo->find($this->supplierId, $id);
        self::assertNotNull($row);
        self::assertSame('small_asset', $row['expense_kind']);
        self::assertSame(10, $row['priority']);
        self::assertTrue($row['is_active'], 'cast() vrací is_active jako bool.');
        self::assertSame(0, $row['hit_count']);

        self::assertTrue($this->repo->update($this->supplierId, $id, ['expense_kind' => 'material', 'is_active' => false]));
        $row = $this->repo->find($this->supplierId, $id);
        self::assertSame('material', $row['expense_kind']);
        self::assertFalse($row['is_active']);

        self::assertTrue($this->repo->delete($this->supplierId, $id));
        self::assertNull($this->repo->find($this->supplierId, $id));
    }

    /** activeFor() je jediné, co určuje pořadí first-match-wins — musí sedět na priority ASC. */
    public function testActiveForOrdersByPriorityAndSkipsInactive(): void
    {
        $general = $this->expenseRule(['name' => 'Obecné', 'vendor_name_contains' => 'Alza', 'priority' => 100]);
        $specific = $this->expenseRule(['name' => 'Konkrétní', 'vendor_name_contains' => 'Alza',
            'description_contains' => 'kabel', 'expense_kind' => 'material', 'priority' => 10]);
        $this->expenseRule(['name' => 'Vypnuté', 'vendor_name_contains' => 'Alza', 'priority' => 1, 'is_active' => 0]);

        // Filtrace na VLASTNÍ pravidla. Tenant v dev DB nese i reálná pravidla firmy
        // (např. „Pojistné → 548"), takže tvrzení „activeFor vrátí přesně tyhle dvě" testuje
        // prázdnou databázi, ne řazení. Pořadí i vynechání vypnutého platí i mezi cizími.
        $mine = [$specific, $general];
        $ids = array_values(array_filter(
            array_map(static fn (array $r): int => $r['id'], $this->repo->activeFor($this->supplierId)),
            static fn (int $id): bool => in_array($id, $mine, true),
        ));

        self::assertSame([$specific, $general], $ids, 'Nižší priorita první, deaktivované vůbec.');
    }

    public function testRecordHitIsTenantScoped(): void
    {
        $id = $this->expenseRule(['name' => 'Alza', 'vendor_name_contains' => 'Alza']);

        $this->repo->recordHit($this->supplierId, $id);
        self::assertSame(1, $this->repo->find($this->supplierId, $id)['hit_count']);

        // Cizí tenant se stejným id nesmí počítadlo hnout.
        $this->repo->recordHit($this->otherSupplierId(), $id);
        self::assertSame(1, $this->repo->find($this->supplierId, $id)['hit_count']);
    }

    /** DB pojistka: pravidlo bez matchovacího kritéria by chytalo všechno (chk_ecr_criteria). */
    public function testDatabaseRejectsRuleWithoutMatchCriteria(): void
    {
        $this->expectException(\PDOException::class);
        $this->db->pdo()->prepare(
            'INSERT INTO expense_classification_rules (supplier_id, name, amount_min, expense_kind)
             VALUES (?, ?, ?, ?)'
        )->execute([$this->supplierId, 'Všechno nad tisícovku', 1000.00, 'small_asset']);
    }

    // ── tenant izolace ───────────────────────────────────────────────────────

    public function testRuleOfOtherSupplierIsNeitherVisibleNorApplied(): void
    {
        $otherId = $this->otherSupplierId();
        $foreign = $this->expenseRule(['name' => 'Cizí — Ukázka = majetek', 'vendor_name_contains' => 'Ukázka',
            'expense_kind' => 'small_asset'], $otherId);

        self::assertNull($this->repo->find($this->supplierId, $foreign), 'find() nesmí vidět přes hranici firmy.');
        self::assertSame([], array_filter(
            $this->repo->activeFor($this->supplierId),
            static fn (array $r): bool => $r['id'] === $foreign,
        ), 'activeFor() nesmí vrátit cizí pravidlo.');
        self::assertFalse($this->repo->update($this->supplierId, $foreign, ['name' => 'Přepis']));
        self::assertFalse($this->repo->delete($this->supplierId, $foreign));
        self::assertNotNull($this->repo->find($otherId, $foreign), 'Cizí pravidlo zůstalo nedotčené.');

        // A hlavně: nesmí ovlivnit naši klasifikaci. „Ukázka" je bez pravidla neznámý text —
        // klasifikaci ověřujeme na ČISTÉ firmě (klon bez reálných pravidel), aby výsledek
        // netestoval reálné pravidlo firmy (dev DB nese vlastní pravidla) místo cross-tenant izolace.
        $clean = $this->cloneSupplier('double_entry');
        $suggestion = $this->classification->suggestForItem(
            $clean, 'Ukázka Photo Studio', 'Ukázka software a.s.', null, 1200.0, self::YEAR,
        );
        self::assertNull($suggestion, 'Cizí pravidlo se nesmí uplatnit na naše doklady.');
    }

    public function testApiListDoesNotLeakOtherSupplierRules(): void
    {
        $otherId = $this->otherSupplierId();
        $foreign = $this->expenseRule(['name' => 'Cizí', 'vendor_name_contains' => 'Cizí dodavatel'], $otherId);
        $own = $this->expenseRule(['name' => 'Naše', 'vendor_name_contains' => 'Alza']);

        $res = $this->callAction($this->action, 'list', 'GET', 'accountant');

        self::assertSame(200, $res['status']);
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $res['body']['items']);
        self::assertContains($own, $ids);
        self::assertNotContains($foreign, $ids);
    }

    public function testApiUpdateAndDeleteOfForeignRuleReturn404(): void
    {
        $foreign = $this->expenseRule(['name' => 'Cizí', 'vendor_name_contains' => 'Cizí'], $this->otherSupplierId());

        $upd = $this->callAction($this->action, 'update', 'PUT', 'accountant', ['name' => 'Přepis'], ['id' => (string) $foreign]);
        self::assertSame(404, $upd['status']);

        $del = $this->callAction($this->action, 'delete', 'DELETE', 'accountant', [], ['id' => (string) $foreign]);
        self::assertSame(404, $del['status']);
        self::assertNotNull($this->repo->find($this->otherSupplierId(), $foreign), 'Cizí pravidlo přežilo.');
    }

    public function testApiRejectsVendorClientOfOtherSupplier(): void
    {
        $foreignClientId = $this->foreignClient();

        $res = $this->callAction($this->action, 'create', 'POST', 'accountant', [
            'name' => 'Přes hranici', 'vendor_client_id' => $foreignClientId, 'expense_kind' => 'small_asset',
        ]);

        self::assertSame(422, $res['status']);
        self::assertSame('vendor_not_found', $res['body']['error']['code']);
    }

    // ── RBAC ─────────────────────────────────────────────────────────────────

    public function testClientRoleCannotWriteRules(): void
    {
        // Kolik pravidel tenant má PŘED pokusem — dev DB nese i reálná pravidla firmy,
        // takže „na konci je prázdno" by netestovalo RBAC, ale prázdnou databázi.
        $before = count($this->repo->activeFor($this->supplierId));

        foreach ([['create', 'POST', []], ['update', 'PUT', ['id' => '1']], ['delete', 'DELETE', ['id' => '1']]] as [$method, $http, $args]) {
            $res = $this->callAction($this->action, $method, $http, 'client', [
                'name' => 'Pokus', 'vendor_name_contains' => 'Alza', 'expense_kind' => 'small_asset',
            ], $args);
            self::assertSame(403, $res['status'], "Role client nesmí projít na {$method}.");
            self::assertSame('forbidden', $res['body']['error']['code']);
        }
        self::assertCount($before, $this->repo->activeFor($this->supplierId), 'Žádné pravidlo nevzniklo.');
    }

    // ── API validace ─────────────────────────────────────────────────────────

    public function testApiCreateRequiresMatchCriterion(): void
    {
        $res = $this->callAction($this->action, 'create', 'POST', 'accountant', [
            'name' => 'Všechno nad tisícovku', 'amount_min' => 1000, 'expense_kind' => 'small_asset',
        ]);

        self::assertSame(422, $res['status']);
        self::assertSame('rule_criteria_missing', $res['body']['error']['code'], 'Cenové rozpětí samo kritérium není.');
    }

    /** Patch nesmí obejít kritéria oklikou — vynulování posledního kritéria = 422, ne 500 z DB. */
    public function testApiUpdateCannotClearLastCriterion(): void
    {
        $id = $this->expenseRule(['name' => 'Jen text', 'description_contains' => 'notebook']);

        $res = $this->callAction($this->action, 'update', 'PUT', 'accountant',
            ['description_contains' => ''], ['id' => (string) $id]);

        self::assertSame(422, $res['status']);
        self::assertSame('rule_criteria_missing', $res['body']['error']['code']);
        self::assertSame('notebook', $this->repo->find($this->supplierId, $id)['description_contains']);
    }

    public function testApiRejectsInvalidKindPriorityAndBand(): void
    {
        $base = ['name' => 'X', 'vendor_name_contains' => 'Alza', 'expense_kind' => 'small_asset'];

        $kind = $this->callAction($this->action, 'create', 'POST', 'accountant', ['expense_kind' => 'nesmysl'] + $base);
        self::assertSame('invalid_expense_kind', $kind['body']['error']['code']);

        $priority = $this->callAction($this->action, 'create', 'POST', 'accountant', ['priority' => 1000] + $base);
        self::assertSame('invalid_priority', $priority['body']['error']['code']);

        $band = $this->callAction($this->action, 'create', 'POST', 'accountant', ['amount_min' => 5000, 'amount_max' => 100] + $base);
        self::assertSame('invalid_amount_band', $band['body']['error']['code']);
    }

    public function testApiCreateRoundTrip(): void
    {
        $res = $this->callAction($this->action, 'create', 'POST', 'accountant', [
            'name' => 'Alza — notebooky',
            'vendor_name_contains' => 'Alza',
            'description_contains' => 'notebook',
            'expense_kind' => 'small_asset',
            'priority' => 10,
        ]);

        self::assertSame(201, $res['status']);
        $rule = $res['body']['rule'];
        self::assertSame($this->supplierId, $rule['supplier_id'], 'Tenant se bere z requestu, ne z těla.');
        self::assertSame('small_asset', $rule['expense_kind']);
        self::assertSame($this->userId, $rule['created_by']);
    }

    // ── služba ───────────────────────────────────────────────────────────────

    /** Pravidlo tenanta musí přebít vestavěná klíčová slova — jinak by nemělo smysl. */
    public function testTenantRuleBeatsBuiltInKeywords(): void
    {
        $this->expenseRule(['name' => 'AXIGON = materiál', 'vendor_name_contains' => 'AXIGON',
            'description_contains' => 'monitor', 'expense_kind' => 'material', 'priority' => 10]);

        $s = $this->classification->suggestForItem(
            $this->supplierId, 'monitor 24"', 'AXIGON s.r.o.', null, 4000.0, self::YEAR,
        );

        self::assertNotNull($s);
        self::assertSame(ExpenseKind::Material, $s->kind, 'Vestavěné „monitor" = small_asset, pravidlo ho přebíjí.');
        self::assertSame('rule', $s->source);
    }

    /**
     * Cenové rozpětí vyhodnocuje SLUŽBA (klasifikátor ho nezná) — kdyby se neaplikovalo,
     * uživatel by ho v UI vyplňoval bez účinku.
     */
    public function testAmountBandNarrowsRuleInService(): void
    {
        // Čistá firma bez reálných pravidel — dev DB nese vlastní pravidla (service, priority 100),
        // které by při shodné prioritě přebilo testovací pravidlo a rozbilo test cenového pásu.
        $sid = $this->cloneSupplier('double_entry');
        $this->expenseRule(['name' => 'Levné kusy Ukázka', 'vendor_name_contains' => 'Ukázka',
            'expense_kind' => 'material', 'amount_max' => 1000.0], $sid);

        $inBand = $this->classification->suggestForItem($sid, 'blíže neurčeno', 'Ukázka software a.s.', null, 500.0, self::YEAR);
        self::assertNotNull($inBand);
        self::assertSame(ExpenseKind::Material, $inBand->kind);

        $outOfBand = $this->classification->suggestForItem($sid, 'blíže neurčeno', 'Ukázka software a.s.', null, 5000.0, self::YEAR);
        self::assertNull($outOfBand, 'Nad amount_max pravidlo neplatí a jiný důkaz není.');
    }

    /** Rozhoduje cena ZA KUS (§26/2 ZDP mluví o vstupní ceně jedné věci), ne za řádek. */
    public function testBandComparesUnitPriceNotLineTotal(): void
    {
        // Čistá firma bez reálných pravidel (viz testAmountBandNarrowsRuleInService).
        $sid = $this->cloneSupplier('double_entry');
        $this->expenseRule(['name' => 'Kusovka do 1 000', 'vendor_name_contains' => 'Ukázka',
            'expense_kind' => 'material', 'amount_max' => 1000.0], $sid);

        // 10 ks po 500 = řádek 5 000, ale cena za kus je pořád 500.
        $s = $this->classification->suggestForItem($sid, 'blíže neurčeno', 'Ukázka software a.s.', null, 500.0, self::YEAR);

        self::assertNotNull($s);
        self::assertSame(ExpenseKind::Material, $s->kind);
    }

    public function testSuggestForInvoiceReturnsPerItemSuggestions(): void
    {
        $vendorId = $this->client('Alza.cz a.s.');
        $pf = $this->purchaseWithItems('PF-SUG', $vendorId, [
            ['Notebook Dell', 45000.00],
            ['Doprava', 150.00],
            ['Blíže neurčená položka', 100.00],
        ]);

        $out = $this->classification->suggestForInvoice($this->supplierId, $pf);
        $byDesc = $this->indexByDescription($pf, $out);

        self::assertSame('small_asset', $byDesc['Notebook Dell']['expense_kind']);
        self::assertTrue($byDesc['Notebook Dell']['auto'], 'Silné klíčové slovo se smí použít bez potvrzení.');
        self::assertSame('service', $byDesc['Doprava']['expense_kind']);
        self::assertArrayNotHasKey('Blíže neurčená položka', $byDesc, 'Co nevíme, nehádáme — řádek zůstane bez návrhu.');
    }

    public function testKeywordCatalogCoversAllSupportedLanguagesAndVetoes(): void
    {
        $catalog = $this->container->get(ExpenseKeywordCatalogRepository::class)->active();

        self::assertGreaterThanOrEqual(100, count($catalog));
        $locales = array_values(array_unique(array_column($catalog, 'locale')));
        sort($locales);
        self::assertSame(['cs', 'de', 'en', 'sk'], $locales);

        foreach ($locales as $locale) {
            foreach (['asset_veto', 'fuel_veto'] as $concept) {
                self::assertNotEmpty(array_filter(
                    $catalog,
                    static fn (array $row): bool => $row['locale'] === $locale
                        && $row['concept_key'] === $concept
                        && $row['polarity'] === 'veto',
                ), "Katalog {$locale} musí obsahovat {$concept}.");
            }
        }

        $sid = $this->cloneSupplier('double_entry');
        foreach ([
            ['poistenie vozidla', ExpenseKind::Service, '548'],
            ['versicherung fahrzeug', ExpenseKind::Service, '548'],
            ['insurance premium', ExpenseKind::Service, '548'],
        ] as [$description, $kind, $account]) {
            $suggestion = $this->classification->suggestForItem($sid, $description, null, null, 1000.0, self::YEAR);
            self::assertNotNull($suggestion);
            self::assertSame($kind, $suggestion->kind);
            self::assertSame($account, $suggestion->accountCode);
            self::assertSame('catalog', $suggestion->source);
        }

        foreach (['drobny majetok prenajom', 'geringwertiges wirtschaftsgut miete', 'small asset rent'] as $description) {
            $suggestion = $this->classification->suggestForItem($sid, $description, null, null, 1000.0, self::YEAR);
            self::assertTrue($suggestion === null || $suggestion->kind !== ExpenseKind::SmallAsset);
        }
    }

    public function testAssetLimitComesFromYearSpecificTaxConstants(): void
    {
        $year = 2099;
        $tax = $this->container->get(TaxConstantsRepository::class);
        $sid = $this->cloneSupplier('double_entry');

        $tax->upsert($year, ['fixed_asset_limit' => 50_000]);
        self::assertSame(50_000.0, $this->classification->assetLimitForYear($year));
        $above = $this->classification->suggestForItem($sid, 'small asset', null, null, 75_000.0, $year);
        self::assertNotNull($above);
        self::assertSame(ExpenseKind::FixedAsset, $above->kind);
        self::assertSame('threshold', $above->source);

        $tax->upsert($year, ['fixed_asset_limit' => 100_000]);
        self::assertSame(100_000.0, $this->classification->assetLimitForYear($year));
        $below = $this->classification->suggestForItem($sid, 'small asset', null, null, 75_000.0, $year);
        self::assertNotNull($below);
        self::assertSame(ExpenseKind::SmallAsset, $below->kind);
    }

    /** Rok bere z data plnění dokladu, ne z dneška — limit DHM se mění. */
    public function testSuggestForInvoiceAppliesAssetLimitFromDocumentYear(): void
    {
        $vendorId = $this->client('Mironet.cz a.s.');
        $pf = $this->purchaseWithItems('PF-LIMIT', $vendorId, [['Notebook výkonný', 120000.00]]);

        $byDesc = $this->indexByDescription($pf, $this->classification->suggestForInvoice($this->supplierId, $pf));

        self::assertSame('fixed_asset', $byDesc['Notebook výkonný']['expense_kind'], 'Nad limit = DHM.');
        self::assertSame('threshold', $byDesc['Notebook výkonný']['source']);
        self::assertStringContainsString('§26/2 ZDP', $byDesc['Notebook výkonný']['reason']);
    }

    /**
     * Task A — cena za kus u cizoměnového dokladu se pro práh §26/2 ZDP přepočte na CZK.
     * Notebook 3 500 EUR × kurz 25 = 87 500 Kč je NAD limitem 80 000 Kč ⇒ dlouhodobý majetek,
     * přestože EUR nominál (3 500) je pod limitem. Bez přepočtu by propadl na drobný majetek.
     */
    public function testSuggestForInvoiceConvertsForeignUnitPriceToCzkForAssetLimit(): void
    {
        $pdo = $this->db->pdo();
        $eurId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'EUR' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($eurId === 0) {
            // currencies jsou per-supplier a nemají sloupec `name` (label/name_cs/name_en).
            $pdo->prepare(
                'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active)
                 VALUES (?, "EUR", "EUR", "€", "euro", "euro", 2, 1)'
            )->execute([$this->supplierId]);
            $eurId = (int) $pdo->lastInsertId();
        }
        $vendorId = $this->client('Mironet.cz a.s.');
        $pf = $this->purchaseWithItems('PF-EUR-DHM', $vendorId, [['Notebook Dell', 3500.00]],
            currencyId: $eurId, exchangeRate: 25.00);

        $byDesc = $this->indexByDescription($pf, $this->classification->suggestForInvoice($this->supplierId, $pf));

        self::assertSame('fixed_asset', $byDesc['Notebook Dell']['expense_kind'], 'EUR nad limit v CZK = DHM.');
        self::assertSame('threshold', $byDesc['Notebook Dell']['source']);
        self::assertStringContainsString('§26/2 ZDP', $byDesc['Notebook Dell']['reason']);
    }

    public function testSuggestForInvoiceIgnoresOtherSuppliersDocument(): void
    {
        $vendorId = $this->client('Alza.cz a.s.');
        $pf = $this->purchaseWithItems('PF-FOREIGN', $vendorId, [['Notebook Dell', 45000.00]]);

        self::assertSame([], $this->classification->suggestForInvoice($this->otherSupplierId(), $pf));
    }

    public function testApiSuggestionsEndpointReturnsReasonAndConfidence(): void
    {
        $vendorId = $this->client('Alza.cz a.s.');
        $pf = $this->purchaseWithItems('PF-API', $vendorId, [['Kávovar do kuchyňky', 12000.00]]);

        $res = $this->callAction($this->action, 'suggestions', 'GET', 'accountant', [], ['id' => (string) $pf]);

        self::assertSame(200, $res['status']);
        self::assertSame($pf, $res['body']['purchase_invoice_id']);
        $item = reset($res['body']['items']);
        self::assertSame('small_asset', $item['expense_kind']);
        self::assertSame('catalog', $item['source']);
        self::assertNotSame('', $item['reason'], 'Bez důvodu se návrhu nedá věřit (§DM/UX).');
        self::assertArrayHasKey('confidence', $item);
        self::assertArrayHasKey('auto', $item);
        self::assertNull($item['current_expense_kind'], 'Řádek zatím klasifikovaný není.');
    }

    public function testApiSuggestionsForForeignInvoiceReturn404(): void
    {
        $res = $this->callAction($this->action, 'suggestions', 'GET', 'accountant', [], ['id' => '999999999']);
        self::assertSame(404, $res['status']);
    }

    // ── recurring_prepaid (Automatizace 2026) ──────────────────────────────────

    /** Přes API jde založit pravidlo s příznakem ročního předplatného; bez příznaku default false. */
    public function testApiCreateAcceptsRecurringPrepaidFlag(): void
    {
        $res = $this->callAction($this->action, 'create', 'POST', 'accountant', [
            'name' => 'Ukázka — roční (381)', 'vendor_name_contains' => 'Ukázka',
            'expense_kind' => 'service', 'recurring_prepaid' => true,
        ]);
        self::assertSame(201, $res['status']);
        self::assertTrue($res['body']['rule']['recurring_prepaid'], 'Příznak recurring_prepaid se má uložit.');

        $plain = $this->callAction($this->action, 'create', 'POST', 'accountant', [
            'name' => 'Ukázka — bez rozlišení', 'vendor_name_contains' => 'Ukázka-měsíční', 'expense_kind' => 'service',
        ]);
        self::assertSame(201, $plain['status']);
        self::assertFalse($plain['body']['rule']['recurring_prepaid'], 'Bez příznaku default false.');
    }

    public function testApiUpdateTogglesRecurringPrepaid(): void
    {
        $id = $this->expenseRule(['name' => 'Parkovné', 'vendor_name_contains' => 'Parkov', 'expense_kind' => 'service']);
        self::assertFalse($this->repo->find($this->supplierId, $id)['recurring_prepaid']);

        $res = $this->callAction($this->action, 'update', 'PUT', 'accountant',
            ['recurring_prepaid' => true], ['id' => (string) $id]);
        self::assertSame(200, $res['status']);
        self::assertTrue($this->repo->find($this->supplierId, $id)['recurring_prepaid'], 'Patch zapne příznak.');
        self::assertTrue($res['body']['rule']['recurring_prepaid']);
    }

    /** Endpoint suggestions nabízí vedle druhu výdaje i návrh časového rozlišení 381 z recurring_prepaid pravidla. */
    public function testApiSuggestionsIncludesRecurringPrepaidAccrual(): void
    {
        $this->repo->insert($this->supplierId, [
            'name' => 'Ukázka — roční (381)', 'vendor_name_contains' => 'Ukázka',
            'expense_kind' => 'service', 'recurring_prepaid' => 1,
        ], $this->userId);
        $vendorId = $this->client('Ukázka a.s.');
        // 2. pololetí → roční krytí přesahuje do N+1, návrh 381 se má vygenerovat.
        $pf = $this->purchaseWithItems('PF-Ukázka-381', $vendorId, [['Cloud předplatné na rok', 12000.00]],
            issueDate: self::YEAR . '-09-01');

        $res = $this->callAction($this->action, 'suggestions', 'GET', 'accountant', [], ['id' => (string) $pf]);

        self::assertSame(200, $res['status']);
        self::assertArrayHasKey('recurring_prepaid', $res['body'], 'Odpověď musí nést samostatnou mapu 381 návrhů.');
        $accrual = reset($res['body']['recurring_prepaid']);
        self::assertNotFalse($accrual, 'Dodavatel s recurring_prepaid pravidlem má dostat návrh 381.');
        self::assertSame(self::YEAR . '-09-01', $accrual['accrual_from']);
        self::assertSame((self::YEAR + 1) . '-08-31', $accrual['accrual_to']);
        self::assertSame('recurring_rule', $accrual['source']);
    }

    // ── fixtury ──────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $data */
    private function expenseRule(array $data, ?int $supplierId = null): int
    {
        return $this->repo->insert($supplierId ?? $this->supplierId, [
            'name' => $data['name'] ?? 'Pravidlo',
            'vendor_client_id' => $data['vendor_client_id'] ?? null,
            'vendor_name_contains' => $data['vendor_name_contains'] ?? null,
            'description_contains' => $data['description_contains'] ?? null,
            'amount_min' => $data['amount_min'] ?? null,
            'amount_max' => $data['amount_max'] ?? null,
            'expense_kind' => $data['expense_kind'] ?? 'small_asset',
            'priority' => $data['priority'] ?? 100,
            'is_active' => $data['is_active'] ?? 1,
        ], $this->userId);
    }

    /** Klient CIZÍ firmy — pro test hranice na vendor_client_id. */
    private function foreignClient(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Cizí dodavatel s.r.o.", "Test 1", "Praha", "11000", ?, "t@example.com", "cs", ?, 1, 1)'
        );
        $stmt->execute([$this->otherSupplierId(), $this->czId, $this->currencyId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @param list<array{0:string,1:float}> $items [popis, cena za kus bez DPH]
     */
    private function purchaseWithItems(string $number, int $vendorId, array $items, ?int $currencyId = null, ?float $exchangeRate = null, ?string $issueDate = null): int
    {
        $base = 0.0;
        foreach ($items as $it) {
            $base += $it[1];
        }
        $issue = $issueDate ?? self::YEAR . '-06-10';
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind, vat_deduction,
                 issue_date, tax_date, due_date, received_at, currency_id, exchange_rate, reverse_charge, is_fixed_asset,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by)
             VALUES (?, ?, ?, "{}", "invoice", "full", ?, ?, ?, ?, ?, ?, 0, 0, ?, 0, ?, "received", "40", ?)'
        )->execute([$this->supplierId, $vendorId, $number, $issue, $issue, $issue, $issue,
            $currencyId ?? $this->currencyId, $exchangeRate, round($base, 2), round($base, 2), $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();

        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code)
             VALUES (?, ?, 1, 'ks', ?, ?, 21.00, ?, 0, ?, ?, '40')"
        );
        foreach (array_values($items) as $i => [$desc, $price]) {
            $stmt->execute([$id, $desc, $price, $this->vatRateId, $price, $price, $i]);
        }
        return $id;
    }

    /**
     * @param array<int,array<string,mixed>> $suggestions
     * @return array<string,array<string,mixed>>
     */
    private function indexByDescription(int $purchaseInvoiceId, array $suggestions): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, description FROM purchase_invoice_items WHERE purchase_invoice_id = ?'
        );
        $stmt->execute([$purchaseInvoiceId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            if (isset($suggestions[$id])) {
                $out[(string) $row['description']] = $suggestions[$id];
            }
        }
        return $out;
    }
}
