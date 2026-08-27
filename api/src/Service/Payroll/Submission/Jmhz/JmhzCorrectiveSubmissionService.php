<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Report\EpoEnvelope;
use Psr\Clock\ClockInterface;

/**
 * Zmrazení STORNUJÍCÍHO a OPRAVNÉHO podání do odesílatelné podoby.
 *
 * XML pro obojí umí ({@see JmhzCancellationXmlSerializer},
 * {@see JmhzComponentCancellationXmlSerializer}) — chyběla cesta ven. Tahle
 * třída ji dodává: z už odeslaného řádného podání udělá podání druhu
 * `cancellation` nebo `correction`, jeho datovou větu uloží jako artefakt a
 * nechá ho ve stavu `ready`. Odesílá se pak TOUTÉŽ cestou jako řádné hlášení
 * ({@see \MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzDispatchService::send()}),
 * včetně ledgeru pokusů, dotažení protokolu a uzavření transakce.
 *
 * Čtyři věci, které se tu nesmí ztratit:
 *
 * 1. **Vazba na původní podání.** `corrects_submission_id` ukazuje na řádné
 *    podání a `idPodani` v XML je JEHO GUID — storno se váže na to, co ČSSZ
 *    opravdu má. Bez obojího by posloupnost „podal jsem, pak stornoval" nešla
 *    dohledat ani u nás, ani u úřadu.
 * 2. **Identita se čte ze zmrazeného XML**, ne z databáze. GUID, variabilní
 *    symbol i rozhodné období jsou přesně ty hodnoty, které úřad dostal.
 * 3. **Stornovat lze jen odeslané.** Podání, které nikdy neopustilo aplikaci,
 *    u ČSSZ neexistuje a rušit se nemá co; storno by se vázalo na GUID, o kterém
 *    úřad nic neví.
 * 4. **Lhůta.** Storno jde podat jen do konce lhůty pro řádné podání; po ní už
 *    jen opravným hlášením. Hlídá to {@see JmhzCancellationRequest} a odmítnutí
 *    je tady jediná správná odpověď.
 */
final readonly class JmhzCorrectiveSubmissionService
{
    private const CHANNEL = 'vrep_apep';
    private const PRODUCT_NAME = 'MyÚčto.cz';

    public function __construct(
        private PayrollSubmissionRepository $repository,
        private PayrollSubmissionService $submissions,
        private PayrollObligationService $obligations,
        private JmhzFrozenPayloadReader $frozen,
        private ClockInterface $clock,
        private JmhzDeadlinePolicy $deadlines,
        private JmhzCancellationXmlSerializer $cancellations = new JmhzCancellationXmlSerializer(),
        private JmhzComponentCancellationXmlSerializer $componentCancellations
            = new JmhzComponentCancellationXmlSerializer(),
    ) {
    }

    /**
     * Storno celého podání (typ „S") — za rozhodné období se ruší VŠECHNO, co
     * bylo podáno, ne jen poslední hlášení.
     *
     * @return array{
     *   submission_id:int,part_id:int,artifact_id:int,status:string,
     *   row_version:int,environment:string,artifact_sha256:string,
     *   created:bool,submission_kind:string,corrects_submission_id:int,
     *   submission_guid:string,variable_symbol:string,month:int,year:int
     * }
     */
    public function cancelSubmission(
        int $supplierId,
        string $environment,
        int $originalSubmissionId,
        ?int $createdBy = null,
    ): array {
        return $this->freeze(
            $supplierId,
            $environment,
            $originalSubmissionId,
            'cancellation',
            'storno',
            [],
            $createdBy,
        );
    }

    /**
     * Opravné podání (typ „O"), které stornuje jmenované součásti — konkrétní
     * pracovněprávní vztahy se zneplatňují a zbytek hlášení zůstává platný.
     *
     * @param list<string> $formGuids GUIDy vybrané ze zmrazeného řádného podání
     * @return array{
     *   submission_id:int,part_id:int,artifact_id:int,status:string,
     *   row_version:int,environment:string,artifact_sha256:string,
     *   created:bool,submission_kind:string,corrects_submission_id:int,
     *   submission_guid:string,variable_symbol:string,month:int,year:int
     * }
     */
    public function cancelComponents(
        int $supplierId,
        string $environment,
        int $originalSubmissionId,
        array $formGuids,
        ?int $createdBy = null,
    ): array {
        if ($formGuids === []) {
            throw new JmhzXmlException(
                'jmhz_amendment_without_components',
                'Opravné podání bez jediné součásti neopravuje nic.',
            );
        }

        return $this->freeze(
            $supplierId,
            $environment,
            $originalSubmissionId,
            'correction',
            'oprava',
            $formGuids,
            $createdBy,
        );
    }

    /**
     * @return list<array{
     *   form_guid:string,
     *   person_external_identifier:string,
     *   employment_external_identifier:string
     * }>
     */
    public function correctableComponents(
        int $supplierId,
        string $environment,
        int $originalSubmissionId,
    ): array {
        $original = $this->requireOriginal(
            $supplierId,
            $environment,
            $originalSubmissionId,
        );
        if ($original['status'] !== 'accepted') {
            throw new \DomainException(
                'Vztahy lze stornovat až po úplném přijetí řádného hlášení.'
                    . ' U částečného výsledku nejdříve načtěte úplný protokol ČSSZ.',
            );
        }

        return $this->frozen->components(
            $supplierId,
            $environment,
            $originalSubmissionId,
        );
    }

    /**
     * @param list<string> $requestedFormGuids
     * @return array{
     *   submission_id:int,part_id:int,artifact_id:int,status:string,
     *   row_version:int,environment:string,artifact_sha256:string,
     *   created:bool,submission_kind:string,corrects_submission_id:int,
     *   submission_guid:string,variable_symbol:string,month:int,year:int
     * }
     */
    private function freeze(
        int $supplierId,
        string $environment,
        int $originalSubmissionId,
        string $submissionKind,
        string $referencePrefix,
        array $requestedFormGuids,
        ?int $createdBy,
    ): array {
        if ($supplierId <= 0 || $originalSubmissionId <= 0) {
            throw new \InvalidArgumentException(
                'Rozsah opravného podání JMHZ není platný.',
            );
        }
        return $this->repository->transaction(function () use (
            $supplierId,
            $environment,
            $originalSubmissionId,
            $submissionKind,
            $referencePrefix,
            $requestedFormGuids,
            $createdBy,
        ): array {
            if (!$this->repository->lockSupplier($supplierId)) {
                throw new \DomainException('Firma JMHZ podání nebyla nalezena.');
            }
            $formGuids = $submissionKind === 'correction'
                ? self::canonicalFormGuids($requestedFormGuids)
                : [];
            $snapshotHash = self::snapshotHash(
                $supplierId,
                $environment,
                $originalSubmissionId,
                $submissionKind,
                $formGuids,
            );
            $keys = self::idempotencyKeys($submissionKind, $snapshotHash);
            $original = $this->requireOriginal($supplierId, $environment, $originalSubmissionId);
            $identity = $this->frozen->identity($supplierId, $environment, $originalSubmissionId);
            $existing = $this->repository->findSubmissionByIdempotencyForUpdate(
                $supplierId,
                hash('sha256', $keys['submission'], true),
                $environment,
            );
            if ($existing !== null) {
                return $this->replayed(
                    $supplierId,
                    $environment,
                    $existing,
                    $keys['artifact'],
                    $identity,
                    $originalSubmissionId,
                );
            }
            if (!in_array($original['status'], ['accepted', 'partially_accepted'], true)) {
                throw new \DomainException(
                    'Storno nebo opravu lze připravit až po konečném přijetí'
                        . ' řádného hlášení ČSSZ.',
                );
            }
            if ($submissionKind === 'correction' && $original['status'] !== 'accepted') {
                throw new \DomainException(
                    'U částečně přijatého hlášení zatím nelze bezpečně určit platné'
                        . ' vztahy. Nejdříve načtěte úplný protokol ČSSZ.',
                );
            }
            $components = $submissionKind === 'correction'
                ? $this->resolveFrozenComponents(
                    $supplierId,
                    $environment,
                    $originalSubmissionId,
                    $formGuids,
                )
                : [];
            $obligation = $this->repository->findObligationOfSubmission(
                $supplierId,
                $environment,
                $originalSubmissionId,
            );
            if ($obligation === null) {
                throw new JmhzXmlException(
                    'jmhz_submission_obligation_required',
                    'K původnímu podání chybí evidovaná povinnost, takže se na ně'
                        . ' nedá navázat storno ani oprava.',
                );
            }
            // Zadání se ověřuje uvnitř stejné zamčené transakce jako zmrazení.
            // Storno po lhůtě by ČSSZ odmítla; souběžné částečné storno zase nesmí
            // obejít kontrolu, že v řádném hlášení zůstane aspoň jedna platná část.
            $request = JmhzCancellationRequest::create(
                $identity->submissionGuid,
                $identity->variableSymbol,
                $identity->year,
                $identity->month,
                $this->deadlines,
                $this->localDate(),
            );
            $envelope = JmhzSubmissionEnvelope::createForExistingSubmission(
                $identity->submissionGuid,
                [],
                $this->filledAt(),
                self::PRODUCT_NAME,
                EpoEnvelope::appVersion() ?? '0',
            );
            $xml = $components === []
                ? $this->cancellations->serialize($request, $envelope)
                : $this->componentCancellations->serialize($request, $components, $envelope);

            // Platforma vyžaduje, aby druh podání odpovídal druhu povinnosti —
            // storno tedy nemůže viset pod povinností řádného hlášení. Vlastní
            // povinnost je i správně věcně: má vlastní lhůtu a vlastní stav, takže
            // ji inbox podání sleduje odděleně od původního hlášení.
            $correctiveObligationId = $this->correctiveObligation(
                $supplierId,
                $environment,
                $obligation,
                $submissionKind,
                $originalSubmissionId,
                $createdBy,
            );

            $submission = $this->submissions->prepare(
                $supplierId,
                $correctiveObligationId,
                $submissionKind,
                self::CHANNEL,
                $snapshotHash,
                $keys['submission'],
                null,
                $originalSubmissionId,
                $createdBy,
                $environment,
            );
            if (!$submission['created']) {
                return $this->replayed(
                    $supplierId,
                    $environment,
                    $submission,
                    $keys['artifact'],
                    $identity,
                    $originalSubmissionId,
                );
            }

            $part = $this->submissions->addPart(
                $supplierId,
                $submission['id'],
                $submission['row_version'],
                "jmhz25-{$referencePrefix}:{$originalSubmissionId}",
                JmhzSubmissionBridgeService::AGENDA_CODE,
                $obligation['subject_reference'],
                'jmhz_submission',
                "jmhz_submission:{$originalSubmissionId}",
                $snapshotHash,
            );
            $artifact = $this->submissions->storeArtifact(
                $supplierId,
                $submission['id'],
                $part['submission_row_version'],
                $part['id'],
                'outbound_xml',
                'outbound',
                'application/xml',
                $xml,
                JmhzSchemaCatalog::PACKAGE_KEY,
                JmhzControlSourceCatalog::CATALOG_KEY,
                self::CHANNEL,
                $keys['artifact'],
                $createdBy,
            );
            $validated = $this->submissions->transition(
                $supplierId,
                $submission['id'],
                $artifact['submission_row_version'],
                'validated',
            );
            $ready = $this->submissions->transition(
                $supplierId,
                $submission['id'],
                $validated['row_version'],
                'ready',
            );

            return [
                'submission_id' => $submission['id'],
                'part_id' => $part['id'],
                'artifact_id' => $artifact['id'],
                'status' => $ready['status'],
                'row_version' => $ready['row_version'],
                'environment' => $environment,
                'artifact_sha256' => $artifact['artifact_sha256'],
                'created' => true,
                'submission_kind' => $submissionKind,
                'corrects_submission_id' => $originalSubmissionId,
                'submission_guid' => $identity->submissionGuid,
                'variable_symbol' => $identity->variableSymbol,
                'month' => $identity->month,
                'year' => $identity->year,
            ];
        });
    }

    /**
     * Idempotentní opakování. XML se NESTAVÍ znovu — vrací se to, co je
     * zmrazené; jinak by druhé kliknutí vyrobilo jiný dokument pod týmž podáním.
     *
     * @param array{
     *   id:int,status:string,row_version:int,request_fingerprint:string,
     *   source_snapshot_hash:string,submission_kind:string,channel:string,
     *   environment:string,obligation_id:int,corrects_submission_id:?int,
     *   correlation_reference:?string,created:bool
     * } $submission
     * @return array{
     *   submission_id:int,part_id:int,artifact_id:int,status:string,
     *   row_version:int,environment:string,artifact_sha256:string,
     *   created:bool,submission_kind:string,corrects_submission_id:int,
     *   submission_guid:string,variable_symbol:string,month:int,year:int
     * }
     */
    private function replayed(
        int $supplierId,
        string $environment,
        array $submission,
        string $artifactKey,
        JmhzFrozenSubmissionIdentity $identity,
        int $originalSubmissionId,
    ): array {
        $artifact = $this->repository->findArtifactByIdempotencyForUpdate(
            $supplierId,
            hash('sha256', $artifactKey, true),
            $environment,
        );
        if ($artifact === null
            || $artifact['submission_id'] !== $submission['id']
            || $artifact['part_id'] === null
        ) {
            throw new JmhzXmlException(
                'jmhz_submission_replay_mismatch',
                'Zmrazený artefakt opravného podání JMHZ chybí nebo neodpovídá podání.',
            );
        }

        return [
            'submission_id' => $submission['id'],
            'part_id' => $artifact['part_id'],
            'artifact_id' => $artifact['id'],
            'status' => $submission['status'],
            'row_version' => $submission['row_version'],
            'environment' => $environment,
            'artifact_sha256' => $artifact['artifact_sha256'],
            'created' => false,
            'submission_kind' => $submission['submission_kind'],
            'corrects_submission_id' => $originalSubmissionId,
            'submission_guid' => $identity->submissionGuid,
            'variable_symbol' => $identity->variableSymbol,
            'month' => $identity->month,
            'year' => $identity->year,
        ];
    }

    /**
     * Povinnost pro storno nebo opravu. Registruje se idempotentně a ve stejném
     * rozsahu jako původní hlášení (agenda, subjekt, období), jen s jiným
     * druhem — a s vlastní lhůtou z {@see JmhzDeadlinePolicy}, protože právě
     * ta rozhoduje, do kdy se ještě smí stornovat.
     *
     * @param array{
     *   id:int,status:string,row_version:int,agenda_code:string,
     *   subject_type:string,subject_reference:string,
     *   period_start:string,period_end:string
     * } $obligation
     */
    private function correctiveObligation(
        int $supplierId,
        string $environment,
        array $obligation,
        string $submissionKind,
        int $originalSubmissionId,
        ?int $createdBy,
    ): int {
        $window = $this->deadlines->forPeriod($obligation['period_start']);
        $reference = "jmhz_submission:{$originalSubmissionId}";
        $registered = $this->obligations->register(
            $supplierId,
            $obligation['agenda_code'],
            $obligation['subject_type'],
            $obligation['subject_reference'],
            $obligation['period_start'],
            $obligation['period_end'],
            $submissionKind,
            self::CHANNEL,
            'jmhz_submission_correction',
            $reference,
            hash('sha256', CanonicalJson::encode([
                'schema_reference' => 'payroll-jmhz-corrective-obligation.v1',
                'corrects_submission_id' => $originalSubmissionId,
                'submission_kind' => $submissionKind,
            ])),
            $window->earliestSubmissionOn,
            $window->dueOn,
            $window->calendarBasis,
            $window->rulesetId,
            $window->rulesetHash,
            "jmhz25-{$submissionKind}-obligation:{$supplierId}:{$environment}:{$originalSubmissionId}",
            null,
            $createdBy,
            null,
            $environment,
        );

        return $registered['id'];
    }

    /** @return array<string,mixed> */
    private function requireOriginal(
        int $supplierId,
        string $environment,
        int $submissionId,
    ): array {
        $original = $this->repository->findSubmission($supplierId, $submissionId);
        if ($original === null || $original['environment'] !== $environment) {
            throw new \DomainException(
                'Původní podání nebylo nalezeno ve stejné firmě a prostředí.',
            );
        }
        if ($original['submission_kind'] !== 'regular'
            || $original['channel'] !== self::CHANNEL
        ) {
            throw new JmhzXmlException(
                'jmhz_cancellation_target_invalid',
                'Stornovat nebo opravovat lze jen řádné měsíční hlášení odeslané'
                    . ' přes VREP.',
            );
        }

        return $original;
    }

    private function filledAt(): string
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    private function localDate(): string
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())
            ->setTimezone(new \DateTimeZone('Europe/Prague'))
            ->format('Y-m-d');
    }

    /**
     * Klient posílá jen GUIDy. Zákonné identifikátory se vždy znovu načtou ze
     * zmrazeného řádného XML, aby je nešlo podvrhnout nebo omylem přepsat.
     *
     * @param list<string> $requestedFormGuids
     * @return list<JmhzComponentCancellation>
     */
    private function resolveFrozenComponents(
        int $supplierId,
        string $environment,
        int $originalSubmissionId,
        array $requestedFormGuids,
    ): array {
        if ($requestedFormGuids === []) {
            throw new JmhzXmlException(
                'jmhz_amendment_without_components',
                'Vyberte alespoň jeden pracovněprávní vztah, který se má stornovat.',
            );
        }
        $normalized = array_fill_keys($requestedFormGuids, true);

        $frozen = $this->frozen->components(
            $supplierId,
            $environment,
            $originalSubmissionId,
        );
        $byGuid = [];
        foreach ($frozen as $component) {
            $byGuid[strtoupper($component['form_guid'])] = $component;
        }
        foreach ($this->repository->resolvedCorrectionsForRoot(
            $supplierId,
            $environment,
            $originalSubmissionId,
        ) as $correction) {
            if ($correction['status'] !== 'accepted') {
                throw new \DomainException(
                    'Předchozí oprava byla přijata jen částečně. Nejdříve načtěte'
                        . ' úplný protokol ČSSZ a vyřešte její jednotlivé formuláře.',
                );
            }
            foreach ($this->frozen->formGuids(
                $supplierId,
                $environment,
                $correction['id'],
            ) as $cancelledGuid) {
                unset($byGuid[$cancelledGuid]);
            }
        }
        foreach (array_keys($normalized) as $guid) {
            if (isset($byGuid[$guid])) {
                continue;
            }
            throw new JmhzXmlException(
                'jmhz_cancellation_component_not_frozen',
                'Vybraný pracovní vztah není mezi dosud platnými součástmi původního podání.',
            );
        }
        if (count($normalized) >= count($byGuid)) {
            throw new JmhzXmlException(
                'jmhz_cancellation_would_leave_no_valid_form',
                'Vybráním všech vztahů by v hlášení nic nezůstalo; použijte storno celého podání.',
            );
        }

        $guids = array_keys($normalized);
        sort($guids, SORT_STRING);
        $resolved = [];
        foreach ($guids as $guid) {
            $source = $byGuid[$guid];
            $resolved[] = JmhzComponentCancellation::create(
                $source['form_guid'],
                $source['person_external_identifier'],
                $source['employment_external_identifier'],
            );
        }

        return $resolved;
    }

    /** @param list<string> $formGuids @return list<string> */
    private static function canonicalFormGuids(array $formGuids): array
    {
        if ($formGuids === []) {
            throw new JmhzXmlException(
                'jmhz_amendment_without_components',
                'Vyberte alespoň jeden pracovněprávní vztah, který se má stornovat.',
            );
        }
        $normalized = [];
        foreach ($formGuids as $formGuid) {
            if (!is_string($formGuid) || trim($formGuid) === '') {
                throw new JmhzXmlException(
                    'jmhz_cancellation_component_invalid',
                    'Vybraný formulář pracovního vztahu nemá platný identifikátor.',
                );
            }
            $guid = strtoupper(trim($formGuid));
            if (isset($normalized[$guid])) {
                throw new JmhzXmlException(
                    'jmhz_cancellation_component_duplicate',
                    'Tentýž pracovní vztah je ke stornu vybraný vícekrát.',
                );
            }
            $normalized[$guid] = true;
        }
        $guids = array_keys($normalized);
        sort($guids, SORT_STRING);

        return $guids;
    }

    /**
     * Otisk zdroje. Řádné podání ho má z mzdové revize; storno žádnou revizi
     * nemá, takže se počítá z toho, co ho jednoznačně určuje — a hlavně z jeho
     * kanonického výběru. Čas vyplnění do něj nepatří: opakované kliknutí i po
     * několika sekundách musí vrátit původní neměnný artefakt.
     *
     * @param list<string> $formGuids
     */
    private static function snapshotHash(
        int $supplierId,
        string $environment,
        int $originalSubmissionId,
        string $submissionKind,
        array $formGuids,
    ): string {
        return hash('sha256', CanonicalJson::encode([
            'schema_reference' => 'payroll-jmhz-corrective-submission.v2',
            'supplier_id' => $supplierId,
            'environment' => $environment,
            'corrects_submission_id' => $originalSubmissionId,
            'submission_kind' => $submissionKind,
            'form_guids' => $formGuids,
        ]));
    }

    /** @return array{submission:string,artifact:string} */
    private static function idempotencyKeys(string $submissionKind, string $snapshotHash): array
    {
        return [
            'submission' => "jmhz25-{$submissionKind}-submission:{$snapshotHash}",
            'artifact' => "jmhz25-{$submissionKind}-artifact:{$snapshotHash}",
        ];
    }
}
