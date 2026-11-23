<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\Closing\DocumentSeriesService;
use MyInvoice\Service\Accounting\OtherItemException;
use MyInvoice\Service\Accounting\OtherItemKindService;
use MyInvoice\Service\Accounting\OtherItemScheduleService;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Druhy ostatních položek (migrace 2228): vestavěný i vlastní druh s vlastní řadou čísluje
 * doklady svým prefixem, ostatní jedou dál na společné OP/OZ. Všechny řady sdílejí unikátnost
 * `uq_other_item_doc_no`, proto prefix nesmí kolidovat s jinou řadou ostatních položek.
 */
#[Group('integration')]
final class OtherItemKindTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PDO $pdo;
    private OtherItemService $items;
    private OtherItemKindService $kinds;
    private DocumentSeriesService $series;
    private OtherItemScheduleService $schedules;
    private int $supplierId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->items = $container->get(OtherItemService::class);
        $this->kinds = $container->get(OtherItemKindService::class);
        $this->series = $container->get(DocumentSeriesService::class);
        $this->schedules = $container->get(OtherItemScheduleService::class);
        $this->pdo = $this->db->pdo();
        $this->pdo->beginTransaction();
        $source = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        self::assertGreaterThan(0, $source);
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $source);
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")
            ->execute([$this->supplierId]);
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $container->get(AccountingPeriodRepository::class)->create($this->supplierId, 2099, '2099-01-01', '2099-12-31');
        $container->get(AccountingPeriodRepository::class)->create($this->supplierId, 2100, '2100-01-01', '2100-12-31');
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if (isset($this->db)) $this->db->close();
    }

    public function testKindWithOwnSeriesNumbersByItsPrefixAndOthersStayOnCompanySeries(): void
    {
        $rent = $this->kinds->create($this->supplierId, ['side' => 'payable', 'name' => 'Nájemné', 'own_series' => true, 'prefix' => 'NJ'], null);
        $grant = $this->kinds->create($this->supplierId, ['side' => 'receivable', 'name' => 'Nárok na dotaci', 'own_series' => true, 'prefix' => 'DT'], null);
        $plain = $this->kinds->create($this->supplierId, ['side' => 'payable', 'name' => 'Odvod', 'own_series' => false], null);

        self::assertSame('NJ-2099-0001', $this->post(['kind_id' => $rent['id']]));
        self::assertSame('NJ-2099-0002', $this->post(['kind_id' => $rent['id']]));
        self::assertSame('DT-2099-0001', $this->post(['side' => 'receivable', 'kind_id' => $grant['id'], 'account_code' => '315', 'counter_account_code' => '648']));
        self::assertSame('OZ-2099-0001', $this->post(['kind_id' => $plain['id']]));
        self::assertSame('OZ-2099-0002', $this->post());

        $item = $this->items->list($this->supplierId, ['q' => 'NJ-2099-0001'], 1, 10)['items'][0];
        self::assertSame((string) $rent['id'], (string) $item['kind_id']);
        self::assertSame('Nájemné', $item['kind_name']);
        self::assertSame('other', $item['kind']);
    }

    public function testListFiltersBuiltinKindApartFromCustomKinds(): void
    {
        $rent = $this->kinds->create($this->supplierId, ['side' => 'payable', 'name' => 'Nájemné', 'own_series' => true, 'prefix' => 'NJ'], null);
        $this->post(['kind_id' => $rent['id']]);
        $this->post(['title' => 'Syntetický jiný závazek']);
        $this->post(['kind' => 'rent', 'title' => 'Syntetické nájemné vestavěné']);

        $titles = fn (array $filters): array => array_column(
            $this->items->list($this->supplierId, $filters + ['status' => 'all'], 1, 10)['items'], 'title');
        self::assertSame(['Syntetické nájemné'], $titles(['kind_id' => (int) $rent['id']]));
        // Vlastní druh nese kind = other, filtr vestavěného „other“ ho ale nevrací.
        self::assertSame(['Syntetický jiný závazek'], $titles(['kind' => 'other']));
        self::assertSame(['Syntetické nájemné vestavěné'], $titles(['kind' => 'rent']));
        self::assertSame(['NJ-2099-0001', 'OZ-2099-0001', 'OZ-2099-0002'],
            array_column($this->items->list($this->supplierId, ['status' => 'all', 'sort_by' => 'document_no', 'sort_dir' => 'asc'], 1, 10)['items'], 'document_no'));
    }

    public function testDeletingSupplierCascadesKindsAndTheirItems(): void
    {
        $doomed = $this->createIsolatedSupplier($this->pdo, $this->supplierId);
        $kind = $this->kinds->create($doomed, ['side' => 'payable', 'name' => 'Nájemné'], null);
        $this->pdo->prepare("INSERT INTO other_items (supplier_id, side, kind, kind_id, title, issued_on, accounting_on, due_on,
                currency, amount, exchange_rate, amount_czk)
             VALUES (?, 'payable', 'other', ?, 'Syntetické nájemné', '2099-01-01', '2099-01-01', '2099-01-20', 'CZK', 100, 1, 100)")
            ->execute([$doomed, $kind['id']]);

        // Kaskáda ze supplier maže druhy i položky; FK položka → druh ji nesmí zastavit.
        $this->pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$doomed]);
        foreach (['other_item_kinds', 'other_items'] as $table) {
            $left = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ?");
            $left->execute([$doomed]);
            self::assertSame(0, (int) $left->fetchColumn(), $table);
        }
    }

    public function testNewYearContinuesWithKindPrefixAndTemplate(): void
    {
        $rent = $this->kinds->create($this->supplierId, ['side' => 'payable', 'name' => 'Nájemné', 'own_series' => true, 'prefix' => 'NJ'], null);
        $this->series->updateSeries($this->supplierId, 'other_payable', (int) date('Y'), ['number_format' => '{YY}{PREFIX}{CCC}'], (int) $rent['id']);

        self::assertSame('99NJ001', $this->post(['kind_id' => $rent['id']]));
        self::assertSame('00NJ001', $this->post(['kind_id' => $rent['id'], 'issued_on' => '2100-01-05', 'accounting_on' => '2100-01-05', 'due_on' => '2100-01-20']));
    }

    public function testPrefixMayNotCollideWithAnotherOtherItemSeries(): void
    {
        foreach (['OP', 'OZ'] as $builtin) {
            try {
                $this->kinds->create($this->supplierId, ['side' => 'payable', 'name' => 'Kolize ' . $builtin, 'own_series' => true, 'prefix' => $builtin], null);
                self::fail('Prefix ' . $builtin . ' patří společné řadě.');
            } catch (OtherItemException $e) {
                self::assertSame('series_prefix_taken', $e->errorCode);
            }
        }
        $this->kinds->create($this->supplierId, ['side' => 'receivable', 'name' => 'Nárok na dotaci', 'own_series' => true, 'prefix' => 'DT'], null);
        try {
            $this->kinds->create($this->supplierId, ['side' => 'payable', 'name' => 'Vratka dotace', 'own_series' => true, 'prefix' => 'DT'], null);
            self::fail('Prefix DT už drží řada pohledávek.');
        } catch (OtherItemException $e) {
            self::assertSame('series_prefix_taken', $e->errorCode);
        }
        // Ruční změna prefixu v Nástrojích hlídá totéž, i u společné řady.
        $this->expectException(ClosingException::class);
        $this->series->updateSeries($this->supplierId, 'other_payable', 2099, ['prefix' => 'DT']);
    }

    public function testCashPrefixesStillGuardedAndIndependentOfOtherItems(): void
    {
        $this->kinds->create($this->supplierId, ['side' => 'payable', 'name' => 'Nájemné', 'own_series' => true, 'prefix' => 'PPD2'], null);
        // Pokladní skupina je samostatná: prefix ostatní položky jí nevadí, firemní VPD ano.
        $this->series->updateSeries($this->supplierId, 'cash_in', 2099, ['prefix' => 'PPD2']);
        $this->series->ensureSeriesRow($this->supplierId, 'cash_out', 2099, 'VPD');
        $this->expectException(ClosingException::class);
        $this->series->updateSeries($this->supplierId, 'cash_in', 2099, ['prefix' => 'VPD']);
    }

    public function testKindMustMatchSideAndBeActiveForNewDrafts(): void
    {
        $grant = $this->kinds->create($this->supplierId, ['side' => 'receivable', 'name' => 'Nárok na dotaci'], null);
        $this->assertItemError('kind_side', ['kind_id' => $grant['id']]);

        $rent = $this->kinds->create($this->supplierId, ['side' => 'payable', 'name' => 'Nájemné'], null);
        $draft = $this->items->create($this->supplierId, $this->input(['kind_id' => $rent['id']]), null);
        $this->kinds->update($this->supplierId, (int) $rent['id'], ['is_active' => false]);
        $this->assertItemError('kind_inactive', ['kind_id' => $rent['id']]);
        // Koncept, který neaktivní druh už nese, jde dál upravit i potvrdit.
        $this->items->update($this->supplierId, (int) $draft['id'], ['title' => 'Syntetické nájemné – oprava'], null);
        $posted = $this->items->post($this->supplierId, (int) $draft['id'], null);
        self::assertSame((string) $rent['id'], (string) $posted['kind_id']);

        $foreign = $this->createIsolatedSupplier($this->pdo, $this->supplierId);
        $foreignKind = $this->kinds->create($foreign, ['side' => 'payable', 'name' => 'Cizí druh'], null);
        $this->assertItemError('invalid_kind', ['kind_id' => $foreignKind['id']]);
    }

    public function testBuiltinKindInBodySwitchesDraftBackFromCustomKind(): void
    {
        $rent = $this->kinds->create($this->supplierId, ['side' => 'payable', 'name' => 'Nájemné'], null);
        $draft = $this->items->create($this->supplierId, $this->input(['kind_id' => $rent['id']]), null);
        $kept = $this->items->update($this->supplierId, (int) $draft['id'], ['amount' => 1500], null);
        self::assertSame((string) $rent['id'], (string) $kept['kind_id']);
        // Klient, který kind_id nezná, přeposílá hlavičku z GET s kind = other: druh zůstane.
        $resent = $this->items->update($this->supplierId, (int) $draft['id'], ['kind' => 'other', 'title' => 'Syntetické nájemné 2'], null);
        self::assertSame((string) $rent['id'], (string) $resent['kind_id']);
        $cleared = $this->items->update($this->supplierId, (int) $draft['id'], ['kind' => 'other', 'kind_id' => null], null);
        self::assertNull($cleared['kind_id']);
        $this->items->update($this->supplierId, (int) $draft['id'], ['kind_id' => $rent['id']], null);

        $switched = $this->items->update($this->supplierId, (int) $draft['id'], ['kind' => 'loan'], null);
        self::assertNull($switched['kind_id']);
        self::assertSame('loan', $switched['kind']);
    }

    public function testSideIsLockedButOwnSeriesCanBeSwitchedLater(): void
    {
        $rent = $this->kinds->create($this->supplierId, ['side' => 'payable', 'name' => 'Nájemné', 'own_series' => true, 'prefix' => 'NJ'], null);
        try {
            $this->kinds->update($this->supplierId, (int) $rent['id'], ['side' => 'receivable']);
            self::fail('Změna strany musí být odmítnuta.');
        } catch (OtherItemException $e) {
            self::assertSame('kind_locked', $e->errorCode);
        }
        self::assertSame('NJ-2099-0001', $this->post(['kind_id' => $rent['id']]));
        // Vypnutá řada se nemaže: nové doklady jdou do společné, po zapnutí čísluje dál.
        $this->kinds->update($this->supplierId, (int) $rent['id'], ['own_series' => false]);
        self::assertSame('OZ-2099-0001', $this->post(['kind_id' => $rent['id']]));
        $renamed = $this->kinds->update($this->supplierId, (int) $rent['id'], ['name' => 'Nájem klubovny', 'own_series' => true]);
        self::assertSame('Nájem klubovny', $renamed['name']);
        self::assertSame('NJ-2099-0002', $this->post(['kind_id' => $rent['id']]));

        // Druh bez řady ji dostane i dodatečně, poprvé ale jen s prefixem.
        $grant = $this->kinds->create($this->supplierId, ['side' => 'receivable', 'name' => 'Nárok na dotaci'], null);
        try {
            $this->kinds->update($this->supplierId, (int) $grant['id'], ['own_series' => true]);
            self::fail('Nová řada potřebuje prefix.');
        } catch (OtherItemException $e) {
            self::assertSame('prefix_required', $e->errorCode);
        }
        $this->kinds->update($this->supplierId, (int) $grant['id'], ['own_series' => true, 'prefix' => 'dt']);
        self::assertSame('DT-2099-0001', $this->post(['side' => 'receivable', 'kind_id' => $grant['id'], 'account_code' => '315', 'counter_account_code' => '648']));
    }

    public function testBuiltinKindNumbersFromItsOwnSeriesPerSide(): void
    {
        $this->kinds->updateBuiltin($this->supplierId, 'payable', 'rent', ['own_series' => true, 'prefix' => 'NJ'], null);

        self::assertSame('NJ-2099-0001', $this->post(['kind' => 'rent']));
        self::assertSame('OZ-2099-0001', $this->post(['kind' => 'loan']));
        self::assertSame('OZ-2099-0002', $this->post());
        // Nastavení patří jedné straně: nájemné k inkasu dál jede na OP.
        self::assertSame('OP-2099-0001', $this->post(['side' => 'receivable', 'kind' => 'rent', 'account_code' => '315', 'counter_account_code' => '648']));

        $list = $this->kinds->list($this->supplierId);
        self::assertCount(2 * count(OtherItemKindService::BUILTIN_CODES), $list);
        $rent = array_values(array_filter($list, static fn (array $k): bool => $k['side'] === 'payable' && $k['builtin_code'] === 'rent'))[0];
        self::assertTrue($rent['own_series']);
        self::assertSame('NJ', $rent['series_prefix']);
        $loan = array_values(array_filter($list, static fn (array $k): bool => $k['side'] === 'payable' && $k['builtin_code'] === 'loan'))[0];
        self::assertNull($loan['id']);
        self::assertFalse($loan['own_series']);
        // Řada vestavěného druhu hlídá prefix stejně jako vlastní druhy.
        try {
            $this->kinds->create($this->supplierId, ['side' => 'payable', 'name' => 'Nájem garáže', 'own_series' => true, 'prefix' => 'NJ'], null);
            self::fail('Prefix NJ už drží řada vestavěného druhu.');
        } catch (OtherItemException $e) {
            self::assertSame('series_prefix_taken', $e->errorCode);
        }
    }

    public function testHiddenBuiltinKindIsRefusedForNewDraftsOnly(): void
    {
        $draft = $this->items->create($this->supplierId, $this->input(['kind' => 'rent']), null);
        $source = $this->items->create($this->supplierId, $this->input(['kind' => 'rent', 'title' => 'Syntetické nájemné měsíčně']), null);
        $this->items->post($this->supplierId, (int) $source['id'], null);
        $schedule = $this->schedules->create($this->supplierId, (int) $source['id'], ['frequency' => 'monthly'], null);
        $hidden = $this->kinds->updateBuiltin($this->supplierId, 'payable', 'rent', ['is_active' => false], null);
        self::assertFalse($hidden['is_active']);

        $this->assertItemError('kind_inactive', ['kind' => 'rent']);
        // Druhá strana skrytá není.
        $this->items->create($this->supplierId, $this->input(['side' => 'receivable', 'kind' => 'rent', 'account_code' => '315', 'counter_account_code' => '648']), null);
        // Koncept i šablona, které druh už nesou, ho smějí ponechat.
        $this->items->update($this->supplierId, (int) $draft['id'], ['title' => 'Syntetické nájemné – oprava'], null);
        self::assertSame('rent', $this->items->post($this->supplierId, (int) $draft['id'], null)['kind']);
        $generated = $this->schedules->generate($this->supplierId, (int) $schedule['id'], '2099-02-28', null);
        self::assertSame('rent', $this->items->get($this->supplierId, (int) $generated['created_ids'][0])['kind']);

        // Vestavěný druh nejde přejmenovat, smazat ani podstrčit jako vlastní druh, `other` nastavit nejde.
        foreach ([
            'kind_locked' => fn () => $this->kinds->update($this->supplierId, (int) $hidden['id'], ['name' => 'Nájem']),
            'kind_builtin' => fn () => $this->kinds->delete($this->supplierId, (int) $hidden['id']),
            'invalid_kind' => fn () => $this->items->create($this->supplierId, $this->input(['kind_id' => $hidden['id']]), null),
            'kind_not_found' => fn () => $this->kinds->updateBuiltin($this->supplierId, 'payable', 'other', ['is_active' => false], null),
        ] as $code => $call) {
            try {
                $call();
                self::fail('Očekávána chyba ' . $code . '.');
            } catch (OtherItemException $e) {
                self::assertSame($code, $e->errorCode);
            }
        }
    }

    public function testUsedKindCannotBeDeletedAndUnusedTakesItsSeriesAlong(): void
    {
        $rent = $this->kinds->create($this->supplierId, ['side' => 'payable', 'name' => 'Nájemné', 'own_series' => true, 'prefix' => 'NJ'], null);
        $this->items->create($this->supplierId, $this->input(['kind_id' => $rent['id']]), null);
        try {
            $this->kinds->delete($this->supplierId, (int) $rent['id']);
            self::fail('Použitý druh nejde smazat.');
        } catch (OtherItemException $e) {
            self::assertSame('kind_in_use', $e->errorCode);
        }

        $unused = $this->kinds->create($this->supplierId, ['side' => 'receivable', 'name' => 'Nárok na dotaci', 'own_series' => true, 'prefix' => 'DT'], null);
        // Smazaný koncept druh neblokuje.
        $discarded = $this->items->create($this->supplierId, $this->input(['side' => 'receivable', 'kind_id' => $unused['id'], 'counter_account_code' => '648']), null);
        $this->items->deleteDraft($this->supplierId, (int) $discarded['id']);
        $this->kinds->delete($this->supplierId, (int) $unused['id']);
        self::assertSame([], array_values(array_filter($this->series->list($this->supplierId),
            static fn (array $row): bool => $row['register_id'] === (int) $unused['id'] && $row['series_code'] === 'other_receivable')));
        // Prefix smazaného druhu je zase volný.
        $this->kinds->create($this->supplierId, ['side' => 'receivable', 'name' => 'Dotace kraj', 'own_series' => true, 'prefix' => 'DT'], null);
    }

    public function testScheduleKeepsKindEvenAfterDeactivationAndResume(): void
    {
        $rent = $this->kinds->create($this->supplierId, ['side' => 'payable', 'name' => 'Nájemné', 'own_series' => true, 'prefix' => 'NJ'], null);
        $source = $this->items->create($this->supplierId, $this->input(['kind_id' => $rent['id']]), null);
        $this->items->post($this->supplierId, (int) $source['id'], null);
        $schedule = $this->schedules->create($this->supplierId, (int) $source['id'], ['frequency' => 'monthly'], null);
        $generated = $this->schedules->generate($this->supplierId, (int) $schedule['id'], '2099-02-28', null);
        $next = $this->items->get($this->supplierId, (int) $generated['created_ids'][0]);
        self::assertSame((string) $rent['id'], (string) $next['kind_id']);
        self::assertSame('NJ-2099-0002', $this->items->post($this->supplierId, (int) $next['id'], null)['document_no']);

        // Pozastavené opakování, deaktivovaný druh a obnovení: výskyty dál dostávají druh i jeho řadu.
        $this->schedules->setStatus($this->supplierId, (int) $schedule['id'], 'paused');
        $this->kinds->update($this->supplierId, (int) $rent['id'], ['is_active' => false]);
        $this->schedules->setStatus($this->supplierId, (int) $schedule['id'], 'active');
        $later = $this->schedules->generate($this->supplierId, (int) $schedule['id'], '2099-03-31', null);
        self::assertCount(1, $later['created_ids']);
        self::assertSame('NJ-2099-0003', $this->items->post($this->supplierId, (int) $later['created_ids'][0], null)['document_no']);

        try {
            $this->kinds->delete($this->supplierId, (int) $rent['id']);
            self::fail('Druh s opakováním nejde smazat.');
        } catch (OtherItemException $e) {
            self::assertSame('kind_in_use', $e->errorCode);
        }
    }

    public function testPrefixAlreadyCarriedByIssuedNumbersIsRefused(): void
    {
        $rent = $this->kinds->create($this->supplierId, ['side' => 'payable', 'name' => 'Nájemné', 'own_series' => true, 'prefix' => 'NJ'], null);
        self::assertSame('NJ-2099-0001', $this->post(['kind_id' => $rent['id']]));
        // Řada se v Nástrojích přejmenuje, vydané doklady ale prefix NJ dál nesou.
        $this->series->updateSeries($this->supplierId, 'other_payable', 2099, ['prefix' => 'NJA'], (int) $rent['id']);
        $this->series->updateSeries($this->supplierId, 'other_payable', (int) date('Y'), ['prefix' => 'NJA'], (int) $rent['id']);
        try {
            $this->kinds->create($this->supplierId, ['side' => 'payable', 'name' => 'Nájem garáže', 'own_series' => true, 'prefix' => 'NJ'], null);
            self::fail('Prefix vydaných čísel musí být odmítnut.');
        } catch (OtherItemException $e) {
            self::assertSame('series_prefix_taken', $e->errorCode);
        }
    }

    public function testDuplicateNumberIsReportedAsSeriesConflict(): void
    {
        $rent = $this->kinds->create($this->supplierId, ['side' => 'payable', 'name' => 'Nájemné', 'own_series' => true, 'prefix' => 'NJ'], null);
        self::assertSame('NJ-2099-0001', $this->post(['kind_id' => $rent['id']]));
        $this->series->updateSeries($this->supplierId, 'other_payable', 2099, ['next_number' => 1], (int) $rent['id']);
        $draft = $this->items->create($this->supplierId, $this->input(['kind_id' => $rent['id']]), null);
        try {
            $this->items->post($this->supplierId, (int) $draft['id'], null);
            self::fail('Duplicitní číslo musí skončit chybou řady.');
        } catch (OtherItemException $e) {
            self::assertSame('document_no_taken', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }
    }

    public function testTaxEvidenceConfirmsFromKindSeries(): void
    {
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")->execute([$this->supplierId]);
        $rent = $this->kinds->create($this->supplierId, ['side' => 'payable', 'name' => 'Nájemné', 'own_series' => true, 'prefix' => 'NJ'], null);
        $draft = $this->items->create($this->supplierId, $this->input(['kind_id' => $rent['id'], 'counter_account_code' => null]), null);
        $confirmed = $this->items->post($this->supplierId, (int) $draft['id'], null);
        self::assertSame('confirmed', $confirmed['status']);
        self::assertSame('NJ-2099-0001', $confirmed['document_no']);
    }

    private function post(array $changes = []): string
    {
        $draft = $this->items->create($this->supplierId, $this->input($changes), null);
        return (string) $this->items->post($this->supplierId, (int) $draft['id'], null)['document_no'];
    }

    private function assertItemError(string $code, array $changes): void
    {
        try {
            $this->items->create($this->supplierId, $this->input($changes), null);
            self::fail('Očekávána chyba ' . $code . '.');
        } catch (OtherItemException $e) {
            self::assertSame($code, $e->errorCode);
        }
    }

    private function input(array $changes = []): array
    {
        return array_replace([
            'side' => 'payable', 'kind' => 'other', 'title' => 'Syntetické nájemné',
            'issued_on' => '2099-01-01', 'accounting_on' => '2099-01-01',
            'due_on' => '2099-01-20', 'currency' => 'CZK', 'amount' => 1200,
            'counter_account_code' => '518',
        ], $changes);
    }
}
