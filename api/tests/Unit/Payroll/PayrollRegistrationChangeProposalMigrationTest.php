<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use PHPUnit\Framework\TestCase;

final class PayrollRegistrationChangeProposalMigrationTest extends TestCase
{
    private function sql(): string
    {
        $sql = file_get_contents(
            dirname(__DIR__, 4)
                . '/db/migrations/1644_payroll_registration_change_proposals.sql',
        );
        self::assertIsString($sql);

        return $sql;
    }

    public function testProposalIsTenantScopedAndIdempotent(): void
    {
        $sql = $this->sql();

        self::assertMatchesRegularExpression(
            '/supplier_id\s+INT UNSIGNED NOT NULL/',
            $sql,
            'Tenantní sloupec musí být INT UNSIGNED jako ve zbytku modulu.',
        );
        self::assertStringContainsString(
            'uq_payroll_registration_change_proposal_state',
            $sql,
            'Bez unikátního klíče nad otiskem stavu by každé otevření karty '
            . 'založilo další kopii téže lhůty.',
        );
        self::assertStringContainsString('current_fingerprint', $sql);
        self::assertStringContainsString('ON DELETE RESTRICT', $sql);
    }

    /**
     * Změna kódu zdravotní pojišťovny zakládá DVĚ povinnosti: do registru
     * pojištěnců i oběma pojišťovnám podle § 10 odst. 1 písm. b) zákona
     * č. 48/1997 Sb. Schéma to musí umět rozlišit.
     */
    public function testBothDutyKindsExistAndOnlyRegistrationCarriesAnAction(): void
    {
        $sql = $this->sql();

        self::assertStringContainsString(
            "duty_kind IN ('regzec_change','health_insurer_change')",
            $sql,
        );
        self::assertStringContainsString(
            "(duty_kind = 'regzec_change' AND action_code BETWEEN 3 AND 7)",
            $sql,
        );
        self::assertStringContainsString(
            "(duty_kind = 'health_insurer_change' AND action_code IS NULL)",
            $sql,
        );
    }

    /**
     * Do hlídače lhůt se nesmí kopírovat osobní údaje v otevřené podobě —
     * jinak by z něj byla druhá, nechráněná kartotéka vedle šifrovaných
     * profilů.
     */
    public function testProposalStoresNoPlaintextPersonalData(): void
    {
        $sql = $this->sql();

        foreach ([
            'birth_number', 'first_name', 'last_name', 'street',
            'value_ciphertext', 'profile_ciphertext',
        ] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $sql,
                "Tabulka návrhů nesmí nést sloupec {$forbidden}.",
            );
        }
    }

    public function testScanWatermarkTableExists(): void
    {
        $sql = $this->sql();

        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS payroll_registration_change_scans',
            $sql,
        );
        self::assertStringContainsString(
            'PRIMARY KEY (supplier_id, environment, employment_id)',
            $sql,
        );
    }
}
