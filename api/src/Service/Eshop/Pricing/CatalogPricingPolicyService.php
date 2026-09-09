<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Pricing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CatalogPricingExchangeRateRepository;
use MyInvoice\Repository\CatalogPricingProfileRepository;
use MyInvoice\Repository\CatalogPricingRuleRepository;
use MyInvoice\Repository\StockCurrencyRepository;
use MyInvoice\Service\Eshop\EshopException;

final class CatalogPricingPolicyService
{
    public function __construct(
        private readonly Connection $db,
        private readonly CatalogPricingProfileRepository $profiles,
        private readonly CatalogPricingRuleRepository $rules,
        private readonly CatalogPricingExchangeRateRepository $rates,
        private readonly StockCurrencyRepository $currencies,
        private readonly CatalogPriceJobService $jobs,
    ) {}

    public function profiles(int $supplierId): array
    {
        return $this->profiles->listForSupplier($supplierId);
    }

    public function saveProfile(int $supplierId, ?int $id, array $input): array
    {
        return $this->withPolicyLock($supplierId, fn (): array => $this->saveProfileLocked($supplierId, $id, $input));
    }

    private function saveProfileLocked(int $supplierId, ?int $id, array $input): array
    {
        $data = $this->profileData($supplierId, $input);
        $existing = $id === null ? null : $this->profiles->find($supplierId, $id);
        if ($id !== null && $existing === null) {
            throw new EshopException('not_found', 'Cenový profil nenalezen.', 404);
        }
        $sameCode = $this->profiles->findByCode($supplierId, $data['code']);
        if ($sameCode !== null && (int) $sameCode['id'] !== $id) {
            throw new EshopException('pricing_profile_code_taken', 'Kód cenového profilu už existuje.', 409);
        }

        return $this->transaction(function () use ($supplierId, $id, $existing, $data): array {
            if ($id === null) {
                $id = $this->profiles->insert($supplierId, $data);
                $changed = true;
            } else {
                $changed = !$this->same($existing, $data);
                if ($changed) {
                    $this->profiles->update($supplierId, $id, $data);
                }
            }
            $jobId = $changed ? $this->jobs->enqueue($supplierId) : 0;
            return ['profile' => $this->profiles->find($supplierId, $id), 'recompute_job_id' => $jobId ?: null];
        });
    }

    public function deleteProfile(int $supplierId, int $id): array
    {
        return $this->withPolicyLock($supplierId, fn (): array => $this->deleteProfileLocked($supplierId, $id));
    }

    private function deleteProfileLocked(int $supplierId, int $id): array
    {
        if ($this->profiles->find($supplierId, $id) === null) {
            throw new EshopException('not_found', 'Cenový profil nenalezen.', 404);
        }
        return $this->transaction(function () use ($supplierId, $id): array {
            $this->profiles->delete($supplierId, $id);
            $jobId = $this->jobs->enqueue($supplierId);
            return ['deleted' => true, 'recompute_job_id' => $jobId ?: null];
        });
    }

    public function rules(int $supplierId): array
    {
        return $this->rules->listForSupplier($supplierId);
    }

    public function saveRule(int $supplierId, ?int $id, array $input): array
    {
        return $this->withPolicyLock($supplierId, fn (): array => $this->saveRuleLocked($supplierId, $id, $input));
    }

    private function saveRuleLocked(int $supplierId, ?int $id, array $input): array
    {
        $data = $this->ruleData($supplierId, $input);
        $existing = $id === null ? null : $this->rules->find($supplierId, $id);
        if ($id !== null && $existing === null) {
            throw new EshopException('not_found', 'Cenové pravidlo nenalezeno.', 404);
        }
        return $this->transaction(function () use ($supplierId, $id, $existing, $data): array {
            if ($id === null) {
                $id = $this->rules->insert($supplierId, $data);
                $changed = true;
            } else {
                $changed = !$this->same($existing, $data);
                if ($changed) {
                    $this->rules->update($supplierId, $id, $data);
                }
            }
            $jobId = $changed ? $this->jobs->enqueue($supplierId) : 0;
            return ['rule' => $this->rules->find($supplierId, $id), 'recompute_job_id' => $jobId ?: null];
        });
    }

    public function deleteRule(int $supplierId, int $id): array
    {
        return $this->withPolicyLock($supplierId, fn (): array => $this->deleteRuleLocked($supplierId, $id));
    }

    private function deleteRuleLocked(int $supplierId, int $id): array
    {
        if ($this->rules->find($supplierId, $id) === null) {
            throw new EshopException('not_found', 'Cenové pravidlo nenalezeno.', 404);
        }
        return $this->transaction(function () use ($supplierId, $id): array {
            $this->rules->delete($supplierId, $id);
            $jobId = $this->jobs->enqueue($supplierId);
            return ['deleted' => true, 'recompute_job_id' => $jobId ?: null];
        });
    }

    public function exchangeRates(int $supplierId, int $limit = 500): array
    {
        return $this->rates->listForSupplier($supplierId, $limit);
    }

    public function saveExchangeRate(int $supplierId, array $input): array
    {
        return $this->withPolicyLock($supplierId, fn (): array => $this->saveExchangeRateLocked($supplierId, $input));
    }

    private function saveExchangeRateLocked(int $supplierId, array $input): array
    {
        $currency = strtoupper(trim($this->text($input['currency_code'] ?? '')));
        if (!in_array($currency, $this->currencies->codes($supplierId), true) || $currency === 'CZK') {
            throw new \InvalidArgumentException('Neplatná prodejní měna kurzu.');
        }
        $source = strtolower(trim($this->text($input['source'] ?? '')));
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,39}$/D', $source)) {
            throw new \InvalidArgumentException('Neplatný zdroj obchodního kurzu.');
        }
        $date = $this->text($input['rate_date'] ?? '');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Neplatné datum obchodního kurzu.');
        }
        $rate = $this->decimal($input['rate'] ?? null, 8, 6);
        if ($rate === null || bccomp($rate, '0', 6) <= 0) {
            throw new \InvalidArgumentException('Obchodní kurz musí být kladný.');
        }
        return $this->transaction(function () use ($supplierId, $currency, $date, $source, $rate): array {
            $changed = $this->rates->upsert($supplierId, $currency, $date, $source, $rate);
            $jobId = $changed && $date <= date('Y-m-d') ? $this->jobs->enqueue($supplierId) : 0;
            return [
                'exchange_rate' => $this->rates->latest($supplierId, $currency, $source, $date),
                'recompute_job_id' => $jobId ?: null,
            ];
        });
    }

    private function profileData(int $supplierId, array $input): array
    {
        $code = strtolower(trim($this->text($input['code'] ?? '')));
        $name = trim($this->text($input['name'] ?? ''));
        $currency = strtoupper(trim($this->text($input['currency_code'] ?? '')));
        $mode = $this->text($input['calculation_mode'] ?? '');
        $rounding = $this->text($input['rounding'] ?? 'none');
        $source = strtolower(trim($this->text($input['fx_source'] ?? 'cnb')));
        $maxAge = filter_var($input['max_rate_age_days'] ?? 7, FILTER_VALIDATE_INT);
        $percentage = $this->decimal($input['percentage'] ?? null, 4, 3);
        if (!preg_match('/^[a-z0-9][a-z0-9_.-]{0,49}$/D', $code)
            || $name === '' || mb_strlen($name) > 150
            || !in_array($currency, $this->currencies->codes($supplierId), true)
            || !in_array($mode, ['markup', 'target_margin'], true)
            || !in_array($rounding, ['none', '0.01', '0.10', '0.50', '1', '9_ending'], true)
            || !preg_match('/^[a-z][a-z0-9_.-]{0,39}$/D', $source)
            || $maxAge === false || $maxAge < 0 || $maxAge > 3650
            || $percentage === null) {
            throw new \InvalidArgumentException('Neplatný cenový profil.');
        }
        if (($mode === 'markup' && bccomp($percentage, '-100', 3) < 0)
            || ($mode === 'target_margin'
                && (bccomp($percentage, '0', 3) < 0 || bccomp($percentage, '100', 3) >= 0))) {
            throw new \InvalidArgumentException('Neplatné procento cenového profilu.');
        }
        return [
            'code' => $code,
            'name' => $name,
            'currency_code' => $currency,
            'calculation_mode' => $mode,
            'percentage' => $percentage,
            'rounding' => $rounding,
            'fx_source' => $source,
            'max_rate_age_days' => $maxAge,
            'is_active' => array_key_exists('is_active', $input)
                ? $this->boolean($input['is_active'])
                : true,
        ];
    }

    private function ruleData(int $supplierId, array $input): array
    {
        $profileId = filter_var($input['profile_id'] ?? null, FILTER_VALIDATE_INT);
        $type = $this->text($input['match_type'] ?? '');
        $rawMatchId = $input['match_id'] ?? null;
        $matchId = $type === 'default' ? null : filter_var($rawMatchId, FILTER_VALIDATE_INT);
        $priority = filter_var($input['priority'] ?? 0, FILTER_VALIDATE_INT);
        if ($profileId === false || $profileId <= 0
            || !in_array($type, ['product', 'category', 'manufacturer', 'vendor', 'default'], true)
            || ($type === 'default' && $rawMatchId !== null)
            || ($type !== 'default' && ($matchId === false || $matchId <= 0))
            || $priority === false || $priority < -1000000 || $priority > 1000000) {
            throw new \InvalidArgumentException('Neplatné cenové pravidlo.');
        }
        if ($this->profiles->find($supplierId, $profileId) === null) {
            throw new EshopException('not_found', 'Cenový profil nenalezen.', 404);
        }
        if ($type !== 'default' && !$this->matchExists($supplierId, $type, (int) $matchId)) {
            throw new EshopException('pricing_rule_reference_not_found', 'Cíl cenového pravidla nenalezen.', 404);
        }
        return [
            'profile_id' => $profileId,
            'match_type' => $type,
            'match_id' => $matchId,
            'priority' => $priority,
            'is_active' => array_key_exists('is_active', $input)
                ? $this->boolean($input['is_active'])
                : true,
        ];
    }

    private function matchExists(int $supplierId, string $type, int $id): bool
    {
        [$table, $extra] = match ($type) {
            'product' => ['stock_items', ''],
            'category' => ['stock_categories', ''],
            'manufacturer' => ['manufacturers', ''],
            'vendor' => ['clients', ' AND is_vendor = 1'],
            default => throw new \InvalidArgumentException('Neplatný typ cenového pravidla.'),
        };
        $stmt = $this->db->pdo()->prepare("SELECT EXISTS (SELECT 1 FROM {$table}
            WHERE supplier_id = ? AND id = ?{$extra})");
        $stmt->execute([$supplierId, $id]);
        return (bool) $stmt->fetchColumn();
    }

    private function transaction(callable $operation): array
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        }
        try {
            $result = $operation();
            if ($owns) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $error) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    private function withPolicyLock(int $supplierId, callable $operation): array
    {
        return $this->transaction(function () use ($supplierId, $operation): array {
            $lock = $this->db->pdo()->prepare('SELECT id FROM supplier WHERE id = ? FOR UPDATE');
            $lock->execute([$supplierId]);
            if ($lock->fetchColumn() === false) {
                throw new EshopException('not_found', 'Firma nenalezena.', 404);
            }
            return $operation();
        });
    }

    private function text(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Hodnota musí být text.');
        }
        return $value;
    }

    private function boolean(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new \InvalidArgumentException('Aktivita musí být boolean.');
        }
        return $value;
    }

    private function same(array $existing, array $data): bool
    {
        foreach ($data as $key => $value) {
            if ((string) $existing[$key] !== (string) $value) {
                return false;
            }
        }
        return true;
    }

    private function decimal(mixed $value, int $integerDigits, int $scale): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $value = str_replace(',', '.', trim((string) $value));
        if (!preg_match('/^-?\d+(?:\.(\d+))?$/D', $value, $matches)
            || strlen($matches[1] ?? '') > $scale) {
            return null;
        }
        $normalized = bcadd($value, '0', $scale);
        if (strlen(ltrim(explode('.', $normalized)[0], '-0')) > $integerDigits) {
            return null;
        }
        return $normalized;
    }
}
