<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Service\Ares\AresClient;
use MyInvoice\Service\Ares\CrpDphClient;
use MyInvoice\Service\Supplier\SupplierCreator;
use MyInvoice\Service\Supplier\SupplierInitializer;
use PDO;

/**
 * Firma, do které dávkový převod převádí zálohu: existující podle IČO, nebo nová
 * založená stejnou cestou jako firma založená v aplikaci ({@see SupplierCreator},
 * {@see SupplierInitializer}) s identitou z {@see MigrationCompanyIdentity}.
 *
 * Existující firma se hledá jen mezi firmami, ke kterým má uživatel přístup: záloha
 * se nesmí vmíchat do účetnictví firmy, kterou účetní nespravuje, a druhá firma se
 * stejným IČO by zdvojila evidenci.
 */
final class MigrationCompanyResolver
{
    public const ACCESS_NONE = 'none';
    public const ACCESS_OK = 'ok';
    public const ACCESS_FORBIDDEN = 'forbidden';
    public const ACCESS_AMBIGUOUS = 'ambiguous';

    public function __construct(
        private readonly Connection $db,
        private readonly SupplierCreator $creator,
        private readonly SupplierInitializer $initializer,
        private readonly AresClient $ares,
        private readonly CrpDphClient $crpdph,
        private readonly AccountingSupplierSettingsRepository $settings,
        private readonly DimensionRepository $groups,
    ) {}

    /**
     * Firma s tímto IČO (bez ohledu na vodicí nuly).
     *
     * @param list<int>|null $allowedSupplierIds firmy, ke kterým má uživatel přístup (null = všechny)
     * @return array{access:string,supplier_id:?int}
     */
    public function findExisting(string $ico, ?array $allowedSupplierIds): array
    {
        $digits = ltrim(preg_replace('/\D/', '', $ico) ?? '', '0');
        if ($digits === '') {
            return ['access' => self::ACCESS_NONE, 'supplier_id' => null];
        }
        $stmt = $this->db->pdo()->prepare("SELECT id FROM supplier WHERE TRIM(LEADING '0' FROM REPLACE(ic, ' ', '')) = ? ORDER BY id");
        $stmt->execute([$digits]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if ($ids === []) {
            return ['access' => self::ACCESS_NONE, 'supplier_id' => null];
        }
        $visible = $allowedSupplierIds === null ? $ids : array_values(array_intersect($ids, $allowedSupplierIds));
        if ($visible === []) {
            return ['access' => self::ACCESS_FORBIDDEN, 'supplier_id' => null];
        }
        if (count($ids) > 1) {
            return ['access' => self::ACCESS_AMBIGUOUS, 'supplier_id' => null];
        }
        return ['access' => self::ACCESS_OK, 'supplier_id' => $visible[0]];
    }

    /**
     * Údaje z veřejných registrů: ARES podle IČO a plátcovství z registru plátců DPH
     * podle DIČ. Výpadek registru vrátí null, převod pokračuje s tím, co zná záloha.
     *
     * @return array{ares:?array<string,mixed>,vat_payer:?bool}
     */
    public function lookupRegistry(string $ico, string $dic): array
    {
        $ares = null;
        $vatPayer = null;
        try {
            $r = $this->ares->lookup($ico);
            if (($r['found'] ?? false) === true) {
                $ares = (array) ($r['data'] ?? []);
            }
        } catch (\Throwable) {
            // ARES je best-effort, identitu doplní záloha a podané přiznání
        }
        $dic = $dic !== '' ? $dic : (string) ($ares['dic'] ?? '');
        if ($dic !== '') {
            try {
                $c = $this->crpdph->lookup($dic);
                if (($c['source'] ?? 'error') !== 'error') {
                    $vatPayer = (bool) ($c['found'] ?? false);
                }
            } catch (\Throwable) {
                // registr plátců nedostupný
            }
        }
        if ($vatPayer === null && $ares !== null && array_key_exists('is_vat_payer', $ares)) {
            $vatPayer = (bool) $ares['is_vat_payer'];
        }
        return ['ares' => $ares, 'vat_payer' => $vatPayer];
    }

    /**
     * Založí firmu podle identity. Převzaté údaje z podaného přiznání (NACE, kategorie
     * účetní jednotky, audit) se zapíšou dřív, než firmu doplní registry — ty doplňují
     * jen prázdná pole.
     *
     * @param bool $useRegistry obohatit firmu z ARES a registru plátců (síť)
     * @throws \MyInvoice\Service\License\LicenseCompanyLimitExceeded
     */
    public function create(MigrationCompanyIdentity $identity, int $userId, bool $assignCreator, bool $useRegistry, bool $nonprofit = false): int
    {
        $input = $identity->supplierInput();
        $supplierId = $this->creator->create($input, $userId, $assignCreator, $nonprofit);

        if ($identity->nace !== null) {
            $this->db->pdo()->prepare("UPDATE supplier SET cz_nace_code = ? WHERE id = ? AND (cz_nace_code IS NULL OR cz_nace_code = '')")
                ->execute([$identity->nace, $supplierId]);
        }
        if ($identity->category !== null || $identity->audit !== null) {
            $current = $this->settings->get($supplierId);
            $this->settings->upsert(
                $supplierId,
                isset($current['avg_employees']) ? (int) $current['avg_employees'] : null,
                $identity->category ?? ($current['statement_scope_override'] ?? null),
                $identity->audit,
            );
        }

        if ($useRegistry) {
            $this->initializer->completeAfterCommit($supplierId, $input, $userId > 0 ? $userId : null);
        } else {
            $this->initializer->alignAccountingModeWithLegalForm($supplierId, $input);
            $this->initializer->finalizeProfile($supplierId, $userId > 0 ? $userId : null);
        }
        return $supplierId;
    }

    /**
     * Skupina firem dávky: existující (id) nebo nová (název). Null = firmy se do skupiny
     * nezařazují.
     */
    public function ensureGroup(?int $groupId, ?string $groupName): ?int
    {
        if ($groupId !== null && $groupId > 0) {
            return $this->groups->findGroup($groupId) !== null ? $groupId : null;
        }
        $name = trim((string) $groupName);
        return $name !== '' ? $this->groups->createGroup(mb_substr($name, 0, 190)) : null;
    }

    /**
     * Zařadí firmu do skupiny. Musí proběhnout PŘED převodem: dimenze globálních typů
     * (projekt společný pro skupinu) převod zakládá podle skupiny firmy.
     */
    public function assignGroup(int $supplierId, int $groupId): void
    {
        $this->groups->setSupplierGroup($supplierId, $groupId);
    }

    /** @return list<string> IČO firem skupiny */
    public function groupIcos(int $groupId): array
    {
        return array_values(array_filter(array_map(
            static fn (array $m): string => (string) ($m['ic'] ?? ''),
            $this->groups->groupMembers($groupId),
        )));
    }
}
