<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Net;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollPayoutRuleRepository;
use PDO;

/**
 * Odvození výchozích výplatních pravidel ze způsobu výplaty na osobní kartě.
 *
 * `payroll_employee_profiles.payout_method` je od svého vzniku čistě deklarativní
 * — nikde se z něj nic neodvozovalo. Uživatel tedy vyplnil „bankou", vypadalo to
 * hotově, a mzda se stejně nedala vyplatit, protože rozhoduje sada v
 * `payroll_payout_rules`. Tahle služba ten rozpor uzavírá: z karty umí navrhnout
 * odpovídající pravidlo a na vyžádání ho i zapsat.
 *
 * DVĚ VĚCI, KTERÉ TAHLE TŘÍDA ZÁMĚRNĚ NEDĚLÁ:
 *
 * 1. NEHÁDÁ. Když z karty jednoznačný cíl neplyne (rozdělená výplata, žádný nebo
 *    víc ověřených účtů), vrátí srozumitelný důvod a nezapíše nic. Tichá volba
 *    „prvního účtu" by znamenala, že mzda odejde jinam, než účetní čekala.
 * 2. NEDĚLÁ FALLBACK PŘI VÝPOČTU. Návrh se vždy musí zhmotnit jako skutečný
 *    řádek pravidla, který se pak zmrazí do snapshotu revize. Kdyby se cíl
 *    dopočítával až v okamžiku výplaty z živého účtu, nebylo by zpětně
 *    dohledatelné, podle čeho se platilo — což MZ-17 výslovně zakazuje.
 */
final class PayrollPayoutRuleDefaultsService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollPayoutRuleRepository $repository,
        private readonly PayrollPayoutRuleService $rules,
    ) {}

    /**
     * Návrh výchozí sady pravidel pro osobu.
     *
     * @return array{
     *   payout_method:?string,
     *   available:bool,
     *   applicable:bool,
     *   has_active_rules:bool,
     *   blocked_reason:?string,
     *   rules:list<array<string,mixed>>
     * }
     */
    public function proposeFor(int $supplierId, int $employeeId): array
    {
        $this->repository->assertEmployee($supplierId, $employeeId);
        $profile = $this->profile($supplierId, $employeeId);
        $hasActiveRules = $this->hasActiveRules(
            $this->repository->listForEmployee($supplierId, $employeeId),
        );

        if ($profile === null) {
            return $this->blocked(
                null,
                $hasActiveRules,
                'Osoba nemá vyplněnou osobní kartu, takže způsob výplaty není známý.',
            );
        }
        $payoutMethod = (string) $profile['payout_method'];

        try {
            $input = $this->proposeInput($supplierId, $employeeId, $profile);
        } catch (\DomainException $exception) {
            return $this->blocked(
                $payoutMethod,
                $hasActiveRules,
                $exception->getMessage(),
            );
        }

        return [
            'payout_method' => $payoutMethod,
            'available' => true,
            'applicable' => !$hasActiveRules,
            'has_active_rules' => $hasActiveRules,
            'blocked_reason' => $hasActiveRules
                ? 'Zaměstnanec už má vlastní výplatní pravidla — výchozí sada '
                    . 'je nepřepisuje.'
                : null,
            'rules' => [[
                'destination_kind' => $input->destinationKind,
                'destination_reference' => $input->destinationReference,
                'allocation_kind' => $input->allocationKind,
                'amount_minor' => $input->amountMinor,
                'basis_points' => $input->basisPoints,
                'priority_no' => $input->priorityNo,
            ]],
        ];
    }

    /**
     * Zapíše návrh — ale jen když zaměstnanec nemá ŽÁDNÉ aktivní pravidlo.
     *
     * Ruční zadání má vždycky přednost: uživatel, který si rozdělil výplatu na
     * dva účty, nesmí o to rozdělení přijít jen proto, že někdo klikl na
     * „vytvořit výchozí pravidlo".
     *
     * @return list<array<string,mixed>>
     */
    public function applyDefaults(int $supplierId, int $employeeId): array
    {
        return $this->repository->transaction(function () use (
            $supplierId,
            $employeeId,
        ): array {
            $this->repository->assertEmployee($supplierId, $employeeId);
            $current = $this->repository->lockForEmployee($supplierId, $employeeId);
            if ($this->hasActiveRules($current)) {
                throw new \DomainException(
                    'Zaměstnanec už má aktivní výplatní pravidlo. Výchozí sada '
                    . 'se používá jen tam, kde není zadané nic.',
                );
            }
            $profile = $this->profile($supplierId, $employeeId);
            if ($profile === null) {
                throw new \DomainException(
                    'Osoba nemá vyplněnou osobní kartu, takže způsob výplaty '
                    . 'není známý.',
                );
            }
            $input = $this->proposeInput($supplierId, $employeeId, $profile);
            $this->rules->create($supplierId, $employeeId, $input);

            return $this->repository->listForEmployee($supplierId, $employeeId);
        });
    }

    public const BULK_LIMIT = 500;

    /**
     * @param list<int> $employeeIds
     * @return list<array{employee_id:int,full_name:string,payout_method:?string,applicable:bool,destination_kind:?string,blocked_reason:?string}>
     */
    public function previewMany(int $supplierId, array $employeeIds): array
    {
        $names = $this->names($supplierId, $employeeIds);
        $rows = [];
        foreach (array_keys($names) as $employeeId) {
            $proposal = $this->proposeFor($supplierId, $employeeId);
            $rows[] = [
                'employee_id' => $employeeId,
                'full_name' => $names[$employeeId],
                'payout_method' => $proposal['payout_method'],
                'applicable' => $proposal['available'] && $proposal['applicable'],
                'destination_kind' => $proposal['rules'][0]['destination_kind'] ?? null,
                'blocked_reason' => $proposal['blocked_reason'],
            ];
        }

        return $rows;
    }

    /**
     * @param list<int> $employeeIds
     * @return array{applied:list<array<string,mixed>>,skipped:list<array{employee_id:int,full_name:string,reason:string}>}
     */
    public function applyMany(int $supplierId, array $employeeIds): array
    {
        $names = $this->names($supplierId, $employeeIds);
        $applied = [];
        $skipped = [];
        foreach (array_keys($names) as $employeeId) {
            try {
                foreach ($this->applyDefaults($supplierId, $employeeId) as $rule) {
                    if ($rule['is_active'] === true) {
                        $applied[] = $rule;
                    }
                }
            } catch (\DomainException $exception) {
                $skipped[] = [
                    'employee_id' => $employeeId,
                    'full_name' => $names[$employeeId],
                    'reason' => $exception->getMessage(),
                ];
            }
        }

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    /**
     * @param list<int> $employeeIds
     * @return array<int,string>
     */
    private function names(int $supplierId, array $employeeIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $employeeIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($ids === []) {
            return [];
        }
        if (count($ids) > self::BULK_LIMIT) {
            throw new \InvalidArgumentException(sprintf('Najednou jde zpracovat nejvýš %d osob.', self::BULK_LIMIT));
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, full_name FROM payroll_employees
              WHERE supplier_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
              ORDER BY full_name, id'
        );
        $stmt->execute([$supplierId, ...$ids]);
        $names = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $names[(int) $row['id']] = (string) $row['full_name'];
        }

        return $names;
    }

    /**
     * @param array<string,mixed> $profile
     * @throws \DomainException když z karty jednoznačné pravidlo neplyne
     */
    private function proposeInput(
        int $supplierId,
        int $employeeId,
        array $profile,
    ): PayrollPayoutRuleInput {
        $payoutMethod = (string) $profile['payout_method'];

        return match ($payoutMethod) {
            'cash' => PayrollPayoutRuleInput::remainder('cash', null),
            'bank' => PayrollPayoutRuleInput::remainder(
                'bank',
                'account:' . $this->singleVerifiedAccountId(
                    $supplierId,
                    $employeeId,
                ),
            ),
            PayrollPartnerSettlement::KIND => PayrollPayoutRuleInput::remainder(
                PayrollPartnerSettlement::KIND,
                $this->settlementAccountCode($profile),
            ),
            'mixed' => throw new \DomainException(
                'Rozdělenou výplatu je nutné zadat ručně — z karty nelze poznat, '
                . 'kolik jde na účet a kolik v hotovosti.',
            ),
            default => throw new \DomainException(
                "Způsob výplaty {$payoutMethod} neumíme převést na výplatní pravidlo.",
            ),
        };
    }

    /**
     * Právě jeden ověřený a účinný výplatní účet — jinak blokující důvod.
     *
     * „Ověřený" znamená kompletní trojici zdroj/datum/uživatel, tedy přesně to,
     * co po zmrazeném účtu vyžaduje PayrollNetWageLiabilityMaterializer. Kdyby
     * se navrhl neověřený účet, pravidlo by vzniklo, ale výplata by o měsíc
     * později spadla na „Zmrazený účet nemá úplné ověření".
     */
    private function singleVerifiedAccountId(int $supplierId, int $employeeId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id
               FROM payroll_person_accounts
              WHERE supplier_id = ? AND employee_id = ?
                AND is_active = 1
                AND verification_source IS NOT NULL
                AND verified_on IS NOT NULL
                AND verified_by IS NOT NULL
                AND (effective_to IS NULL OR effective_to >= CURRENT_DATE)
              ORDER BY id'
        );
        $stmt->execute([$supplierId, $employeeId]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) === 1) {
            return (int) $ids[0];
        }
        if ($ids === []) {
            throw new \DomainException(
                'Zaměstnanec nemá ověřený výplatní účet. Zadejte účet na kartě '
                . 'osoby a ověřte ho — teprve pak z něj lze odvodit výplatní '
                . 'pravidlo.',
            );
        }

        throw new \DomainException(
            'Zaměstnanec má ' . count($ids) . ' ověřené výplatní účty. Výchozí '
            . 'pravidlo z nich nelze odvodit — vyberte cílový účet ručně.',
        );
    }

    /** @param array<string,mixed> $profile */
    private function settlementAccountCode(array $profile): string
    {
        $code = $profile['partner_settlement_account_code'] ?? null;
        if (!is_string($code) || trim($code) === '') {
            throw new \DomainException(
                'Karta osoby nemá vyplněný účet, proti kterému se čistá mzda '
                . 'započítává.',
            );
        }

        return $code;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function profile(int $supplierId, int $employeeId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT payout_method, partner_settlement_account_code
               FROM payroll_employee_profiles
              WHERE supplier_id = ? AND employee_id = ?'
        );
        $stmt->execute([$supplierId, $employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param list<array<string,mixed>> $rules */
    private function hasActiveRules(array $rules): bool
    {
        foreach ($rules as $rule) {
            if ($rule['is_active'] === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *   payout_method:?string,
     *   available:bool,
     *   applicable:bool,
     *   has_active_rules:bool,
     *   blocked_reason:?string,
     *   rules:list<array<string,mixed>>
     * }
     */
    private function blocked(
        ?string $payoutMethod,
        bool $hasActiveRules,
        string $reason,
    ): array {
        return [
            'payout_method' => $payoutMethod,
            'available' => false,
            'applicable' => false,
            'has_active_rules' => $hasActiveRules,
            'blocked_reason' => $reason,
            'rules' => [],
        ];
    }
}
