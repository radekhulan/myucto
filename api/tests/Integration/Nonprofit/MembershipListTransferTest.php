<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Nonprofit;

use MyInvoice\Action\Nonprofit\MembershipAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Nonprofit\Membership\MembershipRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Nonprofit\Membership\MembershipException;
use MyInvoice\Service\Nonprofit\Membership\MembershipImportService;
use MyInvoice\Service\Nonprofit\Membership\MembershipListColumns;
use MyInvoice\Service\Nonprofit\Membership\MembershipListExporter;
use MyInvoice\Service\Nonprofit\Membership\MembershipListFilter;
use MyInvoice\Service\Nonprofit\Membership\MembershipRevisionTimeline;
use MyInvoice\Support\Spreadsheet\SafeSpreadsheetReader;
use MyInvoice\Tests\Support\MembershipBatchReviewFixture;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;
use Slim\Psr7\UploadedFile;

/** Export seznamu členů a import změn stavu a nových členů zpět (#321, krok 8). */
final class MembershipListTransferTest extends MembershipBatchReviewFixture
{
    private const HEADER = 'Členské číslo;ID kontaktu;IČO;Jméno / název;Nový stav;Platí od;Podklad;Doložení';

    private MembershipImportService $import;
    private MembershipRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new MembershipRepository($this->db);
        $this->import = new MembershipImportService($this->db, new ActivityLogger($this->db), $this->members);
    }

    public function testExportHasOnlyMinimalColumnsTodayStatusAndDuesOfFilteredTitle(): void
    {
        $planned = $this->member('EXP-PLAN');
        // Budoucí ukončení nemění dnešní stav (seznam přitom ukazuje poslední platnou revizi).
        $this->members->appendRevision($this->supplier, $planned, ['status' => 'ended', 'effective_from' => '2026-09-01', 'expected_revision' => 1,
            'idempotency_key' => 'exp-plan-end', 'legal_basis' => $this->basis()], $this->actor);
        $charged = $this->member('EXP-CHARGE');
        $request = ['member_id' => $charged] + $this->template;
        $preview = $this->charges->previewIssue($this->supplier, $request);
        $this->charges->confirmIssue($this->supplier, $request, $preview['expected_case_version'], $preview['preview_hash'], 'issue-exp-charge', $this->actor);

        $headers = MembershipListColumns::headers();
        foreach (['adres', 'mail', 'telefon', 'ulice', 'phone'] as $personal) {
            foreach ($headers as $header) self::assertStringNotContainsStringIgnoringCase($personal, $header);
        }
        $rows = $this->exportRows(['year' => '2026']);
        self::assertSame(['active', '2026-01-01', 0], [$rows['SYN-EXP-PLAN']['Dnešní stav'], $rows['SYN-EXP-PLAN']['Stav od'], $rows['SYN-EXP-PLAN']['Počet předpisů']]);
        $row = $rows['SYN-EXP-CHARGE'];
        self::assertSame([1, 500.0, 0.0, 500.0, 'unpaid', 'synthetic-review 2026-01-01–2026-12-31', 500.0],
            [$row['Počet předpisů'], $row['Předepsáno'], $row['Uhrazeno'], $row['Zbývá'], $row['Stav úhrady'], $row['Poslední předpis'], $row['Částka posledního předpisu']]);
        foreach (['Nový stav', 'Platí od', 'Podklad', 'Doložení', 'IČO'] as $empty) self::assertSame('', $row[$empty]);
        // Jiný rok: bez předpisu, poslední předpis prázdný.
        self::assertSame(['none', ''], [$this->exportRows(['year' => '2025'])['SYN-EXP-CHARGE']['Stav úhrady'], $this->exportRows(['year' => '2025'])['SYN-EXP-CHARGE']['Poslední předpis']]);
        // Filtr stavu úhrady platí jako v seznamu.
        self::assertSame(['SYN-EXP-CHARGE'], array_keys($this->exportRows(['year' => '2026', 'payment_status' => 'unpaid'])));
    }

    public function testExportFileRoundTripsThroughImportAsUnchanged(): void
    {
        $this->member('ROUND-A');
        $this->member('ROUND-B');
        // Číslo začínající „=“ CSV chrání apostrofem (CsvWriter::safe) a „042“ si v XLSX drží nulu; import je musí poznat.
        $this->members->create($this->supplier, $this->client('round-eq', 'Synthetic Formula Number', null), '=042', $this->actor);
        foreach (['xlsx', 'csv'] as $format) {
            $file = (new MembershipListExporter($this->db, '2026-06-01'))->export($this->supplier, MembershipListFilter::parse(['year' => '2026']), $format);
            self::assertSame('clenove-2026-06-01.' . $format, $file['filename']);
            $report = $this->import->run($this->supplier, $this->actor, $file['bytes'], $file['filename'], true);
            self::assertSame(['new_revision' => 0, 'new_member' => 0, 'unchanged' => 3, 'error' => 0], $report['counts'], $format);
            self::assertSame(['no_change', 'no_change', 'no_change'], array_column($report['rows'], 'reason'));
            self::assertContains('=042', array_column($report['rows'], 'member_number'), $format);
        }
    }

    public function testPreviewClassifiesEveryRowAndNeverMatchesByName(): void
    {
        $active = $this->member('PRE-ACTIVE');
        $this->member('PRE-SAME');
        $this->member('PRE-EARLY');
        $this->member('PRE-NOCHANGE');
        $mismatch = $this->member('PRE-MISMATCH');
        $this->member('PRE-ICMIS');
        $freeClient = $this->client('pre-free', 'Synthetic Free Contact', null);
        $memberClient = $this->repository->member($this->supplier, $mismatch)['client_id'];
        $this->client('pre-ic-a', 'Synthetic IC A', '12345679');
        $this->client('pre-ic-b', 'Synthetic IC B', '12345679');
        $this->client('pre-ic-one', 'Synthetic IC One', '00012345');
        $this->client('pre-same-name', 'Synthetic Same Name', null);
        $this->members->create($this->supplier, $this->client('pre-bare', 'Synthetic Bare Member', null), 'SYN-PRE-BARE', $this->actor);
        $csv = $this->csv([
            'SYN-PRE-ACTIVE;;;Ignored Name;pozastavené;1. 3. 2026;Synthetic board decision;',
            'syn-pre-same;;;;active;2026-04-01;Synthetic decision;',
            'SYN-PRE-EARLY;;;;ended;2025-12-31;Synthetic decision;',
            'SYN-PRE-NOCHANGE;;;Renamed Person;;;;',
            'SYN-PRE-MISMATCH;' . $freeClient . ';;;ended;2026-05-01;Synthetic decision;',
            'SYN-PRE-ICMIS;;12345679;;ended;2026-05-01;Synthetic decision;',
            'NEW-NAME;;;Synthetic Same Name;active;2026-02-01;Synthetic application;Synthetic signed application',
            'NEW-CLIENT;' . $freeClient . ';;;active;2026-02-01;Synthetic application;',
            'NEW-IC;;12345;;active;2026-02-01;Synthetic application;',
            'NEW-AMBIG;;12345679;;active;2026-02-01;Synthetic application;',
            'NEW-TAKEN;' . $memberClient . ';;;active;2026-02-01;Synthetic application;',
            'NEW-SUSP;;;Synthetic Person;suspended;2026-02-01;Synthetic application;',
            'NEW-NOSTATUS;;;Synthetic Person;;;;',
            'NEW-BADSTATUS;;;Synthetic Person;member;2026-02-01;Synthetic application;',
            'NEW-BADDATE;;;Synthetic Person;active;2026-02-31;Synthetic application;',
            'NEW-NOREF;;;Synthetic Person;active;2026-02-01;;',
            'NEW-NONAME;;;;active;2026-02-01;Synthetic application;',
            'DUP-1;;;Synthetic Dup;active;2026-02-01;Synthetic application;',
            'dup-1;;;Synthetic Dup;active;2026-02-01;Synthetic application;',
            'NEW-GHOST;999999999;;;active;2026-02-01;Synthetic application;',
            'NEW-CLIENTIC;' . $freeClient . ';99999999;;active;2026-02-01;Synthetic application;',
            'SYN-PRE-BARE;;;;suspended;2026-02-01;Synthetic decision;',
        ]);
        $report = $this->import->run($this->supplier, $this->actor, $csv, 'clenove.csv', true, canCreateContacts: true);
        $byNumber = [];
        foreach ($report['rows'] as $row) $byNumber[$row['member_number']] = [$row['status'], $row['reason'] ?? null];
        self::assertSame([
            'SYN-PRE-ACTIVE' => ['new_revision', null],
            'syn-pre-same' => ['unchanged', 'same_status'],
            'SYN-PRE-EARLY' => ['error', 'effective_conflict'],
            'SYN-PRE-NOCHANGE' => ['unchanged', 'no_change'],
            'SYN-PRE-MISMATCH' => ['error', 'contact_mismatch'],
            'SYN-PRE-ICMIS' => ['error', 'contact_mismatch'],
            'NEW-NAME' => ['new_member', null],
            'NEW-CLIENT' => ['new_member', null],
            'NEW-IC' => ['new_member', null],
            'NEW-AMBIG' => ['error', 'ic_ambiguous'],
            'NEW-TAKEN' => ['error', 'client_is_member'],
            'NEW-SUSP' => ['error', 'initial_status'],
            'NEW-NOSTATUS' => ['error', 'new_member_needs_change'],
            'NEW-BADSTATUS' => ['error', 'status_invalid'],
            'NEW-BADDATE' => ['error', 'date_invalid'],
            'NEW-NOREF' => ['error', 'reference_missing'],
            'NEW-NONAME' => ['error', 'name_missing'],
            'DUP-1' => ['error', 'duplicate_number'],
            'dup-1' => ['error', 'duplicate_number'],
            'NEW-GHOST' => ['error', 'client_not_found'],
            'NEW-CLIENTIC' => ['error', 'contact_mismatch'],
            'SYN-PRE-BARE' => ['error', 'initial_status'],
        ], $byNumber);
        $rows = array_column($report['rows'], null, 'member_number');
        self::assertSame(['member_id' => $active, 'new_status' => 'suspended', 'effective_from' => '2026-03-01'],
            array_intersect_key($rows['SYN-PRE-ACTIVE'], array_flip(['member_id', 'new_status', 'effective_from'])));
        // Stejné jméno jako existující kontakt kontakt nezíská: založí se nový (podle jména se nepáruje).
        self::assertSame(['create', null], [$rows['NEW-NAME']['contact'], $rows['NEW-NAME']['client_id']]);
        self::assertSame(['existing', $freeClient], [$rows['NEW-CLIENT']['contact'], $rows['NEW-CLIENT']['client_id']]);
        self::assertSame('existing', $rows['NEW-IC']['contact'], 'IČO z buňky čísla bez úvodních nul se doplní na 8 míst.');
        self::assertSame(['date' => '2026-01-01'], $rows['SYN-PRE-EARLY']['params']);
        self::assertFalse($report['ok']);
        self::assertSame(['new_revision' => 1, 'new_member' => 3, 'unchanged' => 2, 'error' => 16], $report['counts']);
    }

    public function testConfirmWritesRowsAndRepeatedImportByAnotherUserChangesNothing(): void
    {
        $existing = $this->member('CONF-A');
        $client = $this->client('conf-client', 'Synthetic Confirm Contact', null);
        $csv = $this->csv([
            'SYN-CONF-A;;;;ended;2026-06-30;Synthetic board decision;Synthetic minutes',
            'CONF-NEW;;87654321;Synthetic New Person;active;2026-03-01;Synthetic application;',
            'CONF-LINK;' . $client . ';;;aktivní;15.3.2026;Synthetic application;',
        ]);
        $clientsBefore = $this->clientCount();
        $preview = $this->import->run($this->supplier, $this->actor, $csv, 'clenove.csv', true, canCreateContacts: true);
        self::assertTrue($preview['ok']);
        self::assertSame($clientsBefore, $this->clientCount(), 'Náhled nic nezapisuje.');
        $result = $this->import->run($this->supplier, $this->actor, $csv, 'clenove.csv', false, $preview['preview_hash'], canCreateContacts: true);
        self::assertSame([3, 0], [$result['written'], $result['failed_writes']]);
        self::assertSame(['written', 'written', 'written'], array_column($result['rows'], 'outcome'));

        $history = $this->repository->history($this->supplier, $existing);
        $last = $history[array_key_last($history)];
        self::assertSame(['ended', '2026-06-30', 'Synthetic board decision', 'Synthetic minutes'],
            [$last['status'], $last['effective_from'], $last['legal_basis']['reference'], $last['legal_basis']['evidence']]);
        self::assertSame($clientsBefore + 1, $this->clientCount(), 'Kontakt se zakládá jen pro řádek bez ID a známého IČO.');
        $new = $this->memberByNumber('CONF-NEW');
        $q = $this->pdo->prepare('SELECT company_name,ic,is_vat_payer FROM clients WHERE supplier_id=? AND id=?');
        $q->execute([$this->supplier, $new['client_id']]);
        self::assertSame(['company_name' => 'Synthetic New Person', 'ic' => '87654321', 'is_vat_payer' => 0], array_map(static fn ($v) => is_numeric($v) && strlen((string) $v) === 1 ? (int) $v : $v, $q->fetch(\PDO::FETCH_ASSOC)));
        $newHistory = $this->repository->history($this->supplier, $new['id']);
        self::assertSame(['active', '2026-03-01', 'Synthetic application'], [$newHistory[0]['status'], $newHistory[0]['effective_from'], $newHistory[0]['legal_basis']['evidence']],
            'Prázdné doložení převezme podklad.');
        self::assertSame($client, $this->memberByNumber('CONF-LINK')['client_id']);
        self::assertSame(1, $this->auditCount(MembershipImportService::AUDIT_IMPORT));
        self::assertSame(1, $this->auditCount('client.created'));

        // Totéž znovu jiným uživatelem: vše beze změny, nic se nezdvojí a nepíše.
        $other = $this->otherActor();
        $again = $this->import->run($this->supplier, $other, $csv, 'clenove.csv', true, canCreateContacts: true);
        self::assertSame(['new_revision' => 0, 'new_member' => 0, 'unchanged' => 3, 'error' => 0], $again['counts']);
        $repeat = $this->import->run($this->supplier, $other, $csv, 'clenove.csv', false, $again['preview_hash'], canCreateContacts: true);
        self::assertSame(0, $repeat['written']);
        self::assertCount(count($history), $this->repository->history($this->supplier, $existing));
        self::assertSame($clientsBefore + 1, $this->clientCount());
        self::assertSame(1, $this->auditCount(MembershipImportService::AUDIT_IMPORT), 'Import bez zápisu audit nepřidá.');
    }

    public function testRowImportedEarlierIsUnchangedByItsKeyEvenAfterLaterRevision(): void
    {
        $member = $this->member('KEY-A');
        $csv = $this->csv(['SYN-KEY-A;;;;suspended;2026-03-01;Synthetic decision;']);
        $preview = $this->import->run($this->supplier, $this->actor, $csv, 'a.csv', true);
        $this->import->run($this->supplier, $this->actor, $csv, 'a.csv', false, $preview['preview_hash']);
        $this->members->appendRevision($this->supplier, $member, ['status' => 'active', 'effective_from' => '2026-05-01', 'expected_revision' => 2,
            'idempotency_key' => 'key-a-back', 'legal_basis' => $this->basis()], $this->actor);
        // Bez kontroly klíče by starý soubor hlásil chybu data nebo by se pokusil revizi zopakovat.
        $again = $this->import->run($this->supplier, $this->actor, $csv, 'a.csv', true);
        self::assertSame([['unchanged', 'already_imported']], array_map(static fn (array $r): array => [$r['status'], $r['reason']], $again['rows']));
    }

    public function testConfirmRejectsErrorsAndChangedPreviewWithoutWriting(): void
    {
        $member = $this->member('REJ-A');
        $bad = $this->csv(['SYN-REJ-A;;;;suspended;2026-03-01;Synthetic decision;', 'NEW-X;;;;active;2026-02-01;Synthetic application;']);
        $preview = $this->import->run($this->supplier, $this->actor, $bad, 'a.csv', true);
        $error = $this->rejected(fn () => $this->import->run($this->supplier, $this->actor, $bad, 'a.csv', false, $preview['preview_hash']));
        self::assertSame(['nonprofit_membership_import_has_errors', 422], [$error->errorCode, $error->httpStatus]);
        self::assertCount(1, $this->repository->history($this->supplier, $member), 'Ani bezchybný řádek se nezapíše.');

        $good = $this->csv(['SYN-REJ-A;;;;suspended;2026-03-01;Synthetic decision;']);
        $preview = $this->import->run($this->supplier, $this->actor, $good, 'a.csv', true);
        // Mezi náhledem a potvrzením někdo zapíše revizi: náhled už neplatí.
        $this->members->appendRevision($this->supplier, $member, ['status' => 'suspended', 'effective_from' => '2026-02-01', 'expected_revision' => 1,
            'idempotency_key' => 'rej-a-other', 'legal_basis' => $this->basis()], $this->actor);
        $error = $this->rejected(fn () => $this->import->run($this->supplier, $this->actor, $good, 'a.csv', false, $preview['preview_hash']));
        self::assertSame(['nonprofit_membership_import_changed', 409], [$error->errorCode, $error->httpStatus]);
        self::assertSame('unchanged', $error->details['preview']['rows'][0]['status']);
        self::assertCount(2, $this->repository->history($this->supplier, $member));
        $missing = $this->rejected(fn () => $this->import->run($this->supplier, $this->actor, $good, 'a.csv', false, null));
        self::assertSame('nonprofit_membership_invalid', $missing->errorCode);
    }

    public function testMissingRequiredColumnIsFileError(): void
    {
        $error = $this->rejected(fn () => $this->import->run($this->supplier, $this->actor, "Členské číslo;Jméno / název\nA;B\n", 'a.csv', true));
        self::assertSame(['nonprofit_membership_import_file_invalid', 422], [$error->errorCode, $error->httpStatus]);
    }

    public function testRoutesUseListReadForExportAndJournalWriteForImport(): void
    {
        $this->member('ROUTE-A');
        [$status, $headers, $body] = $this->call('GET', 'exportMembers', ['year' => '2026', 'format' => 'csv'], ['accounting' => 1]);
        self::assertSame(200, $status);
        self::assertStringContainsString('attachment; filename="clenove-', $headers['Content-Disposition'][0]);
        self::assertStringContainsString('SYN-ROUTE-A', $body);
        self::assertSame(403, $this->call('GET', 'exportMembers', ['year' => '2026'], ['accounting' => 1], 'bearer')[0]);
        self::assertSame(403, $this->call('GET', 'exportMembers', ['year' => '2026'], [])[0]);
        self::assertSame(422, $this->call('GET', 'exportMembers', ['year' => '2026', 'format' => 'pdf'], ['accounting' => 1])[0]);
        self::assertSame(422, $this->call('GET', 'exportMembers', ['year' => '2026', 'limit' => '5'], ['accounting' => 1])[0]);

        $csv = $this->csv(['SYN-ROUTE-A;;;;ended;2026-07-01;Synthetic decision;']);
        self::assertSame(403, $this->call('POST', 'importMembers', [], ['accounting' => 1], 'session', $csv)[0], 'Čtení nestačí ani na náhled importu.');
        [$status, , $body] = $this->call('POST', 'importMembers', [], null, 'bearer', $csv);
        self::assertSame([403, 'session_required'], [$status, json_decode($body, true)['error']['code']]);
        [$status, , $body] = $this->call('POST', 'importMembers', [], null, 'session', $csv);
        self::assertSame(200, $status);
        $preview = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        [$status, , $body] = $this->call('POST', 'importMembers', ['dry_run' => '0', 'preview_hash' => $preview['preview_hash']], null, 'session', $csv);
        self::assertSame(200, $status, $body);
        self::assertSame(1, json_decode($body, true, flags: JSON_THROW_ON_ERROR)['written']);
        self::assertSame(415, $this->call('POST', 'importMembers', [], null, 'session', $csv, 'clenove.pdf')[0]);
        self::assertSame(415, $this->call('POST', 'importMembers', [], null, 'session', 'PK not a zip', 'clenove.xlsx')[0]);
        self::assertSame(422, $this->call('POST', 'importMembers', ['dry_run' => '1', 'extra' => 'x'], null, 'session', $csv)[0]);
    }

    public function testRowChangedDuringConfirmIsRejectedAndOtherRowsAreWritten(): void
    {
        $first = $this->member('RACE-A');
        $second = $this->member('RACE-B');
        $csv = $this->csv(['SYN-RACE-A;;;;suspended;2026-03-01;Synthetic decision;', 'SYN-RACE-B;;;;suspended;2026-03-01;Synthetic decision;']);
        $preview = $this->import->run($this->supplier, $this->actor, $csv, 'a.csv', true);
        // Mezi kontrolou náhledu a zápisem prvního řádku někdo prvnímu členovi připíše revizi.
        $racing = new MembershipImportService($this->db, new ActivityLogger($this->db), $this->members, function (array $plan) use ($first): void {
            if (($plan['member_id'] ?? null) !== $first) return;
            $this->members->appendRevision($this->supplier, $first, ['status' => 'ended', 'effective_from' => '2026-02-01', 'expected_revision' => 1,
                'idempotency_key' => 'race-a-other', 'legal_basis' => $this->basis()], $this->actor);
        });
        $result = $racing->run($this->supplier, $this->actor, $csv, 'a.csv', false, $preview['preview_hash']);
        self::assertSame([1, 1], [$result['written'], $result['failed_writes']]);
        self::assertSame([['failed', 'nonprofit_membership_revision_changed'], ['written', null]],
            array_map(static fn (array $row): array => [$row['outcome'], $row['failure'] ?? null], $result['rows']));
        self::assertSame('ended', $this->lastStatus($first));
        self::assertSame('suspended', $this->lastStatus($second));
        self::assertSame(1, $this->auditCount(MembershipImportService::AUDIT_IMPORT));
    }

    public function testExportLastClaimSkipsReversedCharge(): void
    {
        $member = $this->member('EXP-REV');
        $request = ['member_id' => $member] + $this->template;
        $preview = $this->charges->previewIssue($this->supplier, $request);
        $issued = $this->charges->confirmIssue($this->supplier, $request, $preview['expected_case_version'], $preview['preview_hash'], 'issue-exp-rev', $this->actor);
        self::assertSame('synthetic-review 2026-01-01–2026-12-31', $this->exportRows(['year' => '2026'])['SYN-EXP-REV']['Poslední předpis']);
        $reverse = ['case_id' => $issued['case']['id'], 'accounting_date' => '2026-02-01', 'reason' => 'Synthetic mistaken charge'];
        $reversePreview = $this->charges->previewReverse($this->supplier, $reverse);
        $this->charges->confirmReverse($this->supplier, $reverse, $reversePreview['expected_case_version'], $reversePreview['preview_hash'], 'reverse-exp-rev', $this->actor);
        self::assertSame('', $this->exportRows(['year' => '2026'])['SYN-EXP-REV']['Poslední předpis'], 'Stornovaný předpis není poslední platný předpis.');
    }

    private function lastStatus(int $member): string
    {
        $valid = \MyInvoice\Service\Nonprofit\Membership\MembershipRevisionTimeline::effective($this->repository->history($this->supplier, $member));
        return $valid[array_key_last($valid)]['status'];
    }

    public function testContactCreationNeedsDirectoryPermission(): void
    {
        $csv = $this->csv(['PERM-NEW;;;Synthetic Permission Person;active;2026-02-01;Synthetic application;']);
        $before = $this->clientCount();
        $denied = $this->import->run($this->supplier, $this->actor, $csv, 'a.csv', true);
        self::assertSame([['error', 'contact_create_forbidden']], array_map(static fn (array $r): array => [$r['status'], $r['reason']], $denied['rows']));
        $this->rejected(fn () => $this->import->run($this->supplier, $this->actor, $csv, 'a.csv', false, $denied['preview_hash']));
        self::assertSame($before, $this->clientCount());
        // Akce rozhoduje podle práva clients.create (RequestAuthorization), ne podle práva zápisu členské evidence.
        [, , $body] = $this->call('POST', 'importMembers', [], ['accounting' => 1, 'accounting.journal.post' => 2], 'session', $csv);
        self::assertSame('contact_create_forbidden', json_decode($body, true)['rows'][0]['reason']);
        [, , $body] = $this->call('POST', 'importMembers', [], ['accounting' => 1, 'accounting.journal.post' => 2, 'clients.create' => 2], 'session', $csv);
        self::assertSame('new_member', json_decode($body, true)['rows'][0]['status']);
    }

    public function testSameUnknownIcForTwoNewMembersIsDuplicateAndArchivedContactIsSkipped(): void
    {
        $active = $this->client('ic-active', 'Synthetic Active IC', '23456789');
        $archived = $this->client('ic-archived', 'Synthetic Archived IC', '23456789');
        $this->pdo->prepare('UPDATE clients SET archived_at=NOW() WHERE supplier_id=? AND id=?')->execute([$this->supplier, $archived]);
        $onlyArchived = $this->client('ic-only-archived', 'Synthetic Only Archived', '34567890');
        $this->pdo->prepare('UPDATE clients SET archived_at=NOW() WHERE supplier_id=? AND id=?')->execute([$this->supplier, $onlyArchived]);
        $csv = $this->csv([
            'DUPIC-1;;11111111;Synthetic First;active;2026-02-01;Synthetic application;',
            'DUPIC-2;;11111111;Synthetic Second;active;2026-02-01;Synthetic application;',
            'ACTIVE-IC;;23456789;;active;2026-02-01;Synthetic application;',
            'ARCH-IC;;34567890;Synthetic Fresh;active;2026-02-01;Synthetic application;',
        ]);
        $rows = array_column($this->import->run($this->supplier, $this->actor, $csv, 'a.csv', true, canCreateContacts: true)['rows'], null, 'member_number');
        self::assertSame(['error', 'duplicate_client'], [$rows['DUPIC-1']['status'], $rows['DUPIC-1']['reason']]);
        self::assertSame(['error', 'duplicate_client'], [$rows['DUPIC-2']['status'], $rows['DUPIC-2']['reason']]);
        self::assertSame(['new_member', 'existing', $active], [$rows['ACTIVE-IC']['status'], $rows['ACTIVE-IC']['contact'], $rows['ACTIVE-IC']['client_id']],
            'Archivovaný kontakt se stejným IČO nedělá IČO nejednoznačným.');
        self::assertSame(['new_member', 'create'], [$rows['ARCH-IC']['status'], $rows['ARCH-IC']['contact']], 'Jen archivovaný kontakt nového člena nedostane.');
    }

    public function testEarlierSameStatusThanPlannedRevisionIsConflictAndExcelYearIsNotDate(): void
    {
        $member = $this->member('PLAN-END');
        $this->members->appendRevision($this->supplier, $member, ['status' => 'ended', 'effective_from' => '2027-01-01', 'expected_revision' => 1,
            'idempotency_key' => 'plan-end', 'legal_basis' => $this->basis()], $this->actor);
        $this->member('SERIAL');
        $csv = $this->csv([
            'SYN-PLAN-END;;;;ended;2026-12-01;Synthetic expulsion;',
            'SERIAL-YEAR;;;Synthetic Year;active;2026;Synthetic application;',
            'SERIAL-OLD;;;Synthetic Old;active;10000;Synthetic application;',
            'SYN-SERIAL;;;;suspended;46113;Synthetic decision;',
        ]);
        $rows = array_column($this->import->run($this->supplier, $this->actor, $csv, 'a.csv', true, canCreateContacts: true)['rows'], null, 'member_number');
        self::assertSame(['error', 'effective_conflict', ['date' => '2027-01-01']], [$rows['SYN-PLAN-END']['status'], $rows['SYN-PLAN-END']['reason'], $rows['SYN-PLAN-END']['params']],
            'Plánované ukončení od 1. 1. nesmí tiše pohltit ukončení od 1. 12.');
        self::assertSame(['error', 'date_invalid'], [$rows['SERIAL-YEAR']['status'], $rows['SERIAL-YEAR']['reason']]);
        self::assertSame(['error', 'date_invalid'], [$rows['SERIAL-OLD']['status'], $rows['SERIAL-OLD']['reason']], 'Sériové číslo 10000 je rok 1927, mimo rozsah.');
        self::assertSame(['new_revision', '2026-04-01'], [$rows['SYN-SERIAL']['status'], $rows['SYN-SERIAL']['effective_from']], 'Pětimístné sériové číslo Excelu je datum.');
        $later = $this->import->run($this->supplier, $this->actor, $this->csv(['SYN-PLAN-END;;;;ended;2027-02-01;Synthetic expulsion;']), 'a.csv', true);
        self::assertSame([['unchanged', 'same_status']], array_map(static fn (array $r): array => [$r['status'], $r['reason']], $later['rows']));
    }

    public function testConcurrentImportOfSameRowIsAlreadyImportedNotFailed(): void
    {
        $member = $this->member('TWIN-A');
        $csv = $this->csv(['SYN-TWIN-A;;;;suspended;2026-03-01;Synthetic decision;']);
        $preview = $this->import->run($this->supplier, $this->actor, $csv, 'a.csv', true);
        $other = $this->otherActor();
        // Druhý uživatel potvrdí stejný soubor těsně před námi: stejný klíč, jiný autor.
        $racing = new MembershipImportService($this->db, new ActivityLogger($this->db), $this->members, function (array $plan) use ($other): void {
            $this->members->appendRevision($this->supplier, $plan['member_id'], ['status' => $plan['status'], 'effective_from' => $plan['effective_from'], 'expected_revision' => $plan['expected_revision'],
                'idempotency_key' => $plan['idempotency_key'], 'legal_basis' => ['organisation_form' => 'association', 'relationship' => 'nonreciprocal_membership',
                    'reference' => $plan['reference'], 'evidence' => $plan['evidence']]], $other);
        });
        $result = $racing->run($this->supplier, $this->actor, $csv, 'a.csv', false, $preview['preview_hash']);
        self::assertSame([0, 0, 'already_imported'], [$result['written'], $result['failed_writes'], $result['rows'][0]['outcome']]);
        self::assertCount(2, $this->repository->history($this->supplier, $member));
    }

    public function testUnexpectedFailureMidImportStillAuditsWrittenRows(): void
    {
        $this->member('BOOM-A');
        $this->member('BOOM-B');
        $csv = $this->csv(['SYN-BOOM-A;;;;suspended;2026-03-01;Synthetic decision;', 'SYN-BOOM-B;;;;suspended;2026-03-01;Synthetic decision;']);
        $preview = $this->import->run($this->supplier, $this->actor, $csv, 'a.csv', true);
        $calls = 0;
        $failing = new MembershipImportService($this->db, new ActivityLogger($this->db), $this->members, function () use (&$calls): void {
            if (++$calls === 2) throw new \RuntimeException('Synthetic infrastructure failure');
        });
        try {
            $failing->run($this->supplier, $this->actor, $csv, 'a.csv', false, $preview['preview_hash']);
            self::fail('Nečekaná chyba se nesmí spolknout.');
        } catch (\RuntimeException $e) {
            self::assertSame('Synthetic infrastructure failure', $e->getMessage());
        }
        $q = $this->pdo->prepare('SELECT payload FROM activity_log WHERE supplier_id=? AND action=?');
        $q->execute([$this->supplier, MembershipImportService::AUDIT_IMPORT]);
        self::assertSame(['new_members' => 0, 'new_revisions' => 1, 'failed' => 0], json_decode((string) $q->fetchColumn(), true));
    }

    public function testTooManyRowsHasOwnCodeAndCsvCountIsWholeNumber(): void
    {
        $lines = [];
        for ($i = 1; $i <= 2001; $i++) $lines[] = "BIG-{$i};;;Synthetic Big {$i};active;2026-02-01;Synthetic application;";
        [$status, , $body] = $this->call('POST', 'importMembers', [], null, 'session', $this->csv($lines));
        self::assertSame([422, 'nonprofit_membership_import_too_many_rows'], [$status, json_decode($body, true)['error']['code']]);

        $member = $this->member('CSV-COUNT');
        $request = ['member_id' => $member] + $this->template;
        $preview = $this->charges->previewIssue($this->supplier, $request);
        $this->charges->confirmIssue($this->supplier, $request, $preview['expected_case_version'], $preview['preview_hash'], 'issue-csv-count', $this->actor);
        $csv = (new MembershipListExporter($this->db, '2026-06-01'))->export($this->supplier, MembershipListFilter::parse(['year' => '2026']), 'csv')['bytes'];
        $line = array_values(array_filter(explode("\n", $csv), static fn (string $l): bool => str_starts_with($l, 'SYN-CSV-COUNT;')))[0];
        $cells = str_getcsv($line, ';', '"', '');
        self::assertSame(['1', '500,00'], [$cells[6], $cells[7]], 'Počet předpisů je celé číslo, částky mají dvě desetinná místa.');
    }

    public function testNumbersEqualByCollationAreDuplicateAndCp1250CsvIsRead(): void
    {
        $this->member('DIA-C');
        $csv = $this->csv(['SYN-DIA-C;;;;suspended;2026-03-01;Synthetic decision;', 'SYN-DIA-Č;;;;suspended;2026-04-01;Synthetic decision;']);
        $rows = $this->import->run($this->supplier, $this->actor, $csv, 'a.csv', true)['rows'];
        self::assertSame([['error', 'duplicate_number'], ['error', 'duplicate_number']], array_map(static fn (array $r): array => [$r['status'], $r['reason']], $rows),
            'DB kolace nerozlišuje diakritiku: obě čísla jsou tentýž člen.');

        $legacy = (string) iconv('UTF-8', 'WINDOWS-1250', $this->csv(['SYN-DIA-C;;;;pozastavené;1. 3. 2026;Usnesení výboru;']));
        self::assertFalse(mb_check_encoding($legacy, 'UTF-8'));
        $rows = $this->import->run($this->supplier, $this->actor, $legacy, 'excel.csv', true)['rows'];
        self::assertSame(['new_revision', 'suspended'], [$rows[0]['status'], $rows[0]['new_status']]);
    }

    public function testNewNumbersEqualByCollationAreDuplicateInPreview(): void
    {
        $csv = $this->csv([
            'NOVY-C;;;Synthetic Jan;active;2026-01-01;Synthetic general meeting;',
            'NOVY-Č;;;Synthetic Petr;active;2026-01-01;Synthetic general meeting;',
            'novy-d;;;Synthetic Eva;active;2026-01-01;Synthetic general meeting;',
            'NOVY-D2;;;Synthetic Ota;active;2026-01-01;Synthetic general meeting;',
        ]);
        $rows = array_column($this->import->run($this->supplier, $this->actor, $csv, 'a.csv', true, canCreateContacts: true)['rows'], null, 'member_number');
        self::assertSame(['error', 'duplicate_number', ['lines' => '2, 3']], [$rows['NOVY-C']['status'], $rows['NOVY-C']['reason'], $rows['NOVY-C']['params']],
            'Unikátní index čísla nerozlišuje diakritiku: druhý člen by se při zápisu ztratil.');
        self::assertSame(['error', 'duplicate_number'], [$rows['NOVY-Č']['status'], $rows['NOVY-Č']['reason']]);
        self::assertSame(['new_member', 'new_member'], [$rows['novy-d']['status'], $rows['NOVY-D2']['status']]);
    }

    public function testDifferentPersonWithSameNumberAndBasisIsNotReportedAsAlreadyImported(): void
    {
        $csv = $this->csv(['NOVY-X;;;Synthetic Petr;active;2026-01-01;Synthetic general meeting;']);
        $preview = $this->import->run($this->supplier, $this->actor, $csv, 'a.csv', true, canCreateContacts: true);
        // Mezi náhledem a zápisem jiný import založí pod stejným číslem jinou osobu se stejným podkladem.
        $racing = new MembershipImportService($this->db, new ActivityLogger($this->db), $this->members, function (): void {
            $other = $this->csv(['NOVY-X;;;Synthetic Jan;active;2026-01-01;Synthetic general meeting;']);
            $service = new MembershipImportService($this->db, new ActivityLogger($this->db), $this->members);
            $first = $service->run($this->supplier, $this->actor, $other, 'b.csv', true, canCreateContacts: true);
            $service->run($this->supplier, $this->actor, $other, 'b.csv', false, $first['preview_hash'], canCreateContacts: true);
        });
        $result = $racing->run($this->supplier, $this->actor, $csv, 'a.csv', false, $preview['preview_hash'], canCreateContacts: true);
        self::assertSame(['failed', 'nonprofit_membership_identity_conflict'], [$result['rows'][0]['outcome'], $result['rows'][0]['failure']],
            'Klíč revize kontakt nenese: shoda čísla a podkladu nestačí k tvrzení, že je řádek zapsaný.');
    }

    public function testConcurrentMemberWithOtherContactOrOtherIcIsNotAlreadyImported(): void
    {
        $mine = $this->client('race-mine', 'Synthetic Mine', null);
        $theirs = $this->client('race-theirs', 'Synthetic Theirs', null);
        $basis = 'active;2026-01-01;Synthetic general meeting;';
        $cases = [
            // Řádek s existujícím kontaktem, souběžný import pod stejným číslem jiný existující kontakt.
            ["RACE-ID;{$mine};;;{$basis}", "RACE-ID;{$theirs};;;{$basis}"],
            // Zakládaný kontakt se stejným názvem, ale jiným IČO.
            ["RACE-IC;;45678901;Synthetic Same Name;{$basis}", "RACE-IC;;56789012;Synthetic Same Name;{$basis}"],
        ];
        foreach ($cases as [$ours, $concurrent]) {
            $csv = $this->csv([$ours]);
            $preview = $this->import->run($this->supplier, $this->actor, $csv, 'a.csv', true, canCreateContacts: true);
            $racing = new MembershipImportService($this->db, new ActivityLogger($this->db), $this->members, function () use ($concurrent): void {
                $service = new MembershipImportService($this->db, new ActivityLogger($this->db), $this->members);
                $first = $service->run($this->supplier, $this->actor, $this->csv([$concurrent]), 'b.csv', true, canCreateContacts: true);
                $service->run($this->supplier, $this->actor, $this->csv([$concurrent]), 'b.csv', false, $first['preview_hash'], canCreateContacts: true);
            });
            $result = $racing->run($this->supplier, $this->actor, $csv, 'a.csv', false, $preview['preview_hash'], canCreateContacts: true);
            self::assertSame('failed', $result['rows'][0]['outcome'], $ours);
        }
    }

    public function testBrokenUtf8OrUndefinedCp1250ByteIsEncodingErrorNotGarbledNames(): void
    {
        // UTF-8 s jediným vadným bajtem: převod z cp1250 by z „Jána“ udělal „JĂˇna“ (všechny bajty cp1250 zná) a zapsal ho.
        $broken = "member number;name;new status;effective from;reference\nNEW-UTF;Synthetic Jána\xFF;active;2026-02-01;Synthetic application\n";
        $error = $this->rejected(fn () => $this->import->run($this->supplier, $this->actor, $broken, 'a.csv', true, canCreateContacts: true));
        self::assertSame(['nonprofit_membership_import_encoding', 422], [$error->errorCode, $error->httpStatus]);
        // Bajt 0x81 cp1250 nedefinuje a Excel ho nezapíše: soubor odmítneme, místo abychom znaky tiše vynechali.
        $legacy = (string) iconv('UTF-8', 'WINDOWS-1250', $this->csv(['SYN-UNDEF;;;;pozastavené;1. 3. 2026;Usnesení výboru;']));
        $error = $this->rejected(fn () => $this->import->run($this->supplier, $this->actor, str_replace('Usnesen', "Usnesen\x81", $legacy), 'excel.csv', true));
        self::assertSame('nonprofit_membership_import_encoding', $error->errorCode);
        // Poškozené UTF-8, kde jediný znak mimo ASCII je čtyřbajtový.
        $emoji = "member number;name;new status;effective from;reference\nNEW-EMO;Synthetic \u{1F389}\xFF;active;2026-02-01;Synthetic application\n";
        $error = $this->rejected(fn () => $this->import->run($this->supplier, $this->actor, $emoji, 'a.csv', true, canCreateContacts: true));
        self::assertSame('nonprofit_membership_import_encoding', $error->errorCode);
    }

    public function testCp1250CsvWithUppercaseDiacriticsIsRead(): void
    {
        // Verzálky s diakritikou vedle š/ž/ť tvoří v cp1250 dvojice podobné UTF-8 (Í Ž = CD 8E, Ú š = DA 9A).
        $legacy = (string) iconv('UTF-8', 'WINDOWS-1250', $this->csv(['NEW-CAPS;;;ČÍŽEK VÝŠKOVÁ Úštěk;active;1. 2. 2026;Přihláška;']));
        $rows = $this->import->run($this->supplier, $this->actor, $legacy, 'excel.csv', true, canCreateContacts: true)['rows'];
        self::assertSame(['new_member', 'ČÍŽEK VÝŠKOVÁ Úštěk'], [$rows[0]['status'], $rows[0]['name']]);
        // Bez ředění českou hlavičkou: ASCII hlavička a jediné jméno, jehož vysoké bajty skoro všechny tvoří dvojice
        // podobné UTF-8 (Ů Ž = D9 8E, Ř Í = D8 CD). Poměr bajtů by soubor odmítl, podpis UTF-8 v něm není.
        $ascii = "member number;name;new status;effective from;reference\nNEW-RUZ;JAN RŮŽIČKA KŘÍŽOVÁ;active;1. 2. 2026;prihlaska\n";
        $rows = $this->import->run($this->supplier, $this->actor, (string) iconv('UTF-8', 'WINDOWS-1250', $ascii), 'excel.csv', true, canCreateContacts: true)['rows'];
        self::assertSame(['new_member', 'JAN RŮŽIČKA KŘÍŽOVÁ'], [$rows[0]['status'], $rows[0]['name']]);
    }

    public function testMixedUtf8AndCp1250FileIsRejected(): void
    {
        // Jméno v UTF-8 (á = C3 A1), podklad v cp1250 (í, č): převodem z cp1250 by vznikl kontakt „JĂˇn“.
        $mixed = "member number;name;new status;effective from;reference\nNEW-MIX;Ján Novak;active;2026-02-01;Usnesen\xED \xE8. 5\n";
        $error = $this->rejected(fn () => $this->import->run($this->supplier, $this->actor, $mixed, 'a.csv', true, canCreateContacts: true));
        self::assertSame('nonprofit_membership_import_encoding', $error->errorCode);
        // Typografická pomlčka v UTF-8 (E2 80 93) vedle cp1250 bajtu.
        $dash = "member number;name;new status;effective from;reference\nNEW-DASH;Synthetic \u{2013} Spolek;active;2026-02-01;Usnesen\xED\n";
        self::assertSame('nonprofit_membership_import_encoding', $this->rejected(fn () => $this->import->run($this->supplier, $this->actor, $dash, 'a.csv', true, canCreateContacts: true))->errorCode);
        // Export s BOM, k němuž někdo připsal řádek v cp1250, a jméno v cyrilici vedle podkladu v cp1250.
        $bom = "\xEF\xBB\xBFmember number;name;new status;effective from;reference\nNEW-BOM;Novak;active;2026-02-01;Usnesen\xED\n";
        self::assertSame('nonprofit_membership_import_encoding', $this->rejected(fn () => $this->import->run($this->supplier, $this->actor, $bom, 'a.csv', true, canCreateContacts: true))->errorCode);
        $cyrillic = "member number;name;new status;effective from;reference\nNEW-CYR;Олена Шевченко;active;2026-02-01;Usnesen\xED\n";
        self::assertSame('nonprofit_membership_import_encoding', $this->rejected(fn () => $this->import->run($this->supplier, $this->actor, $cyrillic, 'a.csv', true, canCreateContacts: true))->errorCode);
    }

    public function testConcurrentImportOfSameNewMemberIsAlreadyImported(): void
    {
        $csv = $this->csv(['TWIN-NEW;;;Synthetic Twin Person;active;2026-02-01;Synthetic application;']);
        $preview = $this->import->run($this->supplier, $this->actor, $csv, 'a.csv', true, canCreateContacts: true);
        $other = $this->otherActor();
        $before = $this->clientCount();
        // Dvojklik nebo druhý uživatel: stejný soubor se potvrdí těsně před naším zápisem.
        $racing = new MembershipImportService($this->db, new ActivityLogger($this->db), $this->members, function () use ($csv, $preview, $other): void {
            (new MembershipImportService($this->db, new ActivityLogger($this->db), $this->members))
                ->run($this->supplier, $other, $csv, 'a.csv', false, $preview['preview_hash'], canCreateContacts: true);
        });
        $result = $racing->run($this->supplier, $this->actor, $csv, 'a.csv', false, $preview['preview_hash'], canCreateContacts: true);
        self::assertSame([0, 0, 'already_imported'], [$result['written'], $result['failed_writes'], $result['rows'][0]['outcome']]);
        self::assertSame($before + 1, $this->clientCount(), 'Kontakt založený naším pokusem se vrátil s jeho transakcí.');
    }

    public function testXlsxExportHasMemberCapButCsvStreamsEverything(): void
    {
        foreach (['CAP-A', 'CAP-B', 'CAP-C'] as $number) $this->member($number);
        $exporter = new MembershipListExporter($this->db, '2026-06-01', 2);
        $filter = MembershipListFilter::parse(['year' => '2026']);
        $error = $this->rejected(fn () => $exporter->export($this->supplier, $filter, 'xlsx'));
        self::assertSame(['nonprofit_membership_export_too_large', 422], [$error->errorCode, $error->httpStatus]);
        $csv = $exporter->export($this->supplier, $filter, 'csv')['bytes'];
        self::assertSame(3, substr_count($csv, "\nSYN-CAP-"), 'CSV strop nemá.');
        self::assertSame('clenove-2026-06-01.xlsx', (new MembershipListExporter($this->db, '2026-06-01', 3))->export($this->supplier, $filter, 'xlsx')['filename']);
    }

    public function testOversizedUnpackedXlsxIsFileErrorNotRowLimit(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'k8zip');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('xl/padding.bin', str_repeat("\0", 21_000_000));
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);
        [$status, , $body] = $this->call('POST', 'importMembers', [], null, 'session', $bytes, 'clenove.xlsx');
        self::assertSame([422, 'nonprofit_membership_import_file_invalid'], [$status, json_decode($body, true)['error']['code']]);
    }

    /** @return array<string,array<string,mixed>> členské číslo => sloupec => hodnota */
    public function testIcLongerThanContactFormAllowsIsErrorAndMatchedContactIsNamedInPreview(): void
    {
        // Formulář kontaktu (Validation::client) přijme jen 8 číslic; import nesmí založit kontakt, který adresář neuloží.
        $padded = $this->client('ic-padded', 'Synthetic Padded Contact', '01234567');
        $byId = $this->client('ic-by-id', 'Synthetic Contact By Id', null);
        $csv = $this->csv([
            'IC-LONG;;123456789;Synthetic Long IC;active;2026-02-01;Synthetic application;',
            'IC-SHORT;;1234567;;active;2026-02-01;Synthetic application;',
            'IC-BYID;' . $byId . ';;;active;2026-02-01;Synthetic application;',
        ]);
        $before = $this->clientCount();
        $rows = array_column($this->import->run($this->supplier, $this->actor, $csv, 'a.csv', true, canCreateContacts: true)['rows'], null, 'member_number');
        self::assertSame(['error', 'ic_invalid'], [$rows['IC-LONG']['status'], $rows['IC-LONG']['reason']]);
        // Doplněné nuly mohou spárovat cizí kontakt: náhled proto ukáže jeho jméno.
        self::assertSame(['new_member', 'existing', $padded, 'Synthetic Padded Contact'],
            [$rows['IC-SHORT']['status'], $rows['IC-SHORT']['contact'], $rows['IC-SHORT']['client_id'], $rows['IC-SHORT']['contact_name'] ?? null]);
        self::assertSame('Synthetic Contact By Id', $rows['IC-BYID']['contact_name'] ?? null);
        self::assertSame($before, $this->clientCount());
    }

    public function testExportIsAuditedWithoutSearchedText(): void
    {
        $this->member('AUDIT-EXP');
        $before = $this->auditCount('report.accounting_export');
        [$status] = $this->call('GET', 'exportMembers', ['year' => '2026', 'format' => 'csv', 'search' => 'Synthetic Secret Name'], ['accounting' => 1]);
        self::assertSame(200, $status);
        self::assertSame($before + 1, $this->auditCount('report.accounting_export'));
        $q = $this->pdo->prepare("SELECT payload FROM activity_log WHERE supplier_id=? AND action='report.accounting_export' ORDER BY id DESC LIMIT 1");
        $q->execute([$this->supplier]);
        $details = (string) $q->fetchColumn();
        self::assertStringContainsString('nonprofit_members', $details);
        self::assertStringNotContainsString('Synthetic Secret Name', $details, 'Hledaný text může být jméno člena.');
        // Odmítnutý export se neauditní.
        $this->call('GET', 'exportMembers', ['year' => '2026', 'format' => 'pdf'], ['accounting' => 1]);
        self::assertSame($before + 1, $this->auditCount('report.accounting_export'));
    }

    public function testApostropheBeforeLineBreakFromCsvExportIsRemovedOnImport(): void
    {
        // CsvWriter::safe chrání i buňku začínající koncem řádku; import musí apostrof odebrat stejně jako u „=“.
        $csv = self::HEADER . "\n" . "NL-NEW;;;\"'\nSynthetic Newline Person\";active;2026-02-01;Synthetic application;\n";
        $preview = $this->import->run($this->supplier, $this->actor, $csv, 'a.csv', true, canCreateContacts: true);
        self::assertSame('new_member', $preview['rows'][0]['status'], json_encode($preview['rows'][0]));
        $this->import->run($this->supplier, $this->actor, $csv, 'a.csv', false, $preview['preview_hash'], canCreateContacts: true);
        $q = $this->pdo->prepare('SELECT c.company_name FROM clients c JOIN nonprofit_members m ON m.client_id=c.id AND m.supplier_id=c.supplier_id WHERE m.supplier_id=? AND m.member_number=?');
        $q->execute([$this->supplier, 'NL-NEW']);
        self::assertSame('Synthetic Newline Person', $q->fetchColumn());
    }

    private function exportRows(array $query): array
    {
        $rows = iterator_to_array((new MembershipListExporter($this->db, '2026-06-01'))->rows($this->supplier, MembershipListFilter::parse($query)), false);
        $headers = MembershipListColumns::headers();
        $out = [];
        foreach ($rows as $cells) {
            $named = array_combine($headers, array_map(static fn (array $cell): mixed => $cell[1], $cells));
            $out[$named['Členské číslo']] = $named;
        }
        return $out;
    }

    private function csv(array $lines): string
    {
        return self::HEADER . "\n" . implode("\n", $lines) . "\n";
    }

    private function client(string $key, string $name, ?string $ic): int
    {
        $q = $this->pdo->prepare('SELECT default_currency_id FROM supplier WHERE id=?');
        $q->execute([$this->supplier]);
        $this->pdo->prepare("INSERT INTO clients(supplier_id,company_name,ic,street,city,zip,country_id,main_email,language,currency_default_id,is_customer,is_vendor) VALUES(?,?,?,'Synthetic street','Synthetic city','10000',203,?,'cs',?,1,0)")
            ->execute([$this->supplier, $name, $ic, $key . '@example.test', (int) $q->fetchColumn()]);
        return (int) $this->pdo->lastInsertId();
    }

    private function clientCount(): int
    {
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM clients WHERE supplier_id=?');
        $q->execute([$this->supplier]);
        return (int) $q->fetchColumn();
    }

    private function memberByNumber(string $number): array
    {
        $q = $this->pdo->prepare('SELECT id,client_id FROM nonprofit_members WHERE supplier_id=? AND member_number=?');
        $q->execute([$this->supplier, $number]);
        return array_map('intval', $q->fetch(\PDO::FETCH_ASSOC) ?: self::fail('Člen ' . $number . ' chybí.'));
    }

    private function auditCount(string $action): int
    {
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM activity_log WHERE supplier_id=? AND action=?');
        $q->execute([$this->supplier, $action]);
        return (int) $q->fetchColumn();
    }

    private function otherActor(): int
    {
        $this->pdo->prepare("INSERT INTO users(email,password_hash,name,role,locale,is_active) VALUES(?,?,'Synthetic second actor','admin','cs',1)")
            ->execute(['import-second-' . $this->supplier . '@example.test', password_hash('Synthetic unused password', PASSWORD_BCRYPT, ['cost' => 4])]);
        return (int) $this->pdo->lastInsertId();
    }

    private function rejected(callable $work): MembershipException
    {
        try {
            $work();
        } catch (MembershipException $e) {
            return $e;
        }
        self::fail('Očekávaná chyba importu nenastala.');
    }

    /** @return array{0:int,1:array<string,list<string>>,2:string} */
    private function call(string $method, string $action, array $params, ?array $permissions, string $auth = 'session', ?string $file = null, string $filename = 'clenove.csv'): array
    {
        $permissions ??= ['accounting' => 1, 'accounting.journal.post' => 2];
        $request = (new ServerRequestFactory())->createServerRequest($method, '/api/accounting/nonprofit-membership/members/' . ($method === 'GET' ? 'export' : 'import'))
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplier)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $auth)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->actor, 'role' => 'accountant'])
            ->withAttribute('auth.effective_role', new EffectiveRole(503, 'Synthetic transfer role', 'staff', true, $permissions));
        $request = $method === 'GET' ? $request->withQueryParams($params) : $request->withParsedBody($params);
        if ($file !== null) {
            $stream = (new StreamFactory())->createStream($file);
            $request = $request->withUploadedFiles(['file' => new UploadedFile($stream, $filename, 'text/csv', strlen($file), UPLOAD_ERR_OK)]);
        }
        $container = $this->fixtureContainer();
        $handler = new MembershipAction($this->db, $container->get(PostingService::class), new ActivityLogger($this->db), $container->get(IpMatcher::class));
        $response = $handler->{$action}($request, new Response());
        return [$response->getStatusCode(), $response->getHeaders(), (string) $response->getBody()];
    }
}
