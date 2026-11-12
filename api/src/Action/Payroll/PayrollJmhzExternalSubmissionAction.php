<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzExternalSubmissionStore;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPredecessorGapService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Podání ČSSZ, která za firmu podal předchozí mzdový program (převod z PAMICA,
 * nahrané XML hlášení).
 *
 *   GET    /api/payroll/submissions/jmhz-external        přehled (osoby a akce, bez obsahu podání)
 *   GET    /api/payroll/submissions/jmhz-external/{id}   detail: všechny formuláře s osobou, akcí a dnem účinnosti
 *   DELETE /api/payroll/submissions/jmhz-external/{id}   odebrání záznamu z historie
 *
 * Záznam blokuje přípravu řádného hlášení za týž měsíc. Když neodpovídá skutečnosti
 * (hlášení ve skutečnosti neodešlo, soubor jiného období), musí ho jít odebrat
 * na obrazovce, ne jen v databázi.
 */
final class PayrollJmhzExternalSubmissionAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly JmhzExternalSubmissionStore $store,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $activity,
        private readonly JmhzPredecessorGapService $gaps,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($denied = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $denied;
        }
        $environment = $this->environment($request);
        if ($environment === null) {
            return $this->invalid($response, 'Prostředí musí být test nebo production.');
        }

        $supplierId = $this->currentSupplierId($request);

        return $this->noStore(Json::ok($response, [
            'environment' => $environment,
            'items' => $this->store->overview($supplierId, $environment),
            // Převzaté měsíce, za které v historii není ŽÁDNÉ hlášení (Q15-17);
            // připravené a neodeslané panel pozná sám z `items`.
            'missing_periods' => array_values(array_map(
                static fn (array $gap): string => $gap['period'],
                array_filter(
                    $this->gaps->missing($supplierId, $environment),
                    static fn (array $gap): bool => !$gap['prepared_not_sent'],
                ),
            )),
        ]));
    }

    /** @param array<string,string> $args */
    public function detail(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->authorize($request, $response, AccessLevel::READ)) !== null) {
            return $denied;
        }
        $environment = $this->environment($request);
        if ($environment === null) {
            return $this->invalid($response, 'Prostředí musí být test nebo production.');
        }
        $id = filter_var($args['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($id)) {
            return $this->invalid($response, 'Podání musí být kladné celé číslo.');
        }
        $detail = $this->store->detail($this->currentSupplierId($request), $environment, $id);
        if ($detail === null) {
            return $this->noStore(Json::error($response, 'not_found', 'Převzaté podání v téhle firmě není.', 404));
        }

        return $this->noStore(Json::ok($response, $detail));
    }

    /** @param array<string,string> $args */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $environment = $this->environment($request);
        if ($environment === null) {
            return $this->invalid($response, 'Prostředí musí být test nebo production.');
        }
        $id = filter_var($args['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($id)) {
            return $this->invalid($response, 'Podání musí být kladné celé číslo.');
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->store->delete($supplierId, $environment, $id)) {
            return $this->noStore(Json::error($response, 'not_found', 'Převzaté podání v téhle firmě není.', 404));
        }
        $this->activity->log('payroll.jmhz_external_deleted', $this->userId($request), 'payroll_external_jmhz_submission', $id,
            ['environment' => $environment], supplierId: $supplierId);

        return $this->noStore(Json::ok($response, ['deleted' => true, 'id' => $id]));
    }

    /**
     * Potvrdí, že řádné hlášení za vybrané převzaté měsíce podal předchozí
     * program nebo portál ČSSZ. Jde jen o měsíce, které hlídač převzatých měsíců
     * právě hlásí jako nepodané; jiný měsíc by potvrzením tiše zmizel z povinností.
     */
    public function attest(Request $request, Response $response): Response
    {
        if (($denied = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $environment = $this->environment($request);
        if ($environment !== 'production') {
            return $this->invalid($response, 'Podání mimo MyÚčto se potvrzuje jen v ostrém prostředí.');
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $periods = $body['periods'] ?? null;
        if (!is_array($periods) || $periods === [] || count($periods) > 24) {
            return $this->invalid($response, 'Vyberte alespoň jeden měsíc.');
        }
        $periods = array_values(array_unique(array_map(static fn (mixed $p): string => (string) $p, $periods)));
        $submittedOn = (string) ($body['submitted_on'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $submittedOn) !== 1 || $submittedOn > date('Y-m-d')) {
            return $this->invalid($response, 'Datum podání musí být platné datum, nejpozději dnešní.');
        }
        $note =isset($body['note']) && is_string($body['note']) ? $body['note'] : null;
        if ($note !== null && mb_strlen(trim($note)) > 500) {
            return $this->invalid($response, 'Poznámka může mít nejvýš 500 znaků.');
        }
        $supplierId = $this->currentSupplierId($request);
        $missing = array_map(
            static fn (array $gap): string => $gap['period'],
            array_filter(
                $this->gaps->missing($supplierId, $environment),
                static fn (array $gap): bool => !$gap['prepared_not_sent'],
            ),
        );
        $unknown = array_values(array_diff($periods, $missing));
        if ($unknown !== []) {
            return $this->invalid(
                $response,
                'Potvrdit jde jen převzatý měsíc bez hlášení v historii: ' . implode(', ', $unknown) . '.',
            );
        }
        $userId = $this->userId($request);
        $created = [];
        try {
            foreach ($periods as $period) {
                $result = $this->store->attestMonthly($supplierId, $environment, $period, $submittedOn, $note, $userId);
                $created[] = ['id' => $result['id'], 'period' => $period];
            }
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($response, $exception->getMessage());
        }
        foreach ($created as $row) {
            $this->activity->log('payroll.jmhz_external_attested', $userId, 'payroll_external_jmhz_submission', $row['id'],
                ['environment' => $environment, 'period' => $row['period'], 'submitted_on' => $submittedOn,
                    'note' => $note === null || trim($note) === '' ? null : trim($note)],
                supplierId: $supplierId);
        }

        return $this->noStore(Json::ok($response, ['attested' => $created]));
    }

    /** @param array<string,string> $args */
    public function revokeAttestation(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->authorize($request, $response, AccessLevel::WRITE)) !== null) {
            return $denied;
        }
        $environment = $this->environment($request);
        if ($environment === null) {
            return $this->invalid($response, 'Prostředí musí být test nebo production.');
        }
        $id = filter_var($args['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($id)) {
            return $this->invalid($response, 'Potvrzení musí být kladné celé číslo.');
        }
        $supplierId = $this->currentSupplierId($request);
        $revoked = $this->store->revokeAttestation($supplierId, $environment, $id);
        if ($revoked === null) {
            return $this->noStore(Json::error($response, 'not_found', 'Potvrzení podání mimo MyÚčto v téhle firmě není.', 404));
        }
        $this->activity->log('payroll.jmhz_external_attestation_revoked', $this->userId($request),
            'payroll_external_jmhz_submission', $id, ['environment' => $environment] + $revoked, supplierId: $supplierId);

        return $this->noStore(Json::ok($response, ['revoked' => true, 'id' => $id, 'period' => $revoked['period']]));
    }

    private function environment(Request $request): ?string
    {
        $value = $request->getQueryParams()['environment'] ?? 'production';

        return in_array($value, ['test', 'production'], true) ? $value : null;
    }

    private function authorize(Request $request, Response $response, AccessLevel $level): ?Response
    {
        if (!RequestAuthorization::actsAsSession($request)) {
            return Json::sessionRequired($response);
        }
        $error = null;
        if (!$this->requirePermission($request, $response, 'payroll.submissions', $level, $error)) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }

        return null;
    }

    private function invalid(Response $response, string $message): Response
    {
        return $this->noStore(Json::error($response, 'validation_failed', $message, 422));
    }

    private function noStore(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }
}
