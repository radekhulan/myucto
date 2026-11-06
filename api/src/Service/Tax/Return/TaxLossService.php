<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;

/**
 * Evidence daňových ztrát §34 ZDP (Fáze E, audit 2026-07) — FO i PO.
 *
 * Ztráta vzniká automaticky při finalizaci přiznání se záporným základem (tax_losses)
 * a v dalších letech se uplatňuje (tax_loss_applications, FIFO od nejstarší). Zbývající
 * zůstatek každé ztráty = amount − Σ applications. Uplatnit lze v 5 zdaňovacích obdobích
 * bezprostředně následujících po období vzniku (origin_year+1 … origin_year+5).
 *
 * Uplatnění je vázané na rok uplatnění (applied_year), takže re-finalizace téhož roku je
 * idempotentní (nejprve se smažou dosavadní uplatnění daného roku, pak zapíšou znovu).
 *
 * ── Zpětné uplatnění (§ 34 odst. 1 ve znění novely 299/2020) ────────────────────────
 * Od roku 2020 lze ztrátu odečíst i ve 2 obdobích bezprostředně PŘEDCHÁZEJÍCÍCH období
 * jejího vzniku, nejvýše však v souhrnné výši 30 000 000 Kč. Systém to neuměl vůbec:
 * okno bylo natvrdo `origin_year <= year - 1`, takže poplatník, kterému vznikla ztráta,
 * neměl jak snížit daň už zaplacenou za předchozí roky — a šlo o peníze navíc, které
 * systém neuměl ani navrhnout.
 *
 * Zpětné uplatnění se v evidenci liší jen tím, že `applied_year < origin_year`; mechanika
 * (FIFO, idempotence per rok, zákaz snížení ztráty pod uplatněné) zůstává stejná. Navenek
 * se provede DODATEČNÝM přiznáním za dřívější rok, což systém už umí.
 *
 * Pořadí FIFO (origin_year ASC) přitom dává správnou přednost: dopředu přenášené starší
 * ztráty se spotřebují dřív než ztráta budoucí, protože ta má vyšší `origin_year`. Zpětné
 * uplatnění je navíc PRÁVO, ne povinnost — systém ho nabídne, ale sám nikdy nezaúčtuje
 * dodatečné přiznání za uzavřený rok.
 */
final class TaxLossService
{
    public function __construct(private readonly Connection $db, private readonly TaxConstantsRepository $constants) {}

    /**
     * Karta „Daňové ztráty" pro FE: seznam ztrát se zůstatkem a expirací + FIFO návrh
     * uplatnění pro daný rok.
     *
     * @return array{
     *   losses: list<array{origin_year:int,amount:float,applied:float,remaining:float,expires_year:int,applicable:bool}>,
     *   available_total: float, suggested: float, year: int, carry_years: int
     * }
     */
    public function card(int $supplierId, int $year, string $type): array
    {
        $rows = $this->rows($supplierId, $type);
        $c = $this->constants->forYear($year);
        $carryYears = (int) ($c['tax_loss_carry_years'] ?? 5);
        $carrybackYears = (int) ($c['tax_loss_carryback_years'] ?? 0);
        $carrybackLimit = (float) ($c['tax_loss_carryback_limit'] ?? 0);

        $losses = [];
        $available = 0.0;
        $carrybackAvailable = 0.0;
        foreach ($rows as $r) {
            $remaining = round((float) $r['amount'] - (float) $r['applied'], 2);
            $originYear = (int) $r['origin_year'];
            $eligible = TaxLossAllocationPlan::eligibleOrigin($originYear, $year, $carryYears, $carrybackYears);
            $forward = $remaining > 0 && $originYear < $year && $eligible;

            // Zpětné uplatnění: ztráta vznikla POZDĚJI než rok, do kterého se odečítá,
            // nejvýš však o `carrybackYears` období — a jen do zbývajícího stropu 30 mil.
            $carrybackRoom = $carrybackYears > 0
                ? round(max(0.0, $carrybackLimit - $this->carriedBackTotal($supplierId, $type, $originYear)), 2)
                : 0.0;
            $backward = $remaining > 0 && $carrybackYears > 0
                && $originYear > $year && $eligible
                && $carrybackRoom > 0;

            if ($forward) {
                $available = round($available + $remaining, 2);
            }
            if ($backward) {
                $carrybackAvailable = round($carrybackAvailable + min($remaining, $carrybackRoom), 2);
            }

            $losses[] = [
                'origin_year' => $originYear,
                'amount' => round((float) $r['amount'], 2),
                'applied' => round((float) $r['applied'], 2),
                'remaining' => $remaining,
                'expires_year' => $originYear + $carryYears,
                'applicable' => $forward,
                'carryback_applicable' => $backward,
                'carryback_room' => $carrybackRoom,
            ];
        }

        return [
            'losses' => $losses,
            'available_total' => $available,
            // Návrh drží jen DOPŘEDNÉ uplatnění. Zpětné je právo vázané na podání
            // dodatečného přiznání za uzavřený rok — přednabídnout ho jako samozřejmost
            // by uživatele tlačilo k úkonu, který musí zvážit sám.
            'suggested' => $available,
            'carryback_available_total' => $carrybackAvailable,
            'year' => $year,
            'carry_years' => $carryYears,
            'carryback_years' => $carrybackYears,
            'carryback_limit' => $carrybackLimit,
        ];
    }

    public function dppoAppendixCard(int $supplierId, int $year, array $period, float $requested, float $yearLoss): array
    {
        $constants=$this->constants->forExactYear($year,true);$carryYears=(int)($constants['tax_loss_carry_years']??0);$carrybackYears=(int)($constants['tax_loss_carryback_years']??0);$carrybackLimit=(float)($constants['tax_loss_carryback_limit']??0);
        $q=$this->db->pdo()->prepare('SELECT tl.id,tl.origin_year,tl.origin_starts_on,tl.origin_ends_on,tl.period_proof_kind,tl.period_proof_hash,tl.amount,tl.source_return_id,
                COALESCE(SUM(CASE WHEN a.applied_year<? THEN a.amount ELSE 0 END),0) AS applied_before_period,
                COALESCE(SUM(CASE WHEN a.applied_year<>? THEN a.amount ELSE 0 END),0) AS applied,
                COALESCE(SUM(CASE WHEN a.applied_year<tl.origin_year AND a.applied_year<>? THEN a.amount ELSE 0 END),0) AS carried_back
            FROM tax_losses tl LEFT JOIN tax_loss_applications a ON a.loss_id=tl.id
            WHERE tl.supplier_id=? AND tl.taxpayer_type=? AND tl.origin_year>=? AND tl.origin_year<=? AND tl.origin_year<>?
            GROUP BY tl.id,tl.origin_year,tl.origin_starts_on,tl.origin_ends_on,tl.period_proof_kind,tl.period_proof_hash,tl.amount,tl.source_return_id
            ORDER BY tl.origin_year,tl.id');
        $q->execute([$year,$year,$year,$supplierId,'po',$year-$carryYears,$year+$carrybackYears,$year]);
        return DppoLossAppendix::build($supplierId,'po',$year,$period,$q->fetchAll(\PDO::FETCH_ASSOC),$requested,$yearLoss,$carryYears,$carrybackYears,$carrybackLimit);
    }

    /** Dosud zpětně uplatněná část ztráty daného roku (applied_year < origin_year). */
    private function carriedBackTotal(int $supplierId, string $type, int $originYear): float
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(a.amount), 0)
               FROM tax_losses l
               JOIN tax_loss_applications a ON a.loss_id = l.id
              WHERE l.supplier_id = ? AND l.taxpayer_type = ? AND l.origin_year = ?
                AND a.applied_year < l.origin_year'
        );
        $stmt->execute([$supplierId, $type, $originYear]);

        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Rekonciliace evidence ztrát při finalizaci přiznání (idempotentně dle roku):
     *  1) zruší dosavadní uplatnění tohoto roku (re-finalizace),
     *  2) zaeviduje/zruší ztrátu vzniklou v tomto roce (yearLoss),
     *  3) FIFO uplatní appliedLoss proti nejstarším uplatnitelným ztrátám.
     */
    public function reconcileFinalize(int $supplierId, string $type, int $year, float $yearLoss, float $appliedLoss, ?int $returnId): void
    {
        (new \MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $type, $year, $yearLoss, $appliedLoss, $returnId): void {
            $this->lockNativeContext($supplierId, $type);
            $this->reconcileFinalizeInTransaction($supplierId, $type, $year, $yearLoss, $appliedLoss, $returnId);
        });
    }

    private function reconcileFinalizeInTransaction(int $supplierId, string $type, int $year, float $yearLoss, float $appliedLoss, ?int $returnId): void
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            // (1) zrušit dosavadní uplatnění tohoto roku
            $pdo->prepare('DELETE FROM tax_loss_applications WHERE supplier_id = ? AND taxpayer_type = ? AND applied_year = ?')
                ->execute([$supplierId, $type, $year]);

            $this->assertLossAmountCanBeSet($supplierId, $type, $year, $yearLoss);

            // (2) ztráta vzniklá v tomto roce
            if ($yearLoss > 0) {
                $pdo->prepare(
                    'INSERT INTO tax_losses (supplier_id, taxpayer_type, origin_year, amount, source_return_id)
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE amount = VALUES(amount), source_return_id = VALUES(source_return_id)'
                )->execute([$supplierId, $type, $year, round($yearLoss, 2), $returnId]);
            } else {
                // Rok už ztrátu netvoří — smaž auto-založený záznam, pokud ho nikdo neuplatnil.
                $pdo->prepare(
                    'DELETE FROM tax_losses
                      WHERE supplier_id = ? AND taxpayer_type = ? AND origin_year = ?
                        AND NOT EXISTS (SELECT 1 FROM tax_loss_applications a WHERE a.loss_id = tax_losses.id)'
                )->execute([$supplierId, $type, $year]);
            }

            // (3) FIFO uplatnění ztráty minulých let
            if ($appliedLoss > 0) {
                $this->consumeFifo($supplierId, $type, $year, round($appliedLoss, 2), $returnId);
            }

            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Ztráta roku, za který přiznání v systému není (vznikla před převzetím účetnictví):
     * jen výše vzniklé ztráty, bez zásahu do uplatnění. Uplatnění v dalších letech
     * zapíše {@see reconcileFinalize()} přiznání roku uplatnění.
     * Toto rozhraní eviduje kalendářní originYear; hospodářské ani zkrácené období nepopisuje.
     */
    public function registerOpeningLoss(int $supplierId, string $type, int $originYear, float $amount): void
    {
        (new \MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $type, $originYear, $amount): void {
            $this->lockNativeContext($supplierId, $type);
            $amount = round(max(0.0, $amount), 2);
            $this->assertLossAmountCanBeSet($supplierId, $type, $originYear, $amount);
            $this->db->pdo()->prepare(
                'INSERT INTO tax_losses (supplier_id, taxpayer_type, origin_year, amount, source_return_id)
                 VALUES (?, ?, ?, ?, NULL)
                 ON DUPLICATE KEY UPDATE amount = VALUES(amount)'
            )->execute([$supplierId, $type, $originYear, $amount]);
        });
    }

    public function assertLossAmountCanBeSet(int $supplierId, string $type, int $year, float $yearLoss): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.amount
               FROM tax_losses l
               JOIN tax_loss_applications a ON a.loss_id = l.id
              WHERE l.supplier_id = ? AND l.taxpayer_type = ? AND l.origin_year = ?' . ($this->db->pdo()->inTransaction() ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId, $type, $year]);
        $usedCents = '0';
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $amount) $usedCents = bcadd($usedCents, TaxLossAllocationPlan::cents($amount), 0);
        $alreadyUsed = (float) TaxLossAllocationPlan::amount($usedCents);
        if ($alreadyUsed > round($yearLoss, 2)) {
            throw new \DomainException(
                'Daňovou ztrátu roku ' . $year . ' nelze snížit na ' . number_format($yearLoss, 2, ',', ' ')
                . ' Kč; v pozdějších přiznáních je již uplatněno ' . number_format($alreadyUsed, 2, ',', ' ')
                . ' Kč. Nejprve znovu otevřete a opravte tato přiznání.'
            );
        }
    }

    /**
     * Uvolní vazby evidence při vrácení přiznání do draftu (reopen): zruší uplatnění
     * provedená v daném roce. Ztrátu vzniklou v roce ponecháváme (živý ledger; při
     * re-finalizaci se přepíše nebo smaže dle nového výsledku).
     */
    public function releaseReturn(int $supplierId, string $type, int $year): void
    {
        (new \MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $type, $year): void {
            $this->lockNativeContext($supplierId, $type);
            $this->db->pdo()
                ->prepare('DELETE FROM tax_loss_applications WHERE supplier_id = ? AND taxpayer_type = ? AND applied_year = ?')
                ->execute([$supplierId, $type, $year]);
        });
    }

    /**
     * FIFO spotřeba uplatňované ztráty. Okno zahrnuje jak DOPŘEDU přenášené ztráty
     * (origin_year < year), tak ZPĚTNÉ uplatnění ztráty vzniklé až po tomto roce
     * (§ 34 odst. 1, novela 299/2020) — u té se navíc hlídá souhrnný strop 30 mil. Kč.
     *
     * Řazení podle origin_year ASC dává správnou přednost: starší dopředu přenášené
     * ztráty se spotřebují dřív než ztráta budoucí, protože ta má vyšší origin_year.
     */
    private function consumeFifo(int $supplierId, string $type, int $year, float $toApply, ?int $returnId): void
    {
        $c = $this->constants->forYear($year);
        $carryYears = (int) ($c['tax_loss_carry_years'] ?? 5);
        $carrybackYears = (int) ($c['tax_loss_carryback_years'] ?? 0);
        $carrybackLimit = (float) ($c['tax_loss_carryback_limit'] ?? 0);
        $pdo = $this->db->pdo();
        // FOR UPDATE (nález N4): zamkne řádky tax_losses po dobu transakce, aby souběžná
        // finalizace jiného přiznání téhož poplatníka nepočítala "remaining" ze zastaralého
        // stavu a neuplatnila tutéž ztrátu dvakrát (race condition).
        $stmt = $pdo->prepare(
            'SELECT tl.id, tl.origin_year, tl.amount,
                    COALESCE((SELECT SUM(a.amount) FROM tax_loss_applications a WHERE a.loss_id = tl.id), 0) AS applied,
                    COALESCE((SELECT SUM(a.amount) FROM tax_loss_applications a
                               WHERE a.loss_id = tl.id AND a.applied_year < tl.origin_year), 0) AS carried_back
               FROM tax_losses tl
              WHERE tl.supplier_id = ? AND tl.taxpayer_type = ?
                AND tl.origin_year >= ? AND tl.origin_year <= ?
                AND tl.origin_year <> ?
              ORDER BY tl.origin_year ASC, tl.id ASC
                FOR UPDATE'
        );
        $stmt->execute([$supplierId, $type, $year - $carryYears, $year + $carrybackYears, $year]);

        $plan = TaxLossAllocationPlan::build($stmt->fetchAll(\PDO::FETCH_ASSOC), $year, $toApply, $carryYears, $carrybackYears, $carrybackLimit);
        $insert = $pdo->prepare(
            'INSERT INTO tax_loss_applications (supplier_id, taxpayer_type, loss_id, applied_year, applied_return_id, amount)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($plan['chunks'] as $chunk) {
            $insert->execute([$supplierId, $type, $chunk['loss_id'], $year, $returnId, TaxLossAllocationPlan::amount($chunk['amount_cents'])]);
        }
    }

    public function lockNativeContext(int $supplierId, string $type): void
    {
        if (!$this->db->pdo()->inTransaction()) throw new \LogicException('Loss allocation requires a caller transaction.');
        if (!in_array($type, ['fo', 'po'], true)) throw new \InvalidArgumentException('Invalid taxpayer type.');
        (new \MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
        foreach ([
            ['SELECT id FROM supplier WHERE id=? FOR UPDATE', [$supplierId]],
            ['SELECT id FROM income_tax_returns WHERE supplier_id=? AND taxpayer_type=? ORDER BY year,id FOR UPDATE', [$supplierId, $type]],
            ['SELECT id FROM tax_losses WHERE supplier_id=? AND taxpayer_type=? ORDER BY origin_year,id FOR UPDATE', [$supplierId, $type]],
            ['SELECT id FROM tax_loss_applications WHERE supplier_id=? AND taxpayer_type=? ORDER BY id FOR UPDATE', [$supplierId, $type]],
        ] as [$sql, $args]) {
            $q = $this->db->pdo()->prepare($sql); $q->execute($args); $q->fetchAll();
        }
    }

    public function allocationPlan(int $supplierId, string $type, int $year, float $requested, ?int $previousOwnerId, ?int $nextOwnerId): array
    {
        $this->lockNativeContext($supplierId, $type);
        $pdo = $this->db->pdo();
        $q = $pdo->prepare('SELECT id,year,status,inputs,computed FROM income_tax_returns WHERE supplier_id=? AND taxpayer_type=? ORDER BY year,id FOR UPDATE');
        $q->execute([$supplierId, $type]); $owners = array_column($q->fetchAll(\PDO::FETCH_ASSOC), null, 'id');
        $effective = (new \MyInvoice\Repository\TaxReturnRepository($this->db))->findLastFinalized($supplierId, $year, $type, null, true);
        if (($effective === null ? null : (int) $effective['id']) !== $previousOwnerId) $this->ownerChanged();
        if ($nextOwnerId !== null && (!isset($owners[$nextOwnerId]) || (int) $owners[$nextOwnerId]['year'] !== $year)) $this->ownerChanged();
        $q = $pdo->prepare('SELECT id,origin_year,origin_starts_on,origin_ends_on,period_proof_kind,period_proof_hash,amount,source_return_id FROM tax_losses WHERE supplier_id=? AND taxpayer_type=? ORDER BY origin_year,id FOR UPDATE');
        $q->execute([$supplierId, $type]); $losses = $q->fetchAll(\PDO::FETCH_ASSOC);
        $q = $pdo->prepare('SELECT a.* FROM tax_loss_applications a JOIN tax_losses l ON l.id=a.loss_id WHERE (l.supplier_id=? AND l.taxpayer_type=?) OR (a.supplier_id=? AND a.taxpayer_type=?) ORDER BY a.id FOR UPDATE');
        $q->execute([$supplierId, $type, $supplierId, $type]); $applications = $q->fetchAll(\PDO::FETCH_ASSOC);
        $lossById = array_column($losses, null, 'id'); $release = [];
        foreach ($applications as $app) {
            if ((int) $app['supplier_id'] !== $supplierId || $app['taxpayer_type'] !== $type || !isset($lossById[$app['loss_id']]) || bccomp(TaxLossAllocationPlan::cents($app['amount']), '0', 0) <= 0) $this->ownerChanged();
            if ((int) $app['applied_year'] !== $year) continue;
            $ownerId = $app['applied_return_id'] === null ? null : (int) $app['applied_return_id'];
            $imported = $previousOwnerId === null && $ownerId === $nextOwnerId && $ownerId !== null && $this->importedOwner($owners[$ownerId], $year);
            if ($ownerId === null || ($ownerId !== $previousOwnerId && !$imported)) $this->ownerChanged();
            $release[] = (int) $app['id'];
        }
        foreach ($losses as &$loss) {
            $applied = '0'; $back = '0';
            foreach ($applications as $app) if ((int) $app['loss_id'] === (int) $loss['id'] && !in_array((int) $app['id'], $release, true)) {
                $applied = bcadd($applied, TaxLossAllocationPlan::cents($app['amount']), 0);
                if ((int) $app['applied_year'] < (int) $loss['origin_year']) $back = bcadd($back, TaxLossAllocationPlan::cents($app['amount']), 0);
            }
            $loss['applied'] = TaxLossAllocationPlan::amount($applied); $loss['carried_back'] = TaxLossAllocationPlan::amount($back);
            if (bccomp(TaxLossAllocationPlan::cents($loss['amount']), $applied, 0) < 0) $this->ownerChanged();
        }
        unset($loss);
        $constants = []; $periods = [];
        if (bccomp(TaxLossAllocationPlan::cents($requested), '0', 0) > 0) {
            try { $constants = $this->constants->forExactYear($year, true); }
            catch (\OutOfRangeException $e) { throw new TaxReturnException('missing_tax_constants', $e->getMessage(), 422); }
            foreach (['tax_loss_carry_years', 'tax_loss_carryback_years', 'tax_loss_carryback_limit'] as $key) if (!isset($constants[$key]) || !is_numeric($constants[$key]) || (float) $constants[$key] < 0) throw new TaxReturnException('missing_tax_constants', 'Pro přesnou alokaci ztráty chybějí ověřené konstanty.', 422);
            $q = $pdo->prepare('SELECT id,fiscal_year,starts_on,ends_on FROM accounting_periods WHERE supplier_id=? ORDER BY starts_on,id FOR UPDATE');
            $q->execute([$supplierId]); $periods = $q->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($losses as $loss) {
                $origin = (int) $loss['origin_year'];
                if (!TaxLossAllocationPlan::eligibleOrigin($origin, $year, (int) $constants['tax_loss_carry_years'], (int) $constants['tax_loss_carryback_years']) || bccomp(TaxLossAllocationPlan::cents($loss['amount']), TaxLossAllocationPlan::cents($loss['applied']), 0) <= 0) continue;
                if ($origin > $year && sprintf('%04d-12-31', $origin) < '2020-06-30') $this->unsupportedPeriod();
                $this->assertCalendarChain($type, $year, $loss, $owners, $periods);
            }
        }
        $plan = TaxLossAllocationPlan::build($losses, $year, $requested, (int) ($constants['tax_loss_carry_years'] ?? 0), (int) ($constants['tax_loss_carryback_years'] ?? 0), (float) ($constants['tax_loss_carryback_limit'] ?? 0));
        return $plan + ['supplier_id' => $supplierId, 'taxpayer_type' => $type, 'year' => $year, 'previous_owner_id' => $previousOwnerId, 'next_owner_id' => $nextOwnerId, 'release_ids' => $release,
            'proof' => ['losses' => $losses, 'applications' => $applications, 'constants' => $constants, 'periods' => $periods]];
    }

    public function currentAllocationPlan(int $supplierId, string $type, int $year, float $requested, ?int $ownerId): array
    {
        $plan = $this->allocationPlan($supplierId, $type, $year, $requested, $ownerId, $ownerId);
        $this->assertExact($plan); $chunks = []; $total = '0'; $constants = $plan['proof']['constants'];
        $byLoss = array_column($plan['proof']['losses'], null, 'id');
        foreach ($plan['proof']['applications'] as $app) if (in_array((int) $app['id'], $plan['release_ids'], true)) {
            $id = (int) $app['loss_id']; $amount = TaxLossAllocationPlan::cents($app['amount']);
            $chunks[$id] ??= ['loss_id' => $id, 'origin_year' => (int) $byLoss[$id]['origin_year'], 'amount_cents' => '0'];
            $chunks[$id]['amount_cents'] = bcadd($chunks[$id]['amount_cents'], $amount, 0); $total = bcadd($total, $amount, 0);
        }
        if ($total !== $plan['requested_cents']) $this->ownerChanged();
        foreach ($chunks as $chunk) {
            $single = TaxLossAllocationPlan::build([$byLoss[$chunk['loss_id']]], $year, (float) TaxLossAllocationPlan::amount($chunk['amount_cents']), (int) $constants['tax_loss_carry_years'], (int) $constants['tax_loss_carryback_years'], (float) $constants['tax_loss_carryback_limit']);
            $this->assertExact($single);
        }
        $plan['chunks'] = array_values($chunks); return $plan;
    }

    public function reconcileExact(array $plan, float $yearLoss, ?array $originPeriod = null): void
    {
        (new \MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard($this->db))->transaction(function () use ($plan, $yearLoss, $originPeriod): void {
            $actual = $this->allocationPlan($plan['supplier_id'], $plan['taxpayer_type'], $plan['year'], (float) TaxLossAllocationPlan::amount($plan['requested_cents']), $plan['previous_owner_id'], $plan['next_owner_id']);
            if ($actual !== $plan) $this->ownerChanged();
            $this->assertExact($plan);
            foreach ($plan['proof']['losses'] as $loss) if ((int) $loss['origin_year'] === $plan['year'] && $loss['source_return_id'] !== null && (int) $loss['source_return_id'] !== $plan['previous_owner_id']) {
                $q = $this->db->pdo()->prepare('SELECT id,year,status,inputs,computed FROM income_tax_returns WHERE supplier_id=? AND taxpayer_type=? AND id=? FOR UPDATE');
                $q->execute([$plan['supplier_id'], $plan['taxpayer_type'], $loss['source_return_id']]); $owner = $q->fetch(\PDO::FETCH_ASSOC);
                if ($plan['previous_owner_id'] !== null || (int) $loss['source_return_id'] !== $plan['next_owner_id'] || !$owner || !$this->importedOwner($owner, $plan['year'])) $this->ownerChanged();
            }
            $pdo = $this->db->pdo();
            foreach ($plan['release_ids'] as $id) {
                $q = $pdo->prepare('DELETE FROM tax_loss_applications WHERE supplier_id=? AND taxpayer_type=? AND id=?');
                $q->execute([$plan['supplier_id'], $plan['taxpayer_type'], $id]); if ($q->rowCount() !== 1) $this->ownerChanged();
            }
            $this->assertLossAmountCanBeSet($plan['supplier_id'], $plan['taxpayer_type'], $plan['year'], $yearLoss);
            if ($yearLoss > 0) {
                $proof=$originPeriod===null?null:TaxLossPeriodProof::native($plan['supplier_id'],$plan['taxpayer_type'],$plan['year'],$originPeriod,$yearLoss,$plan['next_owner_id']);
                $pdo->prepare('INSERT INTO tax_losses(supplier_id,taxpayer_type,origin_year,origin_starts_on,origin_ends_on,period_proof_kind,period_proof_hash,amount,source_return_id)VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE origin_starts_on=VALUES(origin_starts_on),origin_ends_on=VALUES(origin_ends_on),period_proof_kind=VALUES(period_proof_kind),period_proof_hash=VALUES(period_proof_hash),amount=VALUES(amount),source_return_id=VALUES(source_return_id)')
                    ->execute([$plan['supplier_id'],$plan['taxpayer_type'],$plan['year'],$proof['starts_on']??null,$proof['ends_on']??null,$proof['kind']??null,$proof['proof_hash']??null,round($yearLoss,2),$plan['next_owner_id']]);
            } else {
                $pdo->prepare('DELETE FROM tax_losses WHERE supplier_id=? AND taxpayer_type=? AND origin_year=? AND NOT EXISTS(SELECT 1 FROM tax_loss_applications a WHERE a.loss_id=tax_losses.id)')
                    ->execute([$plan['supplier_id'], $plan['taxpayer_type'], $plan['year']]);
            }
            $insert = $pdo->prepare('INSERT INTO tax_loss_applications(supplier_id,taxpayer_type,loss_id,applied_year,applied_return_id,amount)VALUES(?,?,?,?,?,?)');
            foreach ($plan['chunks'] as $chunk) $insert->execute([$plan['supplier_id'], $plan['taxpayer_type'], $chunk['loss_id'], $plan['year'], $plan['next_owner_id'], TaxLossAllocationPlan::amount($chunk['amount_cents'])]);
            $this->assertPersistedPlan($plan);
        });
    }

    public function assertExact(array $plan): void
    {
        if (($plan['unallocated_cents'] ?? null) !== '0' || ($plan['allocated_cents'] ?? null) !== ($plan['requested_cents'] ?? null)) throw new TaxReturnException('loss_allocation_insufficient', 'Vypočtený odpočet ztráty nelze celý přiřadit k evidovaným ztrátám. Opravte výši odpočtu nebo doplňte evidenci.', 409);
    }

    public function assertPersistedPlan(array $plan): void
    {
        if (!$this->db->pdo()->inTransaction()) throw new \LogicException('Loss allocation requires a caller transaction.');
        $q = $this->db->pdo()->prepare('SELECT loss_id,amount,applied_return_id FROM tax_loss_applications WHERE supplier_id=? AND taxpayer_type=? AND applied_year=? ORDER BY loss_id,id FOR UPDATE');
        $q->execute([$plan['supplier_id'], $plan['taxpayer_type'], $plan['year']]); $actual = [];
        foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ($row['applied_return_id'] === null || (int) $row['applied_return_id'] !== $plan['next_owner_id']) $this->ownerChanged();
            $actual[] = ['loss_id' => (int) $row['loss_id'], 'amount_cents' => TaxLossAllocationPlan::cents($row['amount'])];
        }
        $expected = array_map(static fn (array $chunk): array => array_intersect_key($chunk, array_flip(['loss_id', 'amount_cents'])), $plan['chunks']);
        usort($expected, static fn (array $a, array $b): int => $a['loss_id'] <=> $b['loss_id']);
        if ($actual !== $expected) $this->ownerChanged();
    }

    private function assertCalendarChain(string $type, int $year, array $loss, array $owners, array $periods): void
    {
        $origin = (int) $loss['origin_year'];
        $source = $loss['source_return_id'] === null ? null : ($owners[(int) $loss['source_return_id']] ?? null);
        if ($loss['source_return_id'] !== null && ($source === null || (int) $source['year'] !== $origin || ($source['status'] !== 'final' && !$this->importedOwner($source, $origin)))) $this->ownerChanged();
        if ($type === 'fo') return;
        if ($source !== null) {
            if ($source['status'] !== 'final') $this->unsupportedPeriod();
            $snapshot = json_decode((string) ($source['computed'] ?? ''), true);
            $period = $snapshot['podklady']['period'] ?? null;
            if (!is_array($period) || ($period['starts_on'] ?? null) !== sprintf('%04d-01-01', $origin) || ($period['ends_on'] ?? null) !== sprintf('%04d-12-31', $origin)) $this->unsupportedPeriod();
        }
        for ($item = min($origin, $year); $item <= max($origin, $year); ++$item) {
            $matches = array_values(array_filter($periods, static fn (array $p): bool => (int) $p['fiscal_year'] === $item || ($p['starts_on'] <= sprintf('%04d-12-31', $item) && $p['ends_on'] >= sprintf('%04d-01-01', $item))));
            // Opening-loss API records a calendar origin_year; it cannot describe a fiscal or shortened period.
            if ($item === $origin && $source === null && $matches === []) continue;
            if (count($matches) !== 1 || $matches[0]['starts_on'] !== sprintf('%04d-01-01', $item) || $matches[0]['ends_on'] !== sprintf('%04d-12-31', $item)) $this->unsupportedPeriod();
        }
    }

    private function importedOwner(array $owner, int $year): bool
    {
        $input = json_decode((string) $owner['inputs'], true, flags: JSON_THROW_ON_ERROR);
        $source = $input['filed_source'] ?? [];
        return is_string($source['period_to'] ?? null) && str_starts_with($source['period_to'], sprintf('%04d-', $year)) && preg_match('/^[a-f0-9]{40}$/D', (string) ($source['file_sha1'] ?? '')) === 1;
    }

    private function unsupportedPeriod(): never
    {
        throw new TaxReturnException('loss_allocation_period_unsupported', 'Přesná alokace ztráty vyžaduje doložený řetězec kalendářních zdaňovacích období. Hospodářská, zkrácená nebo neúplná období tato evidence zatím nerozlišuje.', 422);
    }

    private function ownerChanged(): never
    {
        throw new TaxReturnException('loss_allocation_owner_unverified', 'Vlastník nebo stav uplatnění daňové ztráty není průkazný. Evidence se nezměnila.', 409);
    }

    /** @return list<array{origin_year:int,amount:float,applied:float}> */
    private function rows(int $supplierId, string $type): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT tl.origin_year, tl.amount,
                    COALESCE((SELECT SUM(a.amount) FROM tax_loss_applications a WHERE a.loss_id = tl.id), 0) AS applied
               FROM tax_losses tl
              WHERE tl.supplier_id = ? AND tl.taxpayer_type = ?
              ORDER BY tl.origin_year ASC'
        );
        $stmt->execute([$supplierId, $type]);
        /** @var list<array{origin_year:int,amount:float,applied:float}> */
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
