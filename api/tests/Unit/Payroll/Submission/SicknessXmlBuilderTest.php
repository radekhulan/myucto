<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Cssz\CsszSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Sickness\HzupnXmlPayload;
use MyInvoice\Service\Payroll\Submission\Sickness\HzupnXmlSerializer;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriDecisiveMonth;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriDecisivePeriod;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriPaymentConnection;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriXmlPayload;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriXmlSerializer;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessBenefitKind;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessException;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessXmlValidator;
use PHPUnit\Framework\TestCase;

/**
 * Buildery NEMPRI25 a HZUPN20 proti PŘIPNUTÝM XSD.
 *
 * Test schválně nepoužívá dvojníka schématu: validuje se proti témuž souboru
 * a témuž otisku, jaký drží {@see CsszSchemaCatalog}. Kdyby se XSD v repozitáři
 * vyměnilo, spadne tenhle test dřív než produkční podání — a to je celý smysl
 * připínání.
 */
final class SicknessXmlBuilderTest extends TestCase
{
    private SicknessXmlValidator $validator;
    private NempriXmlSerializer $nempri;
    private HzupnXmlSerializer $hzupn;

    protected function setUp(): void
    {
        $this->nempri = new NempriXmlSerializer();
        $this->hzupn = new HzupnXmlSerializer();
        $this->validator = new SicknessXmlValidator(
            new CsszSchemaCatalog(),
            $this->nempri,
            $this->hzupn,
        );
    }

    public function testNempriForSicknessBenefitValidatesAgainstPinnedSchema(): void
    {
        $payload = $this->nempriPayload();
        $xml = $this->nempri->serialize($payload);

        $this->validator->validateNempri($payload, $xml);

        self::assertStringContainsString(
            '<druhDavky>NEM</druhDavky>',
            $xml,
        );
        // Základní typ je rozšiřovaný, takže `pracoval` musí předcházet
        // `pobiraDuchod`. Prohozené pořadí XSD odmítne.
        self::assertLessThan(
            strpos($xml, '<pobiraDuchod>'),
            strpos($xml, '<pracoval>'),
        );
        // Rozhodné období je u nemocenského povinné vždy a nese součty.
        self::assertStringContainsString('<zapocitatelnyPrijemCelkem>360000</zapocitatelnyPrijemCelkem>', $xml);
    }

    /**
     * Příjem z malého rozsahu je v XSD v celých Kč. Případ ho drží v haléřích
     * a dřív se do věty psal bez převodu — XSD ho pustilo (je to jen celé
     * číslo), ale ČSSZ by přečetla stonásobek.
     */
    public function testSmallScopeIncomeIsSentInWholeCrowns(): void
    {
        $payload = $this->nempriPayload(['smallScopeIncomeMinor' => 350_050]);
        $xml = $this->nempri->serialize($payload);

        $this->validator->validateNempri($payload, $xml);

        self::assertStringContainsString('<prijemMalyRozsah>3501</prijemMalyRozsah>', $xml);
    }

    public function testNempriForCompensatoryAllowanceOmitsUnpaidLeaveSection(): void
    {
        $payload = $this->nempriPayload([
            'benefitKind' => SicknessBenefitKind::Vpm,
            'decisionNumber' => null,
            'unpaidLeave' => false,
            'unpaidLeaveFrom' => null,
            'unpaidLeaveTo' => null,
        ]);
        $xml = $this->nempri->serialize($payload);

        $this->validator->validateNempri($payload, $xml);

        // `CtPotvrzeniZamestnavateleVpm` prvek `volnoBezNahrady` nemá.
        self::assertStringNotContainsString('volnoBezNahrady', $xml);
        self::assertStringContainsString('<druhDavky>VPM</druhDavky>', $xml);
    }

    /**
     * ČSSZ páruje oznámení o nemocenském s eNeschopenkou podle čísla
     * rozhodnutí. Dřív bylo číslo povinné jen u opravného podání, takže šla
     * připravit věta, kterou ČSSZ nezpracuje.
     */
    public function testNempriRequiresDecisionNumberOutsideForeignCase(): void
    {
        $payload = $this->nempriPayload(['decisionNumber' => null]);

        try {
            $this->validator->validateNempri($payload, $this->nempri->serialize($payload));
            self::fail('Nemocenské bez čísla rozhodnutí ČSSZ nespáruje.');
        } catch (SicknessException $exception) {
            self::assertSame('nempri_decision_number_missing', $exception->validationCode);
        }

        $foreign = $this->nempriPayload(['decisionNumber' => null, 'foreignCase' => true]);
        $this->validator->validateNempri($foreign, $this->nempri->serialize($foreign));
        self::assertTrue(true);
    }

    public function testHzupnRequiresConfirmationNumberOutsideForeignCase(): void
    {
        $payload = $this->hzupnPayload(['confirmationNumber' => null]);

        try {
            $this->validator->validateHzupn($payload, $this->hzupn->serialize($payload), '2026-08-03');
            self::fail('Hlášení bez čísla rozhodnutí ČSSZ nespáruje s neschopenkou.');
        } catch (SicknessException $exception) {
            self::assertSame('hzupn_confirmation_number_missing', $exception->validationCode);
        }

        $foreign = $this->hzupnPayload(['confirmationNumber' => null, 'foreignCase' => true]);
        $this->validator->validateHzupn($foreign, $this->hzupn->serialize($foreign), '2026-08-03');
        self::assertTrue(true);
    }

    /**
     * „Nevrátil se do práce“ nese důvod (nástup na PPM, skončení zaměstnání),
     * ale ne datum návratu: DV HZUPN20 datum zakazuje při „N“.
     */
    public function testHzupnAcceptsNoReturnWithReasonAndDate(): void
    {
        $payload = $this->hzupnPayload([
            'returnedToWork' => false,
            'returnReason' => 'skončení zaměstnání',
            'returnedOn' => null,
            'hoursWorkedLastDay' => null,
            'shiftHoursLastDay' => null,
            'workIntervals' => [],
        ]);
        $xml = $this->hzupn->serialize($payload);

        $this->validator->validateHzupn($payload, $xml, '2026-08-03');

        self::assertStringContainsString('<navratDoPrace>N</navratDoPrace>', $xml);
        self::assertStringNotContainsString('datumNavratDoPrace', $xml);
        self::assertStringContainsString('<duvodNavratDoPrace>skončení zaměstnání</duvodNavratDoPrace>', $xml);
    }

    public function testHzupnNoReturnNeedsReason(): void
    {
        $payload = $this->hzupnPayload([
            'returnedToWork' => false,
            'returnReason' => null,
            'returnedOn' => null,
            'hoursWorkedLastDay' => null,
            'shiftHoursLastDay' => null,
        ]);

        try {
            $this->validator->validateHzupn($payload, $this->hzupn->serialize($payload), '2026-08-03');
            self::fail('Hlášení „nevrátil se“ bez důvodu nic neříká.');
        } catch (SicknessException $exception) {
            self::assertSame('hzupn_return_reason_missing', $exception->validationCode);
        }
    }

    /**
     * Variabilní symbol je v obou XSD typ N s pevnou délkou 10 a vzorem
     * `[1-9][0-9]*`. Doplnit ho zleva nulami tedy NELZE — to je past, do které
     * spadne každý, kdo si vzor opíše z jiné agendy.
     */
    public function testNempriRejectsVariableSymbolPaddedWithLeadingZero(): void
    {
        $payload = $this->nempriPayload([
            'employerVariableSymbol' => '0123456789',
        ]);

        try {
            $this->validator->validateNempri(
                $payload,
                $this->nempri->serialize($payload),
            );
            self::fail('Variabilní symbol s nulou na začátku musí podání shodit.');
        } catch (SicknessException $exception) {
            self::assertSame(
                'sickness_variable_symbol_invalid',
                $exception->validationCode,
            );
        }
    }

    public function testNempriRejectsCorrectionWithoutDecisionNumber(): void
    {
        $payload = $this->nempriPayload([
            'correction' => true,
            'decisionNumber' => null,
        ]);

        try {
            $this->validator->validateNempri(
                $payload,
                $this->nempri->serialize($payload),
            );
            self::fail('Opravné podání bez čísla rozhodnutí nemá co opravit.');
        } catch (SicknessException $exception) {
            self::assertSame(
                'nempri_correction_without_decision_number',
                $exception->validationCode,
            );
        }
    }

    /**
     * Snapshot je pravda podání. XML, které neodpovídá payloadu, se nesmí uložit
     * jako artefakt — jinak by nikdo nepoznal, že se odeslalo něco jiného, než
     * co je v evidenci.
     */
    public function testNempriRejectsXmlThatDoesNotMatchPayload(): void
    {
        $payload = $this->nempriPayload();
        $tampered = str_replace(
            '<insolvence>false</insolvence>',
            '<insolvence>true</insolvence>',
            $this->nempri->serialize($payload),
        );

        try {
            $this->validator->validateNempri($payload, $tampered);
            self::fail('Podvržené XML musí validace zachytit.');
        } catch (SicknessException $exception) {
            self::assertSame(
                'nempri_xml_snapshot_mismatch',
                $exception->validationCode,
            );
        }
    }

    public function testHzupnValidatesAgainstPinnedSchema(): void
    {
        $payload = $this->hzupnPayload();
        $xml = $this->hzupn->serialize($payload);

        $this->validator->validateHzupn($payload, $xml, '2026-08-03');

        // `simpleLType` je výčet písmen; `true` by XSD odmítlo.
        self::assertStringContainsString('<hlasZamest>A</hlasZamest>', $xml);
        self::assertStringContainsString('<hlasOsoby>N</hlasOsoby>', $xml);
        self::assertStringContainsString('<pracovalOd>2026-08-10</pracovalOd>', $xml);
    }

    /**
     * DV_HZUPN20_v12 vede `opravnePodani` jako povinný (XSD ho připouští
     * vynechat); ČSSZ přijala řádná hlášení Premier s „N". Píše se proto vždy.
     */
    public function testHzupnAlwaysCarriesCorrectionFlag(): void
    {
        $regular = $this->hzupnPayload();
        $regularXml = $this->hzupn->serialize($regular);
        $this->validator->validateHzupn($regular, $regularXml, '2026-08-03');
        self::assertStringContainsString('<opravnePodani>N</opravnePodani>', $regularXml);

        $correction = $this->hzupnPayload(['correction' => true]);
        self::assertStringContainsString(
            '<opravnePodani>A</opravnePodani>',
            $this->hzupn->serialize($correction),
        );
    }

    public function testHzupnRejectsWorkIntervalBeforeIncapacity(): void
    {
        $payload = $this->hzupnPayload([
            'workIntervals' => [['from' => '2026-07-01', 'to' => '2026-07-02']],
        ]);

        try {
            $this->validator->validateHzupn(
                $payload,
                $this->hzupn->serialize($payload),
                '2026-08-03',
            );
            self::fail('Práce před vznikem neschopnosti do hlášení nepatří.');
        } catch (SicknessException $exception) {
            self::assertSame(
                'hzupn_work_interval_before_incapacity',
                $exception->validationCode,
            );
        }
    }

    public function testHzupnRejectsOverlappingWorkIntervals(): void
    {
        $payload = $this->hzupnPayload([
            'workIntervals' => [
                ['from' => '2026-08-10', 'to' => '2026-08-12'],
                ['from' => '2026-08-12', 'to' => '2026-08-14'],
            ],
        ]);

        try {
            $this->validator->validateHzupn(
                $payload,
                $this->hzupn->serialize($payload),
                '2026-08-03',
            );
            self::fail('Překryv intervalů zdvojí vyloučené dny.');
        } catch (SicknessException $exception) {
            self::assertSame(
                'hzupn_work_intervals_overlap',
                $exception->validationCode,
            );
        }
    }

    /**
     * Tiskopis je společný pro zaměstnavatele i pro osobu dobrovolně
     * nemocensky pojištěnou. Druhé podání ale činí pojištěnec sám; aplikace
     * zaměstnavatele ho za něj sestavovat nesmí.
     */
    public function testHzupnRefusesVoluntarilyInsuredPersonReport(): void
    {
        $payload = $this->hzupnPayload([
            'employerReport' => false,
            'personReport' => true,
        ]);

        try {
            $this->validator->validateHzupn(
                $payload,
                $this->hzupn->serialize($payload),
                '2026-08-03',
            );
            self::fail('Hlášení pojištěnce není podání zaměstnavatele.');
        } catch (SicknessException $exception) {
            self::assertSame(
                'hzupn_employer_report_required',
                $exception->validationCode,
            );
        }
    }

    /** @param array<string,mixed> $overrides */
    private function nempriPayload(array $overrides = []): NempriXmlPayload
    {
        $values = [
            'benefitKind' => SicknessBenefitKind::Nem,
            'osszCode' => 115,
            'correction' => false,
            'decisionNumber' => 'A1234567',
            'foreignCase' => false,
            'insuredFirstName' => 'Jan',
            'insuredLastName' => 'Novák',
            'insuredBirthNumber' => '8001011227',
            'insuredPhone' => null,
            'insuredEmail' => null,
            'employerVariableSymbol' => '1234567890',
            'employerIdentificationNumber' => '12345678',
            'employerName' => 'ACME s.r.o.',
            'employmentFrom' => '2020-01-01',
            'employmentTo' => null,
            'activityCode' => '1',
            'workedOnDecisiveDay' => true,
            'hoursWorked' => '4.00',
            'dailyWorkingHours' => '8.00',
            'smallScopeIncomeMinor' => null,
            'receivesPension' => false,
            'pensionKind' => null,
            'isStudent' => false,
            'withinSchoolHolidays' => null,
            'firstEmploymentFreeTime' => false,
            'unpaidLeave' => false,
            'unpaidLeaveFrom' => null,
            'unpaidLeaveTo' => null,
            'startsMaternity' => null,
            'childBirthDate' => null,
            'transferredOtherWork' => false,
            'transferredOn' => null,
            'enforcement' => false,
            'insolvency' => false,
            'additionalNote' => null,
            'productName' => 'MyUcto',
            'productVersion' => '1.0',
            'payloadVersion' => '1.0',
            'decisivePeriod' => self::fullPeriod(),
            'paymentConnection' => new NempriPaymentConnection(
                NempriPaymentConnection::KIND_ACCOUNT_CZ,
                accountPrefix: '19',
                accountNumber: '1000000005',
                bankCode: '0100',
            ),
        ];

        return new NempriXmlPayload(...[...$values, ...$overrides]);
    }

    /** Úplné rozhodné období 2025-09 až 2026-08, každý měsíc 30 000 Kč. */
    private static function fullPeriod(): NempriDecisivePeriod
    {
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $date = (new \DateTimeImmutable('2025-09-01'))->modify('+' . $i . ' months');
            $months[] = new NempriDecisiveMonth(
                (int) $date->format('Y'),
                (int) $date->format('n'),
                3_000_000,
                0,
                NempriDecisiveMonth::SOURCE_TAKEOVER,
            );
        }

        return new NempriDecisivePeriod('2025-09-01', '2026-08-31', $months, true);
    }

    /** @param array<string,mixed> $overrides */
    private function hzupnPayload(array $overrides = []): HzupnXmlPayload
    {
        $values = [
            'employerReport' => true,
            'personReport' => false,
            'foreignCase' => false,
            'confirmationNumber' => 'E1234567',
            'osszCode' => 115,
            'osszName' => null,
            'issuedOn' => '2026-08-24',
            'correction' => false,
            'insuredFirstName' => 'Jan',
            'insuredLastName' => 'Novák',
            'insuredTitle' => null,
            'insuredBirthNumber' => '8001011227',
            'insuredBirthDate' => '1980-01-01',
            'employerName' => 'ACME s.r.o.',
            'employerIdentificationNumber' => '12345678',
            'employerVariableSymbol' => '1234567890',
            'returnedToWork' => true,
            'returnReason' => null,
            'returnedOn' => '2026-08-24',
            'hoursWorkedLastDay' => '4',
            'shiftHoursLastDay' => '8',
            'workIntervals' => [['from' => '2026-08-10', 'to' => '2026-08-11']],
            'productName' => 'MyUcto',
            'productVersion' => '1.0',
            'payloadVersion' => '20201.01',
        ];

        return new HzupnXmlPayload(...[...$values, ...$overrides]);
    }
}
