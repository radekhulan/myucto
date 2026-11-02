<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\JournalSettlementRepository;
use MyInvoice\Repository\SaldoRepository;
use MyInvoice\Service\Accounting\Reports\SaldoService;

/**
 * Vyrovnání položky saldokonta zápisem v deníku.
 *
 * Saldokonto porovnává otevřené doklady se zůstatkem účtu v hlavní knize. Po převodu
 * z jiného programu i u ručních zápisů zůstávají otevřené doklady, které hlavní kniha
 * má vyrovnané interním dokladem (zápočet přes 395, interní doklad vyrovnávající víc
 * faktur, odpis), a zůstatky bez dokladu (záloha, přeplatek). Prostý příznak „vyřešeno"
 * by konfrontaci s hlavní knihou rozbil, proto je vyrovnání vždy navázané na řádek
 * zápisu v deníku:
 *
 *   - rozpad řádku na doklady (`settlement`): část řádku vyrovnává doklad, saldokonto
 *     i uzávěrková kontrola ji počítají k datu zápisu ({@see \MyInvoice\Support\Sql\JournalSettlementAllocationSql}),
 *   - ostatní pohledávka / závazek ze zůstatku (`origin`): část řádku je předpisem
 *     nové položky, která se znovu neúčtuje ({@see OtherItemService::createFromJournalLine}).
 *
 * Rozepsat lze jen řádek ručního zápisu v Kč na saldokontním účtu, ne předpis dokladu,
 * storno ani zápis, který doklad vyrovnává už starou vazbou bez částky. Rozpad se vejde
 * do volné částky řádku i do zbytku dokladu, který se počítá stejně jako saldokonto.
 * Doklady v cizí měně se nerozepisují: jejich vyrovnání nese kurzová evidence.
 */
final class JournalSettlementService
{
    /** Saldokontní účty dokladů, jejichž vyrovnání jde rozepsat (zálohové účty mají vlastní čerpání). */
    public const SETTLE_ACCOUNTS = ['311', '321', '315', '325'];
    /** Účty návrhu párování: rozpad zápisu a čerpání záloh konečnou fakturou ({@see AdvanceDeductionService}). */
    public const SUGGEST_ACCOUNTS = ['311', '321', '315', '325', '314', '324'];
    /** Strop řádků rozpadu v jedné skupině (jedna transakce, zámky řádků i dokladů). */
    public const MAX_ALLOCATIONS = 50;
    /** Strop skupin v jednom hromadném potvrzení návrhů; UI posílá po dávkách. */
    public const MAX_GROUPS = 100;
    public const MAX_CANDIDATES = 50;
    public const MAX_RESIDUAL_LINES = 100;
    public const MAX_SUGGESTIONS = 500;
    /** Strop řádků, které návrh párování projde na jednom účtu (paměť requestu). */
    private const SUGGESTION_LINE_CAP = 20000;
    private const TOLERANCE = 0.005;

    public function __construct(
        private readonly Connection $db,
        private readonly JournalSettlementRepository $repo,
        private readonly SaldoRepository $saldo,
        private readonly SaldoService $saldoService,
        private readonly OtherItemService $otherItems,
        private readonly AdvanceDeductionService $advances,
    ) {}

    /** Strana řádku, která saldo dokladu snižuje (shodně s LinkedManualSettlementSql). */
    public static function settleSide(string $docType): string
    {
        return match ($docType) {
            'invoice' => 'credit',
            'purchase_invoice' => 'debit',
            default => throw new JournalSettlementException('invalid_doc_type', 'Neplatný druh dokladu.'),
        };
    }

    /**
     * Kandidáti pro otevřenou položku: volné řádky ručních zápisů na tomtéž saldokontním
     * účtu (vč. analytik) do rozvahového dne, seřazené podle shody částky, čísla dokladu,
     * partnera a data.
     *
     * @return array<string,mixed>
     */
    public function candidates(int $supplierId, string $docType, int $docId, string $accountCode, string $asOf): array
    {
        $side = self::settleSide($docType);
        $doc = $this->repo->document($supplierId, $docType, $docId)
            ?? throw new JournalSettlementException('not_found', 'Doklad nenalezen.', 404);
        $account = $this->settleAccount($supplierId, $accountCode);
        $open = $this->remaining($supplierId, $doc, $account, $asOf);
        $remaining = $open ?? 0.0;
        $supported = $doc['currency_code'] === 'CZK' && $open !== null;
        $lines = $supported
            ? $this->repo->freeLines(
                $supplierId,
                $this->repo->accountFamily($supplierId, $account['id']),
                $side,
                $asOf,
                $docType,
                self::MAX_CANDIDATES,
                [...$doc, 'remaining' => $remaining],
            )
            : [];
        return [
            'document' => [
                'doc_type'      => $docType,
                'doc_id'        => $docId,
                'doc_no'        => $doc['doc_no'],
                'partner_id'    => $doc['partner_id'],
                'partner_name'  => $doc['partner_name'],
                'currency_code' => $doc['currency_code'],
                'remaining'     => $remaining,
                'account'       => ['id' => $account['id'], 'code' => $account['code'], 'name' => $account['name']],
                'side'          => $side,
                'supported'     => $supported,
            ],
            'allocations' => $this->repo->listForDocument($supplierId, $docType, $docId),
            'items'       => $lines,
        ];
    }

    /**
     * Řádky bez dokladu na saldokontním účtu, ze kterých jde založit ostatní pohledávku
     * nebo závazek (obě strany účtu).
     *
     * @return list<array<string,mixed>>
     */
    public function residualLines(int $supplierId, string $accountCode, string $asOf, ?string $search = null): array
    {
        if (!in_array($accountCode, SaldoService::DEFAULT_ACCOUNTS, true)) {
            throw new JournalSettlementException('account_not_saldo', 'Účet není saldokontní.');
        }
        $account = $this->saldo->resolveAccount($supplierId, $accountCode)
            ?? throw new JournalSettlementException('account_not_found', 'Účet v osnově firmy neexistuje.', 404);
        return $this->repo->freeLines(
            $supplierId,
            $this->repo->accountFamily($supplierId, $account['id']),
            null,
            $asOf,
            null,
            self::MAX_RESIDUAL_LINES,
            null,
            $search,
        );
    }

    /**
     * Zápisy rozpadu, které leží v uzavřeném období (rozpad mění saldokonto k jeho konci).
     *
     * @param list<int> $lineIds
     * @return list<array{entry_id:int, period_status:string}>
     */
    public function closedPeriodEntries(int $supplierId, array $lineIds): array
    {
        $out = [];
        foreach ($this->repo->lines($supplierId, $lineIds) as $line) {
            if ($line['period_status'] !== null && AccountingPeriodStatus::isClosed($line['period_status'])) {
                $out[$line['entry_id']] = ['entry_id' => $line['entry_id'], 'period_status' => $line['period_status']];
            }
        }
        return array_values($out);
    }

    /** Stav uzavřeného období zápisu rozpadu, nebo null, když je období otevřené. */
    public function closedPeriodOfAllocation(int $supplierId, int $allocationId): ?string
    {
        $allocation = $this->repo->find($supplierId, $allocationId);
        if ($allocation === null) {
            return null;
        }
        $status = $this->repo->entryPeriodStatus($supplierId, $allocation['entry_id']);
        return $status !== null && AccountingPeriodStatus::isClosed($status) ? $status : null;
    }

    /**
     * Rozepíše řádky zápisů na doklady. Všechno, nebo nic: nejdřív se ověří celý požadavek
     * (volná částka každého řádku, zbytek každého dokladu), pak se zapisuje.
     *
     * @param list<mixed> $input [{line_id, doc_type, doc_id, amount}]
     * @return list<array<string,mixed>> založené rozpady
     */
    public function allocate(int $supplierId, array $input, ?int $userId): array
    {
        $rows = $this->normalizeAllocations($input);
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        // READ COMMITTED: zbytek dokladu i volná částka řádku se čtou až po zámcích a každé
        // čtení vidí poslední potvrzený stav. Pod REPEATABLE READ by snapshot z prvního čtení
        // nepoznal rozpad, který souběžný požadavek potvrdil, zatímco tenhle čekal na zámek
        // dokladu, a doklad by se přealokoval (vzor IssueInvoiceAction).
        if ($ownTx) $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        if ($ownTx) $pdo->beginTransaction();
        try {
            $docKeys = [];
            foreach ($rows as $r) {
                $docKeys[$r['doc_type'] . ':' . $r['doc_id']] = [$r['doc_type'], $r['doc_id']];
            }
            ksort($docKeys);
            $this->repo->lockForAllocation($supplierId, array_column($rows, 'line_id'), array_values($docKeys));
            $lines = $this->repo->lines($supplierId, array_column($rows, 'line_id'));
            $docs = [];
            foreach ($docKeys as $key => [$type, $id]) {
                $doc = $this->repo->document($supplierId, $type, $id)
                    ?? throw new JournalSettlementException('not_found', 'Doklad nenalezen.', 404);
                if ($doc['currency_code'] !== 'CZK') {
                    throw new JournalSettlementException('document_currency', 'Doklad v cizí měně vyrovnejte kurzovým zápisem, rozpad zápisu nese jen Kč.');
                }
                if (in_array($doc['status'], ['draft', 'cancelled'], true)) {
                    throw new JournalSettlementException('document_not_open', 'Doklad ' . $doc['doc_no'] . ' není v saldokontu otevřený.', 409);
                }
                $docs[$key] = $doc;
            }

            $lineTotals = [];
            $docTotals = [];
            $docAccounts = [];
            $docDates = [];
            foreach ($rows as $r) {
                $line = $lines[$r['line_id']] ?? throw new JournalSettlementException('line_not_found', 'Řádek zápisu nenalezen.', 404);
                $account = $this->assertLine($supplierId, $line, self::settleSide($r['doc_type']), self::SETTLE_ACCOUNTS);
                $key = $r['doc_type'] . ':' . $r['doc_id'];
                $lineTotals[$r['line_id']] = ($lineTotals[$r['line_id']] ?? 0.0) + $r['amount'];
                $docTotals[$key] = ($docTotals[$key] ?? 0.0) + $r['amount'];
                $docAccounts[$key][$account['id']] = $account;
                $docDates[$key] = max($docDates[$key] ?? '', $line['entry_date']);
                if ($this->repo->hasCountedLink($supplierId, $line['entry_id'], $r['doc_type'])) {
                    throw new JournalSettlementException('entry_linked',
                        'Zápis ' . ($line['document_no'] ?? '#' . $line['entry_id']) . ' už doklad vyrovnává vazbou. Vazbu v deníku zrušte, nebo ji nechte platit.', 409);
                }
            }
            foreach ($lineTotals as $lineId => $total) {
                $line = $lines[$lineId];
                $free = round($line['amount'] - $this->repo->usedOnLine($supplierId, $line), 2);
                if ($total > $free + self::TOLERANCE) {
                    throw new JournalSettlementException('line_overallocated',
                        'Řádek zápisu ' . ($line['document_no'] ?? '#' . $line['entry_id']) . ' má volných jen ' . self::money($free) . ' Kč.', 409);
                }
            }
            $today = date('Y-m-d');
            foreach ($docTotals as $key => $total) {
                if (count($docAccounts[$key]) > 1) {
                    throw new JournalSettlementException('account_mismatch', 'Doklad lze vyrovnat jen řádky jednoho saldokontního účtu.');
                }
                $account = array_values($docAccounts[$key])[0];
                $remaining = $this->remaining($supplierId, $docs[$key], $account, max($today, $docDates[$key]));
                if ($remaining === null || $remaining < self::TOLERANCE) {
                    throw new JournalSettlementException('document_not_open',
                        'Doklad ' . $docs[$key]['doc_no'] . ' není na účtu ' . $account['code'] . ' otevřený.', 409);
                }
                if ($total > $remaining + self::TOLERANCE) {
                    throw new JournalSettlementException('document_overallocated',
                        'Doklad ' . $docs[$key]['doc_no'] . ' má k vyrovnání jen ' . self::money($remaining) . ' Kč.', 409);
                }
            }

            $created = [];
            foreach ($rows as $r) {
                $id = $this->repo->insert($supplierId, $lines[$r['line_id']], 'settlement', $r['doc_type'], $r['doc_id'], $r['amount'], $userId);
                $created[] = [
                    'id'       => $id,
                    'line_id'  => $r['line_id'],
                    'entry_id' => $lines[$r['line_id']]['entry_id'],
                    'doc_type' => $r['doc_type'],
                    'doc_id'   => $r['doc_id'],
                    'amount'   => $r['amount'],
                ];
            }
            if ($ownTx) $pdo->commit();
            return $created;
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Zruší rozpad. Předpis ostatní položky ze zůstatku se tu nemaže: jde se přes storno
     * položky, aby položka nezůstala živá bez předpisu.
     *
     * @return array<string,mixed> smazaný rozpad
     */
    public function delete(int $supplierId, int $id): array
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $allocation = $this->repo->find($supplierId, $id, true)
                ?? throw new JournalSettlementException('not_found', 'Rozpad nenalezen.', 404);
            if ($allocation['kind'] === 'origin') {
                throw new JournalSettlementException('origin_allocation',
                    'Předpis ostatní položky ze zůstatku zrušíte stornem položky v agendě Ostatní pohledávky a závazky.', 409);
            }
            $this->repo->delete($supplierId, $id);
            if ($ownTx) $pdo->commit();
            return $allocation;
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Ostatní pohledávka / závazek ze zůstatku bez dokladu, navázaná na vybraný řádek zápisu.
     * Bez nového zaúčtování: předpis zůstává v původním zápisu.
     *
     * Řádek pohybu peněz (`$fromPayment`) zůstává mimo výběr účetní v saldokontu, kde pohyb
     * páruje na doklady. Přebírá ho jen převod: přeplatek zaúčtovaný z výpisu přímo na účet
     * jiný program dokladem nevede, předpisem je tedy řádek toho pohybu.
     *
     * @param array<string,mixed> $input {line_id, amount, partner_id?, partner_name?, variable_symbol?, title?, due_on?, note?}
     * @return array{item:array<string,mixed>, allocation_id:int, line:array<string,mixed>}
     */
    public function createOtherItem(int $supplierId, array $input, ?int $userId, bool $fromPayment = false): array
    {
        $lineId = (int) ($input['line_id'] ?? 0);
        $amount = self::amount($input['amount'] ?? null);
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        if ($ownTx) $pdo->beginTransaction();
        try {
            $this->repo->lockForAllocation($supplierId, [$lineId], []);
            $line = $this->repo->lines($supplierId, [$lineId])[$lineId]
                ?? throw new JournalSettlementException('line_not_found', 'Řádek zápisu nenalezen.', 404);
            $this->assertLine($supplierId, $line, null, SaldoService::DEFAULT_ACCOUNTS, $fromPayment
                ? [...JournalSettlementRepository::ALLOCATABLE_SOURCES, ...JournalSettlementRepository::PAYMENT_SOURCES]
                : JournalSettlementRepository::ALLOCATABLE_SOURCES);
            if (!$fromPayment && $this->repo->hasCountedLink($supplierId, $line['entry_id'])) {
                throw new JournalSettlementException('entry_linked', 'Zápis už je navázaný na doklad, zůstatek bez dokladu z něj založit nelze.', 409);
            }
            $free = round($line['amount'] - $this->repo->usedOnLine($supplierId, $line), 2);
            if ($amount > $free + self::TOLERANCE) {
                throw new JournalSettlementException('line_overallocated', 'Řádek zápisu má volných jen ' . self::money($free) . ' Kč.', 409);
            }
            $docRef = $line['document_no'] ?? ('#' . $line['entry_id']);
            $title = trim((string) ($input['title'] ?? ''));
            $dueOn = trim((string) ($input['due_on'] ?? ''));
            try {
                $item = $this->otherItems->createFromJournalLine($supplierId, [
                    'side'         => $line['side'] === 'debit' ? 'receivable' : 'payable',
                    'kind'         => 'other',
                    'title'        => $title !== '' ? $title : mb_substr('Zůstatek ze zápisu ' . $docRef . ($line['description'] ? ' - ' . $line['description'] : ''), 0, 255),
                    'partner_id'   => $input['partner_id'] ?? null,
                    'partner_name' => $input['partner_name'] ?? null,
                    'issued_on'    => $line['entry_date'],
                    'accounting_on' => $line['entry_date'],
                    'due_on'       => $dueOn !== '' ? $dueOn : $line['entry_date'],
                    'currency'     => 'CZK',
                    'amount'       => $amount,
                    'variable_symbol' => $input['variable_symbol'] ?? null,
                    'account_code' => $line['account_code'],
                    'note'         => $input['note'] ?? ('Předpis je v zápisu ' . $docRef . ' (řádek ' . $line['line_no'] . ', účet ' . $line['account_code'] . '); položka se znovu neúčtuje.'),
                ], $userId);
            } catch (OtherItemException $e) {
                throw new JournalSettlementException($e->errorCode, $e->getMessage(), $e->httpStatus);
            }
            $allocationId = $this->repo->insert($supplierId, $line, 'origin', 'other_item', (int) $item['id'], $amount, $userId);
            if ($ownTx) $pdo->commit();
            return ['item' => $item, 'allocation_id' => $allocationId, 'line' => $line];
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Návrh párování: projde otevřené položky saldokonta firmy k rozvahovému dni a navrhne
     * jen jisté shody k hromadnému potvrzení:
     *   - číslo dokladu je v textu zápisu (nebo je zápis na doklad navázaný) a volná částka
     *     řádku se rovná zbytku dokladu,
     *   - zápis jmenuje víc dokladů jednoho partnera a volná částka je přesně jejich součtem,
     *   - partner je jistý (vazba zápisu nebo jeho název v textu) a částku má právě jeden jeho
     *     otevřený doklad, případně je řádek přesně součtem všech jeho otevřených dokladů.
     * Řádek i doklad smí být nejvýš v jednom návrhu; dvojznačná shoda se nenavrhne.
     * Na zálohových účtech 314/324 navrhuje čerpání zálohy konečnou fakturou
     * ({@see AdvanceDeductionService::suggestForAccount()}, položky `type = advance`).
     *
     * @return array{as_of:string, items:list<array<string,mixed>>, truncated:bool}
     */
    public function suggestions(int $supplierId, int $periodId, ?string $asOf, ?string $accountCode): array
    {
        $codes = $accountCode !== null && $accountCode !== 'all' ? [$accountCode] : self::SUGGEST_ACCOUNTS;
        $out = [];
        $truncated = false;
        $resolvedAsOf = $asOf;
        foreach ($codes as $code) {
            if (!in_array($code, self::SUGGEST_ACCOUNTS, true)) {
                throw new JournalSettlementException('account_not_saldo', 'Návrh párování umí účty ' . implode(', ', self::SUGGEST_ACCOUNTS) . '.');
            }
            $report = $this->saldoService->buildCached($supplierId, $periodId, $asOf, $code);
            $resolvedAsOf = (string) $report['as_of'];
            foreach ($report['accounts'] as $block) {
                [$items, $cut] = isset(AdvanceDeductionService::ACCOUNTS[$code])
                    ? [$this->advances->suggestForAccount($supplierId, $block, $resolvedAsOf), false]
                    : $this->suggestForAccount($supplierId, $block, $resolvedAsOf);
                $truncated = $truncated || $cut;
                foreach ($items as $item) {
                    if (count($out) >= self::MAX_SUGGESTIONS) {
                        $truncated = true;
                        break 3;
                    }
                    $out[] = $item;
                }
            }
        }
        return ['as_of' => (string) $resolvedAsOf, 'items' => $out, 'truncated' => $truncated];
    }

    /**
     * @param array<string,mixed> $block blok účtu ze SaldoService
     * @return array{0:list<array<string,mixed>>, 1:bool}
     */
    private function suggestForAccount(int $supplierId, array $block, string $asOf): array
    {
        $docs = [];
        foreach ($block['partners'] as $partner) {
            foreach ($partner['items'] as $it) {
                if (!in_array($it['doc_type'], ['invoice', 'purchase_invoice'], true)
                    || empty($it['settleable'])
                    || ($it['kind'] ?? 'document') !== 'document'
                    || $it['currency_code'] !== 'CZK'
                    || $it['remaining_czk'] < self::TOLERANCE) {
                    continue;
                }
                $key = $it['doc_type'] . ':' . $it['doc_id'];
                $docs[$key] = [
                    'key'          => $key,
                    'doc_type'     => $it['doc_type'],
                    'doc_id'       => (int) $it['doc_id'],
                    'doc_no'       => (string) $it['doc_no'],
                    'partner_id'   => (int) $partner['partner_id'],
                    'partner_name' => (string) $partner['partner_name'],
                    'remaining'    => round((float) $it['remaining_czk'], 2),
                    'side'         => self::settleSide($it['doc_type']),
                ];
            }
        }
        if ($docs === []) {
            return [[], false];
        }
        $byNumber = [];
        foreach (['invoice', 'purchase_invoice'] as $type) {
            $ids = array_map(static fn (array $d): int => $d['doc_id'], array_filter($docs, static fn (array $d): bool => $d['doc_type'] === $type));
            if ($ids === []) continue;
            foreach ($this->repo->documentNumbers($supplierId, $type, array_values($ids)) as $id => $numbers) {
                foreach ([...$numbers, $docs[$type . ':' . $id]['doc_no']] as $number) {
                    if (JournalSettlementRepository::isDocumentNumber($number)) {
                        $byNumber[self::token($number)][$type . ':' . $id] = true;
                    }
                }
            }
        }
        $byPartner = [];
        $partnerNames = [];
        foreach ($docs as $key => $d) {
            $byPartner[$d['side']][$d['partner_id']][] = $key;
            $name = self::normalizeName($d['partner_name']);
            if (mb_strlen($name) >= 5) {
                $partnerNames[$d['side']][$d['partner_id']] = $name;
            }
        }

        $accountIds = $this->repo->accountFamily($supplierId, (int) $block['account']['id']);
        $proposals = [];
        $truncated = false;
        foreach (array_keys($byPartner) as $side) {
            $lines = $this->repo->freeLines($supplierId, $accountIds, $side, $asOf, null, self::SUGGESTION_LINE_CAP + 1);
            if (count($lines) > self::SUGGESTION_LINE_CAP) {
                $truncated = true;
                array_pop($lines);
            }
            foreach ($lines as $line) {
                $proposal = $this->proposeForLine($line, $side, $docs, $byNumber, $byPartner[$side], $partnerNames[$side] ?? []);
                if ($proposal !== null) {
                    $proposals[] = $proposal;
                }
            }
        }

        // Doklad navržený víc řádkům je dvojznačný: nenavrhne se nikomu.
        $claims = [];
        foreach ($proposals as $p) {
            foreach ($p['allocations'] as $a) {
                $claims[$a['doc_type'] . ':' . $a['doc_id']] = ($claims[$a['doc_type'] . ':' . $a['doc_id']] ?? 0) + 1;
            }
        }
        $items = [];
        foreach ($proposals as $p) {
            foreach ($p['allocations'] as $a) {
                if ($claims[$a['doc_type'] . ':' . $a['doc_id']] > 1) continue 2;
            }
            $p['account'] = ['id' => (int) $block['account']['id'], 'code' => (string) $block['account']['code']];
            $p['key'] = $p['line']['line_id'] . ':' . implode(',', array_map(static fn (array $a): string => $a['doc_type'] . ':' . $a['doc_id'], $p['allocations']));
            $items[] = $p;
        }
        return [$items, $truncated];
    }

    /**
     * @param array<string,mixed> $line
     * @param array<string,array<string,mixed>> $docs
     * @param array<string,array<string,true>> $byNumber
     * @param array<int,list<string>> $byPartner
     * @param array<int,string> $partnerNames
     * @return array<string,mixed>|null
     */
    private function proposeForLine(array $line, string $side, array $docs, array $byNumber, array $byPartner, array $partnerNames): ?array
    {
        $free = $line['free_amount'];
        $text = trim(($line['document_no'] ?? '') . ' ' . ($line['description'] ?? ''));
        $named = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            foreach (array_keys($byNumber[self::token($word)] ?? []) as $key) {
                if (($docs[$key]['side'] ?? null) === $side) $named[$key] = true;
            }
        }
        foreach ($line['links'] as $link) {
            $key = $link['doc_type'] . ':' . $link['doc_id'];
            if (($docs[$key]['side'] ?? null) === $side) $named[$key] = true;
        }
        $named = array_keys($named);

        if ($named !== []) {
            $sum = round(array_sum(array_map(static fn (string $k): float => $docs[$k]['remaining'], $named)), 2);
            $partners = array_unique(array_map(static fn (string $k): int => $docs[$k]['partner_id'], $named));
            if (abs($sum - $free) < self::TOLERANCE && count($partners) === 1) {
                return self::proposal($line, count($named) === 1 ? 'doc_no_amount' : 'doc_no_sum', array_map(static fn (string $k): array => $docs[$k], $named));
            }
            return null;
        }

        $partnerId = null;
        $normalized = self::normalizeName($text);
        $matches = [];
        foreach ($partnerNames as $pid => $name) {
            if (str_contains($normalized, $name)) $matches[] = $pid;
        }
        if (count($matches) === 1) {
            $partnerId = $matches[0];
        }
        if ($partnerId === null || !isset($byPartner[$partnerId])) {
            return null;
        }
        $keys = $byPartner[$partnerId];
        $exact = array_values(array_filter($keys, static fn (string $k): bool => abs($docs[$k]['remaining'] - $free) < self::TOLERANCE));
        if (count($exact) === 1) {
            return self::proposal($line, 'partner_amount', [$docs[$exact[0]]]);
        }
        if (count($exact) === 0 && count($keys) > 1
            && abs(round(array_sum(array_map(static fn (string $k): float => $docs[$k]['remaining'], $keys)), 2) - $free) < self::TOLERANCE) {
            return self::proposal($line, 'partner_sum', array_map(static fn (string $k): array => $docs[$k], $keys));
        }
        return null;
    }

    /**
     * @param array<string,mixed> $line
     * @param list<array<string,mixed>> $docs
     * @return array<string,mixed>
     */
    private static function proposal(array $line, string $reason, array $docs): array
    {
        return [
            'type' => 'journal',
            'reason' => $reason,
            'line' => [
                'line_id'      => $line['line_id'],
                'entry_id'     => $line['entry_id'],
                'entry_date'   => $line['entry_date'],
                'document_no'  => $line['document_no'],
                'description'  => $line['description'],
                'account_code' => $line['account_code'],
                'free_amount'  => $line['free_amount'],
            ],
            'partner_name' => $docs[0]['partner_name'],
            'allocations' => array_map(static fn (array $d): array => [
                'doc_type'  => $d['doc_type'],
                'doc_id'    => $d['doc_id'],
                'doc_no'    => $d['doc_no'],
                'remaining' => $d['remaining'],
                'amount'    => $d['remaining'],
            ], $docs),
        ];
    }

    /**
     * Ověří řádek pro rozpad a vrátí saldokontní účet, pod který patří.
     *
     * @param array<string,mixed> $line
     * @param list<string> $allowedCodes
     * @param list<string> $sources druhy zápisu, jejichž řádek se smí rozepsat
     * @return array{id:int, code:string, name:string, account_type:string, normal_side:?string}
     */
    private function assertLine(int $supplierId, array $line, ?string $side, array $allowedCodes, array $sources = JournalSettlementRepository::ALLOCATABLE_SOURCES): array
    {
        $ref = $line['document_no'] ?? ('#' . $line['entry_id']);
        if (!$line['posted'] || $line['reversed'] || $line['is_reversal']) {
            throw new JournalSettlementException('line_not_live', 'Zápis ' . $ref . ' není zaúčtovaný, nebo je stornovaný.', 409);
        }
        if (!in_array($line['source_type'], $sources, true) || $line['own_posting']) {
            throw new JournalSettlementException('line_source', 'Rozepsat na doklady lze jen řádek ručního (interního) zápisu, ne zaúčtování dokladu.', 409);
        }
        if ($line['imported_settlement']) {
            throw new JournalSettlementException('entry_settled',
                'Zápis ' . $ref . ' nese zápočet převzatý z jiného programu, který saldokonto už počítá. Rozpad by vyrovnání zdvojil.', 409);
        }
        if ($line['duplicate_line_no']) {
            throw new JournalSettlementException('line_ambiguous', 'Zápis ' . $ref . ' má víc řádků se stejným pořadím, řádek nelze jednoznačně rozepsat.', 409);
        }
        if ($line['is_red_storno']) {
            throw new JournalSettlementException('line_red_storno', 'Řádek červeného storna nelze rozepsat na doklady.', 409);
        }
        if ($line['currency_code'] !== null && strtoupper($line['currency_code']) !== 'CZK') {
            throw new JournalSettlementException('line_currency', 'Řádek v cizí měně nelze rozepsat na doklady.', 409);
        }
        if ($side !== null && $line['side'] !== $side) {
            throw new JournalSettlementException('line_side', 'Řádek zápisu ' . $ref . ' je na straně, která saldo dokladu nesnižuje.', 409);
        }
        $code = substr($line['synthetic_code'], 0, 3);
        $account = in_array($code, $allowedCodes, true) ? $this->saldo->resolveAccount($supplierId, $code) : null;
        if ($account === null || $account['id'] !== $line['synthetic_id']) {
            throw new JournalSettlementException('account_not_saldo', 'Řádek zápisu ' . $ref . ' není na saldokontním účtu.', 409);
        }
        return $account;
    }

    /**
     * Zbývá k vyrovnání (Kč, na normální straně účtu) podle saldokonta k `$asOf`, spočtené jen
     * pro tento doklad. NULL = doklad nemá na účtu vlastní otevřený předpis (uhrazený, jen
     * v počátečních stavech).
     *
     * @param array<string,mixed> $doc
     * @param array{id:int, code:string, account_type:string, normal_side:?string} $account
     */
    private function remaining(int $supplierId, array $doc, array $account, string $asOf): ?float
    {
        $normal = $account['normal_side'] ?? (in_array($account['account_type'], ['asset', 'expense'], true) ? 'debit' : 'credit');
        $remaining = null;
        foreach ($this->saldo->documentOpenItems($supplierId, $doc['doc_type'], $doc['doc_id'], $account['id'], $asOf) as $it) {
            $booked = $normal === 'debit' ? (float) $it['booked_signed'] : -(float) $it['booked_signed'];
            $remaining = round(($remaining ?? 0.0) + SaldoRepository::settlementAmounts($booked, (float) $it['paid_ratio'])['remaining'], 2);
        }
        return $remaining ?? $this->remainingBehindPaidStatus($supplierId, $doc, $account, $asOf, $normal);
    }

    /**
     * Přijatá faktura ve stavu „uhrazeno" bez evidované úhrady: saldokonto ji podle stavu
     * uzavře, kontrola uzávěrky K3 ale hledá vyrovnání v deníku a hlásí ji. Zápis, který
     * ji v hlavní knize vyrovnal, se proto smí rozepsat do výše, kterou evidované úhrady
     * nekryjí. Saldokonto se tím nezmění (doklad už uzavřený je), kontrola ho přestane hlásit.
     *
     * @param array<string,mixed> $doc
     * @param array{id:int} $account
     */
    private function remainingBehindPaidStatus(int $supplierId, array $doc, array $account, string $asOf, string $normal): ?float
    {
        if ($doc['doc_type'] !== 'purchase_invoice') {
            return null;
        }
        $c = $this->saldo->settlementComponents($supplierId, 'purchase_invoice', $doc['doc_id'], $account['id'], $asOf);
        if ($c === null || empty($c['fully_paid_as_of'])) {
            return null;
        }
        $advance = round((float) $c['advance_on_account'], 2);
        $signal = (float) $c['paid_from_matches'] + (float) $c['paid_from_cash'] + (float) $c['settled_by_offsets'] + $advance;
        $toPay = (float) $c['amount_to_pay'] + $advance;
        $ratio = abs($toPay) < self::TOLERANCE ? 0.0 : max(0.0, min(1.0, $signal / $toPay));
        $booked = $normal === 'debit' ? (float) $c['booked_signed'] : -(float) $c['booked_signed'];
        $remaining = round(SaldoRepository::settlementAmounts($booked, $ratio)['remaining'] - (float) $c['manual_settled'], 2);
        return $remaining >= self::TOLERANCE ? $remaining : null;
    }

    /** @return array{id:int, code:string, name:string, account_type:string, normal_side:?string} */
    private function settleAccount(int $supplierId, string $code): array
    {
        if (!in_array($code, self::SETTLE_ACCOUNTS, true)) {
            throw new JournalSettlementException('account_not_saldo', 'Vyrovnání zápisem umí účty ' . implode(', ', self::SETTLE_ACCOUNTS) . '.');
        }
        return $this->saldo->resolveAccount($supplierId, $code)
            ?? throw new JournalSettlementException('account_not_found', 'Účet v osnově firmy neexistuje.', 404);
    }

    /**
     * @param list<mixed> $input
     * @return list<array{line_id:int, doc_type:string, doc_id:int, amount:float}>
     */
    private function normalizeAllocations(array $input): array
    {
        if ($input === [] || !array_is_list($input) || count($input) > self::MAX_ALLOCATIONS) {
            throw new JournalSettlementException('invalid_allocations', 'Zadejte 1 až ' . self::MAX_ALLOCATIONS . ' řádků vyrovnání.');
        }
        $rows = [];
        foreach ($input as $row) {
            if (!is_array($row)) {
                throw new JournalSettlementException('invalid_allocations', 'Neplatný řádek vyrovnání.');
            }
            $lineId = filter_var($row['line_id'] ?? null, FILTER_VALIDATE_INT);
            $docId = filter_var($row['doc_id'] ?? null, FILTER_VALIDATE_INT);
            $docType = (string) ($row['doc_type'] ?? '');
            if ($lineId === false || $lineId <= 0 || $docId === false || $docId <= 0) {
                throw new JournalSettlementException('invalid_allocations', 'Každý řádek vyrovnání potřebuje řádek zápisu a doklad.');
            }
            self::settleSide($docType);
            $rows[] = ['line_id' => $lineId, 'doc_type' => $docType, 'doc_id' => $docId, 'amount' => self::amount($row['amount'] ?? null)];
        }
        return $rows;
    }

    private static function amount(mixed $raw): float
    {
        $amount = filter_var($raw, FILTER_VALIDATE_FLOAT);
        if ($amount === false || !is_finite((float) $amount) || $amount <= 0
            || $amount > 999999999999.99 || abs(round((float) $amount, 2) - (float) $amount) > 1e-9) {
            throw new JournalSettlementException('invalid_amount', 'Částka musí být kladná a zadaná na haléře.');
        }
        return round((float) $amount, 2);
    }

    private static function token(string $value): string
    {
        return mb_strtoupper(preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? '');
    }

    /** Název partnera bez právní formy a interpunkce, pro hledání v textu zápisu. */
    private static function normalizeName(string $value): string
    {
        $v = mb_strtolower($value);
        $v = preg_replace('/\b(s\.?\s?r\.?\s?o\.?|a\.?\s?s\.?|spol\.?|v\.?\s?o\.?\s?s\.?|k\.?\s?s\.?|z\.?\s?s\.?|o\.?\s?p\.?\s?s\.?)(?=\s|$|,)/u', ' ', $v) ?? $v;
        $v = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $v) ?? $v;
        return trim(preg_replace('/\s+/u', ' ', $v) ?? $v);
    }

    private static function money(float $amount): string
    {
        return number_format($amount, 2, ',', ' ');
    }
}
