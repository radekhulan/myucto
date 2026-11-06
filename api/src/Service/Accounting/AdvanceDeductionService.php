<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AdvanceDeductionRepository;
use MyInvoice\Repository\SaldoRepository;

/**
 * Vyrovnání zálohy v saldokontu čerpáním konečnou fakturou.
 *
 * Zálohu na 314 (poskytnutou: zálohová přijatá faktura, samostatný daňový doklad k platbě,
 * záloha placená v konečné faktuře) a na 324 (přijatou: zaplacená proforma) nevyrovnává
 * zápis v deníku, ale konečná faktura, která ji odečítá stranou zálohového účtu. Hlavní
 * kniha čerpání nese, saldokonto ho bez vazby záloha × faktura nepozná. Vazba je proto
 * řádek čerpání (`purchase_advance_deductions` / `invoice_advance_deductions`) s částkou:
 * jedna záloha může čerpat víc faktur a jedna faktura víc záloh.
 *
 * Čerpání se vejde do zbytku zálohy podle saldokonta a do strany konečné faktury, kterou
 * ještě nečerpá jiná záloha ({@see AdvanceDeductionRepository::finals()}). Platí od
 * zaúčtování konečné faktury, takže uzavřené období hlídá zápis faktury. Doklady v cizí
 * měně se nevyrovnávají: zálohový účet nese Kč a kurzový rozdíl řeší kurzová evidence.
 *
 * Převody z účetních programů zapisují čerpání přes {@see importState()} a
 * {@see recordImported()}: řídí se stejným volným odpočtem faktury a nepřepíšou čerpání,
 * které zadal uživatel.
 */
final class AdvanceDeductionService
{
    /** Zálohový účet → druh dokladu zálohy i konečné faktury. */
    public const ACCOUNTS = ['314' => 'purchase_invoice', '324' => 'invoice'];
    public const MAX_ROWS = 50;
    public const MAX_CANDIDATES = 50;
    private const TOLERANCE = 0.005;

    public function __construct(
        private readonly Connection $db,
        private readonly AdvanceDeductionRepository $repo,
        private readonly SaldoRepository $saldo,
    ) {}

    public static function docTypeForAccount(string $code): string
    {
        return self::ACCOUNTS[$code]
            ?? throw new JournalSettlementException('account_not_advance', 'Čerpání zálohy umí účty ' . implode(', ', array_keys(self::ACCOUNTS)) . '.');
    }

    public static function accountForDocType(string $docType): string
    {
        $code = array_search($docType, self::ACCOUNTS, true);
        if ($code === false) {
            throw new JournalSettlementException('invalid_doc_type', 'Neplatný druh dokladu.');
        }
        return (string) $code;
    }

    /**
     * Kandidáti pro otevřenou zálohu: konečné faktury téhož partnera zaúčtované k `$asOf`
     * s nepřiřazenou stranou čerpání, seřazené podle shody částky, čísla zálohy v textu
     * faktury, data po záloze a blízkosti data.
     *
     * @return array<string,mixed>
     */
    public function candidates(int $supplierId, string $docType, int $advanceId, string $accountCode, string $asOf): array
    {
        if (self::docTypeForAccount($accountCode) !== $docType) {
            throw new JournalSettlementException('account_mismatch', 'Záloha na účtu ' . $accountCode . ' je jiného druhu dokladu.');
        }
        $advance = $this->repo->documents($supplierId, $docType, [$advanceId])[$advanceId]
            ?? throw new JournalSettlementException('not_found', 'Doklad nenalezen.', 404);
        $account = $this->account($supplierId, $accountCode);
        $open = $advance['currency_code'] === 'CZK' ? $this->remaining($supplierId, $advance, $account, $asOf) : null;
        $remaining = $open ?? 0.0;
        $supported = $advance['currency_code'] === 'CZK' && $open !== null;
        $items = [];
        if ($supported) {
            $finals = $this->repo->finals($supplierId, $docType, $this->repo->accountFamily($supplierId, $account['id']), [$advance['partner_id']], $asOf, null, true);
            foreach ($finals as $f) {
                if ($f['doc_id'] === $advanceId || $f['posted_on'] === null) {
                    continue;
                }
                $items[] = $this->candidate($f, $advance, $remaining);
            }
            usort($items, static fn (array $a, array $b): int =>
                [$b['amount_match'], $b['doc_match'], $b['after_advance'], $a['distance'], $a['doc_id']]
                <=> [$a['amount_match'], $a['doc_match'], $a['after_advance'], $b['distance'], $b['doc_id']]);
            $items = array_slice($items, 0, self::MAX_CANDIDATES);
        }
        return [
            'mode'     => 'advance',
            'document' => [
                'doc_type'      => $docType,
                'doc_id'        => $advanceId,
                'doc_no'        => $advance['doc_no'],
                'partner_id'    => $advance['partner_id'],
                'partner_name'  => $advance['partner_name'],
                'currency_code' => $advance['currency_code'],
                'issue_date'    => $advance['issue_date'],
                'remaining'     => $remaining,
                'account'       => ['id' => $account['id'], 'code' => $account['code'], 'name' => $account['name']],
                'supported'     => $supported,
            ],
            'deductions' => array_map(static fn (array $d): array => [...$d, 'deletable' => $d['source'] === 'saldo'],
                $this->repo->listFor($supplierId, $docType, 'advance', $advanceId)),
            'items' => array_map(static function (array $c): array {
                unset($c['distance']);
                return $c;
            }, $items),
        ];
    }

    /**
     * Zapíše čerpání záloh konečnými fakturami. Všechno, nebo nic: nejdřív se ověří celý
     * požadavek (zbytek každé zálohy, nepřiřazená strana každé faktury), pak se zapisuje.
     *
     * @param list<mixed> $input [{doc_type, advance_id, final_id, amount}]
     * @return list<array<string,mixed>> zapsaná čerpání
     */
    public function deduct(int $supplierId, array $input, ?int $userId): array
    {
        [$docType, $rows] = $this->normalize($input);
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        // READ COMMITTED + zámky dokladů před čtením (vzor JournalSettlementService::allocate):
        // zbytek zálohy i nepřiřazená strana faktury se čtou až po zámcích, takže souběžné
        // čerpání téže zálohy nebo faktury i ruční vazba zálohy se seřadí a druhé vidí to první.
        if ($ownTx) $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        if ($ownTx) $pdo->beginTransaction();
        try {
            $advanceIds = array_values(array_unique(array_column($rows, 'advance_id')));
            $finalIds = array_values(array_unique(array_column($rows, 'final_id')));
            $this->repo->lockDocuments($supplierId, $docType, [...$advanceIds, ...$finalIds]);
            $docs = $this->repo->documents($supplierId, $docType, [...$advanceIds, ...$finalIds]);
            foreach ([...$advanceIds, ...$finalIds] as $id) {
                $doc = $docs[$id] ?? throw new JournalSettlementException('not_found', 'Doklad nenalezen.', 404);
                if ($doc['currency_code'] !== 'CZK') {
                    throw new JournalSettlementException('document_currency', 'Doklad ' . $doc['doc_no'] . ' je v cizí měně, čerpání zálohy v saldokontu nese jen Kč.');
                }
                if (in_array($doc['status'], ['draft', 'cancelled'], true)) {
                    throw new JournalSettlementException('document_not_open', 'Doklad ' . $doc['doc_no'] . ' není v saldokontu otevřený.', 409);
                }
            }
            $account = $this->account($supplierId, self::accountForDocType($docType));
            $family = $this->repo->accountFamily($supplierId, $account['id']);
            $finals = $this->repo->finals($supplierId, $docType, $family, null, AdvanceDeductionRepository::ANY_DATE, $finalIds);

            $byFinal = [];
            $byAdvance = [];
            foreach ($rows as $r) {
                $advance = $docs[$r['advance_id']];
                $finalDoc = $docs[$r['final_id']];
                if ($advance['partner_id'] !== $finalDoc['partner_id']) {
                    throw new JournalSettlementException('partner_mismatch',
                        'Záloha ' . $advance['doc_no'] . ' a faktura ' . $finalDoc['doc_no'] . ' mají jiného partnera.', 409);
                }
                $final = $finals[$r['final_id']] ?? null;
                if ($final === null || $final['posted_on'] === null) {
                    throw new JournalSettlementException('final_not_posted',
                        'Faktura ' . $finalDoc['doc_no'] . ' není zaúčtovaná s odpočtem zálohy na účtu ' . $account['code'] . '.', 409);
                }
                $byFinal[$r['final_id']] = ($byFinal[$r['final_id']] ?? 0.0) + $r['amount'];
                $byAdvance[$r['advance_id']] = ($byAdvance[$r['advance_id']] ?? 0.0) + $r['amount'];
            }
            foreach ($byFinal as $finalId => $total) {
                $free = max(0.0, $finals[$finalId]['unassigned']);
                if ($total > $free + self::TOLERANCE) {
                    throw new JournalSettlementException('final_overdeducted',
                        'Faktura ' . $docs[$finalId]['doc_no'] . ' má nepřiřazený odpočet zálohy jen ' . self::money($free) . ' Kč.', 409);
                }
            }
            // Zbytek zálohy se bere k nejpozdějšímu dni, od kterého platí kterékoli její čerpání
            // (nové i zapsané dřív): čerpání fakturou zaúčtovanou dopředu by k dnešku ještě
            // neplatilo a záloha by šla čerpat znovu.
            $validFrom = $this->latestDeductionDates($supplierId, $docType, array_keys($byAdvance));
            foreach ($rows as $r) {
                $validFrom[$r['advance_id']] = max($validFrom[$r['advance_id']] ?? '', (string) $finals[$r['final_id']]['posted_on']);
            }
            $today = date('Y-m-d');
            foreach ($byAdvance as $advanceId => $total) {
                $remaining = $this->remaining($supplierId, $docs[$advanceId], $account, max($today, $validFrom[$advanceId] ?? ''));
                if ($remaining === null || $remaining < self::TOLERANCE) {
                    throw new JournalSettlementException('advance_not_open',
                        'Záloha ' . $docs[$advanceId]['doc_no'] . ' není na účtu ' . $account['code'] . ' otevřená.', 409);
                }
                if ($total > $remaining + self::TOLERANCE) {
                    throw new JournalSettlementException('advance_overdeducted',
                        'Záloha ' . $docs[$advanceId]['doc_no'] . ' má k vyrovnání jen ' . self::money($remaining) . ' Kč.', 409);
                }
            }

            $created = [];
            foreach ($rows as $r) {
                $pair = $this->repo->pair($supplierId, $docType, $r['final_id'], $r['advance_id']);
                if ($pair !== null && $pair['source'] !== 'saldo') {
                    throw new JournalSettlementException('pair_imported',
                        'Čerpání zálohy ' . $docs[$r['advance_id']]['doc_no'] . ' fakturou ' . $docs[$r['final_id']]['doc_no'] . ' je převzaté z převodu, upravte ho opravou převodu.', 409);
                }
                $id = $this->repo->insert($supplierId, $docType, $r['final_id'], $r['advance_id'], $r['amount'], $userId);
                $created[] = [
                    'id'         => $id,
                    'doc_type'   => $docType,
                    'advance_id' => $r['advance_id'],
                    'final_id'   => $r['final_id'],
                    'amount'     => $r['amount'],
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
     * Zruší čerpání zadané v saldokontu. Převzaté z převodu patří ke zdrojovým datům.
     *
     * @return array<string,mixed> smazané čerpání
     */
    public function delete(int $supplierId, string $docType, int $id): array
    {
        self::accountForDocType($docType);
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $row = $this->repo->find($supplierId, $docType, $id, true)
                ?? throw new JournalSettlementException('not_found', 'Čerpání zálohy nenalezeno.', 404);
            if ($row['source'] !== 'saldo') {
                throw new JournalSettlementException('deduction_imported', 'Čerpání převzaté z převodu zrušíte opravou převodu, ne v saldokontu.', 409);
            }
            $this->repo->delete($supplierId, $docType, $id);
            if ($ownTx) $pdo->commit();
            return [...$row, 'doc_type' => $docType];
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Stav konečných faktur pro převod z účetního programu: kolik strany čerpání na
     * zálohovém účtu má faktura zaúčtováno (`deductible`), kolik z ní drží vazba na zálohu
     * (`linked_share`) a řádky čerpání ze všech zdrojů (`deducted`), kolik zbývá
     * (`unassigned`) a zda je faktura zaúčtovaná (`posted`). Faktura bez zaúčtované strany
     * čerpání ve výsledku je s nulami a `posted = false`, pokud nemá živý zápis vůbec.
     *
     * @param list<int> $finalIds
     * @return array<int,array{deductible:float, linked_share:float, deducted:float, unassigned:float, posted:bool}>
     */
    public function importState(int $supplierId, string $docType, array $finalIds): array
    {
        $account = $this->account($supplierId, self::accountForDocType($docType));
        $finals = $this->repo->finals($supplierId, $docType, $this->repo->accountFamily($supplierId, $account['id']), null, AdvanceDeductionRepository::ANY_DATE, $finalIds);
        $posted = $this->repo->postedEntries($supplierId, $docType, $finalIds);
        $deducted = $this->repo->deductedByFinal($supplierId, $docType, $finalIds);
        $out = [];
        foreach (array_values(array_unique(array_map('intval', $finalIds))) as $id) {
            $f = $finals[$id] ?? null;
            $out[$id] = [
                'deductible'   => $f['deductible'] ?? 0.0,
                'linked_share' => $f['linked_share'] ?? 0.0,
                'deducted'     => $f['deducted'] ?? ($deducted[$id] ?? 0.0),
                'unassigned'   => $f['unassigned'] ?? round(-($deducted[$id] ?? 0.0), 2),
                'posted'       => isset($posted[$id]),
            ];
        }
        return $out;
    }

    /**
     * Čerpání zálohy převzaté z převodu (`source = import`). Rozhoduje deterministicky:
     *   - dvojice už existuje (ze saldokonta i z dřívějšího běhu převodu) → nic se nemění
     *     a vrací se 0; ruční čerpání uživatele převod nepřepíše ani nenavýší,
     *   - faktura je zaúčtovaná se stranou čerpání → zapíše se nejvýš její volný odpočet
     *     (bez vazby na zálohu a bez čerpání ostatních záloh, všech zdrojů),
     *   - faktura ještě zaúčtovaná není (převod deník doplní později) → zapíše se celá částka;
     *     přečerpání po zaúčtování ukáže kontrola {@see deductionIssues()}.
     * Zamyká zálohu i fakturu stejně jako ruční čerpání. Vrací skutečně zapsanou částku.
     */
    public function recordImported(int $supplierId, string $docType, int $finalId, int $advanceId, float $amount, ?int $userId): float
    {
        self::accountForDocType($docType);
        $amount = round($amount, 2);
        if ($amount < 0.01 || $finalId <= 0 || $advanceId <= 0 || $finalId === $advanceId) {
            return 0.0;
        }
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $this->repo->lockDocuments($supplierId, $docType, [$finalId, $advanceId]);
            if (count($this->repo->documents($supplierId, $docType, [$finalId, $advanceId])) !== 2
                || $this->repo->pair($supplierId, $docType, $finalId, $advanceId) !== null) {
                if ($ownTx) $pdo->commit();
                return 0.0;
            }
            $state = $this->importState($supplierId, $docType, [$finalId])[$finalId];
            $write = $state['posted'] && $state['deductible'] >= self::TOLERANCE
                ? round(min($amount, max(0.0, $state['unassigned'])), 2)
                : $amount;
            if ($write >= 0.01) {
                $this->repo->insert($supplierId, $docType, $finalId, $advanceId, $write, $userId, 'import');
            }
            if ($ownTx) $pdo->commit();
            return $write >= 0.01 ? $write : 0.0;
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Konečné faktury, jejichž čerpání záloh přesahuje stranu čerpání živého zaúčtování
     * (přeúčtování s menším odpočtem, odpočet na jiném účtu, převod nad rámec zápisu).
     * Saldokonto by zálohy uzavřelo o čerpání, které hlavní kniha nenese.
     *
     * @return list<array<string,mixed>>
     */
    public function deductionIssues(int $supplierId, string $accountCode): array
    {
        $docType = self::docTypeForAccount($accountCode);
        $account = $this->saldo->resolveAccount($supplierId, $accountCode);
        if ($account === null) {
            return [];
        }
        return $this->repo->overdeductedFinals($supplierId, $docType, $this->repo->accountFamily($supplierId, $account['id']));
    }

    /**
     * Zápisy konečných faktur, které leží v uzavřeném období (čerpání mění saldokonto od
     * jejich zaúčtování).
     *
     * @param list<int> $finalIds
     * @return list<array{entry_id:int, period_status:string}>
     */
    public function closedPeriodEntries(int $supplierId, string $docType, array $finalIds): array
    {
        $out = [];
        foreach ($this->repo->postedEntries($supplierId, $docType, $finalIds) as $posted) {
            if ($posted['period_status'] !== null && AccountingPeriodStatus::isClosed($posted['period_status'])) {
                $out[] = ['entry_id' => $posted['entry_id'], 'period_status' => $posted['period_status']];
            }
        }
        return $out;
    }

    /**
     * Uzavřené období, ve kterém čerpání saldokonto ovlivnilo, nebo null. Bere všechny
     * zápisy skupiny zaúčtování konečné faktury včetně stornovaných: čerpání platilo od
     * stornovaného zápisu až do dne storna, a to mohlo být až po uzavřeném období.
     *
     * @return array{entry_id:int, period_status:string}|null
     */
    public function closedPeriodOfDeduction(int $supplierId, string $docType, int $id): ?array
    {
        $row = $this->repo->find($supplierId, $docType, $id);
        if ($row === null) {
            return null;
        }
        foreach ($this->repo->finalEntries($supplierId, $docType, [$row['final_id']], null)[$row['final_id']] ?? [] as $entry) {
            if ($entry['period_status'] !== null && AccountingPeriodStatus::isClosed($entry['period_status'])) {
                return ['entry_id' => $entry['entry_id'], 'period_status' => $entry['period_status']];
            }
        }
        return null;
    }

    /**
     * Návrh párování na zálohovém účtu: jisté shody záloha × konečná faktura téhož partnera.
     *   - číslo zálohy je v textu právě jedné faktury s nepřiřazeným odpočtem
     *     (`advance_doc_no`, čerpá se zbytek zálohy, nejvýš nepřiřazený odpočet),
     *   - nepřiřazený odpočet právě jedné faktury partnera vystavené po záloze se rovná
     *     zbytku zálohy (`advance_amount`),
     *   - mezi touto a další zálohou partnera (podle data vystavení, ze všech jeho záloh
     *     včetně vyrovnaných, nezaplacených a v cizí měně) leží jediná faktura s nepřiřazeným
     *     odpočtem a ten se rovná zbytku zálohy (`advance_sequence`, pravidelné zálohy stejné
     *     výše čerpané následující fakturou).
     * Faktura, na kterou návrhy dohromady přesahují její nepřiřazený odpočet, se nenavrhne nikomu.
     *
     * @param array<string,mixed> $block blok účtu ze SaldoService
     * @return list<array<string,mixed>>
     */
    public function suggestForAccount(int $supplierId, array $block, string $asOf): array
    {
        $code = substr((string) $block['account']['code'], 0, 3);
        $docType = self::docTypeForAccount($code);
        $advances = [];
        foreach ($block['partners'] as $partner) {
            foreach ($partner['items'] as $it) {
                if ($it['doc_type'] !== $docType || empty($it['settleable']) || ($it['kind'] ?? 'document') !== 'document'
                    || $it['currency_code'] !== 'CZK') {
                    continue;
                }
                $id = (int) $it['doc_id'];
                // Tentýž doklad může být v saldu víckrát (počáteční stav a pozdější úhrada):
                // zbytek zálohy je součet jeho položek.
                $advances[$id] ??= [
                    'doc_id'       => $id,
                    'doc_no'       => (string) $it['doc_no'],
                    'partner_id'   => (int) $partner['partner_id'],
                    'partner_name' => (string) $partner['partner_name'],
                    'issue_date'   => (string) $it['issue_date'],
                    'remaining'    => 0.0,
                ];
                $advances[$id]['remaining'] = round($advances[$id]['remaining'] + (float) $it['remaining_czk'], 2);
            }
        }
        $advances = array_filter($advances, static fn (array $a): bool => $a['remaining'] >= self::TOLERANCE);
        if ($advances === []) {
            return [];
        }
        $partnerIds = array_values(array_unique(array_column($advances, 'partner_id')));
        $family = $this->repo->accountFamily($supplierId, (int) $block['account']['id']);
        $finalsByPartner = [];
        foreach ($this->repo->finals($supplierId, $docType, $family, $partnerIds, $asOf, null, true) as $f) {
            if ($f['posted_on'] !== null && !isset($advances[$f['doc_id']])) {
                $f['tokens'] = self::tokens($f['texts']);
                $finalsByPartner[$f['partner_id']][$f['doc_id']] = $f;
            }
        }
        $numbers = [];
        foreach ($this->repo->documents($supplierId, $docType, array_keys($advances)) as $id => $doc) {
            $numbers[$id] = array_map([self::class, 'token'], $doc['numbers']);
        }
        // Den vystavení další zálohy téhož partnera ze všech jeho záloh (stejný den = nerozlišitelné pořadí).
        $nextDate = [];
        foreach ($this->repo->advanceDates($supplierId, $docType, $partnerIds) as $list) {
            foreach ($list as $i => $a) {
                if (isset($list[$i + 1])) {
                    $nextDate[$a['doc_id']] = $list[$i + 1]['issue_date'];
                }
                if ($i > 0 && $list[$i - 1]['issue_date'] === $a['issue_date']) {
                    $nextDate[$a['doc_id']] = $a['issue_date'];
                }
            }
        }

        $proposals = [];
        foreach ($advances as $id => $a) {
            $finals = $finalsByPartner[$a['partner_id']] ?? [];
            if ($finals === []) continue;
            $named = array_values(array_filter($finals, static fn (array $f): bool =>
                array_intersect($numbers[$id] ?? [], $f['tokens']) !== []));
            if (count($named) === 1) {
                $proposals[] = ['reason' => 'advance_doc_no', 'advance' => $a, 'final' => $named[0],
                    'amount' => round(min($a['remaining'], $named[0]['unassigned']), 2)];
                continue;
            }
            if ($named !== []) continue;
            $exact = array_values(array_filter($finals, static fn (array $f): bool =>
                abs($f['unassigned'] - $a['remaining']) < self::TOLERANCE));
            $exactAfter = array_values(array_filter($exact, static fn (array $f): bool => $f['issue_date'] >= $a['issue_date']));
            if (count($exact) === 1 && count($exactAfter) === 1) {
                $proposals[] = ['reason' => 'advance_amount', 'advance' => $a, 'final' => $exactAfter[0], 'amount' => $a['remaining']];
                continue;
            }
            // Pravidelné zálohy (energie, nájem): stejnou částku čerpá víc faktur, jistá je
            // ale ta, která jako jediná leží mezi touto a další zálohou partnera.
            $next = $nextDate[$id] ?? null;
            if ($next === $a['issue_date']) continue;
            $window = array_values(array_filter($finals, static fn (array $f): bool =>
                $f['issue_date'] >= $a['issue_date'] && ($next === null || $f['issue_date'] < $next)));
            if (count($window) === 1 && abs($window[0]['unassigned'] - $a['remaining']) < self::TOLERANCE) {
                $proposals[] = ['reason' => 'advance_sequence', 'advance' => $a, 'final' => $window[0], 'amount' => $a['remaining']];
            }
        }

        $claimed = [];
        foreach ($proposals as $p) {
            $claimed[$p['final']['doc_id']] = ($claimed[$p['final']['doc_id']] ?? 0.0) + $p['amount'];
        }
        $account = ['id' => (int) $block['account']['id'], 'code' => (string) $block['account']['code']];
        $items = [];
        foreach ($proposals as $p) {
            $f = $p['final'];
            if ($claimed[$f['doc_id']] > $f['unassigned'] + self::TOLERANCE) continue;
            $items[] = [
                'type'         => 'advance',
                'key'          => 'adv:' . $docType . ':' . $p['advance']['doc_id'] . ':' . $f['doc_id'],
                'reason'       => $p['reason'],
                'account'      => $account,
                'partner_name' => $p['advance']['partner_name'],
                'advance'      => [
                    'doc_type'  => $docType,
                    'doc_id'    => $p['advance']['doc_id'],
                    'doc_no'    => $p['advance']['doc_no'],
                    'remaining' => $p['advance']['remaining'],
                ],
                'final'        => [
                    'doc_type'   => $docType,
                    'doc_id'     => $f['doc_id'],
                    'doc_no'     => $f['doc_no'],
                    'posted_on'  => $f['posted_on'],
                    'unassigned' => $f['unassigned'],
                ],
                'amount'       => $p['amount'],
            ];
        }
        return $items;
    }

    /**
     * Nejpozdější den, od kterého platí zapsané čerpání záloh (podle zaúčtování jejich
     * konečných faktur kdykoli, i dopředu).
     *
     * @param list<int> $advanceIds
     * @return array<int,string>
     */
    private function latestDeductionDates(int $supplierId, string $docType, array $advanceIds): array
    {
        $rows = $this->repo->deductionsOfAdvances($supplierId, $docType, $advanceIds);
        $finalIds = [];
        foreach ($rows as $list) {
            foreach ($list as $r) {
                $finalIds[] = $r['final_id'];
            }
        }
        $posted = $this->repo->postedEntries($supplierId, $docType, $finalIds);
        $out = [];
        foreach ($rows as $advanceId => $list) {
            foreach ($list as $r) {
                if (isset($posted[$r['final_id']])) {
                    $out[$advanceId] = max($out[$advanceId] ?? '', $posted[$r['final_id']]['entry_date']);
                }
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $final
     * @param array<string,mixed> $advance
     * @return array<string,mixed>
     */
    private function candidate(array $final, array $advance, float $remaining): array
    {
        $tokens = self::tokens($final['texts']);
        $numbers = array_map([self::class, 'token'], $advance['numbers']);
        $issued = (string) $final['issue_date'];
        return [
            'doc_type'      => $advance['doc_type'],
            'doc_id'        => $final['doc_id'],
            'doc_no'        => $final['doc_no'],
            'issue_date'    => $issued,
            'posted_on'     => (string) $final['posted_on'],
            'deductible'    => $final['deductible'],
            'deducted'      => $final['deducted'],
            'linked'        => $final['linked'],
            'unassigned'    => $final['unassigned'],
            'amount_match'  => abs($final['unassigned'] - $remaining) < self::TOLERANCE,
            'doc_match'     => array_intersect($numbers, $tokens) !== [],
            'after_advance' => $issued >= $advance['issue_date'],
            'distance'      => (int) (new \DateTimeImmutable($issued))->diff(new \DateTimeImmutable($advance['issue_date']))->days,
        ];
    }

    /**
     * Zbytek zálohy (Kč, na normální straně účtu) podle saldokonta k `$asOf`. NULL = záloha
     * nemá na účtu otevřenou položku, kterou čerpání snižuje.
     *
     * @param array<string,mixed> $advance
     * @param array{id:int, code:string, account_type:string, normal_side:?string} $account
     */
    private function remaining(int $supplierId, array $advance, array $account, string $asOf): ?float
    {
        $normal = $account['normal_side'] ?? (in_array($account['account_type'], ['asset', 'expense'], true) ? 'debit' : 'credit');
        $remaining = null;
        foreach ($this->saldo->openItems($supplierId, $account['id'], $asOf, $account['code'], null, $advance['partner_id']) as $it) {
            if ($it['doc_type'] !== $advance['doc_type'] || (int) $it['doc_id'] !== $advance['doc_id']
                || empty($it['settleable']) || ($it['kind'] ?? 'document') !== 'document') {
                continue;
            }
            $booked = $normal === 'debit' ? (float) $it['booked_signed'] : -(float) $it['booked_signed'];
            $remaining = round(($remaining ?? 0.0) + SaldoRepository::settlementAmounts($booked, (float) $it['paid_ratio'])['remaining'], 2);
        }
        return $remaining;
    }

    /** @return array{id:int, code:string, name:string, account_type:string, normal_side:?string} */
    private function account(int $supplierId, string $code): array
    {
        self::docTypeForAccount($code);
        return $this->saldo->resolveAccount($supplierId, $code)
            ?? throw new JournalSettlementException('account_not_found', 'Účet v osnově firmy neexistuje.', 404);
    }

    /**
     * @param list<mixed> $input
     * @return array{0:string, 1:list<array{advance_id:int, final_id:int, amount:float}>}
     */
    private function normalize(array $input): array
    {
        if ($input === [] || !array_is_list($input) || count($input) > self::MAX_ROWS) {
            throw new JournalSettlementException('invalid_deductions', 'Zadejte 1 až ' . self::MAX_ROWS . ' řádků čerpání.');
        }
        $docType = null;
        $rows = [];
        foreach ($input as $row) {
            if (!is_array($row)) {
                throw new JournalSettlementException('invalid_deductions', 'Neplatný řádek čerpání.');
            }
            $type = (string) ($row['doc_type'] ?? '');
            self::accountForDocType($type);
            if ($docType !== null && $docType !== $type) {
                throw new JournalSettlementException('invalid_deductions', 'Čerpání v jedné skupině musí být na jednom zálohovém účtu.');
            }
            $docType = $type;
            $advanceId = filter_var($row['advance_id'] ?? null, FILTER_VALIDATE_INT);
            $finalId = filter_var($row['final_id'] ?? null, FILTER_VALIDATE_INT);
            if ($advanceId === false || $advanceId <= 0 || $finalId === false || $finalId <= 0) {
                throw new JournalSettlementException('invalid_deductions', 'Každý řádek čerpání potřebuje zálohu a konečnou fakturu.');
            }
            if ($advanceId === $finalId) {
                throw new JournalSettlementException('invalid_deductions', 'Záloha nemůže čerpat sama sebe.');
            }
            $amount = filter_var($row['amount'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($amount === false || !is_finite((float) $amount) || $amount <= 0
                || $amount > 999999999999.99 || abs(round((float) $amount, 2) - (float) $amount) > 1e-9) {
                throw new JournalSettlementException('invalid_amount', 'Částka musí být kladná a zadaná na haléře.');
            }
            $rows[] = ['advance_id' => $advanceId, 'final_id' => $finalId, 'amount' => round((float) $amount, 2)];
        }
        return [(string) $docType, $rows];
    }

    /**
     * @param list<string> $texts
     * @return list<string>
     */
    private static function tokens(array $texts): array
    {
        $out = [];
        foreach ($texts as $text) {
            foreach (preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                $out[self::token($word)] = true;
            }
        }
        return array_map('strval', array_keys($out));
    }

    private static function token(string $value): string
    {
        return mb_strtoupper(preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? '');
    }

    private static function money(float $amount): string
    {
        return number_format($amount, 2, ',', ' ');
    }
}
