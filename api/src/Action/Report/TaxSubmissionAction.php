<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\RetentionHoldRepository;
use MyInvoice\Service\License\CommercialFeatureAccess;
use MyInvoice\Service\License\TaxSubmissionAccess;
use MyInvoice\Repository\TaxSubmissionEpoRepository;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Report\TaxSubmissionArchiver;
use MyInvoice\Service\Report\TaxSubmissionFilename;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Historie archivovaných EPO XML výkazů včetně asistovaného předání a důkazních souborů.
 *
 *   GET    /api/reports/submissions             → list
 *   GET    /api/reports/submissions/{id}        → detail (s XML obsahem)
 *   GET    /api/reports/submissions/{id}/xml    → XML download
 *   POST   /api/reports/submissions/{id}/submit → označit jako prokazatelně PODANÉ (§2.4)
 *   DELETE /api/reports/submissions/{id}        → smazat archiv (admin)
 *
 * Mazání: blokuje jen to, co PROKAZATELNĚ odešlo, plus nedořešená předání — ta jde
 * uvolnit vědomým smazáním; nepovinný `not_submitted_note` navíc odliší,
 * že uživatel v portálu EPO ověřil, že podání nevzniklo. Pravidlo drží
 * {@see TaxSubmissionEpoRepository::deletionBlocker()}.
 */
final class TaxSubmissionAction
{
    public function __construct(
        private readonly TaxSubmissionRepository $repo,
        private readonly TaxSubmissionEpoRepository $epo,
        private readonly TaxSubmissionArchiver $archiver,
        private readonly ActivityLogger $logger,
        private readonly Connection $db,
        private readonly RetentionHoldRepository $holds,
        private readonly CommercialFeatureAccess $commercial,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'reports', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $query = $request->getQueryParams();

        $status = trim((string) ($query['status'] ?? ''));
        // Neznámý stav je chyba klienta, ne důvod k tichému vypsání všeho:
        // uživatel by dostal plný seznam v domnění, že se filtruje.
        if ($status !== '' && $status !== 'all'
            && !in_array($status, TaxSubmissionRepository::LIST_STATUSES, true)
        ) {
            return Json::error(
                $response,
                'validation_failed',
                'Neplatný stav podání (status): ' . implode(', ', TaxSubmissionRepository::LIST_STATUSES) . '.',
                422,
            );
        }
        $formCode = strtolower(trim((string) ($query['form_code'] ?? '')));
        if ($formCode !== '' && $formCode !== 'all' && !preg_match('/^[a-z0-9]{1,20}$/', $formCode)) {
            return Json::error($response, 'validation_failed', 'Neplatný kód výkazu (form_code).', 422);
        }

        $limit = min(200, max(1, (int) ($query['limit'] ?? 50)));
        $offset = max(0, (int) ($query['offset'] ?? 0));

        // Bez licence se ukážou jen výkazy bezplatné části. Zbytek se
        // nevypisuje vůbec — nabízet detail, který skončí 403, je horší
        // než ho neukázat. Omezení jde do SQL, ne nad načtenou stránku:
        // filtrovat až v PHP by dávalo krátké stránky a špatný celkový počet.
        $filters = [
            'status'    => $status === 'all' ? '' : $status,
            'form_code' => $formCode === 'all' ? '' : $formCode,
            'q'         => trim((string) ($query['q'] ?? '')),
            'allowed_form_codes' => $this->commercial->isAvailable()
                ? null
                : TaxSubmissionAccess::freeFormCodes(),
        ];

        // Obohacení běží až nad stránkou, ne nad celým archivem.
        $rows = $this->epo->enrich($this->repo->list($supplierId, $filters, $limit, $offset), $supplierId);

        return Json::ok($response, [
            'data' => $rows,
            'meta' => [
                'total'      => $this->repo->countList($supplierId, $filters),
                'limit'      => $limit,
                'offset'     => $offset,
                // Dlaždice nahoře popisují celý viditelný archiv, ne aktuální stránku
                // ani filtr — viz {@see TaxSubmissionRepository::listStats()}.
                'stats'      => $this->repo->listStats($supplierId, $filters),
                'form_codes' => $this->repo->listFormCodes($supplierId, $filters),
            ],
        ]);
    }

    /**
     * Smí se na tenhle konkrétní výkaz sáhnout?
     *
     * Cesta o typu výkazu nic neví, takže licenční middleware to rozhodnout
     * nemůže; archiv je společný pro bezplatné i placené výkazy.
     */
    private function deniedByLicense(array $row, Response $response): ?Response
    {
        if (TaxSubmissionAccess::isFreeForm($row['form_code'] ?? null) || $this->commercial->isAvailable()) {
            return null;
        }
        return Json::error(
            $response,
            'license_commercial_feature_unavailable',
            'Tenhle výkaz patří do účetní nadstavby a vyžaduje aktivní licenci.',
            403,
        );
    }

    public function detail(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::allows($request, 'reports', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $row = $this->repo->find((int) ($args['id'] ?? 0), $supplierId);
        if ($row === null) return Json::error($response, 'not_found', 'Záznam nenalezen.', 404);
        if (($denied = $this->deniedByLicense($row, $response)) !== null) return $denied;
        $row = $this->epo->enrich([$row], $supplierId)[0];
        return Json::ok($response, $row);
    }

    public function downloadXml(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::allows($request, 'reports.export', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $row = $this->repo->find((int) ($args['id'] ?? 0), $supplierId);
        if ($row === null) return Json::error($response, 'not_found', 'Záznam nenalezen.', 404);

        if (($denied = $this->deniedByLicense($row, $response)) !== null) return $denied;

        $filename = TaxSubmissionFilename::forSnapshot($row, 'archive.xml');

        $response->getBody()->write((string) $row['xml_content']);
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Označí archivovaný snapshot jako PROKAZATELNĚ PODANÝ (audit §2.4). Teprve tím se
     * stává základem opravného/následného tvrzení, posouvá daňový zámek a v UI je "podáno".
     * Vyžaduje čas podání; identifikátor/č.j. potvrzení podatelny je doporučený.
     *
     * Body: { submitted_at?: 'YYYY-MM-DD'|ISO, submission_ref?: string }
     */
    public function submit(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::allows($request, 'reports.submit', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění označit podání.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);

        $existing = $this->repo->find($id, $supplierId);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Záznam nenalezen.', 404);
        }
        // Neplatné (XSD failed) přiznání nelze doložit jako podané — na EPO by neprošlo.
        if (($existing['validation_status'] ?? null) === 'failed') {
            return Json::error(
                $response,
                'validation_failed',
                'Snapshot neprošel XSD validací — nelze jej označit jako podaný.',
                422,
            );
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $submittedAt = trim((string) ($body['submitted_at'] ?? ''));
        if ($submittedAt === '') {
            $submittedAt = date('Y-m-d H:i:s');
        } else {
            $ts = strtotime($submittedAt);
            if ($ts === false) {
                return Json::error($response, 'validation_failed', 'Neplatné datum podání (submitted_at).', 422);
            }
            $submittedAt = date('Y-m-d H:i:s', $ts);
        }
        $submissionRef = trim((string) ($body['submission_ref'] ?? '')) ?: null;

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0) ?: null;
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            if ($this->epo->lockSubmission($id, $supplierId) === null) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                }
                return Json::error($response, 'not_found', 'Záznam nenalezen.', 404);
            }
            $confirmableAttempt = $this->epo->latestConfirmableAttempt($id, $supplierId);
            if (($confirmableAttempt['epo_environment'] ?? null) === 'test') {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                }
                return Json::error(
                    $response,
                    'epo_test_attempt',
                    'Zkušební předání EPO nelze označit jako právně účinné podání.',
                    409,
                );
            }

            // Dodatečné přiznání (D/E): špička řetězce „poslední známé daně" se od stavby
            // snapshotu nesměla pohnout. Kdyby ano, započetla by se do kumulativní základny
            // delta, která už v řetězci je — typicky když se XML stáhne dvakrát a účetní
            // označí jako podané oba snapshoty. Tichá chyba v ř. 66 dalšího dodatečného.
            $variant = (string) ($existing['form_variant'] ?? '');
            // Opakované označení už podaného snapshotu jen aktualizuje metadata — tam je
            // špička řetězce logicky on sám, takže kontrola neplatí.
            $alreadyFiled = in_array((string) ($existing['status'] ?? ''), ['submitted', 'accepted'], true);
            if (in_array($variant, ['D', 'E'], true) && !$alreadyFiled) {
                $expectedTip = $existing['summary']['reference_submission_id'] ?? null;
                $currentTip = $this->repo->amendmentChainTipId(
                    $supplierId,
                    (string) $existing['form_code'],
                    (int) $existing['period_year'],
                    $existing['period_month'] !== null ? (int) $existing['period_month'] : null,
                    $existing['period_quarter'] !== null ? (int) $existing['period_quarter'] : null,
                );
                if ($expectedTip !== null && (int) $expectedTip !== (int) $currentTip) {
                    if ($ownsTransaction) {
                        $pdo->rollBack();
                    }
                    return Json::error(
                        $response,
                        'amendment_baseline_moved',
                        'Od vytvoření tohoto dodatečného přiznání se změnila poslední známá daň '
                            . 'období (mezitím bylo označeno jako podané jiné dodatečné přiznání). '
                            . 'Rozdíl by se započetl dvakrát — vygenerujte dodatečné přiznání znovu '
                            . 'proti aktuálnímu stavu.',
                        409,
                        ['expected_reference_submission_id' => (int) $expectedTip,
                         'current_reference_submission_id'  => $currentTip],
                    );
                }
            }

            $row = $this->archiver->markSubmitted(
                $id,
                $supplierId,
                $submittedAt,
                $submissionRef,
                $userId,
            );
            if ($row === null) {
                throw new \RuntimeException('Tax submission disappeared.');
            }
            $attemptId = isset($confirmableAttempt['id']) ? (int) $confirmableAttempt['id'] : null;
            if ($attemptId !== null) {
                $this->epo->markAttemptConfirmed($attemptId, $submittedAt);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $this->logger->log('report.submission_marked_submitted', $userId, null, null, [
            'submission_id' => $id,
            'attempt_id' => $attemptId,
            'form_code'     => $row['form_code'] ?? null,
            'period_year'   => $row['period_year'] ?? null,
            'period_month'  => $row['period_month'] ?? null,
            'period_quarter' => $row['period_quarter'] ?? null,
            'submitted_at'  => $submittedAt,
            'submission_ref' => $submissionRef,
        ]);

        return Json::ok($response, $row);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::allows($request, 'reports.export', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $id = (int) ($args['id'] ?? 0);

        $snapshot = $this->repo->find($id, $supplierId);
        if ($snapshot === null) {
            return Json::error($response, 'not_found', 'Záznam nenalezen.', 404);
        }

        // § 32 ZoÚ: běží-li nad obdobím daňové řízení, nesmí být mazání snapshotu cestou,
        // jak se zbavit podkladu, který správce daně prověřuje. Lhůty § 31 / § 35a se
        // naopak neuplatní — nepodaný XML snapshot není účetní ani daňový doklad, a ten
        // podaný blokuje `delivered_attempt`, což je přísnější než jakákoli lhůta.
        $periodYear = (int) ($snapshot['period_year'] ?? 0);
        if ($periodYear > 0 && $this->holds->hasActiveHold($supplierId, $periodYear)) {
            return Json::error(
                $response,
                'submission_on_retention_hold',
                sprintf(
                    'Snapshot nelze smazat: záznamy období %d jsou zadržené podle § 32 ZoÚ.',
                    $periodYear,
                ),
                409,
            );
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $note = trim((string) ($body['not_submitted_note'] ?? ''));
        $unresolved = $this->epo->unresolvedAttempts($id, $supplierId);

        $outcome = $this->epo->deleteSubmission(
            $id,
            $supplierId,
            $userId > 0 ? $userId : null,
            $note !== '' ? $note : null,
        );

        if ($outcome['result'] === 'not_found') {
            return Json::error($response, 'not_found', 'Záznam nenalezen.', 404);
        }
        if ($outcome['result'] === 'blocked') {
            return match ($outcome['blocker']) {
                TaxSubmissionEpoRepository::BLOCK_UNRESOLVED => Json::error(
                    $response,
                    'submission_outcome_unresolved',
                    'Snapshot má předání do EPO, u kterého se nepodařilo doložit, '
                        . 'že nevzniklo. Ověřte v portálu EPO, jestli podání prošlo.',
                    409,
                    ['attempts' => $unresolved],
                ),
                default => Json::error(
                    $response,
                    'submission_has_evidence',
                    'Snapshot má důkaz o podání (potvrzený pokus, podací číslo nebo doručenku) '
                        . 'a nelze ho smazat — je to zákonný doklad o podání.',
                    409,
                ),
            };
        }

        // Auditní stopa musí přežít smazaný snapshot: navázané řádky odejdou s ním
        // (FK ON DELETE CASCADE), takže co se smazalo, se pak z DB nedozvíme.
        $this->logger->log('report.submission_deleted', $userId ?: null, 'tax_submission', $id, [
            'submission_id'   => $id,
            'form_code'       => $snapshot['form_code'] ?? null,
            'period_year'     => $snapshot['period_year'] ?? null,
            'period_month'    => $snapshot['period_month'] ?? null,
            'period_quarter'  => $snapshot['period_quarter'] ?? null,
            'status'          => $snapshot['status'] ?? null,
            'xml_sha256'      => $snapshot['xml_sha256'] ?? null,
            'purged'          => $outcome['purged'],
            'released_attempts' => $outcome['released_attempts'],
            'not_submitted_note' => $outcome['released_attempts'] > 0 && $note !== '' ? $note : null,
            // Čím se nedořešené předání uzavřelo. Poznámka znamená, že uživatel ověřil
            // v portálu EPO, že podání nevzniklo; bez ní víme jen to, že smazání vědomě
            // potvrdil. Auditní stopa to nesmí slévat dohromady.
            'closed_as' => $outcome['released_attempts'] > 0
                ? ($note !== '' ? 'verified_not_submitted' : 'discarded_by_user')
                : null,
            'unresolved_attempts' => $outcome['released_attempts'] > 0 ? $unresolved : [],
        ], null, null, $supplierId);

        return Json::ok($response, [
            'deleted' => true,
            'purged' => $outcome['purged'],
            'released_attempts' => $outcome['released_attempts'],
        ]);
    }
}
