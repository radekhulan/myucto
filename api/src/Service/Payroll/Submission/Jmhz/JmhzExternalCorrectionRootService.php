<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Repository\Payroll\JmhzExternalRootRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionCalendar;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionManualAcceptanceService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Report\EpoEnvelope;
use Psr\Clock\ClockInterface;

/**
 * Kořen opravného JMHZ za měsíc, který podal předchozí program.
 *
 * Opravné hlášení nese GUID řádného podání (oprava ho nemění) a každý opravný
 * formulář GUID součásti, ve které byl vztah poprvé nahlášen (Pravidla podání
 * JMHZ 1.4.5, kap. 3). Obsahová oprava ({@see JmhzContentCorrectionSubmissionService})
 * je čte ze zmrazené datové věty řádného podání v MyÚčtu. Tady se proto z podání
 * předchozího programu udělá řádné podání MyÚčta, které se nikam neodesílá:
 *
 *  - z nahraného původního XML (ověří se XSD, typ R, období a variabilní symbol
 *    registrace; datová věta JMHZ IČO nenese, zaměstnavatele určuje VS),
 *  - nebo ze zadaného GUID podání a GUID formulářů vybraných vztahů; datová věta
 *    se pak sestaví z aktuální přípravy a nese jen identitu formulářů.
 *
 * Přijetí se zapíše ručním potvrzením s poznámkou účetní, takže efektivní sada
 * formulářů bere zadané formuláře jako přijaté a oprava je opraví typem O.
 */
final readonly class JmhzExternalCorrectionRootService
{
    public const MODE_XML = 'original_xml';
    public const MODE_GUIDS = 'guid_reference';
    private const CHANNEL = 'other';
    private const PRODUCT_NAME = 'MyÚčto.cz';
    private const MAX_XML_BYTES = 30 * 1024 * 1024;
    private const GUID_PATTERN = '/^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$/D';

    public function __construct(
        private JmhzExternalRootRepository $roots,
        private JmhzPreparationSnapshotService $preparations,
        private JmhzScenario1DocumentService $documents,
        private JmhzScenario1XmlValidator $validator,
        private PayrollSubmissionRepository $submissionRepository,
        private PayrollSubmissionService $submissions,
        private PayrollObligationService $obligations,
        private PayrollSubmissionManualAcceptanceService $acceptances,
        private JmhzDeadlinePolicy $deadlines,
        private ClockInterface $clock,
    ) {}

    /**
     * Vztahy aktuální schválené revize, ke kterým lze zadat GUID formulářů.
     *
     * @return array{preparation_id:int,period:string,variable_symbol:string,employments:list<array{employment_id:int,employee_id:int,employment_external_identifier:string}>}
     */
    public function candidates(int $supplierId, string $environment, int $revisionId, ?int $officeId, int $userId): array
    {
        [$preparationId, , $document] = $this->current($supplierId, $environment, $revisionId, $officeId, $userId);
        $employments = [];
        foreach (JmhzContentCorrectionSubmissionService::currentForms($document) as $externalId => $form) {
            $employments[] = [
                'employment_id' => $form['employment_id'],
                'employee_id' => $form['employee_id'],
                'employment_external_identifier' => (string) $externalId,
            ];
        }
        $header = $document->payload['header'];

        return [
            'preparation_id' => $preparationId,
            'period' => sprintf('%04d-%02d', $header['year'], $header['month']),
            'variable_symbol' => (string) $header['variable_symbol'],
            'employments' => $employments,
        ];
    }

    /**
     * Převezme nahrané původní XML řádného hlášení.
     *
     * @return array<string,mixed>
     */
    public function attachXml(
        int $supplierId,
        string $environment,
        int $revisionId,
        ?int $officeId,
        string $xml,
        string $note,
        int $userId,
    ): array {
        $note = self::note($note);
        if ($xml === '' || strlen($xml) > self::MAX_XML_BYTES) {
            throw new JmhzXmlException('jmhz_external_root_xml_invalid', 'Soubor hlášení je prázdný nebo větší než 30 MB.');
        }
        [$preparationId, , $document] = $this->current($supplierId, $environment, $revisionId, $officeId, $userId);
        try {
            $schema = $this->validator->validateFrozen($xml);
        } catch (JmhzXmlException $exception) {
            throw new JmhzXmlException(
                'jmhz_external_root_xml_invalid',
                'Soubor není platná datová věta JMHZ podle XSD ČSSZ: ' . $exception->getMessage(),
            );
        }
        $identity = JmhzFrozenSubmissionIdentity::read($xml);
        if ($identity->submissionType !== 'R') {
            throw new JmhzXmlException(
                'jmhz_external_root_not_regular',
                'Nahrajte řádné hlášení (typ podání R). Opravné hlášení i storno se váže na řádné.',
            );
        }
        $this->assertHeader($document, $identity->year, $identity->month, $identity->variableSymbol);
        $current = JmhzContentCorrectionSubmissionService::currentForms($document);
        $forms = self::xmlForms($xml);
        $matched = 0;
        foreach ($forms as $form) {
            if ($form['type'] !== 'R') {
                throw new JmhzXmlException(
                    'jmhz_external_root_not_regular',
                    'Řádné hlášení smí nést jen řádné formuláře (typ R).',
                );
            }
            if ($form['id_ppv'] !== null && isset($current[$form['id_ppv']])) {
                $matched++;
            }
        }
        if ($matched === 0) {
            throw new JmhzXmlException(
                'jmhz_external_root_forms_unmatched',
                'Žádný formulář nahraného hlášení nepatří vztahu v tomto mzdovém běhu (shoda podle ID PPV).',
            );
        }

        return $this->materialize(
            $supplierId,
            $environment,
            $preparationId,
            $officeId,
            $document,
            self::MODE_XML,
            $identity->submissionGuid,
            $xml,
            $schema['package_key'],
            count($forms),
            count($forms) - $matched,
            hash('sha256', $xml),
            $note,
            $userId,
        );
    }

    /**
     * Převezme řádné hlášení zadané GUID podání a GUID formulářů vztahů.
     *
     * @param array<int,string> $formGuids employment_id => GUID formuláře
     * @return array<string,mixed>
     */
    public function attachGuids(
        int $supplierId,
        string $environment,
        int $revisionId,
        ?int $officeId,
        string $submissionGuid,
        array $formGuids,
        bool $formsConfirmed,
        string $note,
        int $userId,
    ): array {
        $note = self::note($note);
        if (!$formsConfirmed) {
            throw new JmhzXmlException(
                'jmhz_external_root_confirmation_required',
                'Potvrďte, že vybrané vztahy byly v původním hlášení se zadanými GUID formulářů.',
            );
        }
        $submissionGuid = strtoupper(trim($submissionGuid));
        if (preg_match(self::GUID_PATTERN, $submissionGuid) !== 1) {
            throw new JmhzXmlException('jmhz_external_root_guid_invalid', 'GUID podání musí mít tvar 8-4-4-4-12 znaků.');
        }
        if ($formGuids === []) {
            throw new JmhzXmlException('jmhz_external_root_guid_invalid', 'Zadejte GUID formuláře aspoň u jednoho vztahu.');
        }
        $normalized = [];
        foreach ($formGuids as $employmentId => $guid) {
            $guid = strtoupper(trim((string) $guid));
            if (!is_int($employmentId) || $employmentId <= 0 || preg_match(self::GUID_PATTERN, $guid) !== 1) {
                throw new JmhzXmlException('jmhz_external_root_guid_invalid', 'GUID formuláře musí mít tvar 8-4-4-4-12 znaků.');
            }
            if ($guid === $submissionGuid || in_array($guid, $normalized, true)) {
                throw new JmhzXmlException('jmhz_external_root_guid_invalid', 'GUID formulářů se nesmí opakovat ani shodovat s GUID podání.');
            }
            $normalized[$employmentId] = $guid;
        }
        [$preparationId, , $document] = $this->current($supplierId, $environment, $revisionId, $officeId, $userId);
        $selection = [];
        $others = [];
        foreach (JmhzContentCorrectionSubmissionService::currentForms($document) as $externalId => $form) {
            if (isset($normalized[$form['employment_id']])) {
                $selection[$form['employment_id']] = (string) $externalId;
            } else {
                $others[] = $form['employment_id'];
            }
        }
        $unknown = array_diff(array_keys($normalized), array_keys($selection));
        if ($unknown !== []) {
            throw new JmhzXmlException(
                'jmhz_external_root_employment_unknown',
                'Vztah se zadaným GUID formuláře v aktuální přípravě hlášení není.',
            );
        }
        $resolution = $this->documents->resolve(
            $supplierId,
            $environment,
            $preparationId,
            $officeId,
            JmhzFormExclusion::correctionScope($others),
        );
        if ($resolution->status() !== 'resolved') {
            throw new JmhzXmlException(
                'jmhz_external_root_preparation_blocked',
                'Vybrané vztahy nejdou z aktuální přípravy sestavit: '
                    . JmhzBlockerExplainer::describe($resolution->blockers),
            );
        }
        $envelope = JmhzSubmissionEnvelope::createForExistingSubmission(
            $submissionGuid,
            $normalized,
            PayrollSubmissionCalendar::filledAt($this->clock->now()),
            self::PRODUCT_NAME,
            EpoEnvelope::appVersion() ?? '0',
        );
        $result = $this->validator->dryRun($resolution, $envelope);

        return $this->materialize(
            $supplierId,
            $environment,
            $preparationId,
            $officeId,
            $resolution->requireResolvedDocument(),
            self::MODE_GUIDS,
            $submissionGuid,
            $result['xml'],
            $result['schema']['package_key'],
            count($normalized),
            0,
            null,
            $note,
            $userId,
        );
    }

    /** @return list<array<string,mixed>> */
    public function list(int $supplierId, string $environment): array
    {
        self::assertEnvironment($environment);

        return $this->roots->list($supplierId, $environment);
    }

    /**
     * Příprava aktuální schválené revize a její dokument bez nálezů mimo vztahy.
     *
     * @return array{int,JmhzVerifiedPreparationSnapshot,JmhzScenario1NormalizedDocument}
     */
    private function current(int $supplierId, string $environment, int $revisionId, ?int $officeId, int $userId): array
    {
        self::assertEnvironment($environment);
        if ($supplierId <= 0 || $revisionId <= 0 || $userId <= 0 || ($officeId !== null && $officeId <= 0)) {
            throw new \InvalidArgumentException('Firma, revize a uživatel musí být kladná čísla.');
        }
        $prepared = $this->preparations->freeze(
            $supplierId,
            $revisionId,
            $environment,
            "jmhz-external-root:{$revisionId}:" . ($officeId ?? 'all'),
            $userId,
        );
        $preparationId = (int) $prepared['id'];
        $preparation = $this->preparations->loadVerified($supplierId, $environment, $preparationId);
        $resolution = $this->documents->resolveForCorrection($supplierId, $environment, $preparationId, $officeId, []);
        if ($resolution->candidate === null) {
            throw new JmhzXmlException(
                'jmhz_external_root_preparation_blocked',
                'Hlášení za běh nejde sestavit: ' . JmhzBlockerExplainer::describe($resolution->blockers),
            );
        }

        return [$preparationId, $preparation, $resolution->candidate];
    }

    private function assertHeader(JmhzScenario1NormalizedDocument $document, int $year, int $month, string $variableSymbol): void
    {
        $header = is_array($document->payload['header'] ?? null) ? $document->payload['header'] : [];
        if (($header['year'] ?? null) !== $year || ($header['month'] ?? null) !== $month) {
            throw new JmhzXmlException(
                'jmhz_external_root_scope_mismatch',
                sprintf(
                    'Hlášení je za období %02d/%04d, mzdový běh za %02d/%04d.',
                    $month,
                    $year,
                    (int) ($header['month'] ?? 0),
                    (int) ($header['year'] ?? 0),
                ),
            );
        }
        if (($header['variable_symbol'] ?? null) !== $variableSymbol) {
            throw new JmhzXmlException(
                'jmhz_external_root_scope_mismatch',
                'Variabilní symbol hlášení neodpovídá registraci mzdové účtárny (jiný zaměstnavatel nebo účtárna).',
            );
        }
        $lastCorrectionOn = $this->deadlines->lastCorrectionOn(sprintf('%04d-%02d-01', $year, $month));
        if (PayrollSubmissionCalendar::today($this->clock->now()) > $lastCorrectionOn) {
            throw new JmhzXmlException(
                'jmhz_content_correction_deadline_expired',
                'Desetiletá lhůta pro obsahovou opravu tohoto období uplynula.',
            );
        }
    }

    /** @return array<string,mixed> */
    private function materialize(
        int $supplierId,
        string $environment,
        int $preparationId,
        ?int $obligationOfficeId,
        JmhzScenario1NormalizedDocument $document,
        string $mode,
        string $submissionGuid,
        string $xml,
        string $packageKey,
        int $formCount,
        int $unmatched,
        ?string $originalSha256,
        string $note,
        int $userId,
    ): array {
        $scope = is_array($document->payload['scope'] ?? null) ? $document->payload['scope'] : [];
        $runId = $scope['run_id'] ?? null;
        $periodStart = $scope['period_start'] ?? null;
        if (!is_int($runId) || !is_string($periodStart)) {
            throw new JmhzXmlException('jmhz_external_root_preparation_blocked', 'Příprava nenese mzdový běh a období.');
        }
        $subject = JmhzSubmissionBridgeService::runReference($runId, $obligationOfficeId);
        $snapshotHash = $document->sha256();
        $requestKey = hash('sha256', implode(':', [$supplierId, $environment, $subject, $periodStart, $submissionGuid]));

        return $this->submissionRepository->transaction(function () use (
            $supplierId,
            $environment,
            $preparationId,
            $mode,
            $submissionGuid,
            $xml,
            $packageKey,
            $formCount,
            $unmatched,
            $originalSha256,
            $note,
            $userId,
            $subject,
            $periodStart,
            $snapshotHash,
            $requestKey,
        ): array {
            if (!$this->submissionRepository->lockSupplier($supplierId)) {
                throw new \DomainException('Firma nebyla nalezena.');
            }
            $obligation = $this->roots->regularObligation($supplierId, $environment, $subject, $periodStart);
            if ($obligation !== null) {
                $existing = $this->roots->regularForObligation($supplierId, $environment, $obligation['id']);
                if ($existing !== null) {
                    $root = $this->roots->forSubmission($supplierId, $environment, $existing['id']);
                    if ($root !== null && $root['submission_guid'] === $submissionGuid) {
                        return $root + ['created' => false];
                    }
                    throw new JmhzXmlException(
                        'jmhz_external_root_regular_exists',
                        'Za tento měsíc už v MyÚčtu existuje řádné hlášení. Opravu navažte na něj,'
                            . ' nebo ho nejdřív zrušte.',
                    );
                }
                $obligationId = $obligation['id'];
            } else {
                $window = $this->deadlines->forPeriod($periodStart);
                $registered = $this->obligations->register(
                    $supplierId,
                    JmhzSubmissionBridgeService::AGENDA_CODE,
                    'payroll_run',
                    $subject,
                    $periodStart,
                    (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d'),
                    'regular',
                    self::CHANNEL,
                    JmhzSubmissionBridgeService::SOURCE_EVENT_TYPE,
                    JmhzSubmissionBridgeService::sourceEventReference($preparationId),
                    $snapshotHash,
                    $window->earliestSubmissionOn,
                    $window->dueOn,
                    $window->calendarBasis,
                    $window->rulesetId,
                    $window->rulesetHash,
                    "jmhz-external-root-obligation:{$requestKey}",
                    $userId,
                    $userId,
                    null,
                    $environment,
                );
                $obligationId = (int) $registered['id'];
            }
            $submission = $this->submissions->prepare(
                $supplierId,
                $obligationId,
                'regular',
                self::CHANNEL,
                $snapshotHash,
                "jmhz-external-root-submission:{$requestKey}",
                null,
                null,
                $userId,
                $environment,
            );
            $part = $this->submissions->addPart(
                $supplierId,
                $submission['id'],
                $submission['row_version'],
                "jmhz-external-root:{$preparationId}",
                JmhzSubmissionBridgeService::AGENDA_CODE,
                $subject,
                'jmhz_preparation',
                JmhzSubmissionBridgeService::sourceEventReference($preparationId),
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
                $packageKey,
                JmhzControlSourceCatalog::CATALOG_KEY,
                self::CHANNEL,
                "jmhz-external-root-artifact:{$requestKey}",
                $userId,
            );
            $version = $artifact['submission_row_version'];
            foreach (['validated', 'ready', 'submitted'] as $status) {
                $version = $this->submissions->transition($supplierId, $submission['id'], $version, $status)['row_version'];
            }
            $this->acceptances->accept(
                $supplierId,
                $environment,
                $submission['id'],
                $version,
                'unchanged',
                'Řádné hlášení podal předchozí program: ' . $note,
                null,
                null,
                "jmhz-external-root-acceptance:{$requestKey}",
                $userId,
            );
            $this->roots->insert([
                'supplier_id' => $supplierId,
                'environment' => $environment,
                'submission_id' => $submission['id'],
                'mode' => $mode,
                'submission_guid' => $submissionGuid,
                'period' => substr($periodStart, 0, 7),
                'form_count' => $formCount,
                'unmatched_form_count' => $unmatched,
                'original_sha256' => $originalSha256,
                'note' => $note,
                'created_by' => $userId,
            ]);
            $root = $this->roots->forSubmission($supplierId, $environment, $submission['id']);
            if ($root === null) {
                throw new \RuntimeException('Převzaté řádné hlášení nelze načíst.');
            }

            return $root + ['created' => true];
        });
    }

    /**
     * Formuláře osob nahraného hlášení (typ, GUID, ID PPV), bez ohledu na ročník.
     *
     * @return list<array{guid:string,type:string,id_ppv:?string}>
     */
    private static function xmlForms(string $xml): array
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            throw new JmhzXmlException('jmhz_external_root_xml_invalid', 'Soubor hlášení nejde přečíst jako XML.');
        }
        $xpath = new \DOMXPath($dom);
        $forms = [];
        $nodes = $xpath->query("/*[local-name()='jmhz']/*[local-name()='formulareOsob']/*[local-name()='formularOsoby']");
        foreach ($nodes === false ? [] : $nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $guid = self::text($xpath, "./*[local-name()='hlavicka']/*[local-name()='idFormulare']", $node);
            $type = self::text($xpath, "./*[local-name()='hlavicka']/*[local-name()='typFormulare']", $node);
            $ppv = self::text($xpath, ".//*[local-name()='identifikace']/*[local-name()='idPpv']", $node);
            if ($guid === null || preg_match(self::GUID_PATTERN, $guid) !== 1 || $type === null) {
                throw new JmhzXmlException('jmhz_external_root_xml_invalid', 'Formulář osoby v hlášení nemá platný GUID nebo typ.');
            }
            $forms[] = ['guid' => strtoupper($guid), 'type' => $type, 'id_ppv' => $ppv];
        }
        if ($forms === []) {
            throw new JmhzXmlException('jmhz_external_root_forms_unmatched', 'Hlášení neobsahuje žádný formulář osoby.');
        }

        return $forms;
    }

    private static function text(\DOMXPath $xpath, string $query, \DOMElement $context): ?string
    {
        $nodes = $xpath->query($query, $context);
        $node = $nodes === false ? null : $nodes->item(0);
        $value = $node === null ? '' : trim($node->textContent);

        return $value === '' ? null : $value;
    }

    private static function note(string $note): string
    {
        $note = trim($note);
        if (mb_strlen($note) < 10 || mb_strlen($note) > 900) {
            throw new \InvalidArgumentException(
                'Poznámka musí mít 10 až 900 znaků: čím je podání a jeho přijetí doložené (program, protokol ČSSZ).',
            );
        }

        return $note;
    }

    private static function assertEnvironment(string $environment): void
    {
        if (!in_array($environment, ['test', 'production'], true)) {
            throw new \InvalidArgumentException('Prostředí musí být test nebo production.');
        }
    }
}
