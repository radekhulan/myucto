<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Closing;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\AccountingPeriodProvisioner;
use MyInvoice\Service\Accounting\Closing\DocumentSeriesService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\UnbalancedEntryException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Dvě nohy převodů mezi peněžními účty přes 261 — Peníze na cestě (Epic F4, R14).
 *
 *   POST /api/accounting/journal/transfer — účetní|admin
 *
 * Body {date_out, date_in, amount, account_from, account_to, description?} →
 * DVA manual zápisy v jedné transakci: noha 1 k date_out MD 261 / D account_from,
 * noha 2 k date_in MD account_to / D 261. Obě nohy sdílejí číslo dokladu z řady
 * `transfer` (sufix /1, /2). Účet 261 z kontace transfer.money_in_transit.
 * Storno se dělá standardně po jednotlivých nohách (POST /journal/{id}/reverse).
 */
final class JournalTransferAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly PostingService $posting,
        private readonly DocumentSeriesService $series,
        private readonly PostingRuleRepository $rules,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly AccountingPeriodRepository $periods,
        private readonly JournalEntryRepository $journal,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly LoggerInterface $log,
    ) {}

    public function transfer(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);

        $dateOut = trim((string) ($body['date_out'] ?? ''));
        $dateIn = trim((string) ($body['date_in'] ?? ''));
        if (!$this->isDate($dateOut) || !$this->isDate($dateIn)) {
            return Json::error($response, 'validation_failed', 'date_out a date_in musí být data (YYYY-MM-DD).', 422);
        }
        if ($dateOut > $dateIn) {
            return Json::error($response, 'validation_failed', 'date_out nesmí být po date_in.', 422);
        }

        $amount = (float) ($body['amount'] ?? 0);
        if (!is_numeric($body['amount'] ?? null) || $amount <= 0) {
            return Json::error($response, 'validation_failed', 'amount musí být kladné číslo.', 422);
        }
        $amount = round($amount, 2);

        $accountFrom = trim((string) ($body['account_from'] ?? ''));
        $accountTo = trim((string) ($body['account_to'] ?? ''));
        if ($accountFrom === '' || $accountTo === '') {
            return Json::error($response, 'validation_failed', 'account_from a account_to jsou povinné.', 422);
        }
        if ($accountFrom === $accountTo) {
            return Json::error($response, 'validation_failed', 'account_from a account_to nesmí být shodné.', 422);
        }
        foreach (['account_from' => $accountFrom, 'account_to' => $accountTo] as $field => $code) {
            if ($this->accounts->findByCode($supplierId, $code) === null) {
                return Json::error($response, 'validation_failed', "{$field}: účet '{$code}' v osnově neexistuje.", 422);
            }
        }

        $description = $this->nullableString($body['description'] ?? null)
            ?? "Převod mezi účty {$accountFrom} → {$accountTo} (261)";

        // Řada `transfer` se čísluje podle roku odchozí nohy (R14). Chybí-li období,
        // doplní ho tentýž provisioner jako u účtování dokladu — jinak by převod končil
        // na `no_accounting_period` DŘÍV, než se vůbec dostane k PostingService, který
        // by si období otevřel sám. Uzavřeného období se to nedotkne (vrátí ho beze změny).
        $period = (new AccountingPeriodProvisioner($this->db, $this->periods, $this->logger))
            ->ensureOpenPeriodForDate(
                $supplierId,
                $dateOut,
                AccountingPeriodProvisioner::REASON_POSTING,
                $this->userId($request),
            );
        if ($period === null) {
            return Json::error($response, 'no_accounting_period', "Pro datum {$dateOut} neexistuje účetní období.", 422,
                ['fiscal_year' => (int) substr($dateOut, 0, 4)]);
        }
        $fiscalYear = (int) $period['fiscal_year'];

        // Kontace Peníze na cestě (seed 1015); fallback 261.
        $rule = $this->rules->resolve($supplierId, 'transfer.money_in_transit');
        $transit = trim((string) ($rule['debit_account_code'] ?? ''));
        if ($transit === '') {
            $transit = '261';
        }

        if (($body['force'] ?? false) !== true) {
            $candidates = $this->findBankCandidates($supplierId, $amount, $dateOut, $dateIn);
            if ($candidates !== []) {
                return Json::error(
                    $response,
                    'bank_transfer_candidates',
                    'K převodu existují bankovní transakce — zaúčtujte je z výpisu, ať se převod nezdvojí.',
                    409,
                    ['data' => ['candidates' => $candidates]],
                );
            }
        }

        $meta = $this->auditMeta($request);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            // Výdej čísla drží FOR UPDATE zámek do commitu — obě nohy + řada atomicky.
            $documentNo = $this->series->next($supplierId, 'transfer', $fiscalYear);

            $outId = $this->posting->postDocument($supplierId, 'manual', null, [
                ['account_code' => $transit,     'side' => 'debit',  'amount' => $amount],
                ['account_code' => $accountFrom, 'side' => 'credit', 'amount' => $amount],
            ], array_merge($meta, [
                'entry_date'  => $dateOut,
                'document_no' => $documentNo . '/1',
                'description' => $description,
                'posted'      => true,
            ]));

            $inId = $this->posting->postDocument($supplierId, 'manual', null, [
                ['account_code' => $accountTo, 'side' => 'debit',  'amount' => $amount],
                ['account_code' => $transit,   'side' => 'credit', 'amount' => $amount],
            ], array_merge($meta, [
                'entry_date'  => $dateIn,
                'document_no' => $documentNo . '/2',
                'description' => $description,
                'posted'      => true,
            ]));

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof UnbalancedEntryException || $e instanceof PostingException) {
                return $this->mapPostingError($response, $e);
            }
            $this->log->error('Převod mezi účty (261) selhal: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'operation_failed', 'Převod se nepodařilo zaúčtovat.', 500);
        }

        $this->logger->log('accounting.journal_transfer', $this->userId($request), 'journal_entry', $outId, [
            'document_no'  => $documentNo,
            'amount'       => $amount,
            'account_from' => $accountFrom,
            'account_to'   => $accountTo,
            'transit'      => $transit,
            'entries'      => [$outId, $inId],
        ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $supplierId);

        return Json::ok($response, [
            'document_no' => $documentNo,
            'entries'     => [
                $this->journal->find($outId, $supplierId),
                $this->journal->find($inId, $supplierId),
            ],
        ], 201);
    }

    private function nullableString(mixed $v): ?string
    {
        if ($v === null) return null;
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    private function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }

    /**
     * Bankovní pohyby, které vypadají jako už zaúčtovaný převod (varování 409).
     *
     * Vlastnictví výpisu rozhoduje VÝHRADNĚ {@see BankStatementOwnershipResolver}
     * — tenant predikát je součástí SQL, takže dotaz cizí pohyby vůbec nenačte.
     * Dřívější varianta filtrovala až v PHP podle shody čísla účtu, čili `WHERE`
     * sypalo přes celou instalaci a to, co prošlo aproximaci, končilo v těle 409
     * (cross-tenant únik čísel pohybů, částek a dat).
     *
     * @return list<array{tx_id:int,statement_id:int,posted_at:string,amount:float,direction:string}>
     */
    private function findBankCandidates(int $supplierId, float $amount, string $dateOut, string $dateIn): array
    {
        $from = (new \DateTimeImmutable($dateOut))->modify('-3 days')->format('Y-m-d');
        $to = (new \DateTimeImmutable($dateIn))->modify('+3 days')->format('Y-m-d');
        $stmt = $this->db->pdo()->prepare(
            'SELECT bt.id, bt.statement_id, bt.posted_at, bt.amount
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
          LEFT JOIN journal_entries je ON je.supplier_id = ? AND je.source_type = "bank"
                                      AND je.source_id = bt.id AND je.reversed_by IS NULL
              WHERE ' . BankStatementOwnershipResolver::sql('bs') . '
                AND ABS(bt.amount) = ? AND bt.posted_at BETWEEN ? AND ?
                AND bt.match_status <> "ignored" AND je.id IS NULL
              ORDER BY bt.posted_at, bt.id
              LIMIT 10'
        );
        $stmt->execute(array_merge(
            [$supplierId],
            BankStatementOwnershipResolver::params($supplierId),
            [$amount, $from, $to],
        ));
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'tx_id' => (int) $row['id'],
                'statement_id' => (int) $row['statement_id'],
                'posted_at' => (string) $row['posted_at'],
                'amount' => (float) $row['amount'],
                'direction' => (float) $row['amount'] < 0 ? 'out' : 'in',
            ];
        }
        return $out;
    }
}
