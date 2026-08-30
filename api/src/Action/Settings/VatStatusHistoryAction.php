<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Report\VatRegistrationService;
use MyInvoice\Service\Vat\VatStatusService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Správa historie plátcovství DPH (EPIC VH-01) — supplier_vat_status_history.
 *
 *   POST   /api/settings/vat-status-history                     — přidání/úprava řádku (upsert po effective_from)
 *   DELETE /api/settings/vat-status-history/{id}                — smazání řádku
 *   GET    /api/settings/vat-status-history/registration-check  — § 6/§ 94 hlídač obratu (EPIC VH-07)
 *
 * Seznam historie vrací GET /api/settings/supplier (klíč vat_status_history).
 *
 * Retro-guard: změna s účinností v uzamčeném období (accounting_periods
 * closing/closed/approved, zámek k datu locked_until) nebo před/uvnitř období
 * už podaného přiznání (tax_submissions status submitted/accepted) by tiše
 * změnila základ, ze kterého výkazy vznikly → 409 s výčtem kolizí; pokračovat
 * lze jen s explicitním body.acknowledge = true (zapíše se do auditu).
 */
final class VatStatusHistoryAction
{
    private const BASELINE_DATE = '1900-01-01';

    public function __construct(
        private readonly Connection $db,
        private readonly VatStatusService $vatStatus,
        private readonly \MyInvoice\Service\Vat\VatStatusGuard $vatGuard,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly VatRegistrationService $vatRegistration,
    ) {}

    public function save(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $sid = $this->supplierId($request);
        if ($sid <= 0) return Json::error($response, 'validation_failed', 'Chybí aktivní firma.', 400);

        $body = (array) ($request->getParsedBody() ?? []);

        $effectiveFrom = trim((string) ($body['effective_from'] ?? ''));
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $effectiveFrom);
        if ($date === false || $date->format('Y-m-d') !== $effectiveFrom) {
            return Json::error($response, 'validation_failed', 'Datum účinnosti plátcovství DPH není platné.', 422);
        }

        $isVatPayer = (bool) ($body['is_vat_payer'] ?? false);
        $isIdentified = (bool) ($body['is_identified'] ?? false);
        if ($isVatPayer && $isIdentified) {
            return Json::error($response, 'validation_failed',
                'Identifikovaná osoba je z definice neplátce DPH — nelze kombinovat s plátcovstvím.', 422);
        }

        $note = trim((string) ($body['note'] ?? ''));
        if (mb_strlen($note) > 255) {
            return Json::error($response, 'validation_failed', 'Poznámka má max. 255 znaků.', 422);
        }

        // Paušalista nesmí být plátce DPH (§ 7a ZDP) — stejné pravidlo jako
        // u flat_tax_band v PUT /settings/supplier, jen z druhé strany.
        if ($isVatPayer) {
            $stmt = $this->db->pdo()->prepare("SELECT COALESCE(flat_tax_band, 'none') FROM supplier WHERE id = ?");
            $stmt->execute([$sid]);
            if ((string) $stmt->fetchColumn() !== 'none') {
                return Json::error($response, 'validation_failed',
                    'Firma je v režimu paušální daně — paušalista nesmí být plátce DPH (§ 7a ZDP). Nejprve zrušte paušální daň.', 422);
            }
        }

        $collisions = $this->collisions($sid, $effectiveFrom);
        $acknowledge = (bool) ($body['acknowledge'] ?? false);
        if ($collisions !== [] && !$acknowledge) {
            return Json::error($response, 'vat_status_locked_conflict',
                'Změna plátcovství zasahuje do uzamčeného období nebo už podaných přiznání.', 409,
                ['collisions' => $collisions]);
        }

        // VH-07: přechod plátcovství (0→1 registrace, 1→0 zrušení registrace) se
        // pozná porovnáním stavu DEN PŘED účinností s nově zapisovaným stavem —
        // ještě před upsertem, aby nový řádek srovnání nezkreslil.
        $wasPayer = VatStatusService::payerAt(
            $this->db->pdo(), $sid, $date->modify('-1 day')->format('Y-m-d')
        );

        $this->vatStatus->upsert($sid, $effectiveFrom, $isVatPayer, $isIdentified, $note !== '' ? $note : null, $this->userId($request));
        $this->vatStatus->refreshLiveCache($sid);

        $this->log($request, 'supplier.vat_status_changed', $sid, [
            'effective_from' => $effectiveFrom,
            'is_vat_payer'   => $isVatPayer,
            'is_identified'  => $isIdentified,
            'note'           => $note !== '' ? $note : null,
            'acknowledged'   => $acknowledge && $collisions !== [],
            'collisions'     => $collisions,
        ]);

        $payload = $this->statePayload($sid);

        // Přechod s účinností <= dnes → nenásilný hint na § 79/§ 79a agendu
        // (odpočet při registraci / snížení odpočtu při zrušení, ř. 45 přiznání).
        // Budoucí přechody hint nedostanou — korekce se řeší až v období účinnosti.
        // Baseline řádek (1900-01-01) není přechod, ale definice výchozího stavu.
        if ($wasPayer !== $isVatPayer && $effectiveFrom !== self::BASELINE_DATE && $effectiveFrom <= date('Y-m-d')) {
            $payload['suggest_s79'] = [
                'kind'         => $isVatPayer ? 'registration' : 'deregistration',
                'effective_on' => $effectiveFrom,
            ];
        }

        return Json::ok($response, $payload);
    }

    /**
     * § 6/§ 4a hlídač obratu pro banner v bloku Plátcovství DPH (EPIC VH-07):
     * výstup VatRegistrationService + termín přihlášky dle § 94 odst. 1
     * (10 pracovních dnů ode dne překročení obratu 2 000 000 Kč).
     *
     * Kouká i na PŘEDCHOZÍ rok: obrat loňska zakládá plátcovství od 1. ledna
     * letoška (§ 6 odst. 1) a novoroční reset obratu běžného roku nesmí banner
     * zhasnout, dokud firma registraci nevyřídila.
     */
    public function registrationCheck(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $sid = $this->supplierId($request);
        if ($sid <= 0) return Json::error($response, 'validation_failed', 'Chybí aktivní firma.', 400);

        $reg = $this->vatRegistration->evaluate($sid, (int) date('Y'));
        if (!in_array($reg['status'], ['exceeded_low', 'exceeded_high'], true)) {
            $prev = $this->vatRegistration->evaluate($sid, (int) date('Y') - 1);
            if (in_array($prev['status'], ['exceeded_low', 'exceeded_high'], true)) {
                $reg = $prev;
            }
        }
        $reg['application_deadline'] = null;
        $reg['application_deadline_basis'] = null;
        if (in_array($reg['status'], ['exceeded_low', 'exceeded_high'], true)) {
            $deadline = $this->vatRegistration->applicationDeadline(
                $reg['crossed_low_on'] ?? null,
                $reg['becomes_payer_on'],
            );
            if ($deadline !== null) {
                $reg['application_deadline'] = $deadline['deadline'];
                $reg['application_deadline_basis'] = $deadline['basis'];
            }
        }

        return Json::ok($response, $reg);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $sid = $this->supplierId($request);
        if ($sid <= 0) return Json::error($response, 'validation_failed', 'Chybí aktivní firma.', 400);

        $id = (int) ($args['id'] ?? 0);
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, effective_from, is_vat_payer, is_identified
               FROM supplier_vat_status_history WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $sid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return Json::error($response, 'not_found', 'Řádek historie plátcovství nenalezen.', 404);
        }

        // Baseline (1900-01-01) drží garanci, že firma má vždy definovaný stav
        // pro celou minulost; a poslední řádek firmy nesmí zmizet nikdy.
        if ((string) $row['effective_from'] === self::BASELINE_DATE) {
            return Json::error($response, 'vat_status_baseline_protected',
                'Výchozí řádek historie plátcovství nelze smazat.', 409);
        }
        $count = $this->db->pdo()->prepare('SELECT COUNT(*) FROM supplier_vat_status_history WHERE supplier_id = ?');
        $count->execute([$sid]);
        if ((int) $count->fetchColumn() <= 1) {
            return Json::error($response, 'vat_status_last_row',
                'Poslední řádek historie plátcovství nelze smazat.', 409);
        }

        // Smazání retroaktivního řádku mění stav v minulosti úplně stejně jako
        // jeho přidání → stejný retro-guard + acknowledge flow.
        $effectiveFrom = (string) $row['effective_from'];
        $body = (array) ($request->getParsedBody() ?? []);
        $query = $request->getQueryParams();
        $acknowledge = (bool) ($body['acknowledge'] ?? $query['acknowledge'] ?? false);
        $collisions = $this->collisions($sid, $effectiveFrom);
        if ($collisions !== [] && !$acknowledge) {
            return Json::error($response, 'vat_status_locked_conflict',
                'Smazání změny plátcovství zasahuje do uzamčeného období nebo už podaných přiznání.', 409,
                ['collisions' => $collisions]);
        }

        $this->db->pdo()->prepare('DELETE FROM supplier_vat_status_history WHERE id = ? AND supplier_id = ?')
            ->execute([$id, $sid]);
        $this->vatStatus->refreshLiveCache($sid);

        $this->log($request, 'supplier.vat_status_deleted', $sid, [
            'effective_from' => $effectiveFrom,
            'is_vat_payer'   => (bool) $row['is_vat_payer'],
            'is_identified'  => (bool) $row['is_identified'],
            'acknowledged'   => $acknowledge && $collisions !== [],
            'collisions'     => $collisions,
        ]);

        return Json::ok($response, $this->statePayload($sid));
    }

    /**
     * Retro-kolize dané účinnosti — sdílená logika ve {@see \MyInvoice\Service\Vat\VatStatusGuard}
     * (používá ji i legacy checkbox v PUT /settings/supplier).
     *
     * @return list<array<string,mixed>>
     */
    private function collisions(int $sid, string $effectiveFrom): array
    {
        return $this->vatGuard->collisions($sid, $effectiveFrom);
    }

    /** @return array<string,mixed> historie + živá cache po změně */
    private function statePayload(int $sid): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, effective_from, is_vat_payer, is_identified, note, annual_deduction_percent
               FROM supplier_vat_status_history WHERE supplier_id = ? ORDER BY effective_from'
        );
        $stmt->execute([$sid]);
        $history = array_map(static fn (array $item): array => [
            'id'                       => (int) $item['id'],
            'effective_from'           => (string) $item['effective_from'],
            'is_vat_payer'             => (bool) $item['is_vat_payer'],
            'is_identified'            => (bool) $item['is_identified'],
            'note'                     => $item['note'] !== null ? (string) $item['note'] : null,
            'annual_deduction_percent' => (float) $item['annual_deduction_percent'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);

        $live = $this->db->pdo()->prepare('SELECT is_vat_payer, is_identified FROM supplier WHERE id = ?');
        $live->execute([$sid]);
        $row = $live->fetch(\PDO::FETCH_ASSOC) ?: [];

        return [
            'vat_status_history' => $history,
            'is_vat_payer'       => (bool) ($row['is_vat_payer'] ?? false),
            'is_identified'      => (bool) ($row['is_identified'] ?? false),
        ];
    }

    private function supplierId(Request $request): int
    {
        return (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
    }

    private function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return ((int) ($user['id'] ?? 0)) ?: null;
    }

    private function guard(Request $request, Response $response, ?Response &$err): bool
    {
        if (!RequestAuthorization::allows($request, 'settings.company.write', AccessLevel::WRITE)) {
            $err = Json::error($response, 'forbidden', 'Pouze admin.', 403);
            return false;
        }
        $err = null;
        return true;
    }

    private function log(Request $request, string $action, int $supplierId, array $payload): void
    {
        $this->logger->log(
            $action,
            (int) (((array) $request->getAttribute(AuthMiddleware::ATTR_USER, []))['id'] ?? 0),
            'supplier',
            $supplierId,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );
    }
}
