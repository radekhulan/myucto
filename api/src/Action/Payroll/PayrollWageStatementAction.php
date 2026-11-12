<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Repository\Payroll\PayrollDocumentRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Document\WageStatementReadinessException;
use MyInvoice\Service\Payroll\Document\WageStatementService;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Mzdový výměr (§ 136 ZP) na kartě pracovního vztahu: připravenost,
 * seznam vydaných verzí a vydání nové.
 */
final class PayrollWageStatementAction
{
    use PayrollActionSupport;

    private const PUBLIC_KEYS = [
        'id',
        'employee_id',
        'employee_name',
        'employment_id',
        'effective_from',
        'wage_statement_revision_id',
        'wage_statement_revision_no',
        'document_kind',
        'document_revision_no',
        'supersedes_document_id',
        'file_sha256',
        'size_bytes',
        'mime_type',
        'suggested_filename',
        'created_at',
    ];

    public function __construct(
        private readonly WageStatementService $statements,
        private readonly PayrollDocumentRepository $documents,
        private readonly PayrollModuleAccess $moduleAccess,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** @param array<string,string> $args */
    public function list(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->guard($request, $response, AccessLevel::READ)) !== null) {
            return $error;
        }
        $supplierId = $this->currentSupplierId($request);
        $employmentId = (int) ($args['id'] ?? 0);
        try {
            $readiness = $this->statements->readiness($supplierId, $employmentId);
            $items = $this->documents->listWageStatementDocuments($supplierId, $employmentId);
        } catch (\InvalidArgumentException $exception) {
            return self::private(Json::error($response, 'validation_failed', $exception->getMessage(), 422));
        }

        return self::private(Json::ok($response, [
            'employment_id' => $employmentId,
            'readiness' => $readiness,
            'items' => array_map(self::publicDocument(...), $items),
        ]));
    }

    /** @param array<string,string> $args */
    public function generate(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->guard($request, $response, AccessLevel::WRITE)) !== null) {
            return $error;
        }
        $supplierId = $this->currentSupplierId($request);
        $userId = $this->userId($request);
        $employmentId = (int) ($args['id'] ?? 0);
        $idempotencyKey = trim($request->getHeaderLine('Idempotency-Key'));
        $body = $request->getParsedBody();
        if ($userId === null || $employmentId <= 0 || $idempotencyKey === '' || !is_array($body)) {
            return self::private(Json::error(
                $response,
                'validation_failed',
                'Požadavek na mzdový výměr není platný.',
                422,
            ));
        }
        try {
            $document = $this->statements->generate(
                $supplierId,
                $employmentId,
                [
                    'effective_from' => $body['effective_from'] ?? null,
                    'payment_place' => $body['payment_place'] ?? null,
                    'note' => $body['note'] ?? null,
                ],
                $idempotencyKey,
                $userId,
            );
        } catch (WageStatementReadinessException $exception) {
            return self::private(Json::error($response, $exception->readinessCode, $exception->getMessage(), 422));
        } catch (\InvalidArgumentException $exception) {
            return self::private(Json::error($response, 'validation_failed', $exception->getMessage(), 422));
        } catch (\Throwable) {
            return self::private(Json::error(
                $response,
                'wage_statement_generation_failed',
                'Mzdový výměr se nepodařilo bezpečně vytvořit.',
                409,
            ));
        }

        $this->activity->log(
            'payroll.wage_statement_generated',
            $userId,
            'payroll_document',
            (int) $document['id'],
            [
                'employment_id' => $employmentId,
                'wage_statement_revision_id' => $document['wage_statement_revision_id'] ?? null,
                'file_sha256' => $document['file_sha256'] ?? null,
            ],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        return self::private(Json::ok($response, self::publicDocument($document), 201));
    }

    private function guard(Request $request, Response $response, AccessLevel $level): ?Response
    {
        if (!RequestAuthorization::actsAsSession($request)) {
            return self::private(Json::sessionRequired(
                $response,
                'Tento endpoint je dostupný pouze z přihlášené webové session.',
            ));
        }
        if (!$this->requirePermission($request, $response, 'payroll.documents', $level, $error)
            || !$this->requirePayrollEnabled($request, $response, $this->moduleAccess, $error)
        ) {
            return self::private($error ?? Json::error($response, 'forbidden', 'Pro tuto akci nemáte oprávnění.', 403));
        }

        return null;
    }

    private static function private(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /**
     * @param array<string,mixed> $document
     * @return array<string,mixed>
     */
    private static function publicDocument(array $document): array
    {
        return array_intersect_key($document, array_flip(self::PUBLIC_KEYS));
    }
}
