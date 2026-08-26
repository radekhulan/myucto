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
 * Most mezi ověřenou přípravou JMHZ a platformou podání: z přípravy udělá
 * ZMRAZENÉ podání připravené k odeslání na VREP a jeho datovou větu uloží
 * jako artefakt.
 *
 * Tři rozhodnutí, na kterých celá vrstva stojí:
 *
 * 1. **Kanál je `vrep_apep`, ne ruční nahrání.** Není to jen štítek: ledger
 *    pokusů má databázový trigger vyžadující shodu kanálu pokusu s kanálem
 *    podání, takže špatná hodnota tady udělá z podání něco, co se nedá odeslat.
 * 2. **GUIDy vznikají právě jednou.** Test je generuje při každém běhu nové —
 *    proto je jen testem. Tady se vygenerují, zapíšou do XML a to XML se uloží
 *    jako artefakt; artefakt JE zmrazená pravda, žádná další tabulka na GUIDy
 *    není potřeba. Idempotentní opakování proto XML NESMÍ stavět znovu: nové
 *    GUIDy by pod stejným podáním tiše vyrobily jiný dokument a duplicitu
 *    přijatého podání nelze u ČSSZ vzít zpět.
 * 3. **Co by ČSSZ zamítla, se nezmrazí.** Běží tu tentýž katalog kontrol jako
 *    v testu; nepřipravené podání skončí výjimkou a NEZALOŽÍ SE NIC. Zmrazit
 *    vědomě vadné podání znamená jen odsunout zamítnutí blíž ke lhůtě.
 */
final readonly class JmhzSubmissionBridgeService
{
    public const AGENDA_CODE = 'JMHZ25';
    public const SOURCE_EVENT_TYPE = 'jmhz_preparation_snapshot';
    private const CHANNEL = 'vrep_apep';
    private const PRODUCT_NAME = 'MyÚčto.cz';
    private const SUBJECT_TYPE = 'payroll_run';

    public function __construct(
        private JmhzScenario1DocumentService $documents,
        private JmhzScenario1XmlValidator $validator,
        private JmhzScenario1ControlValidator $controls,
        private JmhzSubmissionGuidFactory $guids,
        private PayrollSubmissionRepository $submissionRepository,
        private PayrollSubmissionService $submissions,
        private ClockInterface $clock,
        private PayrollObligationService $obligations,
        private JmhzDeadlinePolicy $deadlines = new JmhzDeadlinePolicy(),
    ) {}

    /**
     * @return array{
     *   submission_id:int,part_id:int,artifact_id:int,
     *   status:string,row_version:int,environment:string,
     *   source_snapshot_hash:string,artifact_sha256:string,created:bool,
     *   submission_guid:string,variable_symbol:string
     * }
     */
    public function bridge(
        int $supplierId,
        int $preparationId,
        ?int $obligationId,
        string $environment,
        ?int $createdBy = null,
        ?int $officeId = null,
    ): array {
        if ($supplierId <= 0
            || $preparationId <= 0
            || ($obligationId !== null && $obligationId <= 0)
            || ($createdBy !== null && $createdBy <= 0)
            || ($officeId !== null && $officeId <= 0)
        ) {
            throw new \InvalidArgumentException(
                'Rozsah JMHZ bridge není platný.',
            );
        }

        // Dokument se řeší JEŠTĚ PŘED transakcí a bez obálky, takže tu žádný
        // GUID nevzniká. Kdyby se sestavoval až uvnitř, nešlo by rozhodnout
        // o idempotenci dřív, než se něco zmrazí.
        $resolution = $this->documents->resolve(
            $supplierId,
            $environment,
            $preparationId,
            $officeId,
        );
        if ($resolution->status() !== 'resolved') {
            throw new JmhzXmlException(
                'jmhz_submission_preparation_blocked',
                'Příprava JMHZ není úplná, podání se nezakládá: '
                    . JmhzBlockerExplainer::describe($resolution->blockers),
            );
        }
        $document = $resolution->requireResolvedDocument();
        $snapshotHash = self::snapshotHash($document);
        $runId = self::runId($document);
        $periodStart = self::periodStart($document);
        $obligationId ??= $this->registerRegularObligation(
            $supplierId,
            $preparationId,
            $environment,
            $createdBy,
            $officeId,
            $snapshotHash,
            $runId,
            $periodStart,
        );
        $keys = self::idempotencyKeys(
            $supplierId,
            $environment,
            $obligationId,
            $snapshotHash,
            $officeId,
        );

        return $this->submissionRepository->transaction(function () use (
            $supplierId,
            $preparationId,
            $obligationId,
            $environment,
            $createdBy,
            $officeId,
            $resolution,
            $document,
            $snapshotHash,
            $runId,
            $periodStart,
            $keys,
        ): array {
            if (!$this->submissionRepository->lockSupplier($supplierId)) {
                throw new \DomainException(
                    'Firma JMHZ podání nebyla nalezena.',
                );
            }
            $this->assertObligation(
                $this->submissionRepository->lockObligation(
                    $supplierId,
                    $obligationId,
                    $environment,
                ),
                $runId,
                $periodStart,
                $officeId,
            );

            $submission = $this->submissions->prepare(
                $supplierId,
                $obligationId,
                'regular',
                self::CHANNEL,
                $snapshotHash,
                $keys['submission'],
                null,
                null,
                $createdBy,
                $environment,
            );
            if (!$submission['created']) {
                return $this->replayedResult(
                    $supplierId,
                    $environment,
                    $snapshotHash,
                    $submission,
                    $keys['artifact'],
                );
            }

            // Odsud dál se mrazí. GUIDy vznikají právě tady a nikde jinde.
            $result = $this->validator->dryRun(
                $resolution,
                JmhzSubmissionEnvelope::create(
                    $this->guids->next(),
                    $this->formGuids($document),
                    $this->filledAt(),
                    self::PRODUCT_NAME,
                    EpoEnvelope::appVersion() ?? '0',
                ),
            );
            // XSD hlídá tvar, katalog kontrol obsah. Nepropustná vada nebo
            // nepokrytá kontrola znamená, že by podání ČSSZ neprošlo — a pak
            // se nesmí založit vůbec nic; výjimka vrátí transakci zpět.
            $controls = $this->controls->validate(
                $result['xml'],
                new JmhzControlContext($this->localDate(), schemaValidated: true),
            );
            if (!$controls->submittable()) {
                throw new JmhzXmlException(
                    'jmhz_submission_controls_failed',
                    'Podání JMHZ neprošlo katalogem kontrol, nezakládá se: '
                        . self::describeControls($controls),
                );
            }
            $identity = self::frozenIdentity($result['xml']);

            $part = $this->submissions->addPart(
                $supplierId,
                $submission['id'],
                $submission['row_version'],
                self::partReference($preparationId, $officeId),
                self::AGENDA_CODE,
                self::runReference($runId, $officeId),
                'jmhz_preparation',
                self::sourceEventReference($preparationId),
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
                $result['xml'],
                $result['schema']['package_key'],
                JmhzControlSourceCatalog::CATALOG_KEY,
                self::CHANNEL,
                $keys['artifact'],
                $createdBy,
            );
            if (!hash_equals(
                $result['sha256'],
                $artifact['artifact_sha256'],
            )) {
                throw new JmhzXmlException(
                    'jmhz_submission_artifact_mismatch',
                    'Otisk uloženého artefaktu neodpovídá zmrazenému XML JMHZ.',
                );
            }
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
                'source_snapshot_hash' => $snapshotHash,
                'artifact_sha256' => $artifact['artifact_sha256'],
                'created' => true,
                'submission_guid' => $identity['submission_guid'],
                'variable_symbol' => $identity['variable_symbol'],
            ];
        });
    }

    public static function sourceEventReference(int $preparationId): string
    {
        if ($preparationId <= 0) {
            throw new \InvalidArgumentException(
                'Příprava JMHZ musí být kladné číslo.',
            );
        }

        return "jmhz_preparation:{$preparationId}";
    }

    private function registerRegularObligation(
        int $supplierId,
        int $preparationId,
        string $environment,
        ?int $createdBy,
        ?int $officeId,
        string $snapshotHash,
        int $runId,
        string $periodStart,
    ): int {
        $period = \DateTimeImmutable::createFromFormat('!Y-m-d', $periodStart);
        if (!$period instanceof \DateTimeImmutable
            || $period->format('Y-m-d') !== $periodStart
        ) {
            throw new JmhzXmlException(
                'jmhz_submission_period_missing',
                'Dokument JMHZ nenese platné vykazované období.',
            );
        }
        $window = $this->deadlines->forPeriod($periodStart);
        $officeKey = $officeId === null ? 'all' : (string) $officeId;
        $registered = $this->obligations->register(
            $supplierId,
            self::AGENDA_CODE,
            self::SUBJECT_TYPE,
            self::runReference($runId, $officeId),
            $periodStart,
            $period->modify('last day of this month')->format('Y-m-d'),
            'regular',
            self::CHANNEL,
            self::SOURCE_EVENT_TYPE,
            self::sourceEventReference($preparationId),
            $snapshotHash,
            $window->earliestSubmissionOn,
            $window->dueOn,
            $window->calendarBasis,
            $window->rulesetId,
            $window->rulesetHash,
            "jmhz25-regular-obligation:{$supplierId}:{$environment}:"
                . "{$preparationId}:{$officeKey}:{$snapshotHash}",
            $createdBy,
            $createdBy,
            null,
            $environment,
        );

        return (int) $registered['id'];
    }

    /**
     * Předmět povinnosti.
     *
     * Hlášení se podává za REGISTRACI u OSSZ, takže běh přes víc účtáren má víc
     * povinností a víc podání. Oddělit je musí právě tahle reference: klíč
     * `uq_payroll_submissions_regular` pouští na jednu povinnost právě jedno
     * řádné podání, takže dvě registrace pod jednou povinností by se tiše
     * srazily na jedno. Jednoúčtárenský běh (`null`) drží původní tvar, aby
     * povinnosti evidované dřív zůstaly platné.
     */
    public static function runReference(int $runId, ?int $officeId = null): string
    {
        if ($runId <= 0 || ($officeId !== null && $officeId <= 0)) {
            throw new \InvalidArgumentException(
                'Mzdový běh a mzdová účtárna musí být kladná čísla.',
            );
        }

        return $officeId === null
            ? "payroll_run:{$runId}"
            : "payroll_run:{$runId}:office:{$officeId}";
    }

    public static function partReference(
        int $preparationId,
        ?int $officeId = null,
    ): string {
        if ($preparationId <= 0 || ($officeId !== null && $officeId <= 0)) {
            throw new \InvalidArgumentException(
                'Příprava JMHZ a mzdová účtárna musí být kladná čísla.',
            );
        }

        return $officeId === null
            ? "jmhz25:{$preparationId}"
            : "jmhz25:{$preparationId}:office:{$officeId}";
    }

    /**
     * Idempotentní opakování. Vrací PŮVODNÍ artefakt a jeho bajty — XML se tu
     * zásadně nestaví znovu, protože by dostalo nové GUIDy a pod týmž podáním
     * by vznikl jiný dokument, než jaký je zmrazený.
     *
     * @param array{
     *   id:int,status:string,row_version:int,request_fingerprint:string,
     *   source_snapshot_hash:string,submission_kind:string,channel:string,
     *   environment:string,obligation_id:int,corrects_submission_id:?int,
     *   correlation_reference:?string,created:bool
     * } $submission
     * @return array{
     *   submission_id:int,part_id:int,artifact_id:int,
     *   status:string,row_version:int,environment:string,
     *   source_snapshot_hash:string,artifact_sha256:string,created:bool,
     *   submission_guid:string,variable_symbol:string
     * }
     */
    private function replayedResult(
        int $supplierId,
        string $environment,
        string $snapshotHash,
        array $submission,
        string $artifactKey,
    ): array {
        if ($submission['status'] !== 'ready'
            || $submission['channel'] !== self::CHANNEL
            || !hash_equals($snapshotHash, $submission['source_snapshot_hash'])
        ) {
            throw new JmhzXmlException(
                'jmhz_submission_replay_state_invalid',
                'Existující podání JMHZ už není v idempotentním stavu ready.',
            );
        }
        $artifact = $this->submissionRepository
            ->findArtifactByIdempotencyForUpdate(
                $supplierId,
                hash('sha256', $artifactKey, true),
                $environment,
            );
        if ($artifact === null
            || $artifact['submission_id'] !== $submission['id']
            || $artifact['part_id'] === null
            || $artifact['artifact_kind'] !== 'outbound_xml'
            || $artifact['direction'] !== 'outbound'
            || $artifact['mime_type'] !== 'application/xml'
            || $artifact['xsd_version'] !== JmhzSchemaCatalog::PACKAGE_KEY
            || $artifact['catalog_version']
                !== JmhzControlSourceCatalog::CATALOG_KEY
            || $artifact['channel'] !== self::CHANNEL
        ) {
            throw new JmhzXmlException(
                'jmhz_submission_replay_mismatch',
                'Zmrazený artefakt podání JMHZ chybí nebo neodpovídá podání.',
            );
        }
        // `artifactBytes()` sám ověřuje délku i SHA-256 proti archivu, takže
        // sem se nedostane nic jiného než přesně to, co se kdysi zmrazilo.
        $identity = self::frozenIdentity(
            $this->submissions->artifactBytes($supplierId, $artifact['id']),
        );

        return [
            'submission_id' => $submission['id'],
            'part_id' => $artifact['part_id'],
            'artifact_id' => $artifact['id'],
            'status' => $submission['status'],
            'row_version' => $submission['row_version'],
            'environment' => $environment,
            'source_snapshot_hash' => $snapshotHash,
            'artifact_sha256' => $artifact['artifact_sha256'],
            'created' => false,
            'submission_guid' => $identity['submission_guid'],
            'variable_symbol' => $identity['variable_symbol'],
        ];
    }

    /**
     * @param array{
     *   id:int,environment:string,agenda_code:string,subject_type:string,
     *   subject_reference:string,period_start:string,period_end:string,
     *   obligation_kind:string,status:string,row_version:int,
     *   earliest_submission_on:string,due_on:string
     * }|null $obligation
     */
    private function assertObligation(
        ?array $obligation,
        int $runId,
        string $periodStart,
        ?int $officeId = null,
    ): void {
        if ($obligation === null) {
            throw new JmhzXmlException(
                'jmhz_submission_obligation_required',
                'Podání JMHZ vyžaduje předem evidovanou povinnost a lhůtu.',
            );
        }
        if ($obligation['agenda_code'] !== self::AGENDA_CODE
            || $obligation['subject_type'] !== self::SUBJECT_TYPE
            || $obligation['subject_reference'] !== self::runReference($runId, $officeId)
            || $obligation['obligation_kind'] !== 'regular'
            || $obligation['period_start'] !== $periodStart
            || !in_array($obligation['status'], ['open', 'prepared'], true)
        ) {
            throw new JmhzXmlException(
                'jmhz_submission_obligation_scope_mismatch',
                'Evidovaná povinnost neodpovídá mzdovému běhu, období ani agendě JMHZ.',
            );
        }
    }

    /**
     * GUID podání a variabilní symbol se čtou ZE ZMRAZENÉHO XML, ne z dokumentu
     * ani z databáze. Transportní vrstva staví obálku právě kolem těchhle dvou
     * hodnot a GovTalk obálka vyžaduje shodu variabilního symbolu s hlavičkou
     * datové věty — dohledávat je znovu jinde by tu shodu mohlo tiše rozbít.
     *
     * @return array{submission_guid:string,variable_symbol:string}
     */
    private static function frozenIdentity(string $xml): array
    {
        $identity = JmhzFrozenSubmissionIdentity::read($xml);

        return [
            'submission_guid' => $identity->submissionGuid,
            'variable_symbol' => $identity->variableSymbol,
        ];
    }

    /** @return array<int,string> */
    private function formGuids(JmhzScenario1NormalizedDocument $document): array
    {
        $people = $document->payload['people'] ?? null;
        $guids = [];
        if (!is_array($people)) {
            return $guids;
        }
        foreach ($people as $person) {
            $employments = is_array($person)
                ? ($person['employments'] ?? null)
                : null;
            if (!is_array($employments)) {
                continue;
            }
            foreach ($employments as $employment) {
                $employmentId = is_array($employment)
                    ? ($employment['employment_id'] ?? null)
                    : null;
                if (is_int($employmentId) && $employmentId > 0) {
                    $guids[$employmentId] = $this->guids->next();
                }
            }
        }

        return $guids;
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

    private static function snapshotHash(
        JmhzScenario1NormalizedDocument $document,
    ): string {
        $provenance = $document->payload['provenance'] ?? null;
        $hash = is_array($provenance)
            ? ($provenance['snapshot_fingerprint'] ?? null)
            : null;
        if (!is_string($hash)
            || preg_match('/^[0-9a-f]{64}$/D', $hash) !== 1
        ) {
            throw new JmhzXmlException(
                'jmhz_submission_snapshot_hash_missing',
                'Dokument JMHZ nenese otisk přípravy, ze které vznikl.',
            );
        }

        return $hash;
    }

    private static function runId(
        JmhzScenario1NormalizedDocument $document,
    ): int {
        $scope = $document->payload['scope'] ?? null;
        $runId = is_array($scope) ? ($scope['run_id'] ?? null) : null;
        if (!is_int($runId) || $runId <= 0) {
            throw new JmhzXmlException(
                'jmhz_submission_run_missing',
                'Dokument JMHZ nenese mzdový běh, ke kterému patří.',
            );
        }

        return $runId;
    }

    private static function periodStart(
        JmhzScenario1NormalizedDocument $document,
    ): string {
        $scope = $document->payload['scope'] ?? null;
        $periodStart = is_array($scope)
            ? ($scope['period_start'] ?? null)
            : null;
        if (!is_string($periodStart)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $periodStart) !== 1
        ) {
            throw new JmhzXmlException(
                'jmhz_submission_period_missing',
                'Dokument JMHZ nenese vykazované období.',
            );
        }

        return $periodStart;
    }

    /**
     * Stejné vstupy musí vést na totéž podání, různé nikdy na společné. Otisk
     * přípravy je v klíči proto, že právě on odlišuje dvě podání za totéž
     * období postavená nad jinými daty.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * IDEMPOTENCE — A TÍM I GUID — JE PER REGISTRACI, NE PER REVIZI
     * ─────────────────────────────────────────────────────────────────────────
     * Klíč nese mzdovou účtárnu, protože jedna příprava dá tolik podání, kolik
     * má revize registrací u OSSZ, a otisk přípravy je pro všechny stejný. Bez
     * účtárny v klíči by druhá registrace narazila na idempotentní opakování
     * první: dostala by zpět CIZÍ zmrazený artefakt — tedy cizí GUID i cizí
     * variabilní symbol — místo vlastního podání.
     *
     * Že se tím GUIDy oddělí, plyne z toho, KDE vznikají: `bridge()` je
     * generuje jen na větvi `created === true`. Dva různé klíče proto vedou na
     * dvě zakládající větve a dva různé GUIDy; tentýž klíč vede na
     * `replayedResult()`, který XML nestaví znovu a vrátí původní GUID. Přesně
     * to obojí zadání žádá: dvě registrace ≠ tentýž GUID, opakování téže
     * registrace = tentýž GUID.
     *
     * `null` (jednoúčtárenský běh) záměrně DRŽÍ PŮVODNÍ KLÍČ — bajtově se do
     * kanonického JSONu nepromítne jinak než jako chybějící dimenze, takže se
     * dřív zmrazená podání idempotentně dohledají dál.
     *
     * @return array{submission:string,artifact:string}
     */
    private static function idempotencyKeys(
        int $supplierId,
        string $environment,
        int $obligationId,
        string $snapshotHash,
        ?int $officeId = null,
    ): array {
        $scope = [
            'schema_reference' => 'payroll-jmhz-submission-bridge.v1',
            'supplier_id' => $supplierId,
            'environment' => $environment,
            'obligation_id' => $obligationId,
            'source_snapshot_hash' => $snapshotHash,
        ];
        if ($officeId !== null) {
            $scope['payroll_office_id'] = $officeId;
        }
        $fingerprint = hash('sha256', CanonicalJson::encode($scope));

        return [
            'submission' => "jmhz25-submission:{$fingerprint}",
            'artifact' => "jmhz25-artifact:{$fingerprint}",
        ];
    }

    private static function describeControls(
        JmhzControlEvaluationReport $report,
    ): string {
        $parts = array_map(
            static fn (JmhzControlFinding $finding): string
                => "kontrola {$finding->controlId} — {$finding->message}",
            [...$report->blocking(), ...$report->coverageGaps()],
        );

        return $parts === [] ? 'důvod neuveden' : implode('; ', $parts);
    }
}
