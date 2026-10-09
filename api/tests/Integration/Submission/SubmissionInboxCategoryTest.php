<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Submission;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Submission\SubmissionInboxCategoryRepository;
use MyInvoice\Repository\Submission\SubmissionInboxListQuery;
use MyInvoice\Repository\Submission\SubmissionInboxRepository;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\SubmissionInboxCategoryHeuristics as H;
use MyInvoice\Service\Submission\SubmissionInboxCategoryService;
use MyInvoice\Service\Submission\SubmissionInboxService;
use MyInvoice\Support\PeriodFilter;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Kategorie, filtry, hledání a řazení příchozích zpráv datové schránky
 * (migrace 1982). Data jsou syntetická: smyšlení odesílatelé, ID schránek
 * i čísla jednací.
 */
#[Group('integration')]
final class SubmissionInboxCategoryTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PDO $pdo;
    private SubmissionInboxService $inbox;
    private SubmissionInboxCategoryService $categories;
    private SubmissionInboxCategoryRepository $categoryRepo;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
        if (!(new SubmissionInboxRepository($db))->supportsCategories()) {
            self::fail('Migrace 1982 neproběhla — kategorie zpráv nejsou v testovací DB.');
        }
        $this->pdo = $db->pdo();
        $this->pdo->beginTransaction();
        $template = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $template);
        $this->otherSupplierId = $this->createIsolatedSupplier($this->pdo, $template);
        $this->userId = (int) $this->pdo->query('SELECT MIN(id) FROM users')->fetchColumn();

        $inbox = $container->get(SubmissionInboxService::class);
        self::assertInstanceOf(SubmissionInboxService::class, $inbox);
        $this->inbox = $inbox;
        $categories = $container->get(SubmissionInboxCategoryService::class);
        self::assertInstanceOf(SubmissionInboxCategoryService::class, $categories);
        $this->categories = $categories;
        $this->categoryRepo = new SubmissionInboxCategoryRepository($db);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testExistingMessagesAreCategorizedOnFirstLoadAndAutoRulesCreated(): void
    {
        $tax = $this->message(['sender_box_id' => 'tst0001', 'sender_name' => 'Finanční úřad pro Testovací kraj', 'subject' => 'Platební výměr']);
        $court = $this->message(['sender_box_id' => 'tst0002', 'sender_name' => 'Okresní soud v Testově', 'subject' => 'Usnesení']);
        $system = $this->message(['sender_box_id' => 'aaaaaaa', 'sender_name' => 'Informační systém datových schránek', 'subject' => 'Oznámení']);
        $receipt = $this->message(['sender_box_id' => 'own0001', 'sender_name' => 'Testovací firma', 'classification' => 'delivery_receipt']);
        $partner = $this->message(['sender_box_id' => 'tst0003', 'sender_name' => 'Dodavatel Test s.r.o.'], senderType: 20);
        $unknown = $this->message(['sender_box_id' => null, 'sender_name' => null, 'subject' => null]);

        $page = $this->browse([]);

        self::assertSame(6, $page['total']);
        self::assertSame(H::TAX_OFFICE, $this->codeOf($tax));
        self::assertSame(H::COURTS_ENFORCEMENT, $this->codeOf($court));
        self::assertSame(H::ISDS_SYSTEM, $this->codeOf($system));
        self::assertSame(H::OWN_SUBMISSIONS, $this->codeOf($receipt));
        self::assertSame(H::BUSINESS_PARTNERS, $this->codeOf($partner));
        self::assertSame(H::OTHER, $this->codeOf($unknown));
        self::assertSame(['received' => 5, 'sent' => 1], $page['facets']['directions']);
        self::assertCount(count(H::SYSTEM_CODES), $page['categories']);

        $autoRules = array_column(
            array_filter($this->categoryRepo->listRules($this->supplierId), static fn (array $r): bool => $r['origin'] === 'auto'),
            'pattern',
        );
        sort($autoRules);
        self::assertSame(['aaaaaaa', 'tst0001', 'tst0002', 'tst0003'], $autoRules);

        // Druhé načtení nic nepřepočítává.
        self::assertSame(0, $this->categories->ensureCategorized($this->supplierId));
    }

    public function testNewMessageFromKnownSenderFollowsAutoRuleRetargetedByUser(): void
    {
        $first = $this->message(['sender_box_id' => 'tst0004', 'sender_name' => 'Městský úřad Testov'], senderType: 10);
        $this->browse([]);
        self::assertSame(H::PUBLIC_AUTHORITY, $this->codeOf($first));

        $custom = $this->categories->createCategory($this->supplierId, 'Stavební řízení');
        $rule = $this->ruleFor('sender_box', 'tst0004');
        $this->categories->updateRule($this->supplierId, $rule['id'], $custom);
        self::assertSame($custom, $this->categoryOf($first));

        $second = $this->message(['sender_box_id' => 'tst0004', 'sender_name' => 'Městský úřad Testov']);
        $this->browse([]);
        self::assertSame($custom, $this->categoryOf($second));
        self::assertSame('rule', $this->row($second)['category_source']);
    }

    public function testManualAssignmentSurvivesRecategorizationAndCanTeachSenderRule(): void
    {
        $a = $this->message(['sender_box_id' => 'tst0005', 'sender_name' => 'Dodavatel A s.r.o.'], senderType: 20);
        $b = $this->message(['sender_box_id' => 'tst0005', 'sender_name' => 'Dodavatel A s.r.o.'], senderType: 20);
        $c = $this->message(['sender_box_id' => 'tst0006', 'sender_name' => 'Dodavatel B s.r.o.'], senderType: 20);
        $this->browse([]);
        $custom = $this->categories->createCategory($this->supplierId, 'Klíčoví dodavatelé');

        $this->categories->assignMessage($this->supplierId, $c, $custom, false, 'tst0006', $this->userId);
        self::assertSame('manual', $this->row($c)['category_source']);

        $this->categories->assignMessage($this->supplierId, $a, $custom, true, 'tst0005', $this->userId);
        self::assertSame($custom, $this->categoryOf($a));
        self::assertSame($custom, $this->categoryOf($b), 'Pravidlo pro odesílatele musí přeřadit i jeho další zprávy.');

        // Smazání pravidla vrátí ruční zprávu na místě a ostatní k rozpoznání.
        $this->categories->deleteRule($this->supplierId, $this->ruleFor('sender_box', 'tst0005')['id']);
        self::assertSame($custom, $this->categoryOf($a));
        self::assertSame(H::BUSINESS_PARTNERS, $this->codeOf($b));
        self::assertSame($custom, $this->categoryOf($c));
    }

    public function testSubjectRuleBeatsSenderRuleAndDeletedCategoryReleasesMessages(): void
    {
        $warning = $this->message(['sender_box_id' => 'tst0007', 'sender_name' => 'Finanční úřad pro Testov', 'subject' => 'Výzva k odstranění vad podání']);
        $other = $this->message(['sender_box_id' => 'tst0007', 'sender_name' => 'Finanční úřad pro Testov', 'subject' => 'Informace']);
        $this->browse([]);
        $custom = $this->categories->createCategory($this->supplierId, 'Výzvy');

        $this->categories->createRule($this->supplierId, $custom, 'subject', 'vyzva k odstraneni', $this->userId);
        self::assertSame($custom, $this->categoryOf($warning), 'Vzor bez diakritiky musí najít věc s diakritikou.');
        self::assertSame(H::TAX_OFFICE, $this->codeOf($other));

        $this->categories->deleteCategory($this->supplierId, $custom);
        self::assertSame(H::TAX_OFFICE, $this->codeOf($warning));

        $this->expectException(SubmissionChannelException::class);
        $this->categories->deleteCategory($this->supplierId, $this->systemId(H::TAX_OFFICE));
    }

    public function testFiltersByCategorySenderDirectionReadAttachmentsAndPeriod(): void
    {
        $tax = $this->message(['sender_box_id' => 'tst0001', 'sender_name' => 'Finanční úřad pro Testov', 'delivered_at' => '2026-03-10 10:00:00'], attachments: ['vymer.pdf']);
        $tax2 = $this->message(['sender_box_id' => 'tst0001', 'sender_name' => 'Finanční úřad pro Testov', 'delivered_at' => '2026-04-10 10:00:00']);
        $receipt = $this->message(['sender_box_id' => 'own0001', 'classification' => 'delivery_receipt', 'delivered_at' => '2026-04-11 10:00:00']);
        $partner = $this->message(['sender_box_id' => 'tst0003', 'sender_name' => 'Dodavatel Test s.r.o.', 'delivered_at' => '2025-12-01 10:00:00'], senderType: 20);
        $this->browse([]);
        $this->inbox->markRead($this->supplierId, $tax, true, $this->userId);

        $taxCategory = (string) $this->systemId(H::TAX_OFFICE);
        self::assertSame([$tax2, $tax], $this->ids(['category' => $taxCategory]));
        self::assertSame([$tax2, $tax], $this->ids(['sender' => 'TST0001']));
        self::assertSame([$receipt], $this->ids(['direction' => 'sent']));
        self::assertSame([$receipt, $tax2, $partner], $this->ids(['read' => 'unread']));
        self::assertSame([$tax], $this->ids(['read' => 'read']));
        self::assertSame([$tax], $this->ids(['attachments' => '1']));
        self::assertSame([$receipt, $tax2], $this->ids(['year' => '2026', 'month' => '4']));

        // Počty kategorií ignorují filtr kategorie, ale ctí ostatní filtry.
        $page = $this->browse(['category' => $taxCategory, 'year' => '2026']);
        $counts = array_column($page['facets']['categories'], 'count', 'category_id');
        self::assertSame(2, $counts[$this->systemId(H::TAX_OFFICE)]);
        self::assertSame(1, $counts[$this->systemId(H::OWN_SUBMISSIONS)]);
        self::assertArrayNotHasKey($this->systemId(H::BUSINESS_PARTNERS), $counts);
        self::assertSame(1, $page['facets']['unread']);

        // Přečtení jde vrátit.
        $this->inbox->markRead($this->supplierId, $tax, false, $this->userId);
        self::assertSame([], $this->ids(['read' => 'read']));
    }

    public function testSearchCoversSubjectSenderReferenceNumbersAndAttachmentNames(): void
    {
        $bySubject = $this->message(['sender_box_id' => 'tst0001', 'sender_name' => 'Finanční úřad pro Testov', 'subject' => 'Platební výměr na daň z přidané hodnoty']);
        $byRef = $this->message(['sender_box_id' => 'tst0002', 'sender_name' => 'Okresní soud v Testově', 'subject' => 'Usnesení'], refNumber: 'TST-123/2026', fileMark: '12 C 34/2026');
        $byAttachment = $this->message(['sender_box_id' => 'tst0003', 'sender_name' => 'Dodavatel Test s.r.o.', 'subject' => 'Dokumenty'], attachments: ['smlouva-ramcova.pdf']);
        $this->browse([]);

        self::assertSame([$bySubject], $this->ids(['q' => 'platebni vymer']), 'Hledání ignoruje diakritiku a velikost písmen.');
        self::assertSame([$byRef], $this->ids(['q' => 'TST-123']));
        self::assertSame([$byRef], $this->ids(['q' => '12 C 34/2026']));
        self::assertSame([$byAttachment], $this->ids(['q' => 'ramcova']));
        self::assertSame([$byRef], $this->ids(['q' => 'soud testove']));
        self::assertSame([], $this->ids(['q' => 'soud dodavatel']));
        self::assertSame([], $this->ids(['q' => '100%_']));
        self::assertSame([], $this->ids(['q' => 'šťžý']));
    }

    public function testSortingBySenderSubjectCategoryAndDate(): void
    {
        $b = $this->message(['sender_box_id' => 'tst0003', 'sender_name' => 'Beta s.r.o.', 'subject' => 'Cecil', 'delivered_at' => '2026-01-02 08:00:00'], senderType: 20);
        $a = $this->message(['sender_box_id' => 'tst0001', 'sender_name' => 'Alfa finanční úřad', 'subject' => 'Bedřich', 'delivered_at' => '2026-01-03 08:00:00']);
        $c = $this->message(['sender_box_id' => 'aaaaaaa', 'sender_name' => 'Celní systém', 'subject' => 'Adam', 'delivered_at' => '2026-01-01 08:00:00']);
        $this->browse([]);

        self::assertSame([$a, $b, $c], $this->ids(['sort' => 'delivered']));
        self::assertSame([$c, $b, $a], $this->ids(['sort' => 'delivered', 'order' => 'asc']));
        self::assertSame([$a, $b, $c], $this->ids(['sort' => 'sender']));
        self::assertSame([$c, $b, $a], $this->ids(['sort' => 'sender', 'order' => 'desc']));
        self::assertSame([$c, $a, $b], $this->ids(['sort' => 'subject']));
        // finanční správa (10) → obchodní partneři (60) → systém ISDS (70)
        self::assertSame([$a, $b, $c], $this->ids(['sort' => 'category']));
    }

    public function testCompanyIsolationOfMessagesCategoriesAndRules(): void
    {
        $mine = $this->message(['sender_box_id' => 'tst0009', 'sender_name' => 'Dodavatel Test s.r.o.'], senderType: 20);
        $theirs = $this->message(['sender_box_id' => 'tst0009', 'sender_name' => 'Dodavatel Test s.r.o.'], senderType: 20, supplierId: $this->otherSupplierId);
        $this->browse([]);
        $foreign = $this->categories->createCategory($this->otherSupplierId, 'Cizí kategorie');
        $this->categories->createRule($this->otherSupplierId, $foreign, 'sender_box', 'tst0009', $this->userId);

        self::assertSame([$mine], $this->ids([]));
        self::assertSame(H::BUSINESS_PARTNERS, $this->codeOf($mine), 'Pravidlo jiné firmy se nesmí uplatnit.');
        self::assertSame($foreign, $this->categoryOf($theirs));

        try {
            $this->categories->assignMessage($this->supplierId, $mine, $foreign, false, 'tst0009', $this->userId);
            self::fail('Kategorie jiné firmy nesmí jít přiřadit.');
        } catch (SubmissionChannelException $e) {
            self::assertSame('inbox_category_not_found', $e->errorCode);
        }
        try {
            $this->categories->assignMessage($this->supplierId, $theirs, $this->systemId(H::OTHER), false, null, $this->userId);
            self::fail('Zprávu jiné firmy nesmí jít přeřadit.');
        } catch (SubmissionChannelException $e) {
            self::assertSame('not_found', $e->errorCode);
        }
        self::assertSame($foreign, $this->categoryOf($theirs));
        // Poslední pojistka v SQL: i bez kontroly služby se cizí kategorie nepřiřadí.
        self::assertFalse($this->categoryRepo->assignManual($this->supplierId, $mine, $foreign));
        self::assertSame(H::BUSINESS_PARTNERS, $this->codeOf($mine));
        self::assertFalse($this->inbox->markRead($this->supplierId, $theirs, true, $this->userId));
        self::assertNull($this->row($theirs)['read_at']);
        self::assertSame([], array_filter(
            $this->categories->listCategories($this->supplierId),
            static fn (array $c): bool => $c['id'] === $foreign,
        ));
    }

    public function testInvalidQueryValuesAreRejected(): void
    {
        foreach ([['sort' => 'id'], ['direction' => 'up'], ['sender' => "x' OR 1"], ['category' => 'abc'], ['read' => 'maybe']] as $params) {
            try {
                SubmissionInboxListQuery::fromQuery($params);
                self::fail('Neplatný filtr ' . json_encode($params) . ' měl být odmítnut.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** Filtr období se ve starší cestě seznamu tiše ztrácel (služba ho nepředala). */
    public function testLegacyPageRespectsPeriod(): void
    {
        $this->message(['delivered_at' => '2025-05-01 10:00:00']);
        $kept = $this->message(['delivered_at' => '2026-05-01 10:00:00']);

        $page = $this->inbox->listRecentPage(
            $this->supplierId,
            'production',
            null,
            25,
            0,
            'active',
            PeriodFilter::fromQuery(['year' => '2026']),
        );
        self::assertSame([$kept], array_column($page['items'], 'id'));
    }

    // ───────────────────────── pomocníci ─────────────────────────

    /**
     * @param array<string,mixed> $fields
     * @param list<string> $attachments
     */
    private function message(
        array $fields,
        ?int $senderType = null,
        array $attachments = [],
        ?string $refNumber = null,
        ?string $fileMark = null,
        ?int $supplierId = null,
    ): int {
        static $seq = 0;
        $seq++;
        $supplierId ??= $this->supplierId;
        $documentId = null;
        if ($senderType !== null || $attachments !== [] || $refNumber !== null || $fileMark !== null) {
            $documentId = $this->document($supplierId, 'datova-zprava-' . $seq . '.zfo', null);
            $this->pdo->prepare(
                'INSERT INTO document_dms_messages (document_id, dm_id, direction, sender_box_id, sender_name,
                     sender_type, annotation, sender_ref_number, recipient_ident)
                 VALUES (?, ?, \'received\', ?, ?, ?, ?, ?, ?)'
            )->execute([
                $documentId,
                (string) (900000 + $seq),
                $fields['sender_box_id'] ?? null,
                $fields['sender_name'] ?? null,
                $senderType !== null ? (string) $senderType : null,
                $fields['subject'] ?? null,
                $refNumber,
                $fileMark,
            ]);
            foreach ($attachments as $name) {
                $this->document($supplierId, $name, $documentId);
            }
        }
        $this->pdo->prepare(
            'INSERT INTO submission_inbox_messages
                (supplier_id, environment, channel, external_message_id, sender_box_id, sender_name, subject,
                 classification, document_id, delivered_at)
             VALUES (?, \'production\', \'isds\', ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            (string) (800000 + $seq) . bin2hex(random_bytes(3)),
            array_key_exists('sender_box_id', $fields) ? $fields['sender_box_id'] : 'tst0000',
            array_key_exists('sender_name', $fields) ? $fields['sender_name'] : 'Testovací odesílatel',
            array_key_exists('subject', $fields) ? $fields['subject'] : 'Testovací zpráva ' . $seq,
            $fields['classification'] ?? 'unclassified',
            $documentId,
            $fields['delivered_at'] ?? sprintf('2026-02-%02d 09:00:00', ($seq % 27) + 1),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function document(int $supplierId, string $name, ?int $parentId): int
    {
        $this->pdo->prepare(
            'INSERT INTO documents (supplier_id, title, original_name, filename, sha256, mime_type, size_bytes,
                 doc_type, source, parent_document_id)
             VALUES (?, ?, ?, ?, ?, ?, 10, ?, ?, ?)'
        )->execute([
            $supplierId,
            $name,
            $name,
            bin2hex(random_bytes(8)) . '.bin',
            hash('sha256', random_bytes(16)),
            'application/octet-stream',
            $parentId === null ? 'zfo' : 'pdf',
            $parentId === null ? 'manual' : 'zfo_extract',
            $parentId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string,string> $params
     * @return array<string,mixed>
     */
    private function browse(array $params): array
    {
        return $this->inbox->browse($this->supplierId, 'production', SubmissionInboxListQuery::fromQuery($params));
    }

    /**
     * @param array<string,string> $params
     * @return list<int>
     */
    private function ids(array $params): array
    {
        return array_values(array_map(static fn (array $item): int => (int) $item['id'], $this->browse($params)['items']));
    }

    /** @return array<string,mixed> */
    private function row(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM submission_inbox_messages WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    private function categoryOf(int $id): ?int
    {
        $value = $this->row($id)['category_id'];

        return $value === null ? null : (int) $value;
    }

    private function codeOf(int $id): ?string
    {
        $categoryId = $this->categoryOf($id);
        foreach ($this->categories->listCategories((int) $this->row($id)['supplier_id']) as $category) {
            if ($category['id'] === $categoryId) {
                return $category['code'];
            }
        }
        return null;
    }

    private function systemId(string $code): int
    {
        return $this->categoryRepo->systemCategoryIds($this->supplierId)[$code];
    }

    /** @return array{id:int,category_id:int,match_field:string,pattern:string,origin:string,created_by:?int,created_at:string} */
    private function ruleFor(string $field, string $pattern): array
    {
        foreach ($this->categoryRepo->listRules($this->supplierId) as $rule) {
            if ($rule['match_field'] === $field && $rule['pattern'] === $pattern) {
                return $rule;
            }
        }
        self::fail("Pravidlo {$field}={$pattern} neexistuje.");
    }
}
