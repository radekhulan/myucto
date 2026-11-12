<?php

declare(strict_types=1);

namespace MyInvoice\Service\Nonprofit\Membership;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\JournalLinePairingRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipBalanceRepository;
use MyInvoice\Repository\Nonprofit\Membership\MembershipBankMatchRepository;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Accounting\Dimension\DimensionDefaults;
use MyInvoice\Service\Accounting\OtherItemBankMatchService;
use MyInvoice\Service\Accounting\OtherItemPaymentCandidateRanker;
use MyInvoice\Service\Accounting\OtherItemPostingResolver;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Bank\BankTransactionContext;
use MyInvoice\Service\Bank\OtherItemPaymentMatchReader;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;

final class MembershipBankMatchService
{
    private readonly MembershipBankMatchRepository $repository;
    private readonly MembershipBalanceRepository $balances;
    private readonly JournalLinePairingRepository $pairings;
    private readonly MembershipReceivablePaymentAdapter $adapter;

    public function __construct(private readonly Connection $db,
        private readonly OtherItemBankMatchService $bankMatch,
        private readonly BankPostingService $posting,
        private readonly ActivityLogger $logger,
        private readonly PostingService $journal)
    {
        $this->repository = new MembershipBankMatchRepository($db);
        $this->balances = new MembershipBalanceRepository($db);
        $this->pairings = new JournalLinePairingRepository($db);
        $this->adapter = new MembershipReceivablePaymentAdapter($this->balances);
    }

    public function candidates(int $supplier, int $transaction, string $cursor = '0', int $limit = 50, string $search = ''): array
    {
        $limit = max(1, min(50, $limit));
        $tx = $this->payment($supplier, $transaction, false);
        if ((OtherItemPaymentMatchReader::membershipByTransactions($this->db->pdo(), $supplier, [$transaction])[$transaction] ?? []) !== []) {
            return ['candidates' => [], 'next_cursor' => null];
        }
        $symbol = VariableSymbolNormalizer::matchKey((string) ($tx['variable_symbol'] ?? ''));
        $cents = (int) $tx['amount_cents'];
        // Pozice patří jedné fázi stránkování; cizí fáze by pokračovala od jejího ID a přeskočila novější předpisy.
        $phase = $cursor === '0' ? null : strstr($cursor, ':', true);
        if ($phase !== null && ($search !== '' ? $phase !== 'search' : ($phase === 'search' || ($phase === 'vs' && $symbol === '')))) {
            throw new MembershipException('nonprofit_membership_invalid', 'Pozice stránkování nepatří k tomuto hledání.', 422);
        }
        ['rows' => $rows, 'next_cursor' => $next] = $this->repository->candidates($supplier, $symbol, $cents, $cursor, $limit, $search);
        // Pořadí a důvody shody jako u ostatní položky (#275): VS, zbývající částka a účet protistrany.
        // Protistrana je jen pomocná (platí často rodič či zaměstnavatel), kandidáty vybírá VS a částka;
        // řadí se v rámci stránky, stránky jdou dál po fázích VS → částka.
        // VS jako klíč shody (VariableSymbolNormalizer), stejně jako výběr kandidátů ve fázi VS.
        $payment = ['amount' => $cents / 100, 'variable_symbol' => $symbol !== '' ? $symbol : null,
            'counterparty_account' => $tx['counterparty_account'] ?? null, 'counterparty_bank' => $tx['counterparty_bank'] ?? null,
            'payment_on' => null];
        $accounts = $this->repository->clientAccounts($supplier, array_map(static fn (array $r): int => (int) $r['client_id'], $rows));
        $candidates = [];
        foreach ($rows as $r) {
            $best = null;
            foreach ($accounts[(int) $r['client_id']] ?? [['account_number' => null, 'bank_code' => null]] as $account) {
                $match = OtherItemPaymentCandidateRanker::evaluate($payment, [
                    'variable_symbol' => $r['variable_symbol'], 'counterparty_account' => $account['account_number'],
                    // Celý zbytek jen při haléřové shodě: tolerance rankeru (0,01) by nabídla předpis,
                    // který párování odmítne jako přeplatek.
                    'counterparty_bank_code' => $account['bank_code'],
                    'remaining_amount' => (int) $r['open_cents'] === $cents ? $cents / 100 : INF,
                    'installments' => [],
                ]);
                if ($best === null || $match['score'] > $best['score']) $best = $match;
            }
            $candidates[] = [
                'id' => (int) $r['claim_id'], 'member_id' => (int) $r['member_id'], 'ref' => $r['title_key'],
                'party' => $r['member_name'], 'amount' => (int) $r['open_cents'] / 100,
                'amount_cents' => (string) $r['open_cents'], 'currency' => 'CZK', 'variable_symbol' => $r['variable_symbol'],
                'period_from' => $r['period_from'], 'period_to' => $r['period_to'],
                'match_score' => $best['score'], 'match_reasons' => $best['reasons'],
            ];
        }
        usort($candidates, static fn (array $a, array $b): int => $b['match_score'] <=> $a['match_score']);
        return ['candidates' => $candidates, 'next_cursor' => $next];
    }

    public function match(int $supplier, int $transaction, array $claimIds, int $actor, array $meta, bool $mayPost): array
    {
        $claimIds = self::claimIds($claimIds);
        $pdo = $this->db->pdo();
        return TransactionFailure::retrying($pdo, function () use ($pdo, $supplier, $transaction, $claimIds, $actor, $meta, $mayPost): array {
            $own = !$pdo->inTransaction();
            if ($own) $pdo->beginTransaction();
            try {
                $claims = $this->repository->selected($supplier, $claimIds);
                if (count($claims) !== count($claimIds)) {
                    throw new MembershipException(
                        'nonprofit_membership_claim_not_payable',
                        'Vybraný předpis chybí, byl stornován nebo nemá platný účetní zápis.',
                        409,
                    );
                }
                $tx = $this->payment($supplier, $transaction, true);
                $matched = OtherItemPaymentMatchReader::membershipByTransactions($pdo, $supplier, [$transaction])[$transaction] ?? [];
                if ($matched !== []) {
                    $previous = array_column($matched, 'claim_id');
                    sort($previous, SORT_NUMERIC);
                    if ($previous !== $claimIds) {
                        throw new MembershipException(
                            'nonprofit_membership_payment_already_matched',
                            'Tento pohyb už je přiřazen jiné množině předpisů.',
                            409,
                        );
                    }
                    $evidence = $this->balances->pairingEvidence($supplier, $claims);
                    foreach ($claims as $claim) {
                        $p = $evidence[$claim['claim_id']] ?? null;
                        if ($p === null || $p['invalid_count'] !== 0 || $p['pairing_id'] !== $matched[0]['pairing_id']) {
                            throw new MembershipException(
                                'nonprofit_membership_payment_ambiguous',
                                'Původní párování již nemá platný nativní důkaz.',
                                409,
                            );
                        }
                    }
                    if ($own) $pdo->commit();
                    return ['bank_transaction_id' => $transaction, 'claim_ids' => $claimIds,
                        'pairing_id' => $matched[0]['pairing_id'], 'entry_id' => $matched[0]['entry_id']];
                }
                $paymentCents = (int) $tx['amount_cents'];
                $evidence = $this->balances->pairingEvidence($supplier, $claims);
                $targets = [];
                $open = 0;
                $account = null;
                foreach ($claims as $claim) {
                    $target = $this->adapter->targetFromClaim($supplier, $claim);
                    $id = (int) $claim['claim_id'];
                    $pair = $evidence[$id] ?? null;
                    if ($pair !== null && ($pair['invalid_count'] !== 0 || $pair['membership_claim_count'] !== 1
                        || $pair['net_cents'] <= 0 || $pair['net_cents'] > (int) $claim['amount_cents'])) {
                        throw new MembershipException(
                            'nonprofit_membership_payment_ambiguous',
                            'Předpis je uhrazen nebo má nejednoznačné párování. Spárujte úhrady zvlášť.',
                            409,
                        );
                    }
                    if ($account !== null && $account !== $target['journal_line']['account_id']) {
                        throw new MembershipException(
                            'nonprofit_membership_payment_accounts_differ',
                            'Jednou platbou lze v tomto dialogu uhradit pouze předpisy se stejným účtem pohledávky.',
                            409,
                        );
                    }
                    $account = $target['journal_line']['account_id'];
                    $claimOpen = $pair['net_cents'] ?? (int) $claim['amount_cents'];
                    if ($claimOpen > PHP_INT_MAX - $open) {
                        throw new MembershipException('nonprofit_membership_payment_amount_mismatch', 'Součet částek je příliš vysoký.', 422);
                    }
                    $open += $claimOpen;
                    $targets[] = $target['journal_line'];
                }
                // Kreditní řádek platby nese dimenze pohledávky (DimensionDefaults::membershipClaims). Jeden řádek
                // vyrovná po dimenzi jen předpisy se stejnými dimenzemi; řádek banky se nerozděluje (#321, bod C).
                $assignments = new DimensionAssignmentRepository($this->db);
                $claimDimensions = $assignments->lineDimensions($supplier, array_map(static fn (array $l): int => (int) $l['line_id'], $targets));
                $first = null;
                foreach ($targets as $line) {
                    $dims = $claimDimensions[(int) $line['line_id']] ?? [];
                    ksort($dims);
                    if ($first === null) {
                        $first = $dims;
                    } elseif ($dims !== $first) {
                        throw new MembershipException(
                            'nonprofit_membership_payment_dimensions_differ',
                            'Předpisy mají rozdílné dimenze. Jednou platbou je nelze uhradit, spárujte je zvlášť.',
                            409,
                        );
                    }
                }
                if ($paymentCents > $open || (count($claims) > 1 && $paymentCents !== $open)) {
                    throw new MembershipException(
                        'nonprofit_membership_payment_amount_mismatch',
                        'Výběr neodpovídá platbě. Vyberte předpisy, jejichž součet přesně odpovídá platbě, nebo jeden předpis, který platba uhradí částečně.',
                        409,
                    );
                }
                $lines = $this->repository->paymentLines($supplier, $transaction);
                if ($lines === []) {
                    if (!$mayPost) throw new MembershipException('posting_forbidden', 'K zaúčtování pohybu chybí oprávnění.', 403);
                    // Předpis nemá vlastní kontaci úhrady ani profil druhu, platí výchozí peněžní účet
                    // ostatních položek; analytiku podle účtu výpisu doplní BankPostingService.
                    // Zaúčtování už s dimenzemi hrazených předpisů (povinné pravidlo na 315, systémové dimenze).
                    DimensionDefaults::withPendingMembershipClaims($supplier, $transaction, $claimIds,
                        fn () => $this->posting->postManual($supplier, $transaction, [
                            'debit_account_code' => OtherItemPostingResolver::DEFAULT_MONEY_ACCOUNT['payment_bank'],
                            'credit_account_code' => $claims[0]['account_code'],
                        ], $meta + ['user_id' => $actor, 'posted_by' => $actor]));
                    $lines = $this->repository->paymentLines($supplier, $transaction);
                }
                $credits = array_values(array_filter($lines, static fn (array $l): bool =>
                    (int) $l['account_id'] === $account && $l['side'] === 'credit'));
                if (count($credits) !== 1 || (int) $credits[0]['cents'] !== $paymentCents
                    || $credits[0]['posted_at'] === null || (int) $credits[0]['is_red_storno'] !== 0
                    || $credits[0]['currency_code'] !== null || $credits[0]['amount_foreign'] !== null || $credits[0]['fx_rate'] !== null) {
                    throw new MembershipException(
                        'nonprofit_membership_payment_posting_mismatch',
                        'Pohyb není zaúčtován jediným korunovým řádkem proti účtu vybraných pohledávek (např. ho pravidlo '
                            . 'zaúčtovalo na jiný účet). Pohyb odúčtujte a spárujte znovu; párování ho zaúčtuje samo.',
                        409,
                    );
                }
                $credit = $credits[0];
                $refs = $this->pairings->lineRefs($supplier, [(int) $credit['line_id']]);
                if (($refs[(int) $credit['line_id']]['pairing_id'] ?? null) !== null) {
                    throw new MembershipException('nonprofit_membership_payment_already_matched', 'Bankovní řádek už patří jinému párování.', 409);
                }
                $existing = array_values(array_unique(array_column($evidence, 'pairing_id')));
                // Jedna dílčí úhrada navazuje na původní okruh; více již načatých okruhů se neslučuje potichu.
                if (count($claims) > 1 && $existing !== []) {
                    throw new MembershipException('nonprofit_membership_payment_ambiguous', 'Již částečně hrazené předpisy spárujte zvlášť.', 409);
                }
                $paymentRef = ['entry_id' => (int) $credit['entry_id'], 'line_no' => (int) $credit['line_no'], 'account_id' => $account];
                // Osamocená vazba blokuje INSERT i u bankovního řádku a při doplatku do existujícího okruhu.
                $this->pairings->dissolveDegenerate($supplier, $this->repository->pairingIdsForLines($supplier,
                    [...array_column($claims, 'receivable_line_id'), (int) $credit['line_id']]));
                try {
                    if ($existing !== []) {
                        $pairing = $existing[0];
                        $this->pairings->addItems($supplier, $pairing, [$paymentRef]);
                    } else {
                        $pairing = $this->pairings->create($supplier, $account, [...$targets, $paymentRef],
                            'Úhrada členských příspěvků z banky', 'manual', $actor);
                    }
                } catch (\PDOException $e) {
                    // Souběžné ruční párování v saldokontu obsadilo řádek mezi kontrolou a zápisem (jako OpenItemsService).
                    // Jen zde: jiný duplicitní klíč (např. při zaúčtování) není obsazené párování.
                    if (($e->errorInfo[1] ?? null) === 1062) {
                        throw new MembershipException(
                            'nonprofit_membership_payment_already_matched',
                            'Pohyb nebo předpis mezitím spároval někdo jiný.',
                            409,
                        );
                    }
                    throw $e;
                }
                // Až s párováním vidí výchozí dimenze pohybu hrazené předpisy. Uvolnění výchozích hodnot
                // z řádku 315 je jen zde: saldokonto členské pohledávky se párováním okruhu vyrovnává po dimenzi
                // předpisu. Párování ostatních položek (OtherItemService) je zatím neuvolňuje; ověření a případná
                // oprava patří do sdílené vrstvy jako samostatný PR, ne do tohoto kroku.
                // Pohyb zaúčtovaný před párováním dostal na 315 výchozí hodnoty (účet, pravidlo) jako pohyb
                // bez dokladu. Ty se uvolní jen na tomto řádku a pravidlo dimenzí je při tomto přerazítkování nevrátí
                // (předpis ho také nenese); jiné přerazítkování spárovaného zápisu odmítne zámek platby. Povinné
                // pravidlo bez výchozí hodnoty pro typ, který předpis nenese, odmítne už zaúčtování výše.
                // Zůstane hodnota zadaná uživatelem; rozpady se neuvolňují (automatika řádku pohledávky rozpad nedává).
                // Typy, které předpis určuje, dostane jako u úhrady faktury celý zápis.
                $restamped = MembershipBankPaymentGuard::restampingMatchedPayment($supplier, (int) $credit['entry_id'],
                    fn (): array => $this->journal->restampDimensions($supplier, 'bank', $transaction, true, [], [(int) $credit['line_id']]));
                if ($restamped['needs_repost']) {
                    throw new MembershipException(
                        'nonprofit_membership_payment_dimension_account',
                        'Dimenze předpisu by přesunuly jiný řádek zaúčtování pohybu na jiný analytický účet. Pohyb odúčtujte '
                            . 'a spárujte znovu; párování ho zaúčtuje jen řádkem úhrady proti účtu pohledávky.',
                        409,
                    );
                }
                // Ruční dimenze pohybu přebíjí dimenze dokladu; pohledávka by pak po dimenzi zůstala
                // nevyrovnaná. Totéž platí pro hodnotu nezjištěného původu (starší zápis): nelze poznat, zda ji nezadal
                // uživatel. Saldokonto nese hodnotu předpisu, proto takové párování odmítneme.
                $paid = $assignments->lineDimensions($supplier, [(int) $credit['line_id']])[(int) $credit['line_id']] ?? [];
                ksort($paid);
                if ($paid !== $first || $assignments->lineSplits($supplier, [(int) $credit['line_id']]) !== []) {
                    throw new MembershipException(
                        'nonprofit_membership_payment_dimensions_own',
                        'Pohyb má na řádku úhrady dimenze odlišné od předpisu zadané ručně nebo ze staršího zaúčtování. '
                            . 'Upravte dimenze pohybu, nebo ho odúčtujte a spárujte znovu, případně spárujte jiný předpis.',
                        409,
                    );
                }
                $this->logger->log('nonprofit_membership.bank_matched', $actor, 'journal_line_pairing', $pairing,
                    ['bank_transaction_id' => $transaction, 'claim_ids' => $claimIds], $meta['ip'] ?? null,
                    $meta['user_agent'] ?? null, $supplier);
                if ($own) $pdo->commit();
                return ['bank_transaction_id' => $transaction, 'claim_ids' => $claimIds,
                    'pairing_id' => $pairing, 'entry_id' => (int) $credit['entry_id']];
            } catch (\Throwable $e) {
                if ($own && $pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        });
    }

    private function payment(int $supplier, int $transaction, bool $lock): array
    {
        // Čtení/lock existující úhrady dovolí replay; všechny ostatní bankovní mutace chrání MembershipBankPaymentGuard.
        if ($lock && (new BankTransactionContext($this->db))->lock($supplier, $transaction) === null) {
            throw new MembershipException('nonprofit_membership_payment_unavailable', 'Bankovní pohyb nebyl nalezen.', 404);
        }
        $tx = $this->bankMatch->freeTransaction($supplier, $transaction, false, true);
        $context = $tx === null ? null : (new BankTransactionContext($this->db))->find($transaction, $lock);
        if ($tx === null || $tx['amount'] <= 0 || $tx['used_amount'] != 0 || $tx['locked_item_ids'] !== []
            || ($context['source'] ?? 'statement') !== 'statement') {
            throw new MembershipException(
                'nonprofit_membership_payment_unavailable',
                'Vyberte volný příchozí korunový pohyb z bankovního výpisu.',
                409,
            );
        }
        return $tx;
    }

    public static function claimIds(array $ids): array
    {
        if (!array_is_list($ids) || $ids === [] || count($ids) > 100
            || array_any($ids, static fn (mixed $id): bool => !is_int($id) || $id <= 0)
            || count(array_unique($ids)) !== count($ids)) {
            throw new MembershipException('nonprofit_membership_invalid', 'Vyberte jeden až 100 různých platných předpisů.', 422);
        }
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
}
