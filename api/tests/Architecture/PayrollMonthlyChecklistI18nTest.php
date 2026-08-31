<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSubmissionService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationSubmissionService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Měsíční přehled skládá „Agenda a předmět" ze SUROVÝCH kódů
 * (`agenda_code`, checklist `item_key`) — lidský název dodává až frontend
 * přes i18n ({@see \MyInvoice\Service\Payroll\Submission\PayrollMonthlyChecklistService}
 * a `PayrollMonthlyChecklistPanel.vue::agendaLabel()`). Chybějící klíč se
 * vue-i18n nepozná jako chyba (klíč se skládá z proměnné za běhu, statická
 * i18n brána `check-i18n.mjs` na něj nedosáhne) — účetní by v prohlížeči
 * uviděl syrový klíč `payroll.submissions.monthly_checklist.agenda.PREZEC26`
 * míso jména agendy. Přesně tenhle druh mezery test hlídá.
 *
 * Vzor je stejný jako u {@see PayrollStatutoryAgendaI18nTest} — na tenhle
 * test cíleně odkazuje zadání, ať se nerozejdou.
 */
#[Group('architecture')]
final class PayrollMonthlyChecklistI18nTest extends TestCase
{
    /**
     * Agendové kódy, které panel překládá VLASTNÍM slovníkem
     * `payroll.submissions.monthly_checklist.agenda.*` — tedy ty, které
     * katalog {@see \MyInvoice\Service\Payroll\Submission\PayrollStatutoryAgendaCatalog}
     * nezná (viz `agendaLabel()`: JMHZ25/ELDP/OZUSPOJ/REGZELDOPL25/NEMPRI/
     * HZUPN/STATUTORY_ACCIDENT_INSURANCE jdou přes `statutory.agenda.*` a hlídá
     * je {@see PayrollStatutoryAgendaI18nTest} už dnes).
     *
     * @return list<string>
     */
    private function submissionAgendaCodes(): array
    {
        return [
            PayrollRegistrationSubmissionService::AGENDA_PREZEC,
            PayrollRegistrationSubmissionService::AGENDA_REGZEC,
            PayrollRegistrationSubmissionService::AGENDA_EMPLOYER_REGISTRATION,
            HealthInsuranceSubmissionService::AGENDA_BULK_NOTIFICATION,
            HealthInsuranceSubmissionService::AGENDA_PAYMENT_OVERVIEW,
        ];
    }

    public function testEverySubmissionAgendaCodeHasCzechAndEnglishLabel(): void
    {
        $codes = $this->submissionAgendaCodes();
        self::assertNotSame([], $codes, 'Sken agendových kódů nic nenašel — test by nekontroloval nic.');

        foreach (['cs', 'en'] as $locale) {
            $agenda = $this->monthlyChecklistAgendaMessages($locale);
            foreach ($codes as $code) {
                $label = $agenda[$code] ?? null;
                self::assertIsString(
                    $label,
                    "Chybí payroll.submissions.monthly_checklist.agenda.{$code} v {$locale}.json",
                );
                self::assertNotSame(
                    '',
                    trim($label),
                    "Prázdný payroll.submissions.monthly_checklist.agenda.{$code} v {$locale}.json",
                );
            }
        }
    }

    /**
     * `agendaLabel()` v panelu rozhoduje mezi `statutory.agenda.*` a
     * `monthly_checklist.agenda.*` podle PEVNÉ množiny kódů, které katalog
     * zná (viz `STATUTORY_CATALOG_AGENDA_CODES` v komponentě). Kdyby se
     * REGZELDOPL25 nebo JMHZ25 z katalogu ztratily a z téhle množiny NE,
     * panel by se ptal na klíč, který v `statutory.agenda.*` už neexistuje —
     * proto tenhle test hlídá TYTÉŽ klíče znovu, z pohledu měsíčního přehledu.
     */
    public function testAgendaCodesSharedWithStatutoryCatalogStayTranslated(): void
    {
        $sharedWithFrontendComponent = [
            'NEMPRI', 'HZUPN', 'ELDP', 'JMHZ25', 'OZUSPOJ', 'REGZELDOPL25',
            'STATUTORY_ACCIDENT_INSURANCE',
        ];

        foreach (['cs', 'en'] as $locale) {
            $statutory = $this->statutoryAgendaMessages($locale);
            foreach ($sharedWithFrontendComponent as $code) {
                $label = $statutory[$code] ?? null;
                self::assertIsString(
                    $label,
                    "Chybí payroll.submissions.statutory.agenda.{$code} v {$locale}.json"
                        . ' — měsíční přehled na tenhle klíč spoléhá stejně jako záložka'
                        . ' Další povinnosti.',
                );
            }
        }
    }

    /**
     * Checklist `item_key` (14 hodnot z onboardingu/změny/offboardingu) má
     * VLASTNÍ, už existující slovník na kartě zaměstnance
     * (`payroll.people.checklist.*`) — měsíční přehled ho jen znovu použije.
     * Seznam kódů se čte reflexí přímo z {@see PayrollEmploymentRepository::CHECKLISTS},
     * aby test automaticky pokryl novou položku checklistu, ne jen dnešní
     * čtrnáctku.
     */
    public function testEveryChecklistItemKeyHasCzechAndEnglishLabel(): void
    {
        $reflection = new ReflectionClass(PayrollEmploymentRepository::class);
        /** @var array<string,list<string>> $checklists */
        $checklists = $reflection->getConstant('CHECKLISTS');
        self::assertNotFalse($checklists, 'PayrollEmploymentRepository::CHECKLISTS nebylo možné přečíst reflexí.');

        $itemKeys = array_values(array_unique(array_merge(...array_values($checklists))));
        self::assertGreaterThanOrEqual(
            10,
            count($itemKeys),
            'Sken checklist item_key nic nenašel — test by nekontroloval nic.',
        );

        foreach (['cs', 'en'] as $locale) {
            $checklist = $this->messages($locale)['payroll']['people']['checklist'] ?? null;
            self::assertIsArray($checklist, "Chybí payroll.people.checklist v {$locale}.json");
            foreach ($itemKeys as $itemKey) {
                $label = $checklist[$itemKey] ?? null;
                self::assertIsString(
                    $label,
                    "Chybí payroll.people.checklist.{$itemKey} v {$locale}.json"
                        . ' — měsíční přehled ho zobrazuje jako název položky checklistu.',
                );
                self::assertNotSame(
                    '',
                    trim($label),
                    "Prázdný payroll.people.checklist.{$itemKey} v {$locale}.json",
                );
            }
        }
    }

    /** @return array<string,mixed> */
    private function messages(string $locale): array
    {
        $root = dirname(__DIR__, 3);

        return json_decode(
            (string) file_get_contents($root . "/web/src/i18n/{$locale}.json"),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /** @return array<string,string> */
    private function monthlyChecklistAgendaMessages(string $locale): array
    {
        $agenda = $this->messages($locale)['payroll']['submissions']['monthly_checklist']['agenda'] ?? null;
        self::assertIsArray(
            $agenda,
            "Chybí payroll.submissions.monthly_checklist.agenda v {$locale}.json",
        );

        return $agenda;
    }

    /** @return array<string,string> */
    private function statutoryAgendaMessages(string $locale): array
    {
        $agenda = $this->messages($locale)['payroll']['submissions']['statutory']['agenda'] ?? null;
        self::assertIsArray($agenda, "Chybí payroll.submissions.statutory.agenda v {$locale}.json");

        return $agenda;
    }
}
