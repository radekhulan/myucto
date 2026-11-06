<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Payments;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Payments\BankProposalRepository;
use MyInvoice\Repository\Property\Payments\DebtRepository;
use MyInvoice\Repository\Property\PropertyProfileRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\PropertyInstant;
use MyInvoice\Service\Property\Payments\Workflow\PaymentWorkflowFacade;

/**
 * Návrhy úhrad jednotek SVJ z bankovních pohybů.
 *
 * Import výpisu jen rozpozná jednotku a uloží návrh do fronty; nic se neúčtuje ani
 * nepotvrzuje. Plán úhrady vzniká až na vyžádání stejným náhledem
 * {@see PaymentWorkflowFacade::preview()} jako ruční úhrada a potvrzuje se stejnou
 * cestou (náhled + token), takže pro návrh neplatí žádné vlastní účetní pravidlo.
 */
final class BankProposalService
{
    public function __construct(
        private readonly Connection $db,
        private readonly UnitPaymentResolver $resolver,
        private readonly BankProposalRepository $proposals,
        private readonly PaymentWorkflowFacade $workflow,
    ) {}

    /**
     * SQL výraz „firma má někdy zapnutý profil SVJ" pro připojení k dotazu, který import
     * stejně spouští. Přesná účinnost profilu k datu platby se ověřuje až v {@see afterImport()}.
     */
    public static function profileFlagSql(string $supplierColumn): string
    {
        return "EXISTS (SELECT 1 FROM property_module_profiles pmp WHERE pmp.supplier_id = {$supplierColumn} AND pmp.enabled = 1)";
    }

    /**
     * Háček importu výpisu. Firma bez profilu SVJ skončí po jediném čtení profilu,
     * bez dalšího dotazu a bez zápisu.
     *
     * @param list<int> $transactionIds
     */
    public function afterImport(?int $supplier, array $transactionIds): int
    {
        if ($supplier === null || $supplier <= 0 || $transactionIds === []) return 0;
        $profiles = (new PropertyProfileRepository($this->db))->history($supplier);
        if (!in_array(true, array_column($profiles, 'enabled'), true)) return 0;
        $transactions = array_values(array_filter($this->proposals->freeIncoming($supplier, $transactionIds),
            static fn (array $tx): bool => self::profileEnabled($profiles, UnitPaymentResolver::paymentInstant((string) $tx['posted_at']))));
        if ($transactions === []) return 0;
        $resolutions = array_values($this->resolver->resolveMany($supplier, $transactions));
        $this->proposals->save($supplier, $resolutions);
        return count($resolutions);
    }

    /**
     * Návrh pro jeden pohyb, s volitelnou ruční volbou jednotky, dlužníka a plátce.
     * Vrací rozpoznání, požadavek úhrady a jeho náhled; když náhled vyžaduje rozhodnutí,
     * vrátí místo náhledu důvod (návrh se pak doplní ručně, nikdy se nevybírá potichu).
     */
    public function plan(int $supplier, int $transactionId, array $overrides = []): array
    {
        PropertyInput::fields($overrides, ['unit_id', 'debtor_id', 'payer_id']);
        foreach ($overrides as $field => $value) PropertyInput::id($value, $field);
        $links = $this->proposals->links($supplier, [$transactionId]);
        if (isset($links[$transactionId]['source'])) {
            return ['status' => 'used', 'bank_transaction_id' => $transactionId, 'link' => $links[$transactionId]];
        }
        $tx = $this->proposals->freeIncoming($supplier, [$transactionId])[0] ?? null;
        if ($tx === null) PaymentException::reject('bank_transaction_unavailable', ['bank_transaction_id' => $transactionId]);
        $resolution = $this->resolver->resolveMany($supplier, [$tx])[$transactionId];
        $resolution = $this->applyOverrides($supplier, $tx, $resolution, $overrides);
        $result = ['status' => 'proposal', 'bank_transaction_id' => $transactionId, 'resolution' => $resolution, 'request' => null, 'preview' => null, 'review' => null];
        if ($resolution['unit_id'] === null) return array_replace($result, ['review' => ['code' => 'unit_required']]);
        if ($resolution['debtor_id'] === null || $resolution['payer_id'] === null) return array_replace($result, ['review' => ['code' => 'debtor_required']]);

        $amount = bcmul((string) $tx['amount'], '100', 0);
        $debts = $this->openDebts($supplier, $resolution['debtor_id']);
        if ($debts === []) return array_replace($result, ['review' => ['code' => 'no_open_debts', 'amount_cents' => $amount]]);
        $request = self::request($tx, $resolution, $debts, $amount);
        try {
            $preview = $this->workflow->preview($supplier, $request);
        } catch (PaymentException $e) {
            $allocated = $e->details['allocated_cents'] ?? null;
            if (($e->details['reason'] ?? null) !== 'credit_entitlement_required' || !is_string($allocated) || bccomp($allocated, '0', 0) <= 0) {
                return array_replace($result, ['request' => $request, 'review' => ['code' => $e->errorCode, 'details' => $e->details]]);
            }
            // Přeplatek nad otevřené dluhy zůstane na pohybu nepřiřazený; o kreditu
            // a jeho oprávněných osobách rozhoduje účetní samostatnou částí úhrady.
            $request = self::request($tx, $resolution, $debts, $allocated);
            try {
                $preview = $this->workflow->preview($supplier, $request);
            } catch (PaymentException $retry) {
                return array_replace($result, ['request' => $request, 'review' => ['code' => $retry->errorCode, 'details' => $retry->details]]);
            }
            $result['remaining_cents'] = bcsub($amount, $allocated, 0);
        } catch (\MyInvoice\Service\Property\PropertyException $e) {
            return array_replace($result, ['request' => $request, 'review' => ['code' => $e->errorCode, 'details' => $e->details]]);
        }
        return array_replace($result, ['request' => $request, 'preview' => $preview]);
    }

    private function applyOverrides(int $supplier, array $tx, array $resolution, array $overrides): array
    {
        if ($overrides === []) return $resolution;
        if (isset($overrides['unit_id']) && $overrides['unit_id'] !== $resolution['unit_id']) {
            $unit = $this->resolver->describeUnit($supplier, $overrides['unit_id'], $resolution['received_at']);
            $resolution['unit_id'] = $unit['unit_id'];
            $resolution['debtor_id'] = count($unit['debtor_ids']) === 1 ? $unit['debtor_ids'][0] : null;
            $resolution['payer_id'] = $resolution['debtor_id'];
            $resolution['candidates'] = [$unit, ...array_values(array_filter($resolution['candidates'], static fn (array $c): bool => $c['unit_id'] !== $unit['unit_id']))];
            $resolution['reasons'] = array_values(array_unique([...$resolution['reasons'], 'unit_chosen_manually']));
        }
        $candidate = null;
        foreach ($resolution['candidates'] as $item) if ($item['unit_id'] === $resolution['unit_id']) $candidate = $item;
        if (isset($overrides['debtor_id'])) {
            if ($candidate === null || !in_array($overrides['debtor_id'], $candidate['debtor_ids'], true)) PaymentException::reject('debtor_not_owner', ['debtor_id' => $overrides['debtor_id']]);
            $resolution['debtor_id'] = $overrides['debtor_id'];
            $resolution['payer_id'] ??= $overrides['debtor_id'];
        }
        if (isset($overrides['payer_id'])) $resolution['payer_id'] = $overrides['payer_id'];
        return $resolution;
    }

    /**
     * Aktivní dluhy dlužníka s otevřenou částkou. Rozhodující výpočet kapacity dělá
     * {@see ClaimAllocationPlanner}; tady se jen nevkládají do návrhu zcela uhrazené dluhy.
     * Dluh, který tu chybí a plánovač ho potřebuje, vrátí náhled jako `debt_inventory_incomplete`.
     *
     * @return list<int>
     */
    private function openDebts(int $supplier, int $debtor): array
    {
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplier, $debtor): array {
            $out = [];
            foreach ((new DebtRepository($this->db))->allCurrent($supplier) as $debt) {
                if (!$debt['active']) continue;
                $liable = false;
                foreach ($debt['snapshot']['liability_groups'] as $group) {
                    if ($group['role'] === 'debtor' && in_array($debtor, $group['members'], true)) $liable = true;
                }
                if ($liable && bccomp(self::openCents($debt), '0', 0) > 0) $out[] = $debt['id'];
            }
            sort($out, SORT_NUMERIC);
            return $out;
        });
    }

    private static function openCents(array $debt): string
    {
        $open = '0';
        foreach ($debt['snapshot']['lines'] as $line) {
            $effective = bcsub($line['line']['amount_cents'], $debt['reductions'][$line['id']] ?? '0', 0);
            $open = bcadd($open, bcsub($effective, $debt['paid']['principal:' . $line['id']] ?? '0', 0), 0);
        }
        foreach ($debt['accessories'] as $accessory) {
            $open = bcadd($open, bcsub($accessory['amount_cents'], $debt['paid']['accessory:' . $accessory['id']] ?? '0', 0), 0);
        }
        return $open;
    }

    private static function request(array $tx, array $resolution, array $debts, string $amount): array
    {
        $unitNo = '';
        foreach ($resolution['candidates'] as $candidate) if ($candidate['unit_id'] === $resolution['unit_id']) $unitNo = $candidate['unit_no'];
        $date = substr((string) $tx['posted_at'], 0, 10);
        $vs = trim((string) ($tx['variable_symbol'] ?? ''));
        $reason = trim('Úhrada jednotky ' . $unitNo . ($vs !== '' ? ', VS ' . $vs : ''));
        $total = bcmul((string) $tx['amount'], '100', 0);
        return [
            'source' => ['kind' => 'bank', 'id' => (int) $tx['id']],
            'clearing_cells' => [['key' => 'unresolved', 'amount_cents' => $total, 'dimensions' => []]],
            'parts' => [['key' => 'bank-proposal.' . (int) $tx['id'], 'request' => [
                'kind' => 'allocation', 'amount_cents' => $amount, 'accounting_date' => $date, 'reason' => $reason, 'debts' => $debts,
                'payment' => ['payer_id' => $resolution['payer_id'], 'debtor_id' => $resolution['debtor_id'], 'received_at' => $resolution['received_at']],
            ]]],
        ];
    }

    /** Zrcadlí {@see PropertyProfileRepository::at()} nad jednou načtenou historií, aby dávka nečetla profil pro každý pohyb. */
    private static function profileEnabled(array $profiles, string $at): bool
    {
        foreach ($profiles as $profile) {
            if (PropertyInstant::contains($profile['effective_from'], $profile['effective_to'], $at)) return $profile['enabled'];
        }
        return false;
    }
}
