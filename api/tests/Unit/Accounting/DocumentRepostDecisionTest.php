<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting;

use MyInvoice\Service\Accounting\DocumentRepostService;
use PHPUnit\Framework\TestCase;

/**
 * Rozhodnutí, JAK se doklad přeúčtuje — přepsat × stornovat × odmítnout.
 *
 * Je to celé jádro funkce „Přeúčtovat": špatná volba znamená buď smazaný zápis
 * v uzavřeném období (§35 ZoÚ), nebo tiše přesunuté datum, jehož rozdíl se najde
 * až při závěrce. Proto je rozhodnutí čistá funkce a proto má vlastní bránu.
 */
final class DocumentRepostDecisionTest extends TestCase
{
    private const TODAY = '2026-09-06';

    /** Otevřené a nezamčené období → zápis se přepíše na místě, datum se nemění. */
    public function testOpenPeriodRewritesInPlace(): void
    {
        $d = DocumentRepostService::decide(false, 'open', '2026-08-31', null, 'open', self::TODAY);

        self::assertSame(DocumentRepostService::STRATEGY_REPLACE, $d['strategy']);
        self::assertFalse($d['needs_reversal']);
        self::assertSame('2026-08-31', $d['target_date']);
        self::assertFalse($d['date_shifted']);
        self::assertNull($d['reason_code']);
    }

    /**
     * Uzavřené období: zápis se NESMÍ smazat ani přepsat. Vzniká protizápis a oprava
     * jde novým zápisem — obojí zůstává v deníku.
     */
    public function testClosedPeriodReversesInsteadOfDeleting(): void
    {
        $d = DocumentRepostService::decide(false, 'closed', '2025-11-30', null, 'open', self::TODAY);

        self::assertSame(DocumentRepostService::STRATEGY_REVERSE, $d['strategy']);
        self::assertTrue($d['needs_reversal']);
        self::assertSame('period_not_open', $d['reason_code']);
        self::assertSame(self::TODAY, $d['target_date']);
        self::assertTrue($d['date_shifted'], 'Do uzavřeného období se zapsat nedá — posun data musí být přiznaný.');
    }

    /** Zámek k datu (podané přiznání) se chová stejně jako uzavřené období. */
    public function testDateLockReversesInsteadOfRewriting(): void
    {
        $d = DocumentRepostService::decide(false, 'open', '2026-06-30', '2026-07-31', 'open', self::TODAY);

        self::assertSame(DocumentRepostService::STRATEGY_REVERSE, $d['strategy']);
        self::assertSame('date_locked', $d['reason_code']);
        self::assertSame(self::TODAY, $d['target_date']);
        self::assertTrue($d['date_shifted']);
    }

    /**
     * Zápis, který už někdo stornoval, se nepřepisuje (§35) — oprava jde novým
     * zápisem. Protože původní datum zapsat lze, storno i oprava sedí na TÉMŽ datu
     * jako původní zápis a datum se neposouvá.
     */
    public function testAlreadyReversedEntryPostsNewEntryAtOriginalDate(): void
    {
        $d = DocumentRepostService::decide(true, 'open', '2026-08-31', null, 'open', self::TODAY);

        self::assertSame(DocumentRepostService::STRATEGY_REVERSE, $d['strategy']);
        self::assertFalse($d['needs_reversal'], 'Protizápis už existuje, druhý se nedělá.');
        self::assertSame('entry_reversed', $d['reason_code']);
        self::assertSame('2026-08-31', $d['target_date']);
        self::assertFalse($d['date_shifted']);
    }

    /** Zámek zasahující i dnešek → není kam zapsat; operace se odmítne, nic se neposouvá. */
    public function testLockCoveringTodayBlocksOperation(): void
    {
        $d = DocumentRepostService::decide(false, 'open', '2026-06-30', '2026-12-31', 'open', self::TODAY);

        self::assertSame(DocumentRepostService::STRATEGY_BLOCKED, $d['strategy']);
        self::assertSame('date_locked', $d['reason_code']);
        self::assertNull($d['target_date']);
    }

    /** Uzavřený rok + neotevřené období pro dnešek → taky odmítnout, ne tiše zapsat jinam. */
    public function testClosedTodayPeriodBlocksOperation(): void
    {
        $d = DocumentRepostService::decide(false, 'closed', '2025-11-30', null, 'closed', self::TODAY);

        self::assertSame(DocumentRepostService::STRATEGY_BLOCKED, $d['strategy']);
        self::assertSame('period_not_open', $d['reason_code']);
        self::assertNull($d['target_date']);
    }

    /**
     * Období „closing" (běžící uzávěrkový průvodce) NENÍ otevřené: přepis by obešel
     * pojistku, kterou PostingService vyhrazuje výhradně ClosingService.
     */
    public function testClosingPeriodIsNotOpenForRewrite(): void
    {
        $d = DocumentRepostService::decide(false, 'closing', '2025-12-31', null, 'open', self::TODAY);

        self::assertSame(DocumentRepostService::STRATEGY_REVERSE, $d['strategy']);
        self::assertSame('period_not_open', $d['reason_code']);
    }

    /**
     * Hranice zámku je INKLUZIVNÍ (`entry_date <= locked_until`) — stejně jako
     * v PostingService. O den později už je datum živé a zápis se přepíše na místě.
     */
    public function testLockBoundaryIsInclusive(): void
    {
        $locked = DocumentRepostService::decide(false, 'open', '2026-07-31', '2026-07-31', 'open', self::TODAY);
        self::assertSame(DocumentRepostService::STRATEGY_REVERSE, $locked['strategy']);

        $free = DocumentRepostService::decide(false, 'open', '2026-08-01', '2026-07-31', 'open', self::TODAY);
        self::assertSame(DocumentRepostService::STRATEGY_REPLACE, $free['strategy']);
    }

    /**
     * Chybějící období není překážka — PostingService si ho přes provisioner otevře.
     * Kdyby se tu vyhodnotilo jako „zavřené", odmítla by se i oprava naimportované
     * historie, kterou zapsat jde.
     */
    public function testMissingTodayPeriodIsNotABlocker(): void
    {
        $d = DocumentRepostService::decide(false, 'closed', '2025-11-30', null, null, self::TODAY);

        self::assertSame(DocumentRepostService::STRATEGY_REVERSE, $d['strategy']);
        self::assertSame(self::TODAY, $d['target_date']);
    }
}
