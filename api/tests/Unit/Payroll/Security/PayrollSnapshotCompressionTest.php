<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Security;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollSnapshotCompression;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotService;
use PHPUnit\Framework\TestCase;

/**
 * Šifrovaný snímek přípravy JMHZ velké firmy se ukládá jedním INSERTem. Bez komprese
 * má snímek firmy s tisíci zaměstnanci po zašifrování a base64 desítky MB a překročí
 * výchozí `max_allowed_packet` MariaDB (16 MB). Syntetická data.
 */
final class PayrollSnapshotCompressionTest extends TestCase
{
    /** Výchozí max_allowed_packet MariaDB minus rezerva na zbytek INSERTu (jako Diagnostika). */
    private const PACKET_BUDGET = (16 - 4) * 1024 * 1024;

    public function testLargePreparationSnapshotFitsDefaultPacketAfterCompression(): void
    {
        // Formulář na zaměstnance s proměnlivými hodnotami, ať komprese není nerealisticky dobrá.
        $form = static fn (int $i): array => [
            'employment_id' => 100_000 + $i,
            'id_ppv' => sprintf('%022d', 7_000_000 + $i * 13),
            'person' => ['family_name' => 'Syntetická', 'given_name' => 'Osoba', 'birth_date' => sprintf('19%02d-%02d-%02d', 50 + $i % 50, 1 + $i % 12, 1 + $i % 28)],
            'rows' => array_map(
                static fn (int $row): array => ['attribute_id' => (string) (10200 + $row), 'value' => (string) (($i * 37 + $row * 101) % 99_991)],
                range(0, 150),
            ),
        ];
        $count = intdiv(16_000_000, strlen(CanonicalJson::encode($form(0)))) + 200;
        $forms = array_map($form, range(0, $count));
        $json = CanonicalJson::encode(['people' => $forms]);
        unset($forms);
        self::assertGreaterThan(16_000_000, strlen($json));

        $encryption = new SecretEncryption(new Config([
            'app' => ['secret_encryption_key' => base64_encode(str_repeat('e', 32))],
        ]));
        $context = 'payroll:jmhz-preparation:10:test:1:fingerprint:manifest:readiness';
        $ciphertext = $encryption->encryptFor(PayrollSnapshotCompression::pack($json), $context);

        self::assertLessThan(self::PACKET_BUDGET, strlen($ciphertext), 'Zašifrovaný snímek se musí vejít do výchozího max_allowed_packet.');
        self::assertTrue(PayrollSnapshotCompression::unpack($encryption->decryptFor($ciphertext, $context)) === $json);
    }

    /** Dřív uložený nekomprimovaný snímek se čte beze změny. */
    public function testUncompressedLegacySnapshotIsReadAsIs(): void
    {
        $json = CanonicalJson::encode(['schema_reference' => 'synthetic', 'people' => []]);

        self::assertSame($json, PayrollSnapshotCompression::unpack($json));
        self::assertSame($json, PayrollSnapshotCompression::unpack(PayrollSnapshotCompression::pack($json)));
    }

    public function testPacketLimitErrorIsRecognised(): void
    {
        $tooLarge = new \PDOException('SQLSTATE[08S01]: Communication link failure: 1153 Got a packet bigger than \'max_allowed_packet\' bytes');
        $tooLarge->errorInfo = ['08S01', 1153, 'Got a packet bigger than \'max_allowed_packet\' bytes'];
        $other = new \PDOException('SQLSTATE[23000]: Integrity constraint violation');
        $other->errorInfo = ['23000', 1062, 'Duplicate entry'];

        self::assertTrue(JmhzPreparationSnapshotService::isPacketLimit($tooLarge));
        self::assertFalse(JmhzPreparationSnapshotService::isPacketLimit($other));
    }
}
