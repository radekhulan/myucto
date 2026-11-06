<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollTimeAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Payroll\Time\PayrollTimeService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * `GET /payroll/time/history` — historie docházky jednoho vztahu po měsících.
 *
 * Zúžený seznam (jeden vztah) se listoval měsíc po měsíci přepínáním období.
 * Historie to nahrazuje výpisem měsíců, takže musí platit tři věci: rozsah jde
 * od nástupu po dnešek, stránka má tvrdý strop, a čísla se SHODUJÍ s tím, co za
 * tentýž měsíc ukáže přehled. Poslední bod je tu ta podstatná pojistka — fond,
 * plán i skutečnost mají v obou cestách jediný zdroj a test padne, jakmile se
 * vzorec rozejde.
 */
#[Group('integration')]
final class PayrollTimeHistoryTest extends TestCase
{
    use IsolatedSupplierTrait;

    /** O kolik měsíců zpět vztah začíná; s aktuálním měsícem je to šest období. */
    private const MONTHS_BACK = 5;

    private Connection $db;
    private PayrollTimeService $time;
    private PayrollTimeAction $action;
    private int $supplierId;
    private int $otherSupplierId;
    private int $employmentId;
    private int $foreignEmploymentId;
    private int $userId;
    private string $startPeriod;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
            $this->db = $container->get(Connection::class);
            $this->time = $container->get(PayrollTimeService::class);
            $this->action = $container->get(PayrollTimeAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        foreach ([
            'payroll_employments',
            'payroll_work_calendars',
            'payroll_shifts',
            'payroll_time_entries',
            'payroll_time_months',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped("Chybí integrační tabulka {$table}.");
            }
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')
            ->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')
            ->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)')
            ->execute([$this->supplierId, $this->otherSupplierId]);

        $this->startPeriod = $this->monthsBack(self::MONTHS_BACK);
        $this->employmentId = $this->seedEmployment(
            $this->supplierId,
            'SYN-HIST-1',
            $this->startPeriod . '-01',
        );
        $this->foreignEmploymentId = $this->seedEmployment(
            $this->otherSupplierId,
            'SYN-HIST-2',
            $this->startPeriod . '-01',
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    /**
     * Bez `from`/`to` sahá historie od měsíce nástupu po aktuální měsíc a
     * vypisuje se od nejnovějšího.
     */
    public function testDefaultRangeCoversEveryMonthFromTheStartDateDescending(): void
    {
        $history = $this->time->history($this->supplierId, $this->employmentId, null, null);

        $expected = [];
        for ($back = 0; $back <= self::MONTHS_BACK; ++$back) {
            $expected[] = $this->monthsBack($back);
        }

        self::assertSame(self::MONTHS_BACK + 1, $history['total']);
        self::assertSame($expected, $this->periods($history));
        self::assertSame(
            ['from' => $this->startPeriod, 'to' => $this->monthsBack(0)],
            $history['range'],
            'Odpověď hlásí rozsah, který skutečně platil.',
        );
        self::assertSame($this->employmentId, (int) $history['employment']['id']);
    }

    /** Stránky se nepřekrývají, nepřetékají a `total` se posunem nemění. */
    public function testPagingWalksTheMonthsWithoutOverlap(): void
    {
        $first = $this->time->history($this->supplierId, $this->employmentId, null, null, 2, 0);
        $second = $this->time->history($this->supplierId, $this->employmentId, null, null, 2, 2);
        $last = $this->time->history($this->supplierId, $this->employmentId, null, null, 2, 4);
        $beyond = $this->time->history($this->supplierId, $this->employmentId, null, null, 2, 6);

        self::assertCount(2, $first['items']);
        self::assertCount(2, $second['items']);
        self::assertCount(2, $last['items']);
        self::assertSame([], $beyond['items'], 'Za koncem rozsahu je prázdno.');
        self::assertSame(self::MONTHS_BACK + 1, $second['total']);
        self::assertSame(
            [],
            array_intersect($this->periods($first), $this->periods($second)),
            'Stránky se nesmí překrývat.',
        );
        self::assertSame(
            [$this->monthsBack(0), $this->monthsBack(1)],
            $this->periods($first),
            'První stránka začíná nejnovějším měsícem.',
        );
    }

    /**
     * Strop stránky je tvrdý. Každý měsíc stojí několik dotazů, takže „vypiš
     * celou historii" nesmí jít objednat parametrem.
     */
    public function testPageCapCannotBeLiftedByAParameter(): void
    {
        $overLimit = $this->time->history(
            $this->supplierId,
            $this->employmentId,
            null,
            null,
            10_000,
            0,
        );

        self::assertSame(PayrollTimeService::HISTORY_MAX_LIMIT, $overLimit['limit']);
        self::assertLessThanOrEqual(
            PayrollTimeService::HISTORY_MAX_LIMIT,
            count($overLimit['items']),
        );

        $response = $this->action->history(
            $this->request('GET', '/api/payroll/time/history')->withQueryParams([
                'employment_id' => (string) $this->employmentId,
                'limit' => '10000',
            ]),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            PayrollTimeService::HISTORY_MAX_LIMIT,
            $this->json($response)['limit'],
            'Strop musí platit i na HTTP hranici.',
        );
    }

    /**
     * Cizí vztah je nenalezený, ne prázdný.
     *
     * Prázdná historie by potvrdila, že id v jiné firmě existuje — a vypadala
     * by jako „člověk zatím nic neodpracoval".
     */
    public function testForeignEmploymentIsNotFoundInsteadOfEmpty(): void
    {
        $response = $this->action->history(
            $this->request('GET', '/api/payroll/time/history')->withQueryParams([
                'employment_id' => (string) $this->foreignEmploymentId,
            ]),
            new Response(),
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('not_found', $this->json($response)['error']['code']);

        $this->expectException(\OutOfBoundsException::class);
        $this->time->history($this->supplierId, $this->foreignEmploymentId, null, null);
    }

    /**
     * Historie a přehled musí pro tentýž měsíc říct TOTÉŽ.
     *
     * Kdyby si historie počítala fond, plán nebo skutečnost po svém, rozejdou
     * se čísla na dvou obrazovkách téže evidence. Test proto porovnává celý
     * souhrn a zároveň trvá na tom, aby čísla byla netriviální — shoda nul by
     * neznamenala nic.
     */
    public function testHistoryNumbersMatchTheMonthOverviewExactly(): void
    {
        $period = $this->monthsBack(2);
        $this->seedCalendar();
        $this->seedWorkedMonth($period);

        $history = $this->time->history($this->supplierId, $this->employmentId, $period, $period);
        self::assertCount(1, $history['items']);
        $historySummary = $history['items'][0]['summary'];

        $overview = $this->time->overview(
            $this->supplierId,
            $period,
            false,
            25,
            0,
            $this->employmentId,
        );
        self::assertCount(1, $overview['items']);
        $overviewSummary = $overview['items'][0]['summary'];

        self::assertGreaterThan(0, $historySummary['fund_minutes'], 'Fond nesmí být nula.');
        self::assertSame(450, $historySummary['planned_minutes']);
        self::assertSame(420, $historySummary['actual_minutes']);
        self::assertSame(-30, $historySummary['difference_minutes']);
        self::assertSame($overviewSummary, $historySummary, 'Historie a přehled se nesmí rozejít.');
        self::assertSame(1, $history['items'][0]['shift_count']);
        self::assertSame(1, $history['items'][0]['entry_count']);
        self::assertSame(
            $overview['items'][0]['month']['status'],
            $history['items'][0]['month']['status'],
        );
    }

    /**
     * Měsíc mimo trvání vztahu, ve kterém přesto docházka je, ve výpisu chybět
     * nesmí — převzatá evidence z migrace takhle leží běžně.
     */
    public function testRangeStretchesOverMonthsThatCarryData(): void
    {
        $before = $this->monthsBack(self::MONTHS_BACK + 2);
        $this->seedMonthState($before);

        $history = $this->time->history($this->supplierId, $this->employmentId, null, null);

        self::assertSame($before, $history['range']['from']);
        self::assertSame(self::MONTHS_BACK + 3, $history['total']);
        self::assertContains($before, $this->periodsOfEveryPage());
    }

    /** @param array<string,mixed> $history @return list<string> */
    private function periods(array $history): array
    {
        $periods = [];
        foreach ((array) $history['items'] as $item) {
            self::assertIsArray($item);
            $periods[] = (string) $item['period'];
        }

        return $periods;
    }

    /** @return list<string> */
    private function periodsOfEveryPage(): array
    {
        $periods = [];
        for ($offset = 0; $offset < 24; $offset += PayrollTimeService::HISTORY_MAX_LIMIT) {
            $page = $this->time->history(
                $this->supplierId,
                $this->employmentId,
                null,
                null,
                PayrollTimeService::HISTORY_MAX_LIMIT,
                $offset,
            );
            $periods = [...$periods, ...$this->periods($page)];
            if (count($page['items']) < PayrollTimeService::HISTORY_MAX_LIMIT) {
                break;
            }
        }

        return $periods;
    }

    private function monthsBack(int $months): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Prague')))
            ->modify('first day of this month')
            ->modify("-{$months} months")
            ->format('Y-m');
    }

    private function seedEmployment(int $supplierId, string $code, string $startDate): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp", 1, 1, 0, 30000, 0, 1)'
        )->execute([$supplierId, 'Synteticka Osoba ' . $code]);
        $employeeId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date)
             VALUES (?, ?, ?, "employment", "active", ?, ?)'
        )->execute([$supplierId, $employeeId, $code, $startDate, $startDate]);

        return (int) $pdo->lastInsertId();
    }

    private function seedCalendar(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_work_calendars
                (supplier_id, employment_id, name, timezone_name, schedule_type,
                 week_pattern, weekly_minutes, valid_from, valid_to)
             VALUES (?, ?, "Syntetický pravidelný týden", "Europe/Prague", "regular",
                     ?, 2400, ?, NULL)'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            '{"1":480,"2":480,"3":480,"4":480,"5":480,"6":0,"7":0}',
            $this->startPeriod . '-01',
        ]);
    }

    /** Jedna publikovaná směna (450 min) a jeden zápis (420 min) v měsíci. */
    private function seedWorkedMonth(string $period): void
    {
        $shift = $this->time->saveShift($this->supplierId, [
            'employment_id' => $this->employmentId,
            'starts_at' => $this->wallTime($period, '08:00:00'),
            'ends_at' => $this->wallTime($period, '16:00:00'),
            'timezone' => 'Europe/Prague',
            'break_minutes' => 30,
            'remote_work' => false,
            'standby_minutes' => 0,
            'publish' => true,
            'supersedes_id' => null,
            'calendar_id' => null,
            'row_version' => 0,
            'month_row_version' => 0,
        ], $this->userId);

        $this->time->saveEntry($this->supplierId, [
            'employment_id' => $this->employmentId,
            'category' => 'regular',
            'starts_at' => $this->wallTime($period, '08:00:00'),
            'ends_at' => $this->wallTime($period, '15:30:00'),
            'timezone' => 'Europe/Prague',
            'break_minutes' => 30,
            'supersedes_id' => null,
            'row_version' => 0,
            'month_row_version' => (int) $shift['month']['row_version'],
        ], $this->userId);
    }

    private function seedMonthState(string $period): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_time_months
                (supplier_id, employment_id, period_start, status, revision_no, row_version)
             VALUES (?, ?, ?, 'open', 1, 0)"
        )->execute([$this->supplierId, $this->employmentId, $period . '-01']);
    }

    /** Desátý den měsíce s posunem platným právě ten den (letní i zimní čas). */
    private function wallTime(string $period, string $time): string
    {
        return (new \DateTimeImmutable(
            $period . '-10 ' . $time,
            new \DateTimeZone('Europe/Prague'),
        ))->format('Y-m-d\TH:i:sP');
    }

    private function request(string $method, string $uri): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
