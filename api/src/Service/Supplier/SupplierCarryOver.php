<?php

declare(strict_types=1);

namespace MyInvoice\Service\Supplier;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\EpoSigningCredentialRepository;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Import\AiCredentialsBulkApplier;
use MyInvoice\Service\Signing\CertificateVaultSupplierSharing;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Převzetí AI klíče a certifikátu ze stávajících firem do právě zakládané firmy.
 *
 * Nabízí se jen to, co je ve všech firmách uživatele STEJNÉ: AI nastavení, které
 * mají všechny firmy s nastavenou AI shodné (dešifrovaný klíč, model nebo endpoint,
 * region, EU rezidence, effort), a certifikát z osobního trezoru, který je povolený
 * ve všech firmách, kde uživatel smí spravovat podpisy. Jakmile se firmy liší,
 * převzetí se nenabízí: z různých hodnot by server musel vybírat za uživatele.
 *
 * Firmy uživatele počítá server stejně jako přepínač: superadmin všechny, ostatní
 * jen přiřazené, a každá ještě projde resolucí přístupu (zamčená doména, vázaný
 * token). Náhled vrací jen poskytovatele, názvy firem a veřejná metadata
 * certifikátu, nikdy klíč.
 *
 * Při převzetí se shoda ověřuje znovu: mezi náhledem a uložením se nastavení
 * mohlo změnit. Cílem je nová firma výslovně, ne výběr z přepínače, protože
 * superadmin s jiným členstvím v ní řádek `user_suppliers` nemá.
 */
final class SupplierCarryOver
{
    public const ERROR_NOT_AVAILABLE = 'not_available';
    public const ERROR_DIFFERENT = 'different';
    public const ERROR_FORBIDDEN = 'forbidden';
    public const ERROR_EU_RESIDENCY = 'eu_residency';
    public const ERROR_NOT_OWNED = 'not_owned';
    public const ERROR_NOT_SHARED_EVERYWHERE = 'not_shared_everywhere';
    public const ERROR_FAILED = 'failed';

    /** Strop počtu certifikátů v jednom založení, ať vstup nemůže být libovolně dlouhý. */
    private const MAX_CERTIFICATES = 20;

    public function __construct(
        private readonly Connection $db,
        private readonly UserSupplierRepository $memberships,
        private readonly SupplierAccessResolver $supplierAccess,
        private readonly AiCredentialsBulkApplier $ai,
        private readonly CertificateVaultSupplierSharing $sharing,
        private readonly EpoSigningCredentialRepository $credentials,
        private readonly ActivityLogger $logger,
        private readonly LoggerInterface $log,
    ) {}

    /**
     * Normalizuje `carry_over` z těla požadavku.
     *
     * @return array{ai:bool, certificate_ids:list<int>, step_up:array<string,mixed>}|null
     *         null = převzetí se nežádá
     * @throws \InvalidArgumentException neplatný tvar vstupu
     */
    public static function parse(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        if (!is_array($raw)) {
            throw new \InvalidArgumentException('Pole carry_over musí být objekt.');
        }
        $ai = filter_var($raw['ai'] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($ai === null) {
            throw new \InvalidArgumentException('Pole carry_over.ai musí být true nebo false.');
        }
        $ids = $raw['certificate_ids'] ?? [];
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > self::MAX_CERTIFICATES) {
            throw new \InvalidArgumentException('Pole carry_over.certificate_ids musí být seznam id certifikátů.');
        }
        $certificateIds = [];
        foreach ($ids as $id) {
            $value = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($value === false) {
                throw new \InvalidArgumentException('Pole carry_over.certificate_ids musí být seznam id certifikátů.');
            }
            $certificateIds[$value] = $value;
        }
        $stepUp = $raw['step_up'] ?? [];
        if (!is_array($stepUp)) {
            throw new \InvalidArgumentException('Pole carry_over.step_up musí být objekt.');
        }
        if (!$ai && $certificateIds === []) {
            return null;
        }

        return ['ai' => $ai, 'certificate_ids' => array_values($certificateIds), 'step_up' => $stepUp];
    }

    /**
     * @return array{
     *     ai: array{available:bool, reason:?string, provider:?string, companies_count:int, companies:list<string>},
     *     certificates: list<array{id:int, label:string, subject:string, valid_to:string, companies_count:int}>
     * }
     */
    public function preview(Request $request): array
    {
        $userId = $this->userId($request);
        $reachable = $this->reachableSuppliers($request, $userId, 0);

        $uniform = $this->ai->uniformConfiguration(array_keys($reachable));
        $ai = [
            'available'       => $uniform['status'] === 'same',
            'reason'          => match ($uniform['status']) {
                'same'      => null,
                'different' => self::ERROR_DIFFERENT,
                default     => self::ERROR_NOT_AVAILABLE,
            },
            'provider'        => $uniform['provider'],
            'companies_count' => count($uniform['supplier_ids']),
            'companies'       => $uniform['status'] === 'same'
                ? array_map(static fn (int $id): string => $reachable[$id], $uniform['supplier_ids'])
                : [],
        ];

        $signing = $this->signingSupplierIds($request, $reachable);
        $certificates = [];
        foreach ($this->credentials->listValidOwnedWithSuppliers($userId) as $credential) {
            if ($signing === [] || array_diff($signing, $credential['supplier_ids']) !== []) {
                continue;
            }
            $certificates[] = [
                'id'              => $credential['id'],
                'label'           => $credential['label'],
                'subject'         => self::commonName($credential['subject_dn']),
                'valid_to'        => $credential['valid_to'],
                'companies_count' => count($signing),
            ];
        }

        return ['ai' => $ai, 'certificates' => $certificates];
    }

    /**
     * Převezme vybrané položky do nové firmy. Nikdy nevyhazuje: firma už vznikla
     * a chyba převzetí se jen ohlásí u položky.
     *
     * @param array{ai:bool, certificate_ids:list<int>, step_up:array<string,mixed>} $carryOver
     * @return array{
     *     ai?: array{ok:bool, provider?:string, error?:string},
     *     certificates: list<array{id:int, ok:bool, error?:string}>
     * }
     */
    public function apply(Request $request, int $newSupplierId, array $carryOver, ?string $ip, string $userAgent): array
    {
        $userId = $this->userId($request);
        $result = ['certificates' => []];
        $reachable = null;

        if ($carryOver['ai']) {
            try {
                $reachable = $this->reachableSuppliers($request, $userId, $newSupplierId);
                $result['ai'] = $this->applyAi($request, $newSupplierId, array_keys($reachable), $ip, $userAgent);
            } catch (\Throwable $e) {
                $this->log->warning('supplier-carry-over: převzetí AI selhalo', ['error' => $e::class]);
                $result['ai'] = ['ok' => false, 'error' => self::ERROR_FAILED];
            }
        }

        foreach ($carryOver['certificate_ids'] as $credentialId) {
            try {
                $reachable ??= $this->reachableSuppliers($request, $userId, $newSupplierId);
                $result['certificates'][] = ['id' => $credentialId]
                    + $this->applyCertificate($request, $userId, $credentialId, $newSupplierId, $reachable, $ip);
            } catch (\Throwable $e) {
                $this->log->warning('supplier-carry-over: převzetí certifikátu selhalo', ['error' => $e::class]);
                $result['certificates'][] = ['id' => $credentialId, 'ok' => false, 'error' => self::ERROR_FAILED];
            }
        }

        $this->logger->log(
            'supplier.carry_over',
            $userId,
            'supplier',
            $newSupplierId,
            $result,
            $ip,
            $userAgent,
            $newSupplierId,
        );

        return $result;
    }

    /**
     * @param list<int> $supplierIds
     * @return array{ok:bool, provider?:string, error?:string}
     */
    private function applyAi(Request $request, int $newSupplierId, array $supplierIds, ?string $ip, string $userAgent): array
    {
        $uniform = $this->ai->uniformConfiguration($supplierIds);
        if ($uniform['status'] !== 'same') {
            return [
                'ok'    => false,
                'error' => $uniform['status'] === 'different' ? self::ERROR_DIFFERENT : self::ERROR_NOT_AVAILABLE,
            ];
        }
        $provider = (string) $uniform['provider'];
        $status = $this->ai->applyToSupplier(
            $request,
            (int) $uniform['source_supplier_id'],
            $provider,
            $newSupplierId,
            $ip,
            $userAgent,
        );

        return match ($status) {
            AiCredentialsBulkApplier::STATUS_UPDATED      => ['ok' => true, 'provider' => $provider],
            AiCredentialsBulkApplier::STATUS_EU_RESIDENCY => ['ok' => false, 'error' => self::ERROR_EU_RESIDENCY],
            default                                       => ['ok' => false, 'error' => self::ERROR_FORBIDDEN],
        };
    }

    /**
     * @param array<int,string> $reachable
     * @return array{ok:bool, error?:string}
     */
    private function applyCertificate(
        Request $request,
        int $userId,
        int $credentialId,
        int $newSupplierId,
        array $reachable,
        ?string $ip,
    ): array {
        $credential = null;
        foreach ($this->credentials->listValidOwnedWithSuppliers($userId) as $row) {
            if ($row['id'] === $credentialId) {
                $credential = $row;
                break;
            }
        }
        if ($credential === null) {
            // Cizí, smazaný i prošlý certifikát se hlásí stejně: neplatný výběr.
            return ['ok' => false, 'error' => self::ERROR_NOT_OWNED];
        }
        $signing = $this->signingSupplierIds($request, $reachable);
        if ($signing === [] || array_diff($signing, $credential['supplier_ids']) !== []) {
            return ['ok' => false, 'error' => self::ERROR_NOT_SHARED_EVERYWHERE];
        }

        $status = $this->sharing->enableInSupplier($request, $credentialId, $userId, $newSupplierId, $ip);

        return match ($status) {
            CertificateVaultSupplierSharing::STATUS_ENABLED,
            CertificateVaultSupplierSharing::STATUS_ALREADY_ENABLED => ['ok' => true],
            null                                                    => ['ok' => false, 'error' => self::ERROR_NOT_OWNED],
            default                                                 => ['ok' => false, 'error' => self::ERROR_FORBIDDEN],
        };
    }

    /**
     * Firmy uživatele dosažitelné v tomhle kontextu (přepínač + resoluce přístupu).
     *
     * @return array<int,string> id => název
     */
    private function reachableSuppliers(Request $request, int $userId, int $excludeId): array
    {
        if ($userId <= 0) {
            return [];
        }
        $ids = RequestAuthorization::isSuperadmin($request)
            ? array_map('intval', $this->db->pdo()->query('SELECT id FROM supplier ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN))
            : $this->memberships->allowedSupplierIds($userId);
        $ids = array_values(array_filter($ids, fn (int $id): bool => $id !== $excludeId && $this->reachable($request, $id)));
        if ($ids === []) {
            return [];
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT id, COALESCE(NULLIF(display_name, \'\'), company_name) AS name
               FROM supplier WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id'
        );
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['id']] = (string) $row['name'];
        }

        return $out;
    }

    private function reachable(Request $request, int $supplierId): bool
    {
        $scoped = $request
            ->withHeader(SupplierScopeMiddleware::HEADER_NAME, (string) $supplierId)
            ->withQueryParams([]);
        $access = $this->supplierAccess->resolve($scoped);

        return !$access->denied && $access->supplierId === $supplierId;
    }

    /**
     * Firmy, kde uživatel smí spravovat podpisy (`settings.signing` zápis).
     *
     * @param array<int,string> $reachable
     * @return list<int>
     */
    private function signingSupplierIds(Request $request, array $reachable): array
    {
        return array_values(array_filter(
            array_keys($reachable),
            fn (int $id): bool => $this->sharing->permittedFor($request, $id) === true,
        ));
    }

    private static function commonName(string $subjectDn): string
    {
        return preg_match('/(?:^|[,\/])\s*CN\s*=\s*([^,\/]+)/i', $subjectDn, $m) === 1 ? trim($m[1]) : $subjectDn;
    }

    private function userId(Request $request): int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);

        return (int) ($user['id'] ?? 0);
    }
}
