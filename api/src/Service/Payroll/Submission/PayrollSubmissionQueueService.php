<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

use MyInvoice\Repository\Payroll\PayrollSubmissionQueueRepository;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceIsdsSubmissionService;
use MyInvoice\Service\Payroll\Submission\Isds\PayrollIsdsSubmissionService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFrozenPayloadReader;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzDispatchService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationTransportService;

/**
 * Jedna společná fronta odchozích mzdových podání.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Co řeší
 * ═══════════════════════════════════════════════════════════════════════════
 * Do téhle chvíle se každá mzdová agenda odesílala odjinud: JMHZ z „Stavu
 * odeslání", registrace z karty pracovního vztahu (za každým zaměstnancem
 * zvlášť), nemocenské z karty případu, přehledy pojišťovnám ze zdravotního
 * panelu. Účetní tak neměla jediné místo, kde by viděla, co má rozděláno —
 * a opakovaně nevěděla, jestli podání odešlo.
 *
 * Fronta je DRUHÁ CESTA K TÉMUŽ, ne náhrada. Původní tlačítka fungují dál
 * a odesílá se přes TYTÉŽ služby ({@see JmhzDispatchService},
 * {@see PayrollRegistrationTransportService}, {@see PayrollIsdsSubmissionService},
 * {@see HealthInsuranceIsdsSubmissionService}) — druhá odesílací cesta se
 * nepíše. Kdyby se psala, rozešla by se s první přesně v tom, co považuje za
 * zmrazený artefakt.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč se ukazuje i to, co odeslat nejde
 * ═══════════════════════════════════════════════════════════════════════════
 * Protože mlčky vynechaná položka je horší než položka s důvodem: uživatel
 * neví, jestli na ni zapomněl, nebo ji aplikace neumí. Každý řádek proto nese
 * `dispatchable` a — když je `false` — `blocked_reason` jako CELOU VĚTU
 * s lidským názvem údaje, ne kód chyby.
 *
 * Podání se do fronty dostane SAMO tím, že vznikne: fronta je dotaz nad
 * `payroll_submissions`, ne další evidence, kterou by musel někdo plnit.
 * Nová agenda se tedy objeví bez zásahu — a když ji odeslat neumíme, objeví
 * se s poctivým „neumíme, protože…" z {@see PayrollDispatchCapabilityCatalog}.
 */
final class PayrollSubmissionQueueService
{
    public function __construct(
        private readonly PayrollSubmissionQueueRepository $repository,
        private readonly PayrollDeadlineAssessmentService $deadlines,
        private readonly PayrollDispatchCapabilityCatalog $capabilities,
        private readonly PayrollDispatchGate $gate,
        private readonly JmhzDispatchService $jmhz,
        private readonly JmhzFrozenPayloadReader $frozen,
        private readonly PayrollRegistrationTransportService $registration,
        private readonly PayrollIsdsSubmissionService $isds,
        private readonly HealthInsuranceIsdsSubmissionService $healthIsds,
    ) {}

    /**
     * @return array{
     *   items:list<array<string,mixed>>,total:int,limit:int,offset:int,
     *   summary:array{total:int,ready:int,blocked:int,overdue:int}
     * }
     */
    public function queue(
        int $supplierId,
        string $environment,
        int $limit,
        int $offset,
        ?string $agendaCode = null,
        string $sort = 'due',
    ): array {
        $page = $this->repository->listQueue(
            $supplierId,
            $environment,
            $limit,
            $offset,
            $agendaCode,
            $sort,
        );
        $items = $page['items'];

        $submissionIds = array_map(
            static fn (array $item): int => (int) $item['submission_id'],
            $items,
        );
        $issueCounts = $this->repository->blockingIssueCounts(
            $supplierId,
            $environment,
            $submissionIds,
        );
        $names = $this->repository->employmentNames(
            $supplierId,
            array_values(array_filter(array_map(
                static fn (array $item): int => self::employmentIdIn(
                    (string) $item['subject_reference'],
                ) ?? 0,
                $items,
            ))),
        );

        $summary = ['total' => $page['total'], 'ready' => 0, 'blocked' => 0, 'overdue' => 0];
        $decorated = [];
        foreach ($items as $item) {
            $submissionId = (int) $item['submission_id'];
            $capability = $this->capabilities->forAgenda(
                (string) $item['agenda_code'],
            );
            $blockedReason = $this->gate->blockedReason(
                $item,
                $capability,
                $environment,
                $issueCounts[$submissionId] ?? 0,
            );
            $deadline = $this->deadlines->assess(
                (string) $item['earliest_submission_on'],
                (string) $item['due_on'],
                (string) $item['obligation_status'],
                (string) $item['submission_status'],
            );
            if ($blockedReason === null) {
                ++$summary['ready'];
            } else {
                ++$summary['blocked'];
            }
            if ($deadline->isOverdue) {
                ++$summary['overdue'];
            }

            $item['deadline'] = $deadline->toArray();
            $item['dispatch'] = [
                'mode' => $capability->mode,
                'alternate_mode' => $capability->alternateMode,
                'dispatchable' => $blockedReason === null,
                'blocked_reason' => $blockedReason,
            ];
            $item['subject_label'] = $this->subjectLabel($item, $names);
            $item['blocking_issue_count'] = $issueCounts[$submissionId] ?? 0;
            $decorated[] = $item;
        }

        return [
            'items' => $decorated,
            'total' => $page['total'],
            'limit' => max(1, min(
                PayrollSubmissionQueueRepository::LIST_MAX_LIMIT,
                $limit,
            )),
            'offset' => max(0, $offset),
            'agenda_code' => $agendaCode,
            'sort' => $sort,
            'agendas' => $this->repository->agendaFacets($supplierId, $environment),
            // Souhrn je nad STRÁNKOU s výjimkou `total`. Tvrdit „po lhůtě: 3"
            // nad celou tabulkou by znamenalo druhý dotaz; tvrdit to nad
            // stránkou a NEŘÍCT to by lhalo. Fronta proto zůstává na jedné
            // stránce po dvou stech řádcích a UI ukazuje `total` zvlášť.
            'summary' => $summary,
        ];
    }

    /**
     * Kolik podání smí odejít v JEDNOM požadavku.
     *
     * Strop není tvrdý limit dávky, ale velikost PORCE: klient posílá stovku
     * podání po dvaceti pěti, aby žádný požadavek neběžel minuty. Každá porce
     * je vlastní HTTP volání s vlastní odpovědí, takže:
     *   * nespadne na timeoutu (server ani proxy),
     *   * uživatel vidí průběh po porcích, ne mrtvý spinner,
     *   * výpadek uprostřed dávky nechá už odeslané odeslané.
     *
     * Fronta na pozadí by tohle uměla taky, ale za cenu další tabulky, cronu
     * a stavu, který by se musel uklízet — a hlavně by výsledek přestal být
     * SYNCHRONNÍ, takže by účetní zase nevěděla, jestli to odešlo. Právě to
     * je problém, který fronta řeší.
     */
    public const MAX_BATCH_SIZE = 25;

    /**
     * Hromadné odeslání. JEDNA CHYBA NESMÍ SHODIT DÁVKU: každá položka má
     * vlastní výsledek a vlastní idempotenční klíč, co selže zůstane ve frontě
     * s důvodem a dá se poslat znovu.
     *
     * Klíč přichází PER POLOŽKU od klienta, ne jeden na celou dávku — kdyby
     * dávka sdílela jeden klíč, druhá položka by se transportu jevila jako
     * opakování první a tiše by se neodeslala.
     *
     * @param list<array{submission_id:int,idempotency_key:string}> $items
     * @return array{
     *   results:list<array<string,mixed>>,
     *   summary:array{requested:int,sent:int,failed:int}
     * }
     */
    public function dispatchMany(
        int $supplierId,
        string $environment,
        array $items,
        ?int $userId,
    ): array {
        if (count($items) > self::MAX_BATCH_SIZE) {
            throw new \InvalidArgumentException(sprintf(
                'Najednou lze odeslat nejvýš %d podání; zbytek pošlete'
                    . ' v další dávce.',
                self::MAX_BATCH_SIZE,
            ));
        }

        $results = [];
        $sent = 0;
        $failed = 0;
        $seen = [];
        foreach ($items as $item) {
            $submissionId = (int) $item['submission_id'];
            // Tatáž položka dvakrát v jedné dávce je chyba klienta, ne pokyn
            // odeslat ji dvakrát.
            if (isset($seen[$submissionId])) {
                continue;
            }
            $seen[$submissionId] = true;

            try {
                $result = $this->dispatch(
                    $supplierId,
                    $environment,
                    $submissionId,
                    (string) $item['idempotency_key'],
                    $userId,
                );
                $results[] = ['ok' => true, ...$result];
                ++$sent;
            } catch (\Throwable $exception) {
                // Chyba JEDNÉ položky nesmí zastavit zbytek dávky: při stovce
                // zaměstnanců je vždycky někdo, komu chybí údaj, a kvůli němu
                // se nesmí zdržet ostatní. Zachytává se `Throwable`, protože
                // adaptéry kanálů házejí čtyři různé hierarchie výjimek a
                // neznámý typ nesmí shodit dávku o to spíš.
                $results[] = [
                    'ok' => false,
                    'submission_id' => $submissionId,
                    'dispatched' => false,
                    'error_code' => self::errorCodeOf($exception),
                    'message' => $exception->getMessage(),
                ];
                ++$failed;
            }
        }

        return [
            'results' => $results,
            'summary' => [
                'requested' => count($seen),
                'sent' => $sent,
                'failed' => $failed,
            ],
        ];
    }

    private static function errorCodeOf(\Throwable $exception): string
    {
        /** @var object{errorCode?:string} $exception */
        $code = $exception->errorCode ?? null;

        return is_string($code) && $code !== '' ? $code : 'dispatch_failed';
    }

    /**
     * Odeslání jednoho podání z fronty. Vrací normalizovaný výsledek, aby UI
     * nemuselo rozlišovat čtyři různé tvary odpovědi podle kanálu.
     *
     * @return array<string,mixed>
     */
    public function dispatch(
        int $supplierId,
        string $environment,
        int $submissionId,
        string $idempotencyKey,
        ?int $userId,
    ): array {
        $row = $this->requireQueuedRow($supplierId, $environment, $submissionId);
        $capability = $this->capabilities->forAgenda(
            (string) $row['agenda_code'],
        );
        $blockedReason = $this->gate->blockedReason(
            $row,
            $capability,
            $environment,
            $this->repository->blockingIssueCounts(
                $supplierId,
                $environment,
                [$submissionId],
            )[$submissionId] ?? 0,
        );
        if ($blockedReason !== null) {
            // Výjimka zůstává: fronta ten důvod u řádku UKAZUJE, takže se sem
            // dá dostat jen zastaralou stránkou nebo přímým voláním. Tiše to
            // odeslat by znamenalo obejít bránu, kterou UI respektuje.
            throw new \DomainException($blockedReason);
        }

        return match ($capability->mode) {
            PayrollDispatchCapabilityCatalog::MODE_VREP_JMHZ
                => $this->dispatchJmhz(
                    $supplierId,
                    $environment,
                    $submissionId,
                    $idempotencyKey,
                    $userId,
                ),
            PayrollDispatchCapabilityCatalog::MODE_VREP_REGISTRATION
                => $this->dispatchRegistration(
                    $supplierId,
                    $environment,
                    $submissionId,
                    $idempotencyKey,
                    $userId,
                ),
            PayrollDispatchCapabilityCatalog::MODE_ISDS_PAYROLL
                => $this->dispatchIsds(
                    $supplierId,
                    $environment,
                    $submissionId,
                    $userId,
                ),
            PayrollDispatchCapabilityCatalog::MODE_ISDS_HEALTH
                => $this->dispatchHealthIsds(
                    $supplierId,
                    $submissionId,
                    (string) $row['subject_reference'],
                    $userId,
                ),
            default => throw new \LogicException(
                'Neznámý režim odeslání: ' . $capability->mode,
            ),
        };
    }

    /** @return array<string,mixed> */
    private function dispatchJmhz(
        int $supplierId,
        string $environment,
        int $submissionId,
        string $idempotencyKey,
        ?int $userId,
    ): array {
        // Variabilní symbol se NEBERE z požadavku ani z nastavení účtárny —
        // čte se ze ZMRAZENÉ datové věty, protože GovTalk obálka vyžaduje
        // shodu s hlavičkou právě toho XML, které odchází. Stejně to dělá
        // adaptér registrací; kdyby fronta brala symbol odjinud, poslala by
        // obálku, kterou ČSSZ odmítne jako nesouhlasnou.
        $identity = $this->frozen->identity(
            $supplierId,
            $environment,
            $submissionId,
        );
        $outcome = $this->jmhz->send(
            $supplierId,
            $environment,
            $submissionId,
            null,
            $identity->variableSymbol,
            $idempotencyKey,
            $userId,
        );

        return [
            'submission_id' => $submissionId,
            'mode' => PayrollDispatchCapabilityCatalog::MODE_VREP_JMHZ,
            'dispatched' => true,
            'attempt' => $outcome->attempt,
            'settled' => $outcome->isSettled(),
            'correlation_reference' => $outcome->acknowledgement?->correlationId,
            'outbox' => null,
            // Potvrzení o PŘEVZETÍ není potvrzení o přijetí. Věta to říká
            // narovinu, aby účetní nepřestala výsledek sledovat.
            'message' => 'Podání bylo předáno ČSSZ. Úřad potvrdil převzetí;'
                . ' výsledek zpracování se dotáhne sám a objeví se u podání'
                . ' ve stavu odeslání.',
        ];
    }

    /** @return array<string,mixed> */
    private function dispatchRegistration(
        int $supplierId,
        string $environment,
        int $submissionId,
        string $idempotencyKey,
        ?int $userId,
    ): array {
        $result = $this->registration->send(
            $supplierId,
            $environment,
            $submissionId,
            $idempotencyKey,
            $userId,
        );

        return [
            'submission_id' => $submissionId,
            'mode' => PayrollDispatchCapabilityCatalog::MODE_VREP_REGISTRATION,
            'dispatched' => true,
            'attempt' => $result['attempt'] ?? null,
            'settled' => (bool) ($result['settled'] ?? false),
            'correlation_reference' => is_array($result['attempt'] ?? null)
                ? ($result['attempt']['correlation_reference'] ?? null)
                : null,
            'outbox' => null,
            'message' => 'Přihláška nebo odhláška zaměstnance byla předána'
                . ' ČSSZ. Úřad potvrdil převzetí; výsledek se dotáhne sám.',
        ];
    }

    /** @return array<string,mixed> */
    private function dispatchIsds(
        int $supplierId,
        string $environment,
        int $submissionId,
        ?int $userId,
    ): array {
        $result = $this->isds->enqueue(
            $supplierId,
            $environment,
            $submissionId,
            // Prázdný rozsah znamená „cokoliv s doloženým kanálem". Fronta je
            // právě to místo, které NENÍ obrazovkou jedné agendy, takže by
            // vlastní rozsah jen zdvojil katalog.
            [],
            $userId,
        );

        return [
            'submission_id' => $submissionId,
            'mode' => PayrollDispatchCapabilityCatalog::MODE_ISDS_PAYROLL,
            'dispatched' => true,
            'attempt' => null,
            'settled' => false,
            'correlation_reference' => $result['row']['correlation_reference'] ?? null,
            'outbox' => [
                'id' => $result['outbox_id'],
                'created' => $result['created'],
                'recipient' => $result['recipient'],
                'subject' => $result['subject'],
                'transport' => $result['transport'],
            ],
            'message' => self::isdsMessage($result['transport']),
        ];
    }

    /** @return array<string,mixed> */
    private function dispatchHealthIsds(
        int $supplierId,
        int $submissionId,
        string $subjectReference,
        ?int $userId,
    ): array {
        $parts = explode(':', $subjectReference);
        $insurerCode = (string) end($parts);
        $result = $this->healthIsds->enqueue(
            $supplierId,
            $submissionId,
            $insurerCode,
            $userId,
        );

        return [
            'submission_id' => $submissionId,
            'mode' => PayrollDispatchCapabilityCatalog::MODE_ISDS_HEALTH,
            'dispatched' => true,
            'attempt' => null,
            'settled' => false,
            'correlation_reference' => $result['row']['correlation_reference'] ?? null,
            'outbox' => [
                'id' => $result['outbox_id'],
                'created' => $result['created'],
                'recipient' => $result['recipient'],
                'subject' => $result['subject'],
                'transport' => $result['transport'],
            ],
            'message' => self::isdsMessage($result['transport']),
        ];
    }

    /** @param array{automatic:bool,channel:string,reason:?string} $transport */
    private static function isdsMessage(array $transport): string
    {
        return match ($transport['channel']) {
            'gateway' => 'Podání je zařazené v odchozí frontě datové schránky'
                . ' jako koncept. Odeslání ještě potvrďte ve své datové'
                . ' schránce.',
            'mobile_key' => 'Podání je zařazené v odchozí frontě datové'
                . ' schránky. Odešle se hned po potvrzení v mobilní aplikaci.',
            default => 'Podání je zařazené v odchozí frontě datové schránky.'
                . ' Přílohu si stáhněte a odešlete ze své schránky; doručenku'
                . ' pak nahrajte zpátky k podání.',
        };
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $employmentNames
     */
    private function subjectLabel(array $row, array $employmentNames): ?string
    {
        $reference = (string) $row['subject_reference'];
        $employmentId = self::employmentIdIn($reference);
        if ($employmentId !== null && isset($employmentNames[$employmentId])) {
            return $employmentNames[$employmentId];
        }

        // Zbytek (účtárna, pojišťovna) umí sdílený formátovač; kde nezná
        // odpověď, vrací `null` a fronta radši neukáže nic než syrové ID.
        return PayrollObligationSubjectFormatter::humanSubject(
            (string) $row['agenda_code'],
            $reference,
        );
    }

    private static function employmentIdIn(string $subjectReference): ?int
    {
        if (preg_match(
            '/^employment:([1-9][0-9]*)$/D',
            $subjectReference,
            $matches,
        ) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * @return array<string,mixed>
     */
    private function requireQueuedRow(
        int $supplierId,
        string $environment,
        int $submissionId,
    ): array {
        // Fronta se čte znovu, ne z klienta: stav se mohl mezi zobrazením
        // a kliknutím změnit (jiná obrazovka, jiný uživatel, cron).
        $row = $this->repository->findQueued(
            $supplierId,
            $environment,
            $submissionId,
        );
        if ($row !== null) {
            return $row;
        }

        // Výjimka zůstává: hranice mezi firmami i mezi prostředími. Odeslat
        // podání, které ve frontě není, znamená odeslat něco jiného, než co
        // uživatel viděl.
        throw new \DomainException(
            'Tohle podání už ve frontě k odeslání není. Načtěte frontu znovu —'
            . ' mezitím se mohlo odeslat odjinud nebo změnit stav.',
        );
    }
}
