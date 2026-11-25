<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Nonprofit;

use MyInvoice\Service\Tax\Nonprofit\NonprofitDppoReadiness;
use PHPUnit\Framework\TestCase;

/**
 * Rozdělení chyby výpočtu DPPO 504 do kroků připravenosti (#402). Pořadí a kódy odpovídají
 * NonprofitDppoDataProvider::gather; integrační průchody jsou v NonprofitReferenceAssociationEndToEndTest.
 */
final class NonprofitDppoReadinessTest extends TestCase
{
    public function testStatementMismatchAfterMatchingProfileFailsReturnData(): void
    {
        $result = NonprofitDppoReadiness::afterFailure(['sources' => true, 'legal_profile' => true, 'profile_match' => true],
            ['code' => 'nonprofit_dppo_statement_reconciliation_failed', 'message' => 'Výsledek J6 nesouhlasí s výsledovkou.'], self::unexpected(...));
        self::assertSame(['sources' => 'ok', 'legal_profile' => 'ok', 'profile_match' => 'ok', 'return_data' => 'failed'], self::statuses($result));
        self::assertSame('nonprofit_dppo_statement_reconciliation_failed', $result['steps'][3]['code']);
        self::assertFalse($result['ready']);
    }

    public function testLegacyLegalProfileFailureIsNotAttributedToSources(): void
    {
        // Legacy cesta ověří právní profil dřív než posouzení: nic není splněno, chyba patří profilu.
        $asked = [];
        $result = NonprofitDppoReadiness::afterFailure([], ['code' => 'nonprofit_dppo_legal_profile_required', 'message' => 'Chybí profil.'],
            function (string $step) use (&$asked): array|false|null {
                $asked[] = $step;
                return $step === 'sources' ? null : false;
            });
        self::assertSame(['sources' => 'not_evaluated', 'legal_profile' => 'failed', 'profile_match' => 'not_evaluated', 'return_data' => 'not_evaluated'], self::statuses($result));
        self::assertSame(['sources', 'profile_match', 'return_data'], $asked);
        self::assertSame(['nonprofit_dppo_legal_profile_required'], array_column($result['findings'], 'code'));
    }

    public function testInvalidStoredLegalProfileRaisedWhileReadingSourcesBelongsToLegalProfile(): void
    {
        // Neplatný uložený profil (např. zapnutý modul SVJ k jinému postavení) vyhodí už otisk podkladů.
        $failure = ['code' => 'nonprofit_dppo_legal_profile_invalid', 'message' => 'Synthetic invalid profile.'];
        $result = NonprofitDppoReadiness::afterFailure([], $failure,
            static fn (string $step): array|false|null => match ($step) { 'sources' => null, 'legal_profile' => $failure, default => false });
        self::assertSame(['sources' => 'not_evaluated', 'legal_profile' => 'failed', 'profile_match' => 'not_evaluated', 'return_data' => 'not_evaluated'], self::statuses($result));
        self::assertSame(['nonprofit_dppo_legal_profile_invalid'], array_column($result['findings'], 'code'));
    }

    public function testProfileCodeFromALaterCheckDoesNotFailAnAlreadyPassedProfile(): void
    {
        // NonprofitDppoLegalRules::obligation ve validate() hází stejný kód jako neplatný profil, ale až v údajích pro přiznání.
        $result = NonprofitDppoReadiness::afterFailure(['sources' => true, 'legal_profile' => true, 'profile_match' => true],
            ['code' => 'nonprofit_dppo_legal_profile_invalid', 'message' => 'Synthetic obligation rows.'], self::unexpected(...));
        self::assertSame(['sources' => 'ok', 'legal_profile' => 'ok', 'profile_match' => 'ok', 'return_data' => 'failed'], self::statuses($result));
    }

    public function testIndependentFindingWithTheSameCodeIsReportedOnce(): void
    {
        $failure = ['code' => 'nonprofit_dppo_assessment_required', 'message' => 'Synthetic missing assessment.'];
        $result = NonprofitDppoReadiness::afterFailure([], ['code' => 'nonprofit_dppo_legal_profile_required', 'message' => 'Synthetic missing profile.'],
            static fn (string $step): array|false|null => $step === 'sources' ? $failure : false);
        self::assertSame(['nonprofit_dppo_legal_profile_required', 'nonprofit_dppo_assessment_required'], array_column($result['findings'], 'code'));
        $again = NonprofitDppoReadiness::afterFailure([], $failure, static fn (string $step): array|false|null => $step === 'legal_profile' ? $failure : false);
        self::assertSame(['nonprofit_dppo_assessment_required'], array_column($again['findings'], 'code'));
    }

    public function testGeneralFailureLeavesAllStepsUnevaluated(): void
    {
        $result = NonprofitDppoReadiness::afterFailure([], ['code' => 'nonprofit_dppo_period_required', 'message' => 'Chybí období.'], self::unexpected(...));
        self::assertSame(array_fill_keys(NonprofitDppoReadiness::STEPS, 'not_evaluated'), self::statuses($result));
        self::assertSame(['nonprofit_dppo_period_required'], array_column($result['findings'], 'code'));
    }

    private static function statuses(array $result): array
    {
        return array_column($result['steps'], 'status', 'step');
    }

    private static function unexpected(string $step): never
    {
        self::fail('Nezávislá kontrola kroku ' . $step . ' se neměla spustit.');
    }
}
