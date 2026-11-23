<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

/**
 * Společné výchozí údaje přihlášky A1 pro vybrané vztahy najednou.
 *
 * Firma má obvykle jeden režim práce a několik pozic, které aplikace
 * v podmínkách vztahu nevede (A1-OST: režim práce, nepřetržitý provoz, obec
 * pracoviště, název pozice, vedoucí pozice). Hodnota se zapíše do profilu
 * A1 každého vybraného vztahu stejnou cestou jako formulář na kartě vztahu
 * ({@see PayrollRegistrationIdentityService::saveA1Profile()}), takže stav
 * profilu (ověřený, rozpracovaný) a vady vzniknou stejně. Vyplněné hodnoty
 * se bez volby `overwrite` nepřepisují a za vztah s odeslanou registrací se
 * profil nemění.
 */
final readonly class PayrollRegistrationA1DefaultsService
{
    private const MAX_BATCH = 200;

    public const FIELDS = ['work_mode_code', 'continuous_operation', 'workplace_city', 'position_name', 'leadership'];

    private const META = ['status', 'reference_hash', 'created_at', 'created', 'problems'];

    public function __construct(
        private PayrollRegistrationIdentityService $identities,
    ) {}

    /**
     * @param list<mixed> $employmentIds
     * @param array<string,mixed> $defaults
     * @return array{results:list<array<string,mixed>>,counts:array<string,int>}
     */
    public function apply(
        int $supplierId,
        array $employmentIds,
        array $defaults,
        bool $overwrite,
        bool $splitHouseNumber,
        ?int $userId,
    ): array {
        $ids = [];
        foreach ($employmentIds as $id) {
            if (!is_int($id) || $id <= 0) {
                throw new \InvalidArgumentException('Seznam pracovních vztahů obsahuje neplatné číslo.');
            }
            $ids[$id] = $id;
        }
        if ($ids === [] || count($ids) > self::MAX_BATCH) {
            throw new \InvalidArgumentException('Vyberte 1 až ' . self::MAX_BATCH . ' pracovních vztahů.');
        }
        $values = self::values($defaults);
        if ($values === [] && !$splitHouseNumber) {
            throw new \InvalidArgumentException('Vyplňte aspoň jeden údaj, který se má vybraným vztahům nastavit.');
        }
        $results = [];
        $counts = ['verified' => 0, 'draft' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($ids as $employmentId) {
            try {
                $result = $this->applyOne($supplierId, $employmentId, $values, $overwrite, $splitHouseNumber, $userId);
            } catch (\InvalidArgumentException | \DomainException | \OutOfBoundsException $exception) {
                $result = [
                    'employment_id' => $employmentId,
                    'status' => 'failed',
                    'changed' => [],
                    'message' => $exception->getMessage(),
                    'problems' => [],
                ];
            }
            ++$counts[$result['status']];
            $results[] = $result;
        }

        return ['results' => $results, 'counts' => $counts];
    }

    /**
     * @param array<string,string|bool> $values
     * @return array<string,mixed>
     */
    private function applyOne(
        int $supplierId,
        int $employmentId,
        array $values,
        bool $overwrite,
        bool $splitHouseNumber,
        ?int $userId,
    ): array {
        $view = $this->identities->a1ProfileView($supplierId, $employmentId);
        if (($view['draft']['submitted'] ?? false) === true) {
            return [
                'employment_id' => $employmentId,
                'status' => 'skipped',
                'changed' => [],
                'message' => 'Za vztah už odešla registrace, profil A1 se nemění. Změnu ohlaste změnovým podáním.',
                'problems' => [],
            ];
        }
        $profile = is_array($view['profile']) ? $view['profile'] : (array) ($view['draft']['suggested'] ?? []);
        foreach (self::META as $meta) {
            unset($profile[$meta]);
        }
        $employment = is_array($profile['employment'] ?? null) ? $profile['employment'] : [];
        $changed = [];
        foreach ($values as $field => $value) {
            if (!$overwrite && ($employment[$field] ?? null) !== null && ($employment[$field] ?? null) !== '') {
                continue;
            }
            if (($employment[$field] ?? null) !== $value) {
                $employment[$field] = $value;
                $changed[] = "employment.{$field}";
            }
        }
        $profile['employment'] = $employment;
        if ($splitHouseNumber && is_array($profile['permanent_address'] ?? null)) {
            $split = self::splitStreet($profile['permanent_address']);
            if ($split !== null) {
                $profile['permanent_address'] = $split;
                $changed[] = 'permanent_address.house_number';
            }
        }
        $profile['row_version'] = (int) ($view['draft']['row_version'] ?? 0);
        $profile['effective_on'] = (string) $view['draft']['effective_on'];
        $saved = $this->identities->saveA1Profile($supplierId, $employmentId, $profile, $userId);
        $status = ($saved['status'] ?? null) === 'verified' ? 'verified' : 'draft';

        return [
            'employment_id' => $employmentId,
            'status' => $status,
            'changed' => $changed,
            'message' => null,
            'problems' => $status === 'verified' ? [] : self::problems($saved['problems'] ?? []),
        ];
    }

    /**
     * @param array<string,mixed> $defaults
     * @return array<string,string|bool>
     */
    private static function values(array $defaults): array
    {
        $values = [];
        foreach ($defaults as $field => $value) {
            if (!in_array($field, self::FIELDS, true)) {
                throw new \InvalidArgumentException("Údaj „{$field}“ nejde hromadně nastavit.");
            }
            if ($value === null || $value === '') {
                continue;
            }
            if (in_array($field, ['continuous_operation', 'leadership'], true)) {
                if (!is_bool($value)) {
                    throw new \InvalidArgumentException('Nepřetržitý provoz a vedoucí pozice musí být ano, nebo ne.');
                }
                $values[$field] = $value;
                continue;
            }
            if (!is_string($value) || trim($value) === '') {
                throw new \InvalidArgumentException('Hodnota údaje přihlášky musí být text.');
            }
            $value = trim($value);
            if ($field === 'work_mode_code' && !in_array($value, PayrollRegistrationCodebooks::WORK_MODE, true)) {
                throw new \InvalidArgumentException('Režim práce musí být kód 1 až 4.');
            }
            $values[$field] = mb_substr($value, 0, 255);
        }

        return $values;
    }

    /**
     * Ulice zapsaná v evidenci i s číslem („Dlouhá 12/3") rozdělená na ulici,
     * číslo popisné a orientační. Jen když číslo popisné v profilu chybí.
     *
     * @param array<string,mixed> $address
     * @return array<string,mixed>|null
     */
    public static function splitStreet(array $address): ?array
    {
        $street = is_string($address['street'] ?? null) ? trim($address['street']) : '';
        if ($street === '' || ($address['house_number'] ?? null) !== null) {
            return null;
        }
        if (preg_match('/^(.*\S)\s+(\d{1,4})(?:\s*\/\s*(\d{1,4}[a-zA-Z]?))?$/uD', $street, $match) !== 1) {
            return null;
        }
        $address['street'] = $match[1];
        $address['house_number'] = $match[2];
        $address['orientation_number'] = ($match[3] ?? '') === '' ? ($address['orientation_number'] ?? null) : $match[3];

        return $address;
    }

    /**
     * @param mixed $problems
     * @return list<array{field:?string,code:string,message:string}>
     */
    private static function problems(mixed $problems): array
    {
        return array_values(array_map(static fn (array $problem): array => [
            'field' => isset($problem['field']) ? (string) $problem['field'] : null,
            'code' => (string) ($problem['code'] ?? 'validation_failed'),
            'message' => (string) ($problem['message'] ?? ''),
        ], array_filter(is_array($problems) ? $problems : [], 'is_array')));
    }
}
