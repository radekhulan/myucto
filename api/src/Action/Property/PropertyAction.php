<?php

declare(strict_types=1);

namespace MyInvoice\Action\Property;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\PropertyProfileRepository;
use MyInvoice\Repository\Property\PropertyReadRepository;
use MyInvoice\Repository\Property\PropertyRegistryRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Property\PropertyException;
use MyInvoice\Service\Property\PropertyImportService;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\PropertyInstant;
use MyInvoice\Service\Property\PropertyRegistryImportParser;
use MyInvoice\Service\Property\PropertySummaryService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PropertyAction
{
    use AccountingActionSupport;

    public function __construct(private readonly Connection $db, private readonly PropertyReadRepository $read,
        private readonly PropertyProfileRepository $profiles, private readonly PropertyRegistryRepository $registry,
        private readonly PropertyImportService $imports, private readonly PropertySummaryService $summary) {}

    public function profile(Request $request, Response $response): Response
    {
        return $this->run($request, $response, 'property', false, function (int $supplier) use ($request): array {
            [$at, $known] = $this->dates($request);
            return ['as_of' => $at, 'profile' => $this->profiles->at($supplier, $at, knownAt: $known), 'history' => $this->profiles->history($supplier),
                'import_columns' => PropertyRegistryImportParser::COLUMNS,
                'capabilities' => ['manage' => RequestAuthorization::isSessionAuth($request) && RequestAuthorization::allows($request, 'property.registry.manage', AccessLevel::WRITE),
                    'confirm' => RequestAuthorization::isSessionAuth($request) && RequestAuthorization::allows($request, 'property.registry.confirm', AccessLevel::WRITE),
                    'export' => RequestAuthorization::isSessionAuth($request) && RequestAuthorization::allows($request, 'property.export', AccessLevel::WRITE)]];
        });
    }

    public function saveProfile(Request $request, Response $response): Response
    {
        return $this->run($request, $response, 'property.registry.confirm', true, fn (int $supplier): array => $this->profiles->save($supplier, $this->body($request), $this->actor($request)));
    }

    public function catalogProfile(Request $request, Response $response): Response
    {
        return $this->run($request, $response, 'property', false, function (int $supplier) use ($request): array {
            [$at, $known] = $this->dates($request);
            return ['as_of' => $at, 'known_at' => $known,
                'profile' => \MyInvoice\Service\Property\PropertyPublicProjection::profile($this->profiles->at($supplier, $at, knownAt: $known))];
        });
    }

    public function catalogList(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, 'property', false, function (int $supplier) use ($request, $args): array {
            [$at, $known] = $this->dates($request);
            return ['as_of' => $at, 'known_at' => $known, 'items' => array_map(
                \MyInvoice\Service\Property\PropertyPublicProjection::object(...),
                $this->read->list($supplier, $this->kind($args), $at, $known),
            )];
        });
    }

    public function list(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, 'property', false, function (int $supplier) use ($request, $args): array {
            [$at, $known] = $this->dates($request);
            return ['items' => $this->read->list($supplier, $this->kind($args), $at, $known), 'as_of' => $at, 'known_at' => $known];
        });
    }

    public function detail(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, 'property', false, function (int $supplier) use ($request, $args): array {
            [$at, $known] = $this->dates($request);
            $result = $this->read->find($supplier, (int) ($args['id'] ?? 0), $at, $known);
            $parties = [];
            foreach ($result['history'] as $revision) {
                foreach ($revision['state']['ownerships'] ?? [] as $group) array_push($parties, ...$group['members']);
                array_push($parties, ...array_column($revision['state']['roles'] ?? [], 'client_id'));
            }
            return $result + ['parties' => $this->read->parties($supplier, $parties)];
        });
    }

    public function create(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, 'property.registry.manage', true, function (int $supplier) use ($request, $args): array {
            $body = $this->body($request);
            $this->assertConfirmation($request, $body);
            return $this->registry->create($supplier, $this->kind($args), $body, $this->actor($request));
        });
    }

    public function revise(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, 'property.registry.manage', true, function (int $supplier) use ($request, $args): array {
            $body = $this->body($request);
            $this->assertConfirmation($request, $body);
            $id = (int) ($args['id'] ?? 0);
            $object = $this->read->find($supplier, $id, PropertyInstant::normalize($body['effective_from'] ?? null));
            return $this->registry->revise($supplier, $id, $object['kind'], $body, $this->actor($request));
        });
    }

    public function confirm(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, 'property.registry.confirm', true, fn (int $supplier): array => $this->registry->confirm($supplier,
            (int) ($args['id'] ?? 0), (int) ($args['revisionId'] ?? 0), $this->body($request), $this->actor($request)));
    }

    public function importPreview(Request $request, Response $response): Response
    {
        return $this->run($request, $response, 'property.registry.manage', true, fn (int $supplier): array => $this->imports->previewFile($supplier, $this->body($request)));
    }

    public function importApply(Request $request, Response $response): Response
    {
        return $this->run($request, $response, 'property.registry.manage', true, function (int $supplier) use ($request): array {
            $body = $this->body($request);
            $this->assertConfirmation($request, is_array($body['plan'] ?? null) ? $body['plan'] : []);
            return ['items' => $this->imports->apply($supplier, $body, $this->actor($request))];
        });
    }

    public function summary(Request $request, Response $response): Response
    {
        return $this->run($request, $response, 'property', false, function (int $supplier) use ($request): array {
            [$at, $known] = $this->dates($request);
            return $this->summary->at($supplier, $at, $known);
        });
    }

    public function export(Request $request, Response $response): Response
    {
        return $this->run($request, $response, 'property.export', true, function (int $supplier) use ($request): array {
            [$at, $known] = $this->dates($request);
            return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplier, $request, $at, $known): array {
                (new DimensionPeriodGuard($this->db))->lockPeriods($supplier);
                $payload = ['as_of' => $at, 'known_at' => $known];
                foreach (['house', 'unit', 'service'] as $kind) $payload[$kind] = $this->read->list($supplier, $kind, $at, $known, true);
                $parties = [];
                foreach ($payload['unit'] as $unit) {
                    foreach ($unit['revision']['state']['ownerships'] ?? [] as $group) array_push($parties, ...$group['members']);
                    array_push($parties, ...array_column($unit['revision']['state']['roles'] ?? [], 'client_id'));
                }
                $payload['parties'] = $this->read->parties($supplier, $parties, true);
                $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
                $this->db->pdo()->prepare("INSERT INTO property_audit (supplier_id, action, payload_json, created_by, recorded_at) VALUES (?, 'export', ?, ?, ?)")
                    ->execute([$supplier, json_encode(['as_of' => $at, 'known_at' => $known, 'content_hash' => PropertyInput::hash($payload)], JSON_THROW_ON_ERROR), $this->actor($request), $now]);
                return $payload;
            });
        });
    }

    private function run(Request $request, Response $response, string $permission, bool $write, callable $work): Response
    {
        if ($write && !RequestAuthorization::isSessionAuth($request)) return Json::sessionRequired($response);
        if (!$this->requirePermission($request, $response, $permission, $write ? AccessLevel::WRITE : AccessLevel::READ, $error)) return $error;
        try { return Json::ok($response, $work($this->currentSupplierId($request))); }
        catch (PropertyException $e) { return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, ['details' => $e->details]); }
    }

    private function body(Request $request): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) PropertyInput::invalid('body');
        return $body;
    }

    private function actor(Request $request): int
    {
        return PropertyInput::id($this->userId($request), 'actor');
    }

    private function dates(Request $request): array
    {
        $q = $request->getQueryParams();
        return [PropertyInstant::normalize($q['as_of'] ?? null), isset($q['known_at']) && $q['known_at'] !== '' ? PropertyInstant::normalize($q['known_at']) : null];
    }

    private function kind(array $args): string
    {
        return match ($args['kind'] ?? '') { 'houses' => 'house', 'units' => 'unit', 'services' => 'service', default => throw new PropertyException('property_not_found', 'Číselník nebyl nalezen.', 404) };
    }

    private function assertConfirmation(Request $request, array $input): void
    {
        if (($input['status'] ?? null) === 'confirmed' && !RequestAuthorization::allows($request, 'property.registry.confirm', AccessLevel::WRITE)) {
            throw new PropertyException('forbidden', 'Pro potvrzení právních podkladů nemáte oprávnění.', 403);
        }
    }
}
