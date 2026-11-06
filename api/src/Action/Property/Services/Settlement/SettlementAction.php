<?php

declare(strict_types=1);

namespace MyInvoice\Action\Property\Services\Settlement;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Repository\Property\Services\Settlement\{SettlementReadRepository,SettlementApplicationRepository};
use MyInvoice\Security\{AccessLevel,RequestAuthorization};
use MyInvoice\Service\Property\{PropertyInput,PropertyException};
use MyInvoice\Service\Property\Services\Settlement\{SettlementWorkflow,SettlementEvidence};
use Psr\Http\Message\{ServerRequestInterface as Request,ResponseInterface as Response};

final class SettlementAction
{
    use AccountingActionSupport;
    public function __construct(private readonly SettlementReadRepository $read, private readonly SettlementWorkflow $workflow, private readonly SettlementApplicationRepository $owner, private readonly \MyInvoice\Repository\Property\Services\Settlement\SettlementDispositionReadRepository $dispositions, private readonly \MyInvoice\Repository\Property\Services\Settlement\SettlementDossierRepository $dossiers, private readonly \MyInvoice\Repository\Property\Services\Settlement\SettlementObjectionRepository $objections)
    {
    }
    public function list(Request $q, Response $r): Response
    {
        return $this->listing($q, $r, false);
    }
    public function publicList(Request $q, Response $r): Response
    {
        return $this->listing($q, $r, true);
    }
    private function listing(Request $q, Response $r, bool $public): Response
    {
        return $this->run($q, $r, 'property', false, $public, function (int $supplier) use ($q, $public) {
            $args = $this->query($q, $supplier, ['after_id','limit','unit_id','service_id']);
            $page = $this->read->page($supplier, $this->integer($args['after_id'] ?? 0, 'after_id'), $this->integer($args['limit'] ?? 25, 'limit'), isset($args['unit_id']) ? $this->integer($args['unit_id'], 'unit_id') : null, isset($args['service_id']) ? $this->integer($args['service_id'], 'service_id') : null);
            if (!$public) {
                $page['capabilities'] = $this->capabilities($q);
            }return $page;
        });
    }
    public function detail(Request $q, Response $r, array $args): Response
    {
        return $this->show($q, $r, $args, false);
    }
    public function publicDetail(Request $q, Response $r, array $args): Response
    {
        return $this->show($q, $r, $args, true);
    }
    private function show(Request $q, Response $r, array $args, bool $public): Response
    {
        return $this->run($q, $r, 'property', false, $public, function (int $supplier) use ($q, $args, $public) {
            $this->query($q, $supplier, []);
            $read = $this->read->detail($supplier, $this->id($args), $public);
            if (!$public) {
                $read['capabilities'] = $this->capabilities($q);
            }return $read;
        });
    }
    public function dossier(Request $q, Response $r, array $args): Response
    {
        return $this->run($q, $r, 'property', false, false, function (int $supplier) use ($q,$args): array {
            $this->query($q, $supplier, []);
            return $this->dossiers->read($supplier, $this->id($args));
        });
    }
    public function appendDossierEvent(Request $q, Response $r, array $args): Response
    {
        return $this->run($q, $r, 'property.settlements.manage', true, false, function (int $supplier) use ($q,$args): array {
            $body = $q->getParsedBody(); if (!is_array($body)) PropertyInput::invalid('body');
            SettlementEvidence::fields($body, ['event','expected_revision','reason','idempotency_key']);
            if (!is_array($body['event'])) PropertyInput::invalid('event');
            return $this->dossiers->append($supplier, $this->id($args), $body['event'], $this->integer($body['expected_revision'], 'expected_revision'), PropertyInput::text($body['reason'], 'reason', 4000), PropertyInput::code($body['idempotency_key'], 'idempotency_key'), PropertyInput::id($this->userId($q), 'actor'));
        });
    }
    public function deadlines(Request $q, Response $r, array $args): Response
    {
        return $this->run($q, $r, 'property', false, false, function (int $supplier) use ($q, $args): array {
            $query = $this->query($q, $supplier, ['today']);
            $today = isset($query['today']) ? SettlementEvidence::date($query['today']) : (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Prague')))->format('Y-m-d');
            return $this->objections->deadlines($supplier, $this->id($args), $today);
        });
    }
    public function recordObjection(Request $q, Response $r, array $args): Response
    {
        return $this->run($q, $r, 'property.settlements.manage', true, false, function (int $supplier) use ($q, $args): array {
            $body = $q->getParsedBody();
            if (!is_array($body)) PropertyInput::invalid('body');
            SettlementEvidence::fields($body, ['recipient_id', 'received_on', 'content']);
            if (!is_string($body['content'])) PropertyInput::invalid('content');
            return $this->objections->record($supplier, $this->id($args), PropertyInput::id($body['recipient_id'], 'recipient_id'), SettlementEvidence::date($body['received_on']), $body['content'], PropertyInput::id($this->userId($q), 'actor'));
        });
    }
    public function resolveObjection(Request $q, Response $r, array $args): Response
    {
        return $this->run($q, $r, 'property.settlements.manage', true, false, function (int $supplier) use ($q, $args): array {
            $body = $q->getParsedBody();
            if (!is_array($body)) PropertyInput::invalid('body');
            SettlementEvidence::fields($body, ['resolved_on', 'resolution']);
            if (!is_string($body['resolution'])) PropertyInput::invalid('resolution');
            return $this->objections->resolve($supplier, $this->id($args), SettlementEvidence::date($body['resolved_on']), $body['resolution'], PropertyInput::id($this->userId($q), 'actor'));
        });
    }
    public function disposition(Request $q, Response $r, array $args): Response
    {
        return $this->run($q, $r, 'property', false, false, function (int $supplier) use ($q, $args): array {
            $query = $this->query($q, $supplier, ['accounting_date']);
            return $this->dispositions->current($supplier, $this->id($args), SettlementEvidence::date($query['accounting_date'] ?? null));
        });
    }
    public function current(Request $q, Response $r, array $args): Response
    {
        return $this->currentRead($q, $r, $args, false);
    }
    public function publicCurrent(Request $q, Response $r, array $args): Response
    {
        return $this->currentRead($q, $r, $args, true);
    }
    private function currentRead(Request $q, Response $r, array $args, bool $public): Response
    {
        return $this->run($q, $r, 'property', false, $public, function (int $supplier) use ($q, $args) {
            $this->query($q, $supplier, []);
            return $this->read->current($supplier, $this->id($args));
        });
    }
    public function catalog(Request $q, Response $r, array $args): Response
    {
        return $this->run($q, $r, 'property', false, false, function (int $supplier) use ($q, $args) {
            $a = $this->query($q, $supplier, ['unit_id','service_id','date','after_id','after_service_id','limit','s3_line_id']);
            return $this->read->catalog($supplier, $args['collection'], $this->integer($a['unit_id'] ?? null, 'unit_id'), $this->integer($a['service_id'] ?? null, 'service_id'), (string)($a['date'] ?? ''), $this->integer($a['after_id'] ?? 0, 'after_id'), $this->integer($a['limit'] ?? 25, 'limit'), $this->integer($a['after_service_id'] ?? 0, 'after_service_id'), isset($a['s3_line_id']) ? $this->integer($a['s3_line_id'], 's3_line_id') : null);
        });
    }
    public function prepare(Request $q, Response $r): Response
    {
        return $this->run($q, $r, 'property.settlements.manage', true, false, function (int $supplier) use ($q) {
            $b = $this->body($q, ['request','expected_revision']);
            return $this->workflow->prepare($supplier, $b['request'], $this->integer($b['expected_revision'] ?? null, 'expected_revision'));
        });
    }
    public function save(Request $q, Response $r): Response
    {
        return $this->run($q, $r, 'property.settlements.manage', true, false, function (int $supplier) use ($q) {
            $b = $this->body($q, ['request','expected_revision','preview_hash','idempotency_key']);
            return $this->workflow->save($supplier, $b['request'], $this->integer($b['expected_revision'] ?? null, 'expected_revision'), SettlementEvidence::hash($b['preview_hash'] ?? null), PropertyInput::code($b['idempotency_key'] ?? null, 'idempotency_key'), PropertyInput::id($this->userId($q), 'actor'));
        });
    }
    public function preview(Request $q, Response $r, array $args): Response
    {
        return $this->run($q, $r, 'property.settlements.manage', true, false, function (int $supplier) use ($q, $args) {
            $b = $this->body($q, ['request','expected_version']);
            return $this->owner->preview($supplier, $this->id($args), $b['request'], $this->integer($b['expected_version'] ?? null, 'expected_version'));
        });
    }
    public function apply(Request $q, Response $r, array $args): Response
    {
        return $this->run($q, $r, 'property.settlements.post', true, false, function (int $supplier) use ($q, $args) {
            $b = $this->body($q, ['request','expected_version','preview_hash','idempotency_key']);
            return $this->owner->apply($supplier, $this->id($args), $b['request'], $this->integer($b['expected_version'] ?? null, 'expected_version'), SettlementEvidence::hash($b['preview_hash'] ?? null), PropertyInput::code($b['idempotency_key'] ?? null, 'idempotency_key'), PropertyInput::id($this->userId($q), 'actor'));
        });
    }
    public function reverse(Request $q, Response $r, array $args): Response
    {
        return $this->run($q, $r, 'property.settlements.reverse', true, false, function (int $supplier) use ($q, $args) {
            $body = $q->getParsedBody();
            if (!is_array($body)) {
                PropertyInput::invalid('body');
            }SettlementEvidence::fields($body, ['accounting_date','expected_version','reason','idempotency_key']);
            return $this->owner->reverse($supplier, $this->id($args), SettlementEvidence::date($body['accounting_date']), $this->integer($body['expected_version'], 'expected_version'), PropertyInput::text($body['reason'], 'reason', 4000), PropertyInput::code($body['idempotency_key'], 'idempotency_key'), PropertyInput::id($this->userId($q), 'actor'));
        });
    }
    private function capabilities(Request $q): array
    {
        $out = [];
        foreach (['manage','post','reverse'] as $cap) {
            $out[$cap] = RequestAuthorization::isSessionAuth($q) && RequestAuthorization::allows($q, 'property.settlements.'.$cap, AccessLevel::WRITE);
        }return $out;
    }
    private function run(Request $q, Response $r, string $permission, bool $write, bool $public, callable $work): Response
    {
        if (!$public && !RequestAuthorization::isSessionAuth($q)) {
            return Json::sessionRequired($r);
        }if (!RequestAuthorization::allows($q, $permission, $write ? AccessLevel::WRITE : AccessLevel::READ)) {
            return Json::error($r, 'forbidden', 'Pro tuto operaci nemáte oprávnění.', 403);
        }
        try {
            $supplier = $this->currentSupplierId($q);
            PropertyInput::id($supplier, 'supplier_id');
            return Json::ok($r, $work($supplier));
        } catch (PropertyException|\MyInvoice\Service\Property\Opening\OpeningException|\MyInvoice\Service\Property\Payments\PaymentException|\MyInvoice\Service\Property\Charges\Issuance\IssuanceException|\MyInvoice\Service\Property\Receivables\ReceivableException $e) {
            return Json::error($r, $e->errorCode, $e->getMessage(), $e->httpStatus, $public ? [] : ['details' => $e->details]);
        } catch (\MyInvoice\Service\Accounting\PostingException|\MyInvoice\Service\Accounting\Dimension\DimensionException $e) {
            return Json::error($r, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\DomainException $e) {
            if (!str_starts_with($e->getMessage(), 'settlement_')) {
                throw $e;
            }return Json::error($r, $e->getMessage(), 'Podklady vyúčtování nejsou aktuální nebo doložené.', 409);
        }
    }
    private function query(Request $q, int $supplier, array $fields): array
    {
        $args = $q->getQueryParams();
        PropertyInput::fields($args, [...$fields,'supplier_id']);
        if (isset($args['supplier_id']) && $this->integer($args['supplier_id'], 'supplier_id') !== $supplier) {
            PropertyInput::invalid('supplier_id');
        }return $args;
    }
    private function body(Request $q,array $fields): array
    {
        $body = $q->getParsedBody();
        if (!is_array($body)) {
            PropertyInput::invalid('body');
        }SettlementEvidence::fields($body,$fields);
        if (!is_array($body['request'] ?? null)) {
            PropertyInput::invalid('request');
        }return $body;
    }
    private function integer(mixed $value,string $field): int
    {
        if (is_bool($value) || (!is_int($value) && (!is_string($value) || !preg_match('/^(0|[1-9][0-9]*)$/D',$value))) || filter_var($value,FILTER_VALIDATE_INT) === false) {
            PropertyInput::invalid($field);
        }return (int)$value;
    }
    private function id(array $args): int
    {
        return PropertyInput::id($this->integer($args['id'] ?? null,'id'),'id');
    }
}
