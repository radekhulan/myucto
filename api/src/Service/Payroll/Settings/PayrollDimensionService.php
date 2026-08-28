<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Settings;

use MyInvoice\Repository\Payroll\PayrollDimensionRepository;
use MyInvoice\Service\Payroll\Posting\PayrollPostingAccountPolicy;

final class PayrollDimensionService
{
    private const TYPES = ['cost_center', 'project', 'activity'];

    public function __construct(
        private readonly PayrollDimensionRepository $repository,
    ) {}

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function save(
        int $supplierId,
        ?int $id,
        array $input,
        int $expectedVersion,
        ?int $actorUserId,
    ): array {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException('Firma musí být určena.');
        }
        if ($expectedVersion < 0) {
            throw new \InvalidArgumentException('row_version nesmí být záporné.');
        }
        $data = $this->normalize($input);

        if ($id === null) {
            if ($expectedVersion !== 0) {
                throw new \InvalidArgumentException('Nová dimenze musí mít row_version 0.');
            }

            return $this->repository->create($supplierId, $data, $actorUserId);
        }
        if ($id <= 0 || $expectedVersion <= 0) {
            throw new \InvalidArgumentException('Upravovaná dimenze musí mít platné ID a row_version.');
        }

        return $this->repository->update($supplierId, $id, $data, $expectedVersion, $actorUserId)
            ?? throw new \RuntimeException('Mzdová dimenze nebyla nalezena.');
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function normalize(array $input): array
    {
        $type = $input['dimension_type'] ?? null;
        if (!is_string($type) || !in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Typ dimenze musí být cost_center, project nebo activity.');
        }

        $code = $input['code'] ?? null;
        if (!is_string($code)) {
            throw new \InvalidArgumentException('Kód dimenze musí být text.');
        }
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{0,49}$/', $code)) {
            throw new \InvalidArgumentException(
                'Kód dimenze musí mít 1–50 znaků: velká písmena, číslice, tečku, podtržítko nebo pomlčku.',
            );
        }
        // Prefixy MZ-SR- a MZ-EX- nese ve sloupci `cost_center` PSEUDONYM
        // oprávněného ze srážky, respektive z exekuce — saldokonto per
        // oprávněný datový model zatím nemá. Reálné středisko se stejným
        // prefixem by se v reconciliaci vydávalo za srážku a naopak.
        if (PayrollPostingAccountPolicy::isReservedDimensionCode($code)) {
            throw new \InvalidArgumentException(
                'Kódy začínající MZ-SR- a MZ-EX- jsou vyhrazené analytice srážek '
                . 'a exekucí ve mzdovém deníku. Zvolte prosím jiný kód dimenze.',
            );
        }

        $name = $input['name'] ?? null;
        if (!is_string($name)) {
            throw new \InvalidArgumentException('Název dimenze musí být text.');
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 190) {
            throw new \InvalidArgumentException('Název dimenze musí mít 1–190 znaků.');
        }

        $validFrom = $this->date($input['valid_from'] ?? null, 'valid_from');
        $validTo = $this->nullableDate($input['valid_to'] ?? null, 'valid_to');
        if ($validTo !== null && $validTo < $validFrom) {
            throw new \InvalidArgumentException('Konec platnosti nesmí předcházet začátku.');
        }

        $isActive = $input['is_active'] ?? null;
        if (!is_bool($isActive)) {
            throw new \InvalidArgumentException('Pole is_active musí být boolean.');
        }

        $rawAccount = $input['default_account_code'] ?? null;
        if ($rawAccount !== null && !is_string($rawAccount)) {
            throw new \InvalidArgumentException('Výchozí účet dimenze musí být text.');
        }
        $account = $rawAccount === null ? null : strtoupper(trim($rawAccount));
        if ($account === '') {
            $account = null;
        }
        if ($account !== null && !preg_match('/^[0-9]{3}[.A-Z0-9]{0,13}$/', $account)) {
            throw new \InvalidArgumentException(
                'Výchozí účet dimenze musí mít formát účtové osnovy (např. 518 nebo 518.001).',
            );
        }
        // Rezervované prefixy se dosud ověřovaly AŽ při zaúčtování
        // (PayrollDimensionCostAccountResolver), takže chybný účet dimenze
        // shodil APPROVE celého mzdového běhu — tedy dávno potom, co ho někdo
        // uložil. Táž kontrola patří k zadání, kde se dá bez následků opravit.
        if ($account !== null) {
            try {
                PayrollPostingAccountPolicy::assertGrossCostAccountIsUnambiguous(
                    $account,
                );
            } catch (\DomainException $exception) {
                throw new \InvalidArgumentException(
                    "Výchozí účet dimenze {$account} je vyhrazený jiné mzdové "
                    . 'kategorii (pojistné, daň, srážky, závazek mzdy nebo '
                    . 'zápočet). Zvolte nákladový účet hrubé mzdy, například 521.100.',
                    previous: $exception,
                );
            }
        }

        return [
            'dimension_type' => $type,
            'code' => $code,
            'name' => $name,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'is_active' => $isActive,
            'default_account_code' => $account,
        ];
    }

    private function nullableDate(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->date($value, $field);
    }

    private function date(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException("Pole {$field} musí být datum YYYY-MM-DD.");
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value
        ) {
            throw new \InvalidArgumentException("Pole {$field} musí být datum YYYY-MM-DD.");
        }

        return $value;
    }
}
