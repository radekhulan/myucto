<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\OtherItemScheduleAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\OtherItemScheduleService;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Šablona názvu výskytů opakované ostatní položky: stejné zástupné znaky
 * jako opakovaná faktura, referenční datum = datum vzniku výskytu.
 */
#[Group('integration')]
final class OtherItemScheduleTitleTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PDO $pdo;
    private OtherItemService $items;
    private OtherItemScheduleService $schedules;
    private int $supplierId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->items = $container->get(OtherItemService::class);
        $this->schedules = $container->get(OtherItemScheduleService::class);
        $this->pdo = $this->db->pdo();
        $this->pdo->beginTransaction();
        $source = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $source);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if (isset($this->db)) $this->db->close();
    }

    /** Rozvrh bez šablony: stejná šablona v DB i stejné názvy jako před zavedením šablon. */
    public function testScheduleWithoutTemplateKeepsSourceTitleAndStoredTemplate(): void
    {
        $source = $this->items->create($this->supplierId, $this->input(['title' => 'Syntetický nájem 01/2099']), null);
        $schedule = $this->schedules->create($this->supplierId, (int) $source['id'], ['frequency' => 'monthly'], null);
        self::assertSame(['side', 'kind', 'kind_id', 'title', 'partner_id', 'partner_name', 'currency', 'amount',
            'exchange_rate', 'variable_symbol', 'counterparty_account', 'counterparty_bank_code', 'account_code',
            'counter_account_code', 'posting_lines', 'note'], array_keys($this->storedTemplate((int) $schedule['id'])));
        self::assertSame('Syntetický nájem 01/2099', $schedule['next_title']);
        $result = $this->schedules->generate($this->supplierId, (int) $schedule['id'], '2099-03-31', null);
        self::assertSame(['Syntetický nájem 01/2099', 'Syntetický nájem 01/2099'], $this->titles($result['created_ids']));
    }

    /** Šablona uložená před touto změnou (bez nových klíčů) generuje beze změny. */
    public function testLegacyStoredTemplateGeneratesSameTitle(): void
    {
        $source = $this->items->create($this->supplierId, $this->input(), null);
        $schedule = $this->schedules->create($this->supplierId, (int) $source['id'], ['frequency' => 'monthly'], null);
        $legacy = json_encode(['side' => 'payable', 'kind' => 'rent', 'title' => 'Starý rozvrh {MM}',
            'currency' => 'CZK', 'amount' => 1200, 'counter_account_code' => '518'], JSON_THROW_ON_ERROR);
        $this->pdo->prepare('UPDATE other_item_schedules SET template_json = ? WHERE supplier_id = ? AND id = ?')
            ->execute([$legacy, $this->supplierId, $schedule['id']]);
        $result = $this->schedules->generate($this->supplierId, (int) $schedule['id'], '2099-02-28', null);
        // Zástupné znaky v názvu zdroje se bez šablony nevyhodnocují.
        self::assertSame(['Starý rozvrh {MM}'], $this->titles($result['created_ids']));
    }

    public function testEveryPlaceholderUsesOccurrenceDateAcrossYearEnd(): void
    {
        $source = $this->items->create($this->supplierId, $this->input([
            'issued_on' => '2099-11-30', 'due_on' => '2099-12-05',
        ]), null);
        $schedule = $this->schedules->create($this->supplierId, (int) $source['id'], [
            'frequency' => 'monthly',
            'title_template' => 'Nájem {MM}/{YYYY} {M}/{YY} Q{Q} {MMMM} {D}.{DD} {DATE} {BOM}–{EOM} {MM+1}/{YYYY+1M}',
        ], null);
        self::assertSame('Syntetické nájemné', $this->items->get($this->supplierId, (int) $source['id'])['title']);
        $result = $this->schedules->generate($this->supplierId, (int) $schedule['id'], '2100-02-28', null);
        self::assertSame([
            'Nájem 12/2099 12/99 Q4 prosinec 30.30 30. 12. 2099 1. 12. 2099–31. 12. 2099 01/2100',
            'Nájem 01/2100 1/00 Q1 leden 30.30 30. 1. 2100 1. 1. 2100–31. 1. 2100 02/2100',
            'Nájem 02/2100 2/00 Q1 únor 28.28 28. 2. 2100 1. 2. 2100–28. 2. 2100 03/2100',
        ], $this->titles($result['created_ids']));
    }

    public function testQuarterTurnsWithQuarterlyOccurrences(): void
    {
        $source = $this->items->create($this->supplierId, $this->input([
            'issued_on' => '2099-12-15', 'due_on' => '2099-12-20',
        ]), null);
        $schedule = $this->schedules->create($this->supplierId, (int) $source['id'], [
            'frequency' => 'quarterly', 'title_template' => 'Pojistné Q{Q}/{YYYY}',
        ], null);
        self::assertSame('Pojistné Q1/2100', $schedule['next_title']);
        $result = $this->schedules->generate($this->supplierId, (int) $schedule['id'], '2100-09-15', null);
        self::assertSame(['Pojistné Q1/2100', 'Pojistné Q2/2100', 'Pojistné Q3/2100'], $this->titles($result['created_ids']));
    }

    public function testSyncPeriodRewritesMonthAndYearInSourceTitle(): void
    {
        $source = $this->items->create($this->supplierId, $this->input(['title' => 'Nájem 01/2099 a 2099-01']), null);
        $schedule = $this->schedules->create($this->supplierId, (int) $source['id'], [
            'frequency' => 'monthly', 'title_sync_period' => true,
        ], null);
        $result = $this->schedules->generate($this->supplierId, (int) $schedule['id'], '2099-03-31', null);
        self::assertSame(['Nájem 02/2099 a 2099-02', 'Nájem 03/2099 a 2099-03'], $this->titles($result['created_ids']));
        self::assertSame('Nájem 01/2099 a 2099-01', $this->items->get($this->supplierId, (int) $source['id'])['title']);
    }

    /** Pořadí jako u faktury: nejdřív zástupné znaky, pak srovnání období. */
    public function testTemplateAndSyncCombineInInvoiceOrder(): void
    {
        $source = $this->items->create($this->supplierId, $this->input(), null);
        $schedule = $this->schedules->create($this->supplierId, (int) $source['id'], [
            'frequency' => 'monthly', 'title_template' => 'Záloha {MMMM} za 12/2098', 'title_sync_period' => true,
        ], null);
        $result = $this->schedules->generate($this->supplierId, (int) $schedule['id'], '2099-02-28', null);
        self::assertSame(['Záloha únor za 2/2099'], $this->titles($result['created_ids']));
    }

    public function testChangedTemplateAppliesOnlyToFutureOccurrences(): void
    {
        $source = $this->items->create($this->supplierId, $this->input(), null);
        $schedule = $this->schedules->create($this->supplierId, (int) $source['id'], ['frequency' => 'monthly'], null);
        $first = $this->schedules->generate($this->supplierId, (int) $schedule['id'], '2099-02-28', null);
        $updated = $this->schedules->setTitle($this->supplierId, (int) $schedule['id'], ['title_template' => 'Nájem {MM}/{YYYY}'], true);
        self::assertSame('Nájem {MM}/{YYYY}', $updated['template']['title_template']);
        self::assertSame('Nájem 03/2099', $updated['next_title']);
        $second = $this->schedules->generate($this->supplierId, (int) $schedule['id'], '2099-03-31', null);
        self::assertSame(['Syntetické nájemné'], $this->titles($first['created_ids']));
        self::assertSame(['Nájem 03/2099'], $this->titles($second['created_ids']));
        // Prázdná šablona vrací rozvrh k názvu zdroje a z uložené šablony zmizí.
        $cleared = $this->schedules->setTitle($this->supplierId, (int) $schedule['id'], ['title_template' => '  '], true);
        self::assertArrayNotHasKey('title_template', $this->storedTemplate((int) $schedule['id']));
        self::assertArrayNotHasKey('title_sync_period', $this->storedTemplate((int) $schedule['id']));
        self::assertSame('Syntetické nájemné', $cleared['next_title']);
    }

    public function testPreviewMatchesGeneratedTitle(): void
    {
        $source = $this->items->create($this->supplierId, $this->input(), null);
        self::assertSame(['title' => 'Nájem Q2 2099', 'issued_on' => '2099-04-30'], $this->schedules->previewTitle(
            $this->supplierId, (int) $source['id'], ['frequency' => 'quarterly', 'title_template' => 'Nájem Q{Q} {YYYY}']));
        $schedule = $this->schedules->create($this->supplierId, (int) $source['id'], ['frequency' => 'monthly'], null);
        $this->schedules->generate($this->supplierId, (int) $schedule['id'], '2099-02-28', null);
        // Existující rozvrh: příští termín rozvrhu, četnost z dotazu se nepoužije.
        $preview = $this->schedules->previewTitle($this->supplierId, (int) $source['id'],
            ['frequency' => 'yearly', 'title_template' => '{MMMM} {YYYY}']);
        self::assertSame(['title' => 'březen 2099', 'issued_on' => '2099-03-31'], $preview);
        $this->schedules->setTitle($this->supplierId, (int) $schedule['id'], ['title_template' => '{MMMM} {YYYY}'], true);
        $result = $this->schedules->generate($this->supplierId, (int) $schedule['id'], '2099-03-31', null);
        self::assertSame([$preview['title']], $this->titles($result['created_ids']));
    }

    /**
     * Znovu naplánovaný zdroj navazuje na položky zrušeného opakování. Náhled názvu musí
     * ukázat stejný první termín, jaký založení skutečně vytvoří, ne první měsíc po zdroji.
     */
    public function testPreviewAfterCancelledScheduleMatchesFirstCreatedOccurrence(): void
    {
        $source = $this->items->create($this->supplierId, $this->input(), null);
        $old = $this->schedules->create($this->supplierId, (int) $source['id'], ['frequency' => 'monthly'], null);
        self::assertCount(2, $this->schedules->generate($this->supplierId, (int) $old['id'], '2099-03-31', null)['created_ids']);
        $this->schedules->delete($this->supplierId, (int) $old['id']);

        $settings = ['frequency' => 'monthly', 'title_template' => 'Nájem {MM}/{YYYY}'];
        $preview = $this->schedules->previewTitle($this->supplierId, (int) $source['id'], $settings);
        self::assertSame(['title' => 'Nájem 04/2099', 'issued_on' => '2099-04-30'], $preview);
        $schedule = $this->schedules->create($this->supplierId, (int) $source['id'], $settings, null);
        self::assertSame('2099-04-30', $schedule['next_on']);
        $result = $this->schedules->generate($this->supplierId, (int) $schedule['id'], '2099-04-30', null);
        self::assertSame([$preview['title']], $this->titles($result['created_ids']));
    }

    /** Automaticky zaúčtovaný výskyt má v popisu zápisu dosazený název. */
    public function testAutoPostedOccurrenceUsesSubstitutedTitleInJournal(): void
    {
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplierId]);
        (new ChartOfAccountsSeeder($this->db))->seedForSupplier($this->supplierId);
        $anchor = new \DateTimeImmutable('first day of last month');
        $periods = new AccountingPeriodRepository($this->db);
        foreach (array_unique([(int) $anchor->format('Y'), (int) (new \DateTimeImmutable('today'))->format('Y')]) as $year) {
            $periods->create($this->supplierId, $year, "$year-01-01", "$year-12-31");
        }
        $source = $this->items->create($this->supplierId, $this->input([
            'issued_on' => $anchor->format('Y-m-d'), 'due_on' => $anchor->modify('+5 days')->format('Y-m-d'),
        ]), null);
        $this->items->post($this->supplierId, (int) $source['id'], null);
        $schedule = $this->schedules->create($this->supplierId, (int) $source['id'], [
            'frequency' => 'monthly', 'auto_post' => true, 'title_template' => 'Nájem {MM}/{YYYY}',
        ], null);
        $result = $this->schedules->generate($this->supplierId, (int) $schedule['id'],
            (new \DateTimeImmutable('first day of this month'))->format('Y-m-d'), null);
        self::assertCount(1, $result['posted_ids']);
        $item = $this->items->get($this->supplierId, $result['posted_ids'][0]);
        $expected = 'Nájem ' . (new \DateTimeImmutable('first day of this month'))->format('m/Y');
        self::assertSame($expected, $item['title']);
        $entry = $this->pdo->prepare('SELECT description FROM journal_entries WHERE supplier_id = ? AND id = ?');
        $entry->execute([$this->supplierId, $item['journal_entry_id']]);
        self::assertSame($expected, $entry->fetchColumn());
    }

    /**
     * Neplatná šablona je 422 s polem formuláře, při založení i úpravě, a nic neuloží.
     *
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function invalidSettings(): iterable
    {
        yield 'neznámý znak' => [['title_template' => 'Nájem {FOO}'], 'other_items.error.unknown_title_placeholder', 'title_template'];
        yield 'malá písmena' => [['title_template' => 'Nájem {mm}'], 'other_items.error.unknown_title_placeholder', 'title_template'];
        yield 'neuzavřená závorka' => [['title_template' => 'Nájem {MM'], 'other_items.error.unknown_title_placeholder', 'title_template'];
        yield 'není text' => [['title_template' => ['x']], 'other_items.error.invalid_title_template', 'title_template'];
        yield 'dlouhá šablona' => [['title_template' => str_repeat('a', 256)], 'other_items.error.title_template_too_long', 'title_template'];
        // Šablona má 255 znaků, první výskyt (leden) 253, až prosinec o rok později 259.
        yield 'dlouhý výsledek' => [['title_template' => str_repeat('a', 243) . '{MMMM}{MMMM}'], 'other_items.error.occurrence_title_too_long', 'title_template'];
        yield 'četnost není text' => [['frequency' => ['monthly']], 'other_items.error.invalid_frequency', 'frequency'];
        yield 'konec není text' => [['ends_on' => ['2099-12-31']], 'other_items.error.invalid_date', 'ends_on'];
        yield 'konec neexistující datum' => [['ends_on' => '2099-02-30'], 'other_items.error.invalid_date', 'ends_on'];
        yield 'konec před vznikem' => [['ends_on' => '2099-01-01'], 'other_items.error.invalid_end', 'ends_on'];
        yield 'srovnání není boolean' => [['title_sync_period' => 'ano'], 'other_items.error.invalid_title_sync_period', 'title_sync_period'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSettings')]
    public function testInvalidSettingsReturn422WithField(array $settings, string $code, string $field): void
    {
        $source = $this->items->create($this->supplierId, $this->input([
            'issued_on' => '2099-12-15', 'due_on' => '2099-12-20',
        ]), null);
        $action = new OtherItemScheduleAction($this->schedules, $this->items);
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Test', 'staff', true, ['other_items' => 2], 'custom'));
        $response = $action->create($request->withParsedBody($settings + ['frequency' => 'monthly']), new Response(), ['item_id' => $source['id']]);
        self::assertSame(422, $response->getStatusCode());
        $error = json_decode((string) $response->getBody(), true)['error'];
        self::assertSame([$code, $field], [$error['code'], $error['field']]);
        self::assertSame([], $this->schedules->list($this->supplierId));

        if (in_array($field, ['frequency', 'ends_on'], true)) return; // úprava názvu četnost ani konec nemění
        $schedule = $this->schedules->create($this->supplierId, (int) $source['id'], ['frequency' => 'monthly'], null);
        $before = $this->storedTemplate((int) $schedule['id']);
        $response = $action->title($request->withParsedBody($settings), new Response(), ['id' => $schedule['id']]);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame($field, json_decode((string) $response->getBody(), true)['error']['field']);
        self::assertSame($before, $this->storedTemplate((int) $schedule['id']));
    }

    /** Přetéká jen srovnaný název zdroje: chyba patří k volbě srovnání, ne k šabloně. */
    public function testSyncThatOverflowsSourceTitleReportsSyncField(): void
    {
        $source = $this->items->create($this->supplierId, $this->input(['title' => str_repeat('a', 248) . ' 1/2099']), null);
        $action = new OtherItemScheduleAction($this->schedules, $this->items);
        $response = $action->create($this->session(2)->withParsedBody(['frequency' => 'monthly', 'title_sync_period' => true]),
            new Response(), ['item_id' => $source['id']]);
        self::assertSame(422, $response->getStatusCode());
        $error = json_decode((string) $response->getBody(), true)['error'];
        self::assertSame(['other_items.error.occurrence_title_too_long', 'title_sync_period'], [$error['code'], $error['field']]);
    }

    /** Výskyt za koncem rozvrhu nevznikne, jeho délka uložení neblokuje. */
    public function testLengthIsCheckedOnlyUntilScheduleEnd(): void
    {
        $source = $this->items->create($this->supplierId, $this->input([
            'issued_on' => '2099-12-15', 'due_on' => '2099-12-20',
        ]), null);
        // Leden až červen 2100 vyjdou nejvýš na 255 znaků, červenec (červenec×2) by přetekl.
        $template = str_repeat('a', 243) . '{MMMM}{MMMM}';
        $schedule = $this->schedules->create($this->supplierId, (int) $source['id'], [
            'frequency' => 'monthly', 'ends_on' => '2100-06-30', 'title_template' => $template,
        ], null);
        self::assertSame($template, $this->storedTemplate((int) $schedule['id'])['title_template']);
        self::assertSame($template, $this->schedules->setTitle($this->supplierId, (int) $schedule['id'],
            ['title_template' => $template], true)['template']['title_template']);
    }

    public function testUnknownPlaceholderReturnsTokensForClientMessage(): void
    {
        $source = $this->items->create($this->supplierId, $this->input(), null);
        $action = new OtherItemScheduleAction($this->schedules, $this->items);
        $response = $action->create($this->session(2)->withParsedBody(['frequency' => 'monthly', 'title_template' => '{FOO} {mm} {FOO}']),
            new Response(), ['item_id' => $source['id']]);
        self::assertSame(['{FOO}', '{mm}'], json_decode((string) $response->getBody(), true)['error']['tokens']);
    }

    /**
     * API token nezmění název opakování s automatickým účtováním: cron by doklady zaúčtoval,
     * aniž je kdo viděl. Bez automatiky token smí, v aplikaci vždy.
     */
    public function testBearerCannotChangeTitleOfAutoPostSchedule(): void
    {
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")->execute([$this->supplierId]);
        $source = $this->items->create($this->supplierId, $this->input(), null);
        $this->items->post($this->supplierId, (int) $source['id'], null);
        $schedule = $this->schedules->create($this->supplierId, (int) $source['id'], ['frequency' => 'monthly', 'auto_post' => true], null);
        $action = new OtherItemScheduleAction($this->schedules, $this->items);
        $bearer = $this->session(2)->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer');
        $before = $this->storedTemplate((int) $schedule['id']);
        $response = $action->title($bearer->withParsedBody(['title_template' => 'Nájem {MM}']), new Response(), ['id' => $schedule['id']]);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('other_items.error.auto_post_session_only', json_decode((string) $response->getBody(), true)['error']['code']);
        self::assertSame($before, $this->storedTemplate((int) $schedule['id']));
        $session = $action->title($this->session(2)->withParsedBody(['title_template' => 'Nájem {MM}']), new Response(), ['id' => $schedule['id']]);
        self::assertSame(200, $session->getStatusCode());
        $this->schedules->setStatus($this->supplierId, (int) $schedule['id'], 'active', false);
        $response = $action->title($bearer->withParsedBody(['title_template' => 'Nájem {MM}/{YYYY}']), new Response(), ['id' => $schedule['id']]);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testCancelledSourceOrFinishedScheduleRejectsTitleChangeAndPreview(): void
    {
        $source = $this->items->create($this->supplierId, $this->input(), null);
        $schedule = $this->schedules->create($this->supplierId, (int) $source['id'], ['frequency' => 'monthly', 'ends_on' => '2099-02-28'], null);
        $this->schedules->generate($this->supplierId, (int) $schedule['id'], '2099-12-31', null);
        foreach ([
            fn () => $this->schedules->setTitle($this->supplierId, (int) $schedule['id'], ['title_template' => '{MM}'], true),
            fn () => $this->schedules->previewTitle($this->supplierId, (int) $source['id'], ['title_template' => '{MM}']),
        ] as $call) {
            try {
                $call();
                self::fail('Skončený rozvrh nesmí přijmout šablonu.');
            } catch (\MyInvoice\Service\Accounting\OtherItemException $e) {
                self::assertSame(['schedule_finished', 409], [$e->errorCode, $e->httpStatus]);
            }
        }

        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")->execute([$this->supplierId]);
        $other = $this->items->create($this->supplierId, $this->input(), null);
        $active = $this->schedules->create($this->supplierId, (int) $other['id'], ['frequency' => 'monthly'], null);
        $this->items->post($this->supplierId, (int) $other['id'], null);
        $this->items->reverse($this->supplierId, (int) $other['id'], 'Ukončená smlouva', null);
        self::assertFalse($this->schedules->get($this->supplierId, (int) $active['id'])['source_active']);
        self::assertTrue($this->schedules->get($this->supplierId, (int) $schedule['id'])['source_active']);
        foreach ([
            fn () => $this->schedules->setTitle($this->supplierId, (int) $active['id'], ['title_template' => '{MM}'], true),
            fn () => $this->schedules->previewTitle($this->supplierId, (int) $other['id'], ['title_template' => '{MM}']),
        ] as $call) {
            try {
                $call();
                self::fail('Stornovaný zdroj nesmí přijmout šablonu.');
            } catch (\MyInvoice\Service\Accounting\OtherItemException $e) {
                self::assertSame(['schedule_source_inactive', 409], [$e->errorCode, $e->httpStatus]);
            }
        }
    }

    /** Náhled nového rozvrhu odmítne doklad, ze kterého založení rozvrhu nejde (výskyt jiného rozvrhu). */
    /**
     * Rozhodnutí uživatele 2A (20. 11. 2026): výskyt jiného rozvrhu se jako zdroj odmítne kódem
     * already_scheduled i s automatickým účtováním. Kontrola, zda doklad do rozvrhu už patří
     * (assertSchedulable), běží před kontrolou potvrzeného zdroje; dřív vracel nepotvrzený
     * koncept výskytu schedule_source_unconfirmed. Odmítnutí zůstává, mění se jen kód chyby.
     */
    public function testOccurrenceWithAutoPostIsRejectedAsAlreadyScheduled(): void
    {
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")->execute([$this->supplierId]);
        $source = $this->items->create($this->supplierId, $this->input(), null);
        $this->items->post($this->supplierId, (int) $source['id'], null);
        $schedule = $this->schedules->create($this->supplierId, (int) $source['id'], ['frequency' => 'monthly'], null);
        $occurrence = $this->schedules->generate($this->supplierId, (int) $schedule['id'], '2099-02-28', null)['created_ids'][0];
        self::assertSame('draft', $this->items->get($this->supplierId, $occurrence)['status']);
        $action = new OtherItemScheduleAction($this->schedules, $this->items);
        $response = $action->create($this->session(2)->withParsedBody(['frequency' => 'monthly', 'auto_post' => true]),
            new Response(), ['item_id' => $occurrence]);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('other_items.error.already_scheduled', json_decode((string) $response->getBody(), true)['error']['code']);
        self::assertCount(1, $this->schedules->list($this->supplierId));
    }

    public function testPreviewOfOccurrenceFailsLikeCreate(): void
    {
        $source = $this->items->create($this->supplierId, $this->input(), null);
        $schedule = $this->schedules->create($this->supplierId, (int) $source['id'], ['frequency' => 'monthly'], null);
        $occurrence = $this->schedules->generate($this->supplierId, (int) $schedule['id'], '2099-02-28', null)['created_ids'][0];
        try {
            $this->schedules->previewTitle($this->supplierId, $occurrence, ['frequency' => 'monthly']);
            self::fail('Výskyt jiného rozvrhu nesmí dostat náhled nového rozvrhu.');
        } catch (\MyInvoice\Service\Accounting\OtherItemException $e) {
            self::assertSame(['already_scheduled', 409], [$e->errorCode, $e->httpStatus]);
        }
    }

    /**
     * Kontrola délky projde všech dvanáct příštích výskytů: šablona přeteče jen v listopadu,
     * který je u výskytů od prosince dvanáctý (délka měsíce + dalšího měsíce + číslo je jen tam 18).
     */
    public function testLengthCheckCoversTwelfthOccurrence(): void
    {
        $source = $this->items->create($this->supplierId, $this->input([
            'issued_on' => '2099-11-15', 'due_on' => '2099-11-20',
        ]), null);
        $template = str_repeat('a', 238) . '{MMMM}{MMMM+1}{M}';
        try {
            $this->schedules->create($this->supplierId, (int) $source['id'], ['frequency' => 'monthly', 'title_template' => $template], null);
            self::fail('Listopad 2100 by přetekl 255 znaků.');
        } catch (\MyInvoice\Service\Accounting\OtherItemException $e) {
            self::assertSame('occurrence_title_too_long', $e->errorCode);
        }
        $schedule = $this->schedules->create($this->supplierId, (int) $source['id'],
            ['frequency' => 'monthly', 'ends_on' => '2100-10-31', 'title_template' => $template], null);
        self::assertSame($template, $schedule['template']['title_template']);
    }

    /** Vynechané pole úprava zachová; šablonu ruší až null nebo prázdný text. */
    public function testSetTitleKeepsOmittedSetting(): void
    {
        $source = $this->items->create($this->supplierId, $this->input(), null);
        $schedule = $this->schedules->create($this->supplierId, (int) $source['id'], [
            'frequency' => 'monthly', 'title_template' => 'Nájem {MM}', 'title_sync_period' => true,
        ], null);
        $this->schedules->setTitle($this->supplierId, (int) $schedule['id'], ['title_template' => 'Nájem {MM}/{YYYY}'], true);
        self::assertSame(['Nájem {MM}/{YYYY}', true], [$this->storedTemplate((int) $schedule['id'])['title_template'],
            $this->storedTemplate((int) $schedule['id'])['title_sync_period']]);
        $this->schedules->setTitle($this->supplierId, (int) $schedule['id'], ['title_sync_period' => false], true);
        self::assertSame('Nájem {MM}/{YYYY}', $this->storedTemplate((int) $schedule['id'])['title_template']);
        self::assertArrayNotHasKey('title_sync_period', $this->storedTemplate((int) $schedule['id']));
        $this->schedules->setTitle($this->supplierId, (int) $schedule['id'], ['title_template' => null], true);
        self::assertArrayNotHasKey('title_template', $this->storedTemplate((int) $schedule['id']));
    }

    /** Náhled nového rozvrhu kontroluje délku do zadaného konce, stejně jako založení. */
    public function testPreviewOfNewScheduleChecksLengthOnlyUntilEnd(): void
    {
        $source = $this->items->create($this->supplierId, $this->input([
            'issued_on' => '2099-12-15', 'due_on' => '2099-12-20',
        ]), null);
        $template = str_repeat('a', 243) . '{MMMM}{MMMM}';
        $preview = $this->schedules->previewTitle($this->supplierId, (int) $source['id'],
            ['frequency' => 'monthly', 'ends_on' => '2100-06-30', 'title_template' => $template]);
        self::assertSame('2100-01-15', $preview['issued_on']);
        try {
            $this->schedules->previewTitle($this->supplierId, (int) $source['id'], ['frequency' => 'monthly', 'title_template' => $template]);
            self::fail('Bez konce rozvrhu musí náhled odmítnout přetékající výskyt.');
        } catch (\MyInvoice\Service\Accounting\OtherItemException $e) {
            self::assertSame('occurrence_title_too_long', $e->errorCode);
        }
    }

    public function testPreviewKeepsStoredSettingsAndRejectsEndBeforeIssue(): void
    {
        $source = $this->items->create($this->supplierId, $this->input(), null);
        try {
            $this->schedules->previewTitle($this->supplierId, (int) $source['id'], ['frequency' => 'monthly', 'ends_on' => '2098-12-31']);
            self::fail('Konec před vznikem musí náhled odmítnout jako založení.');
        } catch (\MyInvoice\Service\Accounting\OtherItemException $e) {
            self::assertSame('invalid_end', $e->errorCode);
        }
        $schedule = $this->schedules->create($this->supplierId, (int) $source['id'], [
            'frequency' => 'monthly', 'title_template' => 'Nájem {MM} 01/2099', 'title_sync_period' => true,
        ], null);
        // Vynechaná šablona = uložená (jako PUT), zadaná volba srovnání se použije.
        self::assertSame('Nájem 02 01/2099', $this->schedules->previewTitle($this->supplierId, (int) $source['id'],
            ['title_sync_period' => false])['title']);
        self::assertSame('Nájem 02 02/2099', $this->schedules->previewTitle($this->supplierId, (int) $source['id'], [])['title']);
        self::assertSame('Syntetické nájemné', $this->schedules->previewTitle($this->supplierId, (int) $source['id'],
            ['title_template' => null, 'title_sync_period' => false])['title']);
        unset($schedule);
    }

    /**
     * Rada v dialogu: už vytvořený koncept přejmenovat ručně, ne smazat. Smazaný výskyt
     * rozvrh znovu nevytvoří (next_index se nevrací), období by z pohledávek zmizelo.
     */
    public function testDeletedOccurrenceIsNotRecreatedWithNewTemplate(): void
    {
        $source = $this->items->create($this->supplierId, $this->input(), null);
        $schedule = $this->schedules->create($this->supplierId, (int) $source['id'], ['frequency' => 'monthly'], null);
        $created = $this->schedules->generate($this->supplierId, (int) $schedule['id'], '2099-03-31', null)['created_ids'];
        $this->schedules->setTitle($this->supplierId, (int) $schedule['id'], ['title_template' => 'Nájem {MM}'], true);
        $this->items->deleteDraft($this->supplierId, $created[0]);
        $again = $this->schedules->generate($this->supplierId, (int) $schedule['id'], '2099-04-30', null);
        self::assertSame(['Nájem 04'], $this->titles($again['created_ids']));
    }

    /** Anonymizace klonu firmy šablonu nerozbije: zástupné znaky zůstanou platné. */
    public function testAnonymizedTemplateStaysValid(): void
    {
        $scrubber = new \MyInvoice\Service\Anonymization\TextScrubber(
            new \MyInvoice\Service\Anonymization\Pseudonymizer(str_repeat('synthetic-clone-', 3)));
        $json = json_encode(['title' => 'Nájem jan.novak@syntet-firma.cz', 'title_template' => 'Nájem {MMMM} {YYYY} jan.novak@syntet-firma.cz',
            'title_sync_period' => true], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $scrubbed = json_decode($scrubber->scrubJson($json), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('jan.novak', $scrubbed['title_template']);
        self::assertSame([], \MyInvoice\Service\Invoice\DescriptionPlaceholders::unknownTokens($scrubbed['title_template']));
        self::assertStringContainsString('{MMMM} {YYYY}', $scrubbed['title_template']);
        self::assertTrue($scrubbed['title_sync_period']);
    }

    public function testFinishedFlagMarksScheduleAfterEndEvenWhenPaused(): void
    {
        $source = $this->items->create($this->supplierId, $this->input(), null);
        $schedule = $this->schedules->create($this->supplierId, (int) $source['id'], ['frequency' => 'monthly', 'ends_on' => '2099-02-28'], null);
        self::assertFalse($schedule['finished']);
        $this->schedules->generate($this->supplierId, (int) $schedule['id'], '2099-12-31', null);
        $paused = $this->schedules->setStatus($this->supplierId, (int) $schedule['id'], 'paused');
        self::assertTrue($paused['finished']);
        self::assertNull($paused['next_on']);
        self::assertTrue($this->schedules->list($this->supplierId)[0]['finished']);
    }

    public function testPreviewRejectsInvalidQueryWith422(): void
    {
        $source = $this->items->create($this->supplierId, $this->input(), null);
        $action = new OtherItemScheduleAction($this->schedules, $this->items);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Test', 'staff', true, ['other_items' => 1], 'custom'));
        $args = ['item_id' => $source['id']];
        $ok = $action->titlePreview($request->withQueryParams(['frequency' => 'monthly', 'title_template' => '{MM}/{YYYY}',
            'title_sync_period' => 'false']), new Response(), $args);
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame('02/2099', json_decode((string) $ok->getBody(), true)['title']);
        foreach ([
            [['frequency' => 'monthly', 'title_template' => ['x']], 'title_template'],
            [['frequency' => 'monthly', 'title_sync_period' => 'možná'], 'title_sync_period'],
            [['frequency' => 'monthly', 'title_template' => '{X}'], 'title_template'],
            [['frequency' => 'weekly'], 'frequency'],
            [['frequency' => ['monthly']], 'frequency'],
            [['frequency' => 'monthly', 'title_template' => "\xFF{"], 'title_template'],
            [['frequency' => 'monthly', 'title_template' => "Nájem \xFF"], 'title_template'],
            [['frequency' => 'monthly', 'ends_on' => ['2099-01-01']], 'ends_on'],
            [['frequency' => 'monthly', 'ends_on' => '2099-02-30'], 'ends_on'],
            [['frequency' => 'monthly', 'ends_on' => '2098-12-31'], 'ends_on'],
        ] as [$query, $field]) {
            $response = $action->titlePreview($request->withQueryParams($query), new Response(), $args);
            self::assertSame(422, $response->getStatusCode());
            self::assertSame($field, json_decode((string) $response->getBody(), true)['error']['field']);
        }
    }

    private function session(int $level): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Test', 'staff', true,
                ['other_items' => $level, 'accounting.journal.post' => 2], 'custom'));
    }

    /** @param list<int> $ids @return list<string> */
    private function titles(array $ids): array
    {
        return array_map(fn (int $id) => (string) $this->items->get($this->supplierId, $id)['title'], $ids);
    }

    private function storedTemplate(int $scheduleId): array
    {
        $stmt = $this->pdo->prepare('SELECT template_json FROM other_item_schedules WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$this->supplierId, $scheduleId]);
        return json_decode((string) $stmt->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function input(array $changes = []): array
    {
        return array_replace([
            'side' => 'payable', 'kind' => 'rent', 'title' => 'Syntetické nájemné',
            'issued_on' => '2099-01-31', 'due_on' => '2099-02-05',
            'currency' => 'CZK', 'amount' => 1200, 'counter_account_code' => '518',
        ], $changes);
    }
}
