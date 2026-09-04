<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollSubmissionConflictException;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionSettlementService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /submissions/{submissionId}/settle — účetní potvrdí, že je povinnost
 * vyřízená, protože od úřadu už nic nepřijde.
 *
 * Tělo: `{ environment, row_version, note }`, kde `row_version` je verze
 * POVINNOSTI — ta se mění, ne stav podání.
 *
 * Brána je v {@see \MyInvoice\Service\Payroll\Submission\PayrollSubmissionSettlementPolicy}:
 * projde jen agenda, u které je doložené, že úřad výsledek zpracování
 * neposílá (přehled zdravotní pojišťovně). Tam, kde protokol dorazí, se tudy
 * měsíc odkliknout nedá.
 *
 * `note` je povinná a jde do historie i do odchozí fronty jako
 * `manual_confirmation` — aby bylo později poznat, že měsíc uzavřel člověk
 * a o co se opřel, ne že by dorazil protokol.
 */
final class PayrollSubmissionSettlementAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollSubmissionSettlementService $settlements,
        private readonly PayrollModuleAccess $access,
    ) {}

    /** @param array{submissionId?:string} $args */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        // Uzavření úřední povinnosti jménem firmy se nespouští přes token:
        // token se dá odcizit a na rozdíl od relace u něj není druhý faktor.
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return Json::error(
                $response,
                'session_required',
                'Uzavření podání je dostupné jen z přihlášené relace.',
                403,
            );
        }
        $error = null;
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.submissions',
            AccessLevel::WRITE,
            $error,
        )) {
            if ($error === null) {
                throw new \LogicException('Chybí odpověď pro zamítnuté oprávnění.');
            }

            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            if ($error === null) {
                throw new \LogicException('Chybí odpověď pro vypnutý modul mezd.');
            }

            return $error;
        }

        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $environment = $body['environment'] ?? null;
        if (!is_string($environment)
            || !in_array($environment, ['production', 'test'], true)
        ) {
            return $this->invalid(
                $response,
                'Prostředí podání musí být test nebo production.',
            );
        }
        $submissionId = $args['submissionId'] ?? '';
        if (preg_match('/^[1-9][0-9]*$/D', $submissionId) !== 1) {
            return $this->invalid(
                $response,
                'Identifikátor podání musí být kladné celé číslo.',
            );
        }
        $rowVersion = $body['row_version'] ?? null;
        if (!is_int($rowVersion)
            && (!is_string($rowVersion)
                || preg_match('/^[1-9][0-9]*$/D', $rowVersion) !== 1)
        ) {
            return $this->invalid(
                $response,
                'Verze povinnosti musí být kladné celé číslo — bez ní by šlo'
                    . ' uzavřít povinnost, která se mezitím pohnula.',
            );
        }
        $note = $body['note'] ?? null;
        $note = is_string($note) ? $note : '';

        try {
            $result = $this->settlements->settle(
                $this->currentSupplierId($request),
                $environment,
                (int) $submissionId,
                (int) $rowVersion,
                $note,
                $this->userId($request),
            );
        } catch (PayrollSubmissionConflictException $exception) {
            return $this->noStore(Json::error(
                $response,
                'row_version_conflict',
                $exception->getMessage(),
                409,
            ));
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid($response, $exception->getMessage());
        } catch (\DomainException $exception) {
            return $this->noStore(Json::error(
                $response,
                'conflict',
                $exception->getMessage(),
                409,
            ));
        }

        return $this->noStore(Json::ok($response, [
            'environment' => $environment,
            ...$result,
        ]));
    }

    private function invalid(Response $response, string $message): Response
    {
        return $this->noStore(
            Json::error($response, 'validation_failed', $message, 422),
        );
    }

    private function noStore(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }
}
