<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Crm;

use MyInvoice\Service\Crm\CrmAggregationService;
use MyInvoice\Service\License\LicenseState;
use PHPUnit\Framework\TestCase;

/**
 * Licence v „Akcích pro tebe".
 *
 * Banner v layoutu je pasivní a odpočet trialu v něm naskakuje až 5 dní před koncem —
 * kdo se do aplikace dívá obden, o končícím trialu se dozví pozdě. Položka v seznamu
 * úkolů má proto širší okno (7 dní) a dá se odbavit nebo vědomě skrýt.
 *
 * Mapování je čistá funkce nad {@see LicenseState}, takže hraniční dny (poslední den
 * trialu, propadlý deadline) jde ověřit bez DB i bez skutečného „teď".
 */
final class LicenseActionItemTest extends TestCase
{
    private const NOW = 1800000000;   // pevný bod v čase, ať test nezávisí na dnešku

    public function testActiveLicenseProducesNothing(): void
    {
        self::assertNull(CrmAggregationService::licenseActionItem(
            $this->state(LicenseState::ACTIVE, validUntil: self::NOW + 90 * 86400),
            self::NOW,
        ));
    }

    /** Trial se hlásí až v posledním týdnu — dřív by to byl jen šum. */
    public function testTrialFarFromEndProducesNothing(): void
    {
        self::assertNull(CrmAggregationService::licenseActionItem(
            $this->state(LicenseState::TRIAL, trialEndsAt: self::NOW + 10 * 86400),
            self::NOW,
        ));
    }

    public function testTrialInLastWeekIsMediumWithCountdown(): void
    {
        $item = CrmAggregationService::licenseActionItem(
            $this->state(LicenseState::TRIAL, trialEndsAt: self::NOW + 5 * 86400),
            self::NOW,
        );

        self::assertNotNull($item);
        self::assertSame('license', $item['type']);
        self::assertSame('medium', $item['severity']);
        self::assertSame(5, $item['days']);
        self::assertStringContainsString('5 dní', $item['hint']);
        self::assertSame('/activation/license', $item['link']);
    }

    /** Poslední dva dny už jsou naléhavé — po nich moduly zmizí. */
    public function testTrialInLastTwoDaysIsHigh(): void
    {
        $item = CrmAggregationService::licenseActionItem(
            $this->state(LicenseState::TRIAL, trialEndsAt: self::NOW + 2 * 86400),
            self::NOW,
        );

        self::assertSame('high', $item['severity']);
        self::assertStringContainsString('2 dny', $item['hint'], 'Skloňování: 2 dny, ne 2 dní.');
    }

    public function testTrialEndingTodayHasNoCountdown(): void
    {
        $item = CrmAggregationService::licenseActionItem(
            $this->state(LicenseState::TRIAL, trialEndsAt: self::NOW),
            self::NOW,
        );

        self::assertSame('high', $item['severity']);
        self::assertStringContainsString('dnes', $item['hint']);
    }

    /** Trial bez data konce nemá co odpočítávat — položka by lhala. */
    public function testTrialWithoutEndDateProducesNothing(): void
    {
        self::assertNull(CrmAggregationService::licenseActionItem(
            $this->state(LicenseState::TRIAL),
            self::NOW,
        ));
    }

    public function testExpiredStatesAreHighWithoutCountdown(): void
    {
        foreach ([LicenseState::TRIAL_EXPIRED, LicenseState::DEGRADED] as $kind) {
            $item = CrmAggregationService::licenseActionItem($this->state($kind), self::NOW);

            self::assertNotNull($item, $kind);
            self::assertSame('high', $item['severity'], $kind);
            self::assertNull($item['days'], $kind . ' už dopad má, není co odpočítávat.');
            self::assertStringContainsString('Fakturace funguje dál', $item['hint'], $kind);
        }
    }

    /**
     * Karta licence jde skrýt.
     *
     * Widget nabízí nabídku skrytí každé kartě, která nemá `dismissible => false`,
     * a ta musí mít protějšek v {@see CrmAggregationService::DISMISSIBLE_ITEM_TYPES}.
     * Bez toho uživatel skrytí zvolí a dostane „Invalid item_type: license".
     */
    public function testLicenseItemCanBeDismissed(): void
    {
        $item = CrmAggregationService::licenseActionItem($this->state(LicenseState::TRIAL_EXPIRED), self::NOW);

        self::assertNotNull($item);
        self::assertArrayNotHasKey('dismissible', $item, 'Karta skrývání nabízí, takže musí být i povolená.');
        self::assertContains($item['type'], CrmAggregationService::DISMISSIBLE_ITEM_TYPES);
    }

    /** Překročený rozsah nese počty — bez nich uživatel neví, co má rozšířit. */
    public function testOverageCarriesCountsAndDeadline(): void
    {
        $item = CrmAggregationService::licenseActionItem(
            $this->state(
                LicenseState::OVERAGE,
                overageDeadline: self::NOW + 10 * 86400,
                usersLicensed: 2,
                usersActive: 5,
                companiesActive: 3,
                maxCompanies: 1,
            ),
            self::NOW,
        );

        self::assertSame('medium', $item['severity']);
        self::assertStringContainsString('5 z 2 uživatelů', $item['hint']);
        self::assertStringContainsString('3 z 1 firem', $item['hint']);
        self::assertStringContainsString('10 dní', $item['hint']);
    }

    public function testOverageCloseToDeadlineIsHigh(): void
    {
        $item = CrmAggregationService::licenseActionItem(
            $this->state(LicenseState::OVERAGE, overageDeadline: self::NOW + 2 * 86400, usersLicensed: 2, usersActive: 3),
            self::NOW,
        );

        self::assertSame('high', $item['severity']);
    }

    /** Propadlý deadline nesmí ukázat záporný počet dní. */
    public function testOveragePastDeadlineClampsToZero(): void
    {
        $item = CrmAggregationService::licenseActionItem(
            $this->state(LicenseState::OVERAGE, overageDeadline: self::NOW - 5 * 86400, usersLicensed: 2, usersActive: 3),
            self::NOW,
        );

        self::assertSame('high', $item['severity']);
        self::assertStringContainsString('do 0 dní', $item['hint']);
    }

    private function state(
        string $kind,
        ?int $trialEndsAt = null,
        ?int $overageDeadline = null,
        ?int $validUntil = null,
        int $usersLicensed = 0,
        int $usersActive = 0,
        int $companiesActive = 0,
        ?int $maxCompanies = null,
    ): LicenseState {
        return new LicenseState(
            $kind, 'inst-test', 'pro', $maxCompanies, $usersLicensed, $usersActive,
            $companiesActive, $validUntil, $trialEndsAt, $overageDeadline, null, null, true,
        );
    }
}
