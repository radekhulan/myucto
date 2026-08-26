<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionBridgeService;
use MyInvoice\Service\Payroll\Submission\PayrollAgendaCorrectionPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Na co se smí navázat oprava nebo storno.
 *
 * Testuje se hlavně to, co se má NEZMĚNIT: výchozí sada je přísná a rozšířit ji
 * jde jen jmenovitě a s důvodem. Plošné rozvolnění by u agend s okamžitým
 * protokolem (EPO) dovolilo podat opravu dřív, než se ví, jestli originál
 * prošel — a duplicitní podání se pozná až u správce daně.
 */
final class PayrollAgendaCorrectionPolicyTest extends TestCase
{
    /**
     * Neznámá agenda dostane přísnou sadu. Je to důležitější než to vypadá:
     * nová agenda se přidává jinde než tady, takže výchozí chování musí být to
     * bezpečné.
     */
    public function testUndeclaredAgendaNeverAcceptsAPendingPredecessor(): void
    {
        foreach (['EPO_DPH', 'REGZELDOPL25', 'JMHZ', ''] as $agendaCode) {
            self::assertFalse(
                PayrollAgendaCorrectionPolicy::allowsPendingPredecessor($agendaCode),
                "Agenda {$agendaCode} nemá deklaraci, takže nesmí opravovat nerozhodnuté podání.",
            );
            $statuses = PayrollAgendaCorrectionPolicy::correctableStatuses($agendaCode);
            self::assertNotContains('submitted', $statuses);
            self::assertNotContains('processing', $statuses);
            self::assertContains('accepted', $statuses);
            self::assertContains('rejected', $statuses);
            self::assertNull(PayrollAgendaCorrectionPolicy::reason($agendaCode));
        }
    }

    /**
     * JMHZ smí navázat až na konečný důvěryhodný výsledek. Samotné odeslání,
     * zpracování ani zamítnutí nevytváří platný řádný kořen pro O/S.
     */
    public function testJmhzOnlyAcceptsFinalAcceptedPredecessors(): void
    {
        $agendaCode = JmhzSubmissionBridgeService::AGENDA_CODE;

        self::assertFalse(
            PayrollAgendaCorrectionPolicy::allowsPendingPredecessor($agendaCode),
        );
        self::assertSame(
            ['accepted', 'partially_accepted'],
            PayrollAgendaCorrectionPolicy::correctableStatuses($agendaCode),
        );
        self::assertNull(PayrollAgendaCorrectionPolicy::reason($agendaCode));
    }

    /**
     * Rozšíření bez důvodu se do katalogu nesmí dostat. Kdyby se dalo přidat
     * jen kódem agendy, příští čtenář by nevěděl, jestli to je záměr nebo
     * nedopatření — a nedopatření by se projevilo duplicitním podáním.
     */
    public function testEveryDeclarationCarriesAReason(): void
    {
        $declarations = PayrollAgendaCorrectionPolicy::declarations();
        self::assertIsArray($declarations);

        foreach ($declarations as $agendaCode => $reason) {
            self::assertMatchesRegularExpression('/^[A-Z0-9_]{2,48}$/D', $agendaCode);
            self::assertGreaterThan(
                60,
                mb_strlen($reason),
                "Deklarace {$agendaCode} musí nést větu, ne poznámku.",
            );
        }
    }

    public function testJmhzHasNoPendingPredecessorException(): void
    {
        self::assertArrayNotHasKey(
            JmhzSubmissionBridgeService::AGENDA_CODE,
            PayrollAgendaCorrectionPolicy::declarations(),
        );
    }

    public function testAcceptedJmhzCorrectionKeepsItsRegularRoot(): void
    {
        self::assertFalse(PayrollAgendaCorrectionPolicy::supersedesPredecessorOnAcceptance(
            JmhzSubmissionBridgeService::AGENDA_CODE,
            'correction',
        ));
        self::assertTrue(PayrollAgendaCorrectionPolicy::supersedesPredecessorOnAcceptance(
            JmhzSubmissionBridgeService::AGENDA_CODE,
            'cancellation',
        ));
        self::assertTrue(PayrollAgendaCorrectionPolicy::supersedesPredecessorOnAcceptance(
            'EPO_DPH',
            'correction',
        ));
        self::assertTrue(PayrollAgendaCorrectionPolicy::supersedesCorrectionChainOnAcceptance(
            JmhzSubmissionBridgeService::AGENDA_CODE,
            'cancellation',
        ));
        self::assertFalse(PayrollAgendaCorrectionPolicy::supersedesCorrectionChainOnAcceptance(
            JmhzSubmissionBridgeService::AGENDA_CODE,
            'correction',
        ));
    }
}
