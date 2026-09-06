<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\ActivityLogger;

/**
 * Přeúčtování zaúčtovaného dokladu — opravená kontace místo té, která v deníku už je.
 *
 * Vzniklo proto, že oprava chybné kontace se dosud musela poskládat z několika kroků
 * v účetním deníku (najdi zápis → smaž nebo stornuj → vrať se na doklad → zaúčtuj
 * znovu), a účetní přitom musela sama uhodnout, KTERÝ z těch dvou postupů období
 * dovolí. Špatná volba skončila chybou až v posledním kroku, kdy už byl původní zápis
 * pryč nebo stornovaný.
 *
 * Vlastní zápis dělá VÝHRADNĚ {@see PostingService} (podvojnost, otevřenost období,
 * zámek k datu, idempotence) a storno {@see PostingService::reverse()} — tahle služba
 * jen ROZHODUJE, který z těch dvou mechanismů se použije, a to rozhodnutí umí vrátit
 * dopředu jako {@see plan()}, aby ho uživatel viděl PŘED potvrzením:
 *
 *   - **otevřené a nezamčené období** → `replace`: existující zápis se přepíše na místě
 *     (postDocument → rewriteExisting: staré řádky se smažou, zapíšou se nové). Číslo
 *     zápisu i datum zůstávají, takže odkazy na doklad drží.
 *   - **zamčené / uzavřené období, nebo už stornovaný zápis** → `reverse`: původní
 *     zápis se NEMAŽE, stornuje se protizápisem a opravená kontace jde novým zápisem.
 *     Obojí zůstává v deníku kvůli auditu (§35 ZoÚ).
 *   - **není kam zapsat** (dnešek je taky zamčený, období je uzavřené) → `blocked`:
 *     operace se odmítne s důvodem. Datum se nikdy neposouvá potichu.
 *
 * DPH se tím NEMĚNÍ: {@see \MyInvoice\Service\Report\VatLedgerService} evidenci
 * odvozuje z řádků dokladu, ne z deníku, takže přeúčtování kontace do ní nesahá
 * (a sahat nesmí — daňový režim se opravuje editací dokladu, ne kontace).
 */
final class DocumentRepostService
{
    public const STRATEGY_REPLACE = 'replace';
    public const STRATEGY_REVERSE = 'reverse';
    public const STRATEGY_BLOCKED = 'blocked';

    /** Zdroje, které přeúčtování umí. Mzdy schválně chybí — {@see PostingService::reverse()}. */
    public const SOURCES = ['invoice', 'purchase_invoice', 'bank'];

    public function __construct(
        private readonly Connection $db,
        private readonly PostingService $posting,
        private readonly JournalEntryRepository $journal,
        private readonly AccountingPeriodRepository $periods,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly ActivityLogger $activity,
        private readonly BankPostingService $bankPosting,
    ) {}

    /**
     * Co se s dokladem stane, kdyby se přeúčtoval teď. Čte, nezapisuje — dialog z toho
     * staví hlášku a předvyplní řádky.
     *
     * @param 'invoice'|'purchase_invoice'|'bank' $sourceType
     *
     * @return array{entry_id:int, entry_date:string, document_no:?string, description:?string,
     *               period_status:?string, locked_until:?string, strategy:string,
     *               needs_reversal:bool, target_date:?string, date_shifted:bool,
     *               reason_code:?string, already_reversed:bool,
     *               lines:list<array{account_code:?string, account_name:?string, side:string, amount:float}>}
     *
     * @throws PostingException doklad není zaúčtovaný
     */
    public function plan(int $supplierId, string $sourceType, int $docId): array
    {
        $entry = $this->journal->findBySource($supplierId, $sourceType, $docId);
        if ($entry === null) {
            throw new PostingException(
                'entry_not_found',
                'Doklad zatím není zaúčtovaný — přeúčtovat lze jen doklad, který v deníku zápis má.',
                409,
            );
        }

        $entryId = (int) $entry['id'];
        $entryDate = (string) $entry['entry_date'];
        $alreadyReversed = $entry['reversed_by'] !== null;
        $period = $this->periods->findById($supplierId, (int) $entry['period_id']);
        $periodStatus = $period === null ? null : (string) $period['status'];
        $lockedUntil = $this->lockedUntil($supplierId);

        $plan = [
            'entry_id'         => $entryId,
            'entry_date'       => $entryDate,
            'document_no'      => $entry['document_no'] === null ? null : (string) $entry['document_no'],
            'description'      => $entry['description'] === null ? null : (string) $entry['description'],
            'period_status'    => $periodStatus,
            'locked_until'     => $lockedUntil,
            'already_reversed' => $alreadyReversed,
            'lines'            => $this->describeLines($supplierId, $entryId),
        ];

        $today = date('Y-m-d');
        $todayPeriod = $this->periods->findForDate($supplierId, $today);

        return $plan + self::decide(
            $alreadyReversed,
            $periodStatus,
            $entryDate,
            $lockedUntil,
            $todayPeriod === null ? null : (string) $todayPeriod['status'],
            $today,
        );
    }

    /**
     * ROZHODNUTÍ samo — čistá funkce nad stavem, bez DB.
     *
     * Je oddělené schválně, a to ze dvou důvodů. Za prvé je to jediné místo, kde se
     * rozhoduje „přepsat × stornovat × odmítnout"; schované uvnitř {@see plan()} by se
     * dalo okopírovat rychleji, než kdyby neexistovalo. Za druhé jde o pravidlo, které
     * se DÁ OVĚŘIT testem bez databáze — a bez testu je zelená u účetní vrstvy bezcenná.
     *
     * @param bool        $alreadyReversed    původní zápis už někdo stornoval
     * @param ?string     $periodStatus       stav období PŮVODNÍHO zápisu (null = období chybí)
     * @param ?string     $todayPeriodStatus  stav období pro dnešek (null = žádné, provisioner ho otevře)
     *
     * @return array{strategy:string, needs_reversal:bool, target_date:?string,
     *               date_shifted:bool, reason_code:?string}
     */
    public static function decide(
        bool $alreadyReversed,
        ?string $periodStatus,
        string $entryDate,
        ?string $lockedUntil,
        ?string $todayPeriodStatus,
        string $today,
    ): array {
        $entryDateLocked = self::locked($entryDate, $lockedUntil);

        // Přepsat na místě lze jen zápis, který ještě nikdo nestornoval a jehož vlastní
        // datum je pořád „živé". Obě podmínky vynucuje i PostingService::rewriteExisting —
        // tady se jen ptáme dopředu, ať uživatel nedostane chybu až po potvrzení.
        if (!$alreadyReversed && $periodStatus === 'open' && !$entryDateLocked) {
            return [
                'strategy'       => self::STRATEGY_REPLACE,
                'needs_reversal' => false,
                'target_date'    => $entryDate,
                'date_shifted'   => false,
                'reason_code'    => null,
            ];
        }

        $reasonCode = $alreadyReversed
            ? 'entry_reversed'
            : ($periodStatus !== 'open' ? 'period_not_open' : 'date_locked');

        // Datum opravy: pokud do původního data zapsat lze (typicky už stornovaný zápis
        // v otevřeném období), zůstává se na něm — storno i oprava pak sedí na tomtéž
        // účetním případu. Jinak se musí na nejbližší živé datum, a to se uživateli
        // MUSÍ říct; tiché přesunutí na dnešek je přesně ta věc, po které se rozdíl
        // hledá zpětně v deníku. Chybějící období není překážka: PostingService si ho
        // přes AccountingPeriodProvisioner otevře sám (a když nemůže, řekne to naostro).
        $entryDatePostable = !$entryDateLocked && ($periodStatus === null || $periodStatus === 'open');
        if ($entryDatePostable) {
            return [
                'strategy'       => self::STRATEGY_REVERSE,
                'needs_reversal' => !$alreadyReversed,
                'target_date'    => $entryDate,
                'date_shifted'   => false,
                'reason_code'    => $reasonCode,
            ];
        }

        $todayLocked = self::locked($today, $lockedUntil);
        if ($todayLocked || !($todayPeriodStatus === null || $todayPeriodStatus === 'open')) {
            return [
                'strategy'       => self::STRATEGY_BLOCKED,
                'needs_reversal' => !$alreadyReversed,
                'target_date'    => null,
                'date_shifted'   => false,
                'reason_code'    => $todayLocked ? 'date_locked' : 'period_not_open',
            ];
        }

        return [
            'strategy'       => self::STRATEGY_REVERSE,
            'needs_reversal' => !$alreadyReversed,
            'target_date'    => $today,
            'date_shifted'   => $today !== $entryDate,
            'reason_code'    => $reasonCode,
        ];
    }

    /**
     * Provede přeúčtování. Storno i nový zápis běží v JEDNÉ transakci — půlka operace
     * by nechala doklad buď dvakrát zaúčtovaný, nebo bez zápisu vůbec.
     *
     * U zdroje `bank` projdou řádky NAVÍC bankovními invarianty
     * ({@see BankPostingService::prepareRepostLines()}): pohyb na 221 musí sedět na
     * částku výpisu a bankovní noha jde na analytiku vlastního účtu. Bez toho by
     * obecné přeúčtování bylo dírou vedle ručního zaúčtování, kde tytéž kontroly
     * platí — a přesně takhle vzniká drift mezi dvěma cestami k témuž zápisu.
     *
     * Storno ani idempotence banky se tím nemění: zápis vzniká přes
     * {@see PostingService::postDocument()} se zdrojem ('bank', txId), takže platí týž
     * unikát nad AKTIVNÍM zápisem a `findBySource` (ORDER BY id DESC) vrátí i po
     * variantě `reverse` nový živý zápis — `unpost()` tedy stornuje ten správný.
     * Návrhy v `bank_posting_suggestions` se schválně NEPŘEPISUJÍ: ukazují na zápis,
     * který automatika opravdu vytvořila, a přeúčtování je ruční zásah účetní
     * ({@see PostingOriginService} ho z activity_logu pozná a provenienci přebije).
     *
     * @param 'invoice'|'purchase_invoice'|'bank' $sourceType
     * @param list<array{account_code:string, side:'debit'|'credit', amount:float}> $lines
     * @param array<string,mixed> $meta audit kontext (user_id, ip, user_agent) + description
     *
     * @return array{strategy:string, entry_id:int, reversal_entry_id:?int, entry_date:string, date_shifted:bool}
     *
     * @throws PostingException nelze přeúčtovat (blocked / neodsouhlasený posun data)
     */
    public function repost(
        int $supplierId,
        string $sourceType,
        int $docId,
        array $lines,
        array $meta,
        bool $confirmDateShift = false,
    ): array {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            // Bankovní invarianty se ověřují UVNITŘ transakce a PŘED stornem: jinak by
            // vadné řádky shodily operaci až po zrušení původního zápisu.
            if ($sourceType === 'bank') {
                $lines = $this->bankPosting->prepareRepostLines($supplierId, $docId, $lines);
            }

            // Plán se počítá ZNOVU uvnitř transakce: mezi náhledem a potvrzením mohla
            // proběhnout uzávěrka nebo cizí storno a rozhodnutí by se rozešlo se stavem.
            $plan = $this->plan($supplierId, $sourceType, $docId);

            if ($plan['strategy'] === self::STRATEGY_BLOCKED) {
                throw new PostingException(
                    $plan['reason_code'] === 'date_locked' ? 'date_locked' : 'period_not_open',
                    $plan['reason_code'] === 'date_locked'
                        ? 'Účetnictví je zamčené k ' . (string) $plan['locked_until']
                            . ' včetně dneška — opravu není kam zapsat. Posuň zámek v nastavení účetnictví (jen admin).'
                        : 'Zápis je v období „' . (string) $plan['period_status']
                            . '" a pro dnešek není otevřené účetní období — opravu není kam zapsat.',
                    409,
                );
            }
            if ($plan['date_shifted'] && !$confirmDateShift) {
                throw new PostingException(
                    'repost_date_shift_confirmation_required',
                    'Původní datum ' . $plan['entry_date'] . ' je uzavřené nebo zamčené, takže storno i oprava '
                        . 'padnou na ' . (string) $plan['target_date'] . '. Potvrď posun data.',
                    409,
                    ['entry_date' => $plan['entry_date'], 'target_date' => $plan['target_date']],
                );
            }

            $targetDate = (string) $plan['target_date'];
            $reversalId = null;
            if ($plan['strategy'] === self::STRATEGY_REVERSE && $plan['needs_reversal']) {
                $reversalId = $this->posting->reverse($supplierId, (int) $plan['entry_id'], $meta + [
                    'entry_date'  => $targetDate,
                    'description' => 'Storno před přeúčtováním dokladu (zápis #' . $plan['entry_id'] . ')',
                ]);
            }

            $entryId = $this->posting->postDocument($supplierId, $sourceType, $docId, $lines, array_merge($meta, [
                'entry_date' => $targetDate,
            ]));

            $this->activity->log(
                'accounting.reposted',
                $meta['user_id'] ?? null,
                'journal_entry',
                $entryId,
                [
                    'source_type'        => $sourceType,
                    'source_id'          => $docId,
                    'strategy'           => $plan['strategy'],
                    'original_entry_id'  => $plan['entry_id'],
                    'reversal_entry_id'  => $reversalId,
                    'original_entry_date' => $plan['entry_date'],
                    'entry_date'         => $targetDate,
                    'before'             => $plan['lines'],
                    'after'              => $lines,
                ],
                $meta['ip'] ?? null,
                $meta['user_agent'] ?? null,
                $supplierId,
            );

            if ($ownTx) {
                $pdo->commit();
            }

            return [
                'strategy'          => (string) $plan['strategy'],
                'entry_id'          => $entryId,
                'reversal_entry_id' => $reversalId,
                'entry_date'        => $targetDate,
                'date_shifted'      => (bool) $plan['date_shifted'],
            ];
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Zámek účetnictví k datu (§35 soft-close) — zamčené je datum <= locked_until. */
    private static function locked(string $date, ?string $lockedUntil): bool
    {
        return $lockedUntil !== null && $date <= $lockedUntil;
    }

    private function lockedUntil(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? null : (string) $value;
    }

    /**
     * @return list<array{account_code:?string, account_name:?string, side:string, amount:float}>
     */
    private function describeLines(int $supplierId, int $entryId): array
    {
        $map = $this->accounts->idToAccountMap($supplierId);
        $out = [];
        foreach ($this->journal->linesForEntry($entryId, $supplierId) as $line) {
            $acc = $map[(int) $line['account_id']] ?? null;
            $out[] = [
                'account_code' => isset($acc['code']) ? (string) $acc['code'] : null,
                'account_name' => isset($acc['name']) ? (string) $acc['name'] : null,
                'side'         => (string) $line['side'],
                'amount'       => (float) $line['amount'],
            ];
        }
        return $out;
    }
}
