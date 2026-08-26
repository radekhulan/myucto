<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Submission;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Submission\SubmissionOutboxRepository;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Databázová vrstva ochrany proti záměně „doručeno" a „zpracováno".
 *
 * PHP vrstvy (slovník, tvar, oprávnění) hlídá `DeliveryIsNotAcceptanceTest`.
 * Tenhle test hlídá poslední: co se nedá zapsat ani ručním UPDATE. Je to
 * důležité proto, že opravný skript, migrace nebo zásah v konzoli PHP vrstvu
 * obejdou — a právě takhle podobná záměna do projektu jednou vnikla.
 */
#[Group('integration')]
final class SubmissionOutboxInvariantsTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private SubmissionOutboxRepository $outbox;
    private int $supplierId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
        $this->outbox = new SubmissionOutboxRepository($db);
        if (!$this->outbox->isAvailable()) {
            $this->markTestSkipped('Migrace 1381 neproběhla.');
        }
        $pdo = $db->pdo();
        $pdo->beginTransaction();
        $source = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    /** Doručenka nemá ve slovníku důkazů hodnotu — nejde ji zapsat ani přímo. */
    public function testAcceptanceCannotBeRecordedWithADeliveryReceiptAsEvidence(): void
    {
        $id = $this->enqueue()['id'];
        $this->markSent($id);

        $this->expectException(PDOException::class);
        $this->db->pdo()->prepare(
            "UPDATE submission_outbox
                SET acceptance_state = 'accepted',
                    acceptance_evidence_kind = 'isds_delivery_receipt',
                    accepted_at = UTC_TIMESTAMP(),
                    row_version = row_version + 1
              WHERE id = ?"
        )->execute([$id]);
    }

    /** Vyřízení bez druhu důkazu je tvrzení bez podkladu. */
    public function testAcceptanceWithoutEvidenceKindIsRejected(): void
    {
        $id = $this->enqueue()['id'];
        $this->markSent($id);

        $this->expectException(PDOException::class);
        $this->db->pdo()->prepare(
            "UPDATE submission_outbox
                SET acceptance_state = 'accepted', accepted_at = UTC_TIMESTAMP(),
                    row_version = row_version + 1
              WHERE id = ?"
        )->execute([$id]);
    }

    /**
     * Jádro: zápis doručení a zápis vyřízení nesmí být jeden UPDATE. Právě
     * takhle by vzniklo „doručenka dorazila → tedy je to přijaté".
     */
    public function testDeliveryAndAcceptanceCannotBeWrittenTogether(): void
    {
        $id = $this->enqueue()['id'];
        $this->markSent($id);

        $this->expectException(PDOException::class);
        $this->db->pdo()->prepare(
            "UPDATE submission_outbox
                SET dispatch_state = 'delivered', delivered_at = UTC_TIMESTAMP(),
                    acceptance_state = 'accepted',
                    acceptance_evidence_kind = 'agency_protocol_message',
                    accepted_at = UTC_TIMESTAMP(),
                    row_version = row_version + 1
              WHERE id = ?"
        )->execute([$id]);
    }

    /** Doručení samo o sobě projde — a osu vyřízení nechá být. */
    public function testDeliveryAloneIsAllowedAndLeavesAcceptanceUnknown(): void
    {
        $row = $this->enqueue();
        $this->markSent($row['id']);
        $current = $this->outbox->find($this->supplierId, $row['id']);
        self::assertNotNull($current);

        $delivered = $this->outbox->markDelivered(
            $this->supplierId,
            $row['id'],
            // Musí být po sent_at — doručení nemůže předcházet odeslání
            // (CHECK `chk_submission_outbox_timeline`).
            new \DateTimeImmutable('+1 day'),
            (int) $current['row_version'],
        );

        self::assertSame('delivered', $delivered['dispatch_state']);
        self::assertSame('unknown', $delivered['acceptance_state']);
        self::assertNull($delivered['acceptance_evidence_kind']);
        self::assertNull($delivered['accepted_at']);
    }

    /** Úřad nemůže rozhodnout o podání, které k němu neodešlo. */
    public function testAcceptanceBeforeSendingIsRejected(): void
    {
        $id = $this->enqueue()['id'];

        $this->expectException(PDOException::class);
        $this->db->pdo()->prepare(
            "UPDATE submission_outbox
                SET acceptance_state = 'accepted',
                    acceptance_evidence_kind = 'manual_confirmation',
                    accepted_at = UTC_TIMESTAMP(),
                    row_version = row_version + 1
              WHERE id = ?"
        )->execute([$id]);
    }

    /** Odeslané podání se nesmí vrátit do fronty — druhé potvrzení by bylo duplicitní podání. */
    public function testDispatchedSubmissionCannotReturnToReady(): void
    {
        $id = $this->enqueue()['id'];
        $this->markSent($id);

        $this->expectException(PDOException::class);
        $this->db->pdo()->prepare(
            "UPDATE submission_outbox SET dispatch_state = 'ready', row_version = row_version + 1 WHERE id = ?"
        )->execute([$id]);
    }

    /** Bez potvrzení člověkem se řádek z fronty nehne. */
    public function testDispatchWithoutHumanConfirmationIsRejected(): void
    {
        $id = $this->enqueue()['id'];

        $this->expectException(PDOException::class);
        $this->db->pdo()->prepare(
            "UPDATE submission_outbox
                SET dispatch_state = 'sending', row_version = row_version + 1
              WHERE id = ?"
        )->execute([$id]);
    }

    /** Ledger pokusů se nemaže. */
    public function testAttemptLedgerIsAppendOnly(): void
    {
        $id = $this->enqueue()['id'];
        $this->db->pdo()->prepare(
            "INSERT INTO submission_outbox_attempts
                (supplier_id, outbox_id, channel, attempt_no, outcome, request_sha256,
                 correlation_reference, started_at)
             VALUES (?, ?, 'isds', 1, 'in_flight', ?, ?, UTC_TIMESTAMP())"
        )->execute([$this->supplierId, $id, str_repeat('b', 64), 'TEST-LEDGER-1']);

        $this->expectException(PDOException::class);
        $this->db->pdo()->prepare('DELETE FROM submission_outbox_attempts WHERE outbox_id = ?')
            ->execute([$id]);
    }

    /** Spisová značka delší než 50 znaků by se v dmSenderIdent ořízla. */
    public function testCorrelationReferenceLongerThanFiftyCharactersIsRejected(): void
    {
        $this->expectException(PDOException::class);
        $this->enqueue(str_repeat('Z', 51));
    }

    /** Odkaz na zdroj je užitečný auditní údaj, ale nesmí blokovat firemní číselník. */
    public function testRecipientBoxIdWithoutSourceIsAllowed(): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO submission_recipients (supplier_id, code, name, kind, isds_box_id)
             VALUES (?, 'fu_bez_dokladu', 'FÚ bez dokladu', 'tax_office', 'abcdefg')"
        )->execute([$this->supplierId]);

        $stored = $this->db->pdo()->prepare(
            'SELECT isds_box_id, source_url FROM submission_recipients WHERE supplier_id = ? AND code = ?'
        );
        $stored->execute([$this->supplierId, 'fu_bez_dokladu']);
        $row = $stored->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        self::assertSame('abcdefg', $row['isds_box_id']);
        self::assertNull($row['source_url']);
    }

    /**
     * Seedují se jen doložené záznamy.
     *
     * Test dřív vynucoval prefix `zp_`, což byla zástupná kontrola z doby, kdy
     * byly doložené jedině pojišťovny. Prefix ale není totéž co doklad — od
     * chvíle, kdy jsou doložené i schránky e-Podání ČSSZ (migrace 1410), by
     * takový test bránil správnému seedu a povolil špatný, kdyby se jen trefil
     * do prefixu. Kontroluje se proto přímo to, na čem záleží: každý systémový
     * záznam má doložený zdroj a je na jmenovitém seznamu.
     */
    public function testOnlyDocumentedRecipientsAreSeeded(): void
    {
        // Finanční úřady tu schválně NEJSOU: jejich ID nemáme doložená
        // v repozitáři a seznam Finanční správy je z roku 2023, takže není
        // zdrojem pravdy. Seznam se smí rozšířit jen spolu s dokladem.
        $documented = [
            'zp_vzp_111', 'zp_vozp_201', 'zp_cpzp_205', 'zp_ozp_207',
            'zp_zpskoda_209', 'zp_zpmvcr_211', 'zp_rbp_213',
            'cssz_epodani_jmhz', 'cssz_epodani_obecna', 'cssz_epodani_test',
        ];

        $rows = $this->db->pdo()
            ->query("SELECT code, isds_box_id, source_url FROM submission_recipients WHERE supplier_id IS NULL")
            ->fetchAll(PDO::FETCH_ASSOC);

        self::assertNotSame([], $rows, 'Doložení příjemci mají být naseedovaní.');
        foreach ($rows as $row) {
            self::assertNotNull($row['source_url'], 'Každé naseedované ID musí mít doložený zdroj.');
            self::assertMatchesRegularExpression('/^[a-z0-9]{7}$/', (string) $row['isds_box_id']);
            self::assertContains(
                $row['code'],
                $documented,
                'Seedovat se smí jen to, co je doložené.',
            );
        }

        // Schránka zřízená ČSSZ výslovně pro JMHZ musí sedět na doslovnou
        // citaci ze stránky komunikačních kanálů — sedm znaků bez kontrolní
        // číslice se jinak zkontrolovat nedá.
        $byCode = array_column($rows, 'isds_box_id', 'code');
        self::assertSame('iie254d', $byCode['cssz_epodani_jmhz'] ?? null);
        self::assertSame('5ffu6xk', $byCode['cssz_epodani_obecna'] ?? null);
        self::assertSame('9tsaf6s', $byCode['cssz_epodani_test'] ?? null);
    }

    /** Historický příznak zůstává vypnutý; produkt automatický režim nenabízí. */
    public function testInboxPollingIsDisabledByDefault(): void
    {
        $this->insertCredential();

        $enabled = $this->db->pdo()->query(
            'SELECT inbox_polling_enabled FROM submission_channel_credentials WHERE supplier_id = ' . $this->supplierId
        )->fetchColumn();

        self::assertSame(0, (int) $enabled);
    }

    /** Plaintext v ciphertext sloupci neprojde. */
    public function testPlaintextCertificateIsRejected(): void
    {
        $this->expectException(PDOException::class);
        $this->db->pdo()->prepare(
            "INSERT INTO submission_channel_credentials
                (supplier_id, environment, channel, label, box_id, certificate_ciphertext)
             VALUES (?, 'test', 'isds', 'Test', 'abcdefg', 'holy-plaintext-certifikat')"
        )->execute([$this->supplierId]);
    }

    // ───────────────────────── pomocné ─────────────────────────

    /** @return array{id:int,row_version:int} */
    private function enqueue(string $correlation = 'DPHDP3-20260815-TESTA1'): array
    {
        $result = $this->outbox->enqueue([
            'supplier_id' => $this->supplierId,
            'environment' => 'test',
            'channel' => 'isds',
            'agenda_code' => 'DPHDP3',
            'recipient_id' => null,
            'recipient_box_id' => 'abcdefg',
            'subject' => 'Přiznání k DPH',
            'artifact_kind' => 'tax_submission',
            'artifact_id' => 1,
            'artifact_filename' => 'dphdp3.xml',
            'artifact_sha256' => str_repeat('a', 64),
            'correlation_reference' => $correlation,
            'created_by' => null,
        ], 'idem-' . $correlation);

        return ['id' => (int) $result['row']['id'], 'row_version' => (int) $result['row']['row_version']];
    }

    /** Projde branami (validace + ověření schránky) a odešle — přímým SQL, ať test nepotřebuje kanál. */
    private function markSent(int $id): void
    {
        $this->db->pdo()->prepare(
            "UPDATE submission_outbox
                SET artifact_validation_status = 'passed', artifact_validated_at = UTC_TIMESTAMP(),
                    recipient_box_verified_at = UTC_TIMESTAMP(),
                    dispatch_state = 'sent', external_message_id = 'DM-9001',
                    sent_at = UTC_TIMESTAMP(),
                    confirmed_by = (SELECT MIN(id) FROM users), confirmed_at = UTC_TIMESTAMP(),
                    row_version = row_version + 1
              WHERE id = ?"
        )->execute([$id]);
    }

    private function insertCredential(): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO submission_channel_credentials
                (supplier_id, environment, channel, label, box_id, certificate_ciphertext)
             VALUES (?, 'test', 'isds', 'Testovací schránka', 'abcdefg', 'enc:v2:0000:synteticky')"
        )->execute([$this->supplierId]);
    }
}
