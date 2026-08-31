<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Service\Payroll\Deadline\PayrollDeadlineOverviewService;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpStatementService;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSubmissionService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionBridgeService;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojSubmissionService;
use MyInvoice\Service\Payroll\Submission\Registration\Change\PayrollRegistrationChangeDetectionService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationSubmissionService;
use MyInvoice\Service\Payroll\Submission\Regzel\RegzelSubmissionBridgeService;
use MyInvoice\Service\Submission\Channel\Isds\IsdsTransportAvailabilityResolver;

/**
 * Jeden měsíční přehled pro účetní: co přesně vygenerovat a odeslat, kam,
 * jakou cestou, do kdy — a u toho, co odeslat nejde, PROČ.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč tahle třída nic sama negeneruje ani neodesílá
 * ═══════════════════════════════════════════════════════════════════════════
 * Je to ČTECÍ skladač nad třemi existujícími prameny pravdy:
 *
 *   1. {@see PayrollSubmissionRepository::listOverview()} — obligace a jejich
 *      poslední podání za zvolené OBDOBÍ (`period_start`), tentýž dotaz, který
 *      používá záložka „Stav odeslání",
 *   2. {@see PayrollDeadlineOverviewService::itemsForWindow()} — odvody,
 *      checklist, registrační změny, roční vyúčtování a případy nemocenského
 *      pojištění za OKNO due_on = kalendářní měsíc, tentýž pramen jako panel
 *      zákonných termínů,
 *   3. {@see PayrollStatutoryAgendaCatalog} — ověřená matice schopností pro
 *      NEMPRI/HZUPN/ELDP/úrazové pojištění, kterou už používá záložka „Další
 *      povinnosti".
 *
 * Kdyby si tahle třída data stavěla znovu, rozešla by se s panely, které nad
 * týmiž daty už existují — a účetní by dostala DRUHOU verzi pravdy o tom, co
 * je hotové.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč se odsud neodesílá rovnou
 * ═══════════════════════════════════════════════════════════════════════════
 * Skutečné odeslání (náhled PVPOJ/pojišťovny, volba účtárny, Mobilní klíč,
 * dávkové potvrzení) žije v {@see \MyInvoice\Service\Payroll\Submission\Isds\PayrollIsdsSubmissionService},
 * {@see \MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceIsdsSubmissionService}
 * a jejich frontendových panelech — hotové, otestované a bohatší, než co by
 * šlo znovu postavit tady. Tenhle přehled proto místo druhé kopie posílací
 * logiky vrací ODKAZ na existující záložku s předvyplněným obdobím; splňuje
 * to „tlačítko rovnou tam" doslova, jen bez zdvojení pěti set řádků logiky.
 *
 * Odkaz ale musí vést TAM, KDE TO JDE. U nemocenských hlášení vedl na „Stav
 * odeslání", což je obrazovka kanálu VREP/APEP — a tudy NEMPRI ani HZUPN
 * odeslat nejde. Odkaz proto míří na Nemocenské případy.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Pravidlo pro `action.kind`
 * ═══════════════════════════════════════════════════════════════════════════
 * Každá položka nese PRÁVĚ JEDNU ze tří hodnot:
 *   `send`     — jde to odeslat (automatická brána nebo Mobilní klíč),
 *   `generate` — není to (ještě) vygenerované, nebo to vůbec není podání
 *                (platba, úkon v kartě zaměstnance) — odkaz vede tam, kde
 *                se to udělá,
 *   `manual`   — appka to poslat neumí, `reason` říká PROČ jednou větou.
 *
 * `done=true` navíc znamená, že už není co dělat (splněno/zrušeno) — `kind`
 * pak popisuje, co by se dělalo, kdyby splněno nebylo (pro konzistenci dat),
 * ale UI místo tlačítka ukáže stav.
 */
final readonly class PayrollMonthlyChecklistService
{
    public function __construct(
        private PayrollSubmissionRepository $submissions,
        private PayrollDeadlineOverviewService $deadlines,
        private PayrollDeadlineAssessmentService $assessments,
        private PayrollStatutoryAgendaCatalog $statutoryCatalog,
        private IsdsTransportAvailabilityResolver $transportAvailability,
    ) {}

    /**
     * @return array{
     *   environment:string,period:string,window:array{from:string,to:string},
     *   summary:array{total:int,send:int,generate:int,manual:int,done:int},
     *   items:list<array<string,mixed>>
     * }
     */
    public function checklist(
        int $supplierId,
        string $environment,
        string $period,
    ): array {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException(
                'Firma měsíčního přehledu podání není platná.',
            );
        }
        if (!in_array($environment, ['production', 'test'], true)) {
            throw new \InvalidArgumentException(
                'Prostředí měsíčního přehledu podání musí být production nebo test.',
            );
        }
        [$periodStart, $periodEnd] = self::periodBounds($period);
        // Dostupnost ISDS transportu závisí jen na firmě a prostředí, ne na
        // konkrétním řádku — počítá se tu JEDNOU a dál se předává jako
        // parametr (třída je `readonly`, takže si to nemůže schovat do
        // vlastní mutovatelné vlastnosti).
        $transport = $this->transportAvailability->resolve($supplierId, $environment);

        $items = [
            ...$this->submissionRows($supplierId, $environment, $periodStart, $periodEnd, $transport),
            ...$this->deadlineRows($supplierId, $environment, $periodStart, $periodEnd),
        ];
        usort(
            $items,
            static fn (array $a, array $b): int
                => [$a['due_on'], $a['source'], $a['agenda_label']]
                <=> [$b['due_on'], $b['source'], $b['agenda_label']],
        );

        $summary = ['total' => count($items), 'send' => 0, 'generate' => 0, 'manual' => 0, 'done' => 0];
        foreach ($items as $item) {
            if ($item['done'] === true) {
                ++$summary['done'];
                continue;
            }
            ++$summary[(string) $item['action']['kind']];
        }

        return [
            'environment' => $environment,
            'period' => $period,
            'window' => ['from' => $periodStart, 'to' => $periodEnd],
            'summary' => $summary,
            'items' => $items,
        ];
    }

    /**
     * Agendové povinnosti období: JMHZ, ELDP, OZUSPOJ, REGZEL, registrace
     * u ČSSZ (kontrolní náhled), zdravotní agenda a PŘIPRAVENÁ nemocenská
     * hlášení — tentýž dotaz jako záložka „Stav odeslání", jen BEZ filtru na
     * jednu skupinu.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * Proč se NEMPRI a HZUPN nekříží s pramenem `sickness_case`
     * ═══════════════════════════════════════════════════════════════════════
     * Prameny jsou disjunktní KONSTRUKCÍ, ne filtrem:
     *
     *   * povinnost v `payroll_obligations` u nemocenského hlášení vzniká až
     *     v {@see \MyInvoice\Service\Payroll\Submission\Sickness\SicknessSubmissionService::prepare()},
     *     takže sem se dostane jen PŘIPRAVENÉ podání,
     *   * {@see \MyInvoice\Service\Payroll\Deadline\PayrollDeadlineOverviewService}
     *     naopak případ s vyplněným `*_submission_id` přeskakuje, takže ze
     *     zdroje `sickness_case` přijdou jen NEPŘIPRAVENÁ.
     *
     * Dřív se sem NEMPRI a HZUPN vůbec nepouštěly. Připravené hlášení tím
     * z přehledu ZMIZELO: z termínů vypadlo (je připravené) a mezi povinnosti
     * se nedostalo (bylo vyfiltrované) — přesně ve chvíli, kdy zbýval jediný
     * krok, totiž odeslat ho.
     *
     * @param array{automatic:bool,channel:string,reason:?string} $transport
     * @return list<array<string,mixed>>
     */
    private function submissionRows(
        int $supplierId,
        string $environment,
        string $periodStart,
        string $periodEnd,
        array $transport,
    ): array {
        $page = $this->submissions->listOverview(
            $supplierId,
            $environment,
            $periodStart,
            $periodEnd,
            PayrollSubmissionRepository::LIST_MAX_LIMIT,
            0,
            null,
        );

        $rows = [];
        foreach ($page['items'] as $row) {
            $assessment = $this->assessments->assess(
                $row['earliest_submission_on'],
                $row['due_on'],
                $row['status'],
                $row['latest_submission']['status'] ?? null,
            );
            $done = in_array($assessment->phase, ['fulfilled', 'cancelled'], true);
            $generated = $row['latest_submission'] !== null;
            $description = $this->submissionAgendaDescription(
                (string) $row['agenda_code'],
                (string) $row['subject_reference'],
                $transport,
            );

            $rows[] = [
                'key' => 'submission:' . $row['id'],
                'source' => 'submission',
                'agenda_code' => $row['agenda_code'],
                // Lidský název dodává frontend přes i18n z `agenda_code`
                // (viz `agendaLabel()` v panelu) — tohle pole se u agendových
                // povinností nečte, zůstává tu jen kvůli jednotnému tvaru
                // řádku napříč prameny.
                'agenda_label' => $row['agenda_code'],
                'subject' => PayrollObligationSubjectFormatter::humanSubject(
                    (string) $row['agenda_code'],
                    (string) $row['subject_reference'],
                ),
                'period' => substr($row['period_start'], 0, 7),
                'due_on' => $row['due_on'],
                'phase' => $assessment->phase,
                'days_to_due' => $assessment->daysToDue,
                'is_overdue' => $assessment->isOverdue,
                'status' => $row['latest_submission']['status'] ?? $row['status'],
                'document' => $description['document'],
                'recipient' => self::withApplicable($description['recipient']),
                'channel' => self::withApplicable($description['channel']),
                'done' => $done,
                'action' => $done
                    ? $description['action']
                    : ($generated ? $description['action'] : [
                        'kind' => 'generate',
                        'label' => 'Připravit podání',
                        'path' => $description['tab_path'],
                        'reason' => null,
                    ]),
            ];
        }

        return $rows;
    }

    /**
     * Odvody, checklist, registrační změny, roční vyúčtování a nemocenské
     * případy — okno je KALENDÁŘNÍ MĚSÍC podle DUE_ON, ne podle období, ke
     * kterému se povinnost vztahuje. Tyhle prameny (na rozdíl od agendových
     * povinností výš) nejsou vázané na jedno mzdové období: lhůta u člověka
     * nebo splatnost odvodu může padnout do vybraného měsíce, i když se týká
     * jiného mzdového běhu.
     *
     * @return list<array<string,mixed>>
     */
    private function deadlineRows(
        int $supplierId,
        string $environment,
        string $periodStart,
        string $periodEnd,
    ): array {
        $period = substr($periodStart, 0, 7);
        $rows = [];
        foreach ($this->deadlines->itemsForWindow(
            $supplierId,
            $environment,
            $periodStart,
            $periodEnd,
        ) as $item) {
            $source = (string) $item['source'];
            if ($source === 'submission') {
                // Pokryto bohatší cestou v submissionRows(); viz docblock.
                continue;
            }
            $description = match ($source) {
                'levy' => $this->levyDescription($item),
                'checklist' => $this->checklistDescription($item),
                'registration_change' => $this->registrationChangeDescription($item),
                'tax_statement' => $this->taxStatementDescription($item),
                'sickness_case' => $this->sicknessCaseDescription($item, $period),
                default => throw new \LogicException(
                    'Neznámý pramen mzdového termínu: ' . $source,
                ),
            };
            $rows[] = [
                'key' => $source . ':' . $item['reference'],
                'source' => $source,
                'agenda_code' => $description['agenda_code'],
                'agenda_label' => $description['agenda_label'],
                'subject' => $item['subject'],
                'period' => $item['period'],
                'due_on' => $item['due_on'],
                'phase' => $item['phase'],
                'days_to_due' => $item['days_to_due'],
                'is_overdue' => $item['is_overdue'],
                'status' => $description['status'],
                'document' => $description['document'],
                'recipient' => self::withApplicable($description['recipient']),
                'channel' => self::withApplicable($description['channel']),
                'done' => false,
                'action' => $description['action'],
            ];
        }

        return $rows;
    }

    /**
     * Popis konkrétní agendové povinnosti (formát, příjemce, kanál, akce).
     *
     * Statická data tu smí být jen tam, kde jsou OVĚŘENÁ přímo v kódu agendy
     * (viz odkazy v jednotlivých větvích) — dvě agendy (JMHZ, zdravotní) mají
     * kanál DYNAMICKÝ podle {@see IsdsTransportAvailabilityResolver}, tedy
     * bez toho, aby se dal předem napsat jako řetězec. Neznámý `agendaCode`
     * (starý/legacy kód bez živého generátoru) dostane poctivé „neznámo",
     * ne smyšlený popis.
     *
     * @param array{automatic:bool,channel:string,reason:?string} $transport
     * @return array{
     *   document:array{format:?string,note:string},
     *   recipient:array{label:?string,note:string},
     *   channel:array{label:?string,note:string},
     *   action:array{kind:string,label:string,path:?string,reason:?string},
     *   tab_path:?string
     * }
     */
    private function submissionAgendaDescription(
        string $agendaCode,
        string $subjectReference,
        array $transport,
    ): array {
        return match ($agendaCode) {
            // Odesílací cesta: {@see \MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzIsdsSubmissionService}.
            JmhzSubmissionBridgeService::AGENDA_CODE => $this->isdsAgendaDescription(
                'XML (JMHZ — jednotné měsíční hlášení zaměstnavatele)',
                'ČSSZ',
                '/payroll/submissions/jmhz',
                $transport,
            ),
            // Nemocenská hlášení: kanál ISDS je doložený až do tvaru zprávy
            // ({@see \MyInvoice\Service\Payroll\Submission\Sickness\SicknessChannelCatalog})
            // a odesílá se z karty případu
            // ({@see \MyInvoice\Service\Payroll\Submission\Sickness\SicknessSubmissionService::enqueueDataBox()}).
            // Kanál VREP/APEP pro ně otevřený není — protokol v1.47 pro ně
            // neuvádí identifikátor třídy podání — takže odkaz vede na
            // Nemocenské případy, ne na „Stav odeslání".
            'NEMPRI', 'HZUPN' => $this->isdsAgendaDescription(
                'XML (' . $agendaCode . ' — hlášení zaměstnavatele o dávce nemocenského pojištění)',
                'ČSSZ (nebo místně příslušná OSSZ)',
                '/payroll/submissions/sickness',
                $transport,
            ),
            // {@see EldpStatementService} — „Odesílá člověk, ne tahle
            // služba": kanál `other`, žádný transport na to nesahá.
            EldpStatementService::AGENDA_CODE => [
                'document' => ['format' => 'XML (evidenční list důchodového pojištění)', 'note' => ''],
                'recipient' => ['label' => 'ČSSZ', 'note' => ''],
                'channel' => ['label' => null, 'note' => 'Aplikace XML sestaví a zvaliduje, ale odeslání nemá zapojené.'],
                'action' => [
                    'kind' => 'manual',
                    'label' => 'Otevřít evidenční listy',
                    'path' => '/payroll/submissions/eldp',
                    'reason' => 'Appka evidenční list jen sestaví — odešlete ho ručně datovou schránkou nebo přes VREP.',
                ],
                'tab_path' => '/payroll/submissions/eldp',
            ],
            // {@see OzuspojSubmissionService} — kanál `vrep_apep`, adaptér
            // pro automatické odeslání zatím nevznikl.
            OzuspojSubmissionService::AGENDA_CODE => [
                'document' => ['format' => 'XML (oznámení záměru uplatňovat slevu / jeho skončení)', 'note' => ''],
                'recipient' => ['label' => 'ČSSZ', 'note' => ''],
                'channel' => ['label' => null, 'note' => 'VREP/APEP ČSSZ přijímá, appka pro něj ale nemá odesílací adaptér.'],
                'action' => [
                    'kind' => 'manual',
                    'label' => 'Otevřít záměr slevy',
                    'path' => '/payroll/submissions/discount_intents',
                    'reason' => 'XML se v appce připraví, odešlete ho ručně datovou schránkou nebo přes VREP/APEP.',
                ],
                'tab_path' => '/payroll/submissions/discount_intents',
            ],
            // {@see RegzelSubmissionBridgeService} — kanál je explicitně
            // `manual_upload`.
            RegzelSubmissionBridgeService::AGENDA_CODE => [
                'document' => ['format' => 'XML (REGZELDOPL — doplňující věta registrace zaměstnavatele)', 'note' => ''],
                'recipient' => ['label' => 'ČSSZ', 'note' => ''],
                'channel' => ['label' => null, 'note' => 'Kanál je ručně nahrávaný (manual_upload).'],
                'action' => [
                    'kind' => 'manual',
                    'label' => 'Otevřít REGZEL',
                    'path' => '/payroll/submissions/regzel',
                    'reason' => 'XML si appka jen připraví ke stažení — nahrajte ho ručně do datové schránky.',
                ],
                'tab_path' => '/payroll/submissions/regzel',
            ],
            // {@see PayrollRegistrationSubmissionService} — u PREZEC/REGZEC
            // a prvotní registrace zaměstnavatele je `official_submission.supported`
            // natvrdo `false`: appka umí jen kontrolní náhled.
            PayrollRegistrationSubmissionService::AGENDA_PREZEC,
            PayrollRegistrationSubmissionService::AGENDA_REGZEC,
            PayrollRegistrationSubmissionService::AGENDA_EMPLOYER_REGISTRATION => [
                'document' => ['format' => 'kontrolní náhled XML (PREZEC/REGZEC), ne právně úplné podání', 'note' => ''],
                'recipient' => ['label' => 'ČSSZ', 'note' => ''],
                'channel' => ['label' => null, 'note' => 'Appka nemá odesílací cestu — jde jen o náhled.'],
                'action' => [
                    'kind' => 'manual',
                    'label' => 'Otevřít podání ČSSZ',
                    'path' => '/payroll/submissions/jmhz',
                    'reason' => 'Appka umí jen kontrolní náhled, právně úplné podání zatím nepodporuje — vyřiďte přes portál ČSSZ nebo VREP.',
                ],
                'tab_path' => '/payroll/submissions/jmhz',
            ],
            // {@see HealthInsuranceSubmissionService} — odesílací cesta
            // {@see \MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceIsdsSubmissionService}.
            HealthInsuranceSubmissionService::AGENDA_BULK_NOTIFICATION,
            HealthInsuranceSubmissionService::AGENDA_PAYMENT_OVERVIEW => $this->isdsAgendaDescription(
                'XML nebo PDF podle toho, co pojišťovna přijímá',
                PayrollObligationSubjectFormatter::humanSubject($agendaCode, $subjectReference),
                '/payroll/submissions/health',
                $transport,
            ),
            default => [
                'document' => ['format' => null, 'note' => 'Neznámo — appka pro tuhle agendu nemá ověřený generátor.'],
                'recipient' => ['label' => null, 'note' => ''],
                'channel' => ['label' => null, 'note' => ''],
                'action' => [
                    'kind' => 'manual',
                    'label' => 'Otevřít podání',
                    'path' => '/payroll/submissions/other',
                    'reason' => 'Aplikace pro tuhle agendu nemá ověřenou cestu generování ani odeslání — ověřte ručně.',
                ],
                'tab_path' => '/payroll/submissions/other',
            ],
        };
    }

    /**
     * Společný tvar pro agendy s dynamickým ISDS transportem (JMHZ, zdravotní).
     *
     * Dostupnost se počítá JEDNOU za firmu a prostředí v {@see self::checklist()}
     * a sem se předává parametrem — resolver na konkrétním řádku vůbec
     * nezávisí, takže by bylo zbytečné (a u `readonly` třídy nemožné bez
     * druhé pomocné třídy) volat ho znovu pro každou položku.
     *
     * @param array{automatic:bool,channel:string,reason:?string} $transport
     * @return array{
     *   document:array{format:?string,note:string},
     *   recipient:array{label:?string,note:string},
     *   channel:array{label:?string,note:string},
     *   action:array{kind:string,label:string,path:?string,reason:?string},
     *   tab_path:?string
     * }
     */
    private function isdsAgendaDescription(
        string $format,
        ?string $recipient,
        string $tabPath,
        array $transport,
    ): array {
        $sendable = $transport['automatic'] || $transport['channel'] === 'mobile_key';

        return [
            'document' => ['format' => $format, 'note' => ''],
            'recipient' => ['label' => $recipient, 'note' => ''],
            'channel' => [
                'label' => match ($transport['channel']) {
                    'gateway' => 'datová schránka — odesílací brána',
                    'mobile_key' => 'datová schránka — Mobilní klíč',
                    default => 'datová schránka — ručně',
                },
                'note' => '',
            ],
            'action' => $sendable
                ? ['kind' => 'send', 'label' => 'Odeslat', 'path' => $tabPath, 'reason' => null]
                : [
                    'kind' => 'manual',
                    'label' => 'Otevřít podání',
                    'path' => $tabPath,
                    'reason' => 'Firma nemá nastavenou odesílací bránu ani doloženou datovou schránku pro '
                        . 'Mobilní klíč — stáhněte připravenou zprávu z detailu podání a odešlete ji ručně.',
                ],
            'tab_path' => $tabPath,
        ];
    }

    /** @param array<string,mixed> $item */
    private function levyDescription(array $item): array
    {
        $kind = (string) $item['title'];
        $label = match ($kind) {
            'social_insurance' => 'Sociální pojištění (odvod)',
            'health_insurance' => 'Zdravotní pojištění (odvod)',
            'advance_tax' => 'Záloha na daň z příjmů ze závislé činnosti',
            'withholding_tax' => 'Srážková daň',
            'statutory_insurance' => 'Zákonné pojištění odpovědnosti zaměstnavatele (úrazové)',
            default => $kind,
        };
        $note = $kind === 'statutory_insurance'
            ? 'Čtvrtletní platba — appka ji spočítá, ale nikam ji neodesílá; jde jen o úhradu.'
            : '';

        return [
            'agenda_code' => $kind,
            'agenda_label' => $label,
            'status' => 'open',
            'document' => ['format' => null, 'note' => 'Bez dokumentu — jde o platbu, ne o podání.' . ($note !== '' ? ' ' . $note : '')],
            'recipient' => ['label' => (string) $item['subject'], 'note' => ''],
            'channel' => ['label' => 'bankovní převod', 'note' => 'ABO/SEPA export v modulu Platby.'],
            'action' => [
                'kind' => 'generate',
                'label' => 'Otevřít platby',
                'path' => $item['path'],
                'reason' => null,
            ],
        ];
    }

    /**
     * `$item['title']` nese `item_key` — {@see PayrollDeadlineOverviewService::checklistItems()}
     * ho posílá pod klíčem `title`, ne `item_key`.
     *
     * @param array<string,mixed> $item
     */
    private function checklistDescription(array $item): array
    {
        return [
            'agenda_code' => (string) $item['title'],
            'agenda_label' => (string) $item['title'],
            'status' => 'pending',
            'document' => ['format' => null, 'note' => 'Úkon v kartě zaměstnance, ne samostatné podání.'],
            // Úkon v kartě zaměstnance nikam neodchází — příjemce jako pojem
            // na něj nesedí, ne že bychom ho neznali.
            'recipient' => ['label' => null, 'note' => '', 'applicable' => false],
            'channel' => ['label' => null, 'note' => 'Vyřídí se v kartě zaměstnance.'],
            'action' => [
                'kind' => 'generate',
                'label' => 'Otevřít kartu zaměstnance',
                'path' => $item['path'],
                'reason' => null,
            ],
        ];
    }

    /** @param array<string,mixed> $item */
    private function registrationChangeDescription(array $item): array
    {
        $isRegistration = (string) $item['title'] === PayrollRegistrationChangeDetectionService::DUTY_REGISTRATION;

        if ($isRegistration) {
            return [
                'agenda_code' => (string) $item['title'],
                'agenda_label' => 'Změna registrace u ČSSZ (REGZEC)',
                'status' => 'pending',
                'document' => ['format' => 'kontrolní náhled XML (REGZEC), ne právně úplné podání', 'note' => ''],
                'recipient' => ['label' => 'ČSSZ', 'note' => ''],
                // Žádný kanál neexistuje (appka umí jen náhled) — důvod je
                // vidět ve sloupci „Co s tím" (`action.reason`), tady stačí
                // „netýká se", ne dvojení stejné věty.
                'channel' => ['label' => null, 'note' => '', 'applicable' => false],
                'action' => [
                    'kind' => 'manual',
                    'label' => 'Otevřít kartu zaměstnance',
                    'path' => $item['path'],
                    'reason' => 'Appka umí jen kontrolní náhled, právně úplné podání zatím nepodporuje — vyřiďte přes portál ČSSZ nebo VREP.',
                ],
            ];
        }

        return [
            'agenda_code' => (string) $item['title'],
            'agenda_label' => 'Oznámení změny zdravotní pojišťovně',
            'status' => 'pending',
            'document' => ['format' => null, 'note' => ''],
            'recipient' => ['label' => 'zdravotní pojišťovna', 'note' => ''],
            'channel' => ['label' => null, 'note' => 'Vyřídí se v kartě zaměstnance / záložce Zdravotní.'],
            'action' => [
                'kind' => 'generate',
                'label' => 'Otevřít kartu zaměstnance',
                'path' => $item['path'],
                'reason' => null,
            ],
        ];
    }

    /** @param array<string,mixed> $item */
    private function taxStatementDescription(array $item): array
    {
        return [
            'agenda_code' => (string) $item['form_code'],
            'agenda_label' => (string) $item['form_code'] . ' (roční vyúčtování daně)',
            'status' => 'pending',
            'document' => ['format' => (string) $item['form_code'] . ' (XML)', 'note' => ''],
            'recipient' => ['label' => 'Finanční úřad', 'note' => ''],
            'channel' => ['label' => 'EPO / datová schránka', 'note' => 'Mimo modul Mzdy.'],
            'action' => [
                'kind' => 'generate',
                'label' => 'Otevřít přípravu vyúčtování',
                'path' => $item['path'],
                'reason' => null,
            ],
        ];
    }

    /**
     * NEMPRI má JEDINOU period-závislou větev v {@see PayrollStatutoryAgendaCatalog}:
     * před rokem 2025 je `capability` `not_supported` (legacy varianta), jinak
     * `prepared_only`. Bez tohohle dotazu by staré období dostalo tlačítko
     * „Připravit", i když appka tuhle variantu vědomě neumí — přesně ten druh
     * tvrzení, co si tenhle přehled nesmí dovolit.
     *
     * @param array<string,mixed> $item
     */
    private function sicknessCaseDescription(array $item, string $period): array
    {
        $agenda = (string) $item['title'];
        $matrix = $this->statutoryCatalog->forPeriod($period);
        $entry = null;
        foreach ($matrix['agendas'] as $candidate) {
            if ($candidate['agenda_code'] === $agenda) {
                $entry = $candidate;
                break;
            }
        }

        if ($entry !== null && $entry['capability'] === 'not_supported') {
            return [
                'agenda_code' => $agenda,
                'agenda_label' => $agenda,
                'status' => 'not_supported',
                'document' => ['format' => null, 'note' => 'Aplikace tuhle variantu podání nepodporuje.'],
                // Appka tenhle případ vůbec nesestaví — příjemce ani kanál na
                // něj proto nesedí; PROČ appka nepomůže, říká `action.reason`.
                'recipient' => ['label' => null, 'note' => '', 'applicable' => false],
                'channel' => ['label' => null, 'note' => '', 'applicable' => false],
                'action' => [
                    'kind' => 'manual',
                    'label' => 'Vyřídit mimo aplikaci',
                    'path' => null,
                    'reason' => $this->statutoryReasonText((string) $entry['reason_code']),
                ],
            ];
        }

        // Případ, ze kterého se ještě nepřipravilo podání. Chybí tedy KROK
        // PŘÍPRAVY, ne odeslání — kanál se popisuje pravdivě už tady, aby se
        // účetní nedozvěděla až po přípravě, že „odeslání uděláte ručně“,
        // což od zavedení {@see \MyInvoice\Service\Payroll\Submission\Sickness\SicknessSubmissionService::enqueueDataBox()}
        // není pravda.
        return [
            'agenda_code' => $agenda,
            'agenda_label' => $agenda,
            'status' => 'not_prepared',
            'document' => ['format' => 'XML (' . $agenda . ')', 'note' => ''],
            'recipient' => ['label' => 'ČSSZ (nebo místně příslušná OSSZ)', 'note' => ''],
            'channel' => [
                'label' => 'datová schránka',
                'note' => 'Appka XML připraví, zvaliduje a zařadí k odeslání datovou schránkou.',
            ],
            'action' => [
                'kind' => 'generate',
                'label' => 'Připravit v Nemocenských případech',
                'path' => '/payroll/submissions/sickness',
                'reason' => null,
            ],
        ];
    }

    /**
     * Věta pro `reason_code` z {@see PayrollStatutoryAgendaCatalog}. Katalog
     * nese jen strojový kód (frontendová záložka „Další povinnosti" ho
     * překládá přes i18n); tenhle přehled skládá text na serveru, takže
     * potřebuje vlastní mapování. Neznámý kód dostane obecnou, ale pravdivou
     * větu — ne vymyšlenou specifickou.
     */
    private function statutoryReasonText(string $reasonCode): string
    {
        return match ($reasonCode) {
            'nempri_legacy_variant_not_supported' =>
                'Appka tuhle historickou variantu NEMPRI (před rokem 2025) nepodporuje — vyřiďte ji mimo aplikaci.',
            default => 'Appka pro tenhle případ nemá podporovanou cestu (kód ' . $reasonCode . ') — ověřte a vyřiďte ručně.',
        };
    }

    /**
     * `applicable=false` je jiná informace než chybějící `label` — „tahle
     * položka nikam neodchází, otázka na příjemce/kanál na ni nesedí" místo
     * „nevíme, ale mělo by tu něco být". Popisné funkce nastavují `applicable`
     * jen tam, kde je vědomě `false`; jinak (výchozí, i skutečně NEZNÁMÉ
     * případy jako neznámá agenda) doplní tenhle wrapper `true` — chybějící
     * `label` s `applicable=true` je poctivé „neznámo, ověřte".
     *
     * @param array{label:?string,note:string,applicable?:bool} $field
     * @return array{label:?string,note:string,applicable:bool}
     */
    private static function withApplicable(array $field): array
    {
        return $field + ['applicable' => true];
    }

    /** @return array{string,string} */
    private static function periodBounds(string $period): array
    {
        if (preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $period) !== 1) {
            throw new \InvalidArgumentException(
                'Období měsíčního přehledu musí mít formát RRRR-MM.',
            );
        }
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $period . '-01');
        if (!$start instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException(
                'Období měsíčního přehledu není platné.',
            );
        }

        return [
            $start->format('Y-m-d'),
            $start->modify('last day of this month')->format('Y-m-d'),
        ];
    }
}
