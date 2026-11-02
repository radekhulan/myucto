<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AdvanceDeductionRepository;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Repository\SaldoRepository;
use MyInvoice\Service\Accounting\AdvanceDeductionService;
use MyInvoice\Service\Accounting\DocumentPostingGroup;
use MyInvoice\Service\Accounting\TakenOverRecord;
use PDO;

/**
 * Čerpání poskytnutých záloh (314), které Money vede bez vazby na zálohu.
 *
 * Money vyúčtuje zálohu řádkem konečné faktury na zálohovém účtu („vyúčtované zálohy",
 * „odpočet - vyúčtování plynu", „uhrazené zálohy 12x"), který nese symbol a IČO faktury,
 * ne zálohy. Položka s odkazem na zálohu (`CisloZaFak`) chybí, takže {@see AdvanceLinker}
 * vazbu nepozná a saldokonto drží zálohu i daňový doklad k přijaté platbě (DDKP) otevřené,
 * přestože je hlavní kniha čerpání nese. Saldo Money stojí na partnerovi a účtu: počáteční
 * stavy vede bez párovacího symbolu.
 *
 * Proto se čerpání páruje po partnerovi (IČO) napříč analytikami 314 - Money přeúčtovává
 * zálohu i daň mezi analytikami (platba 314.610, základ DDKP 314.612, vyúčtování jinde):
 *  - zálohou je otevřená položka saldokonta 314 převzatého dokladu (záloha, samostatný DDKP,
 *    záloha placená v konečné faktuře), s otevřenou částkou podle saldokonta - u DDKP tedy
 *    základ, daň čerpá DDKP sám. K dispozici je ode dne první úhrady (záloha placená
 *    fakturou ode dne zápisu, neuhrazená ode dne vystavení),
 *  - čerpáním je kredit převzaté konečné faktury na 314 (bez přeúčtování mezi analytikami
 *    v témž zápisu, stejně jako ve {@see SaldoRepository}), snížený o už evidované čerpání,
 *  - opravný doklad konečné faktury (dobropis se zápornými částkami, tedy debetem 314) její
 *    čerpání vrací: nejdřív se vyrovná s fakturou (čerpání faktura × dobropis) a jako
 *    záloha ani čerpání do párování nevstupuje,
 *  - nejdřív se přiřadí všechna čerpání, jejichž řádek v deníku Money uvádí číslo zálohy,
 *    DDKP (u DDKP zálohy jeho rodič) nebo bankovního dokladu úhrady („Odpočet zálohy
 *    ZF190014 (daň. dokl. DPP190023)"),
 *  - pak chronologicky: zálohy partnera z doby před prvním převáděným rokem (počáteční
 *    stav partnera, nebo - Money ho vede bez IČO - zálohy dopočtené z deníku posledního roku
 *    před převodem, {@see carriedAdvances()}) se spotřebují nejdřív a vazbu nedostanou,
 *    zbytek FIFO na nejstarší otevřené zálohy partnera zaplacené do data čerpání, nejvýš do
 *    jejich otevřené částky. FIFO páruje jen korunovou fakturu s korunovou zálohou.
 *
 * Výsledek je `purchase_advance_deductions`. Deník se nemění. Vazby zadané v MyÚčtu se
 * nepřepisují: faktura s vazbou na zálohu se přeskočí a faktura i záloha s čerpáním, které
 * nevzniklo převodem (sloupec `source` mimo `import`, nebo čerpání mimo mapu převodu), se
 * z párování vyřadí celé. Čerpání, které převod založil a uživatel smazal, se znovu nezaloží
 * a jeho faktura ani záloha se dál nepárují. Opakovaný převod nic nezdvojí.
 *
 * Stejně se páruje čerpání přijatých záloh na 324 ({@see issued()}): zálohou je otevřená
 * proforma saldokonta 324 (datum první přijaté úhrady), čerpáním debet převzaté vydané
 * konečné faktury na 324 a výsledkem `invoice_advance_deductions`. Money vede odpočet
 * vydané faktury často pod symbolem jen jedné ze záloh („MD 311 / D 324" záporně za všechny
 * zálohy partnera), proto odkazem na zálohu je u vydané strany i párovací symbol řádku
 * (`ParSym`), shodný s číslem proformy nebo s číslicemi jejího variabilního symbolu
 * („26010" k „ZF26010") - funguje i u partnera bez IČO. Konečná faktura s vazbou na proformu
 * (`parent_invoice_id`) se páruje jen v části, kterou saldokonto vazbě nepřiřadí (strana
 * čerpání nad částkou navázané proformy, {@see AdvanceDeductionService::importState()}),
 * a se svou proformou už ne; v cizí měně (strana čerpání se pak nepočítá) se nepáruje.
 * Dobropisy vydaných faktur saldokonto 324 nevede, opravné doklady se proto nepárují.
 */
final class AdvanceDeductionMatcher
{
    private const EPSILON = 0.005;
    /** Haléřové zbytky (zaokrouhlení DDKP a vyúčtování v Money) FIFO s cizí zálohou nepáruje. */
    private const MIN_FIFO = 1.0;
    private const FAR_FUTURE = '9999-12-31';
    /**
     * Kolik let deníku před převodem se čte pro dopočet záloh partnera. Zálohy vyúčtuje
     * nejpozději roční vyúčtování; starší deník nese zbytky, které Money srovnalo zápisy
     * bez IČO (u reálné agendy dopočet z osmi let 1,64 mil. proti počátečnímu stavu 0,21 mil.).
     */
    private const CARRY_YEARS = 1;

    private ?bool $hasSource = null;
    private readonly bool $issuedSide;
    private readonly string $account;

    /** @param 'purchase_invoice'|'invoice' $docType poskytnuté zálohy (314), nebo přijaté zálohy (324) */
    public function __construct(
        private readonly Connection $db,
        private readonly ?MoneyS3ImportRepository $map = null,
        private readonly string $docType = 'purchase_invoice',
    ) {
        $this->account = AdvanceDeductionService::accountForDocType($docType);
        $this->issuedSide = $docType === 'invoice';
    }

    /** Párování čerpání přijatých záloh (proformy na 324) vydanými konečnými fakturami. */
    public static function issued(Connection $db, ?MoneyS3ImportRepository $map = null): self
    {
        return new self($db, $map, 'invoice');
    }

    /**
     * @param list<string> $dirs adresáře let, jejichž doklady převod zná
     * @param array<int,list<int>> $knownRefs konečná faktura => zálohy, které uvádí položka odpočtu dokladu Money
     */
    public function match(ImportContext $ctx, array $dirs, array $knownRefs = []): void
    {
        $years = [];
        foreach ($dirs as $dir) {
            if (isset($ctx->dirYears[$dir])) {
                $years[$ctx->dirYears[$dir]] = $dir;
            }
        }
        if ($years === []) {
            return;
        }
        ksort($years);
        $firstYear = (int) array_key_first($years);
        $from = (string) ($ctx->periods[$firstYear]['starts_on'] ?? ($firstYear . '-01-01'));
        $accountId = $this->accountId($ctx->supplierId);
        if ($accountId === null) {
            return;
        }
        $mapped = $this->map?->all($ctx->supplierId, MoneyS3ImportRepository::KIND_ADVANCE_DEDUCTION) ?? [];
        $blocked = $this->blocked($ctx->supplierId, $mapped);
        $pool = $this->openItems($ctx->supplierId, $accountId);
        $advances = array_values(array_filter($this->advances($ctx->supplierId, $pool), static fn (array $a): bool => !isset($blocked[$a['id']])));
        $deductions = array_values(array_filter($this->deductions($ctx->supplierId, $accountId, $from), static fn (array $d): bool => !isset($blocked[$d['id']])));
        // Volný odpočet faktury určuje služba čerpání (SSOT i pro ruční čerpání); převod jí
        // nepřidělí víc, než zapíše, jinak by opakovaný běh rozděloval zbytek znovu.
        $state = $deductions === [] ? [] : self::service($this->db)->importState($ctx->supplierId, $this->docType, array_column($deductions, 'id'));
        foreach ($deductions as $i => $d) {
            $s = $state[$d['id']] ?? null;
            $counted = $s !== null && $s['posted'] && $s['deductible'] >= self::EPSILON;
            if ($counted) {
                $deductions[$i]['amount'] = round(min($d['amount'], max(0.0, $s['unassigned'])), 2);
            } elseif (($d['skip'] ?? []) !== []) {
                // Fakturu s vazbou na proformu, jejíž stranu čerpání služba nespočítá (cizí
                // měna), saldokonto přiřadí celou proformě - zbytek se určit nedá.
                $deductions[$i]['amount'] = 0.0;
            }
        }
        $deductions = array_values(array_filter($deductions, static fn (array $d): bool => $d['amount'] >= 0.01));
        if ($deductions === []) {
            return;
        }
        $finals = array_fill_keys(array_column($deductions, 'id'), true);
        $corrections = array_values(array_filter(
            $this->corrections($ctx->supplierId, $pool),
            static fn (array $c): bool => isset($finals[$c['final']]) && !isset($blocked[$c['id']]),
        ));
        $olderDirs = [];
        foreach ($ctx->agenda->toArray()['years'] as $y) {
            $fy = $y['fiscal_year'] ?? null;
            if ($fy !== null && (int) $fy < $firstYear && (int) $fy >= $firstYear - self::CARRY_YEARS && !isset($ctx->dirYears[(string) $y['dir']])) {
                $olderDirs[] = (string) $y['dir'];
            }
        }
        [$opening, $refs] = $this->moneyJournal($ctx, $years[$firstYear], array_values($years), $olderDirs);
        // Doklad počátečního stavu (navázaný na otevírací zápis) je v zálohách před převodem
        // zahrnutý; páruje se sám, bez ohledu na to, zda je dnes otevřený.
        foreach ($this->openingDocuments($ctx->supplierId, $accountId) as $partner => $amount) {
            if (isset($opening[$partner])) {
                $opening[$partner] = round($opening[$partner] - $amount, 2);
            }
        }
        $refIds = $this->referencedAdvances($ctx, $refs, $advances);
        foreach ($deductions as $i => $d) {
            $deductions[$i]['refs'] = array_values(array_unique(array_merge($knownRefs[$d['id']] ?? [], $refIds[$d['id']] ?? [])));
        }
        $result = self::allocate($opening, $advances, $deductions, $corrections);
        $written = $this->write($ctx, $result['deductions'], $mapped);

        // Souhrn do počtů kroku: zprávy kroku úhrad se u velké agendy ořezávají.
        $p = $ctx->protocol;
        $prefix = $this->issuedSide ? 'issued_' : '';
        $dates = array_column($deductions, 'date', 'id');
        $locked = 0;
        foreach ($written as $finalId) {
            if ($ctx->isLocked($ctx->calendar->fiscalYearOfDate((string) $dates[$finalId]))) {
                $locked++;
            }
        }
        if ($locked > 0) {
            // Čerpání mění saldokonto uzavřeného roku zpětně (deník ne) - účetní o tom ví.
            $p->setCount(DocumentLinker::STEP_PAYMENTS, $prefix . 'advance_deductions_closed_period', $locked);
        }
        $p->setCount(DocumentLinker::STEP_PAYMENTS, $prefix . 'advance_deductions_opening_amount', round($result['opening_consumed'], 2));
        if ($result['unmatched'] !== []) {
            $idKey = $this->issuedSide ? 'invoice_id' : 'purchase_invoice_id';
            $p->setCount(DocumentLinker::STEP_PAYMENTS, $prefix . 'advance_deductions_unmatched', count($result['unmatched']));
            $p->setCount(DocumentLinker::STEP_PAYMENTS, $prefix . 'advance_deductions_unmatched_amount', round(array_sum($result['unmatched']), 2));
            $p->set($prefix . 'advance_deductions_unmatched', array_slice(array_map(
                static fn (int $id, float $amount): array => [$idKey => $id, 'amount' => $amount],
                array_keys($result['unmatched']), $result['unmatched'],
            ), 0, ImportProtocol::LIST_LIMIT));
        }
    }

    /**
     * Párování čerpání se zálohami po partnerech. Čistá funkce, pořadí výsledku je stabilní.
     *
     * @param array<string,float> $opening partner => zálohy z doby před převodem
     * @param list<array{id:int,partner:string,date:string,open:float,fifo?:bool}> $advances otevřené zálohy (`fifo` = smí do FIFO, tj. korunová)
     * @param list<array{id:int,partner:string,date:string,amount:float,refs?:list<int>,fifo?:bool,skip?:list<int>}> $deductions nespárovaná čerpání (`skip` = zálohy, které čerpání nesmí dostat)
     * @param list<array{id:int,final:int,amount:float}> $corrections opravný doklad konečné faktury a jeho debet na 314
     * @return array{deductions:array<int,array<int,float>>,unmatched:array<int,float>,opening_consumed:float,opening_left:array<string,float>}
     */
    public static function allocate(array $opening, array $advances, array $deductions, array $corrections = []): array
    {
        usort($advances, static fn (array $a, array $b): int => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
        usort($deductions, static fn (array $a, array $b): int => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
        $left = [];
        $partnerOf = [];
        $byPartner = [];
        foreach ($advances as $a) {
            if ($a['open'] < self::EPSILON) {
                continue;
            }
            $left[$a['id']] = round($a['open'], 2);
            $partnerOf[$a['id']] = $a['partner'];
            if ($a['fifo'] ?? true) {
                $byPartner[$a['partner']][] = $a;
            }
        }
        $need = [];
        foreach ($deductions as $d) {
            $need[$d['id']] = round($d['amount'], 2);
        }
        $out = [];
        $add = static function (int $finalId, int $advanceId, float $amount) use (&$out, &$need): void {
            $need[$finalId] = round($need[$finalId] - $amount, 2);
            $out[$finalId][$advanceId] = round(($out[$finalId][$advanceId] ?? 0.0) + $amount, 2);
        };
        $skip = [];
        foreach ($deductions as $d) {
            foreach ($d['skip'] ?? [] as $advanceId) {
                $skip[$d['id']][$advanceId] = true;
            }
        }
        $take = static function (int $finalId, int $advanceId, float $amount) use (&$left, $add, $skip): void {
            $amount = round(min($amount, $left[$advanceId]), 2);
            if ($amount < 0.01 || $finalId === $advanceId || isset($skip[$finalId][$advanceId])) {
                return;
            }
            $left[$advanceId] = round($left[$advanceId] - $amount, 2);
            $add($finalId, $advanceId, $amount);
        };
        // Opravný doklad vrací čerpání své faktury.
        foreach ($corrections as $c) {
            $amount = round(min($c['amount'], $need[$c['final']] ?? 0.0), 2);
            if ($amount >= 0.01) {
                $add($c['final'], $c['id'], $amount);
            }
        }
        // Odkaz na zálohu v textu deníku Money má přednost před vším ostatním.
        foreach ($deductions as $d) {
            foreach ($d['refs'] ?? [] as $advanceId) {
                if ($need[$d['id']] < 0.01) {
                    break;
                }
                if (($partnerOf[$advanceId] ?? null) === $d['partner'] && $left[$advanceId] >= 0.01) {
                    $take($d['id'], $advanceId, $need[$d['id']]);
                }
            }
        }
        $openingLeft = array_map(static fn (float $v): float => max(0.0, round($v, 2)), $opening);
        $openingConsumed = 0.0;
        $unmatched = [];
        foreach ($deductions as $d) {
            $id = $d['id'];
            if ($need[$id] >= 0.01 && ($d['fifo'] ?? true)) {
                $fromOpening = round(min($need[$id], $openingLeft[$d['partner']] ?? 0.0), 2);
                if ($fromOpening > 0.0) {
                    $openingLeft[$d['partner']] = round($openingLeft[$d['partner']] - $fromOpening, 2);
                    $openingConsumed += $fromOpening;
                    $need[$id] = round($need[$id] - $fromOpening, 2);
                }
                foreach ($byPartner[$d['partner']] ?? [] as $a) {
                    if ($need[$id] < 0.01) {
                        break;
                    }
                    if ($a['date'] > $d['date'] || min($need[$id], $left[$a['id']]) < self::MIN_FIFO) {
                        continue;
                    }
                    $take($id, $a['id'], $need[$id]);
                }
            }
            if ($need[$id] >= 0.01) {
                $unmatched[$id] = $need[$id];
            }
        }
        ksort($out);
        return ['deductions' => $out, 'unmatched' => $unmatched, 'opening_consumed' => round($openingConsumed, 2), 'opening_left' => $openingLeft];
    }

    /**
     * Zálohy partnerů nevyúčtované na konci let před převodem: pohyby na 314 s IČO partnera
     * v pořadí data, debet (platba, záloha) přidá zálohu, kredit (daň DDKP, vyúčtování,
     * vratka) spotřebuje nejstarší zálohy, stejně jako párování v převáděných letech. Kredit
     * nad zbývající zálohy patří zálohám z ještě starších let a zahodí se.
     *
     * @param array<string,list<array{date:string,amount:float}>> $history
     * @return array<string,float> partner => nevyúčtované zálohy
     */
    public static function carriedAdvances(array $history): array
    {
        $out = [];
        foreach ($history as $partner => $moves) {
            usort($moves, static fn (array $a, array $b): int => [$a['date'], $a['amount'] < 0] <=> [$b['date'], $b['amount'] < 0]);
            $lots = [];
            foreach ($moves as $m) {
                $amount = round($m['amount'], 2);
                if ($amount > 0) {
                    $lots[] = $amount;
                    continue;
                }
                $need = -$amount;
                while ($need >= 0.005 && $lots !== []) {
                    $take = min($need, $lots[0]);
                    $lots[0] = round($lots[0] - $take, 2);
                    $need = round($need - $take, 2);
                    if ($lots[0] < 0.005) {
                        array_shift($lots);
                    }
                }
            }
            $left = round(array_sum($lots), 2);
            if ($left >= 0.01) {
                $out[(string) $partner] = $left;
            }
        }
        return $out;
    }

    /** Klíč mapy převodu pro čerpání poskytnuté zálohy (314). */
    public static function mapKey(int $finalId, int $advanceId): string
    {
        return 'pad|' . $finalId . '|' . $advanceId;
    }

    /** Klíč mapy převodu pro čerpání přijaté zálohy (324). */
    public static function issuedMapKey(int $finalId, int $advanceId): string
    {
        return 'iad|' . $finalId . '|' . $advanceId;
    }

    private function key(int $finalId, int $advanceId): string
    {
        return $this->issuedSide ? self::issuedMapKey($finalId, $advanceId) : self::mapKey($finalId, $advanceId);
    }

    /** Sloupec `source` (čerpání zadané převodem / uživatelem v saldokontu) - jen pokud ho schéma má. */
    public function hasSourceColumn(): bool
    {
        if ($this->hasSource === null) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT COUNT(*) FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = ? AND column_name = 'source'"
            );
            $stmt->execute([AdvanceDeductionRepository::table($this->docType)]);
            $this->hasSource = (int) $stmt->fetchColumn() > 0;
        }
        return $this->hasSource;
    }

    /**
     * Otevřené položky saldokonta zálohového účtu (314 přijatých dokladů, 324 vydaných)
     * firmy (doklad => otevřeno).
     *
     * @return array<int,float>
     */
    public function openAdvances(int $supplierId): array
    {
        $accountId = $this->accountId($supplierId);
        return $accountId === null ? [] : $this->openItems($supplierId, $accountId);
    }

    private function accountId(int $supplierId): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ? ORDER BY is_synthetic DESC, id LIMIT 1');
        $stmt->execute([$supplierId, $this->account]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    private function docTable(): string
    {
        return $this->issuedSide ? 'invoices' : 'purchase_invoices';
    }

    /**
     * Doklady, které převod nepáruje: s čerpáním, které nezaložil převod (uživatel ho zadal
     * v saldokontu - `source` mimo `import`, nebo čerpání chybí v mapě převodu), a doklady
     * čerpání, které převod založil a uživatel smazal. Smazané čerpání se pozná jen tehdy,
     * když obě faktury dál existují; smazáním faktury zmizí čerpání kaskádou.
     *
     * @param array<string,int> $mapped
     * @return array<int,true> faktury a zálohy
     */
    private function blocked(int $supplierId, array $mapped): array
    {
        $source = $this->hasSourceColumn() ? 'source' : "'import'";
        $table = AdvanceDeductionRepository::table($this->docType);
        $stmt = $this->db->pdo()->prepare("SELECT final_invoice_id, advance_invoice_id, {$source} FROM {$table} WHERE supplier_id = ?");
        $stmt->execute([$supplierId]);
        $out = [];
        $live = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$final, $advance, $src]) {
            $key = $this->key((int) $final, (int) $advance);
            $live[$key] = true;
            if ($src !== 'import' || !isset($mapped[$key])) {
                $out[(int) $final] = true;
                $out[(int) $advance] = true;
            }
        }
        $removed = [];
        $prefix = $this->issuedSide ? 'iad|' : 'pad|';
        foreach ($mapped as $key => $_) {
            // Mapa vede čerpání obou stran pod jedním druhem; druhá strana sem nepatří.
            if (!isset($live[$key]) && str_starts_with((string) $key, $prefix)) {
                $parts = explode('|', (string) $key);
                $removed[(int) ($parts[1] ?? 0)] = (int) ($parts[2] ?? 0);
            }
        }
        if ($removed !== []) {
            $ids = array_unique(array_merge(array_keys($removed), array_values($removed)));
            $exists = [];
            foreach (array_chunk($ids, 500) as $chunk) {
                $q = $this->db->pdo()->prepare('SELECT id FROM ' . $this->docTable() . ' WHERE supplier_id = ? AND id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
                $q->execute([$supplierId, ...$chunk]);
                foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
                    $exists[(int) $id] = true;
                }
            }
            foreach ($removed as $final => $advance) {
                if (isset($exists[$final], $exists[$advance])) {
                    $out[$final] = true;
                    $out[$advance] = true;
                }
            }
        }
        return $out;
    }

    /**
     * Otevřené položky saldokonta zálohového účtu dokladů téže strany s otevřenou částkou
     * přesně podle saldokonta (SSOT {@see SaldoRepository::openItems()}).
     *
     * @return array<int,float> doklad => otevřeno
     */
    private function openItems(int $supplierId, int $accountId): array
    {
        $open = [];
        foreach ((new SaldoRepository($this->db))->openItems($supplierId, $accountId, self::FAR_FUTURE, $this->account) as $item) {
            if (($item['doc_type'] ?? '') !== $this->docType) {
                continue;
            }
            // Přijatá záloha je na 324 dal - saldokonto ji vede se záporným znaménkem.
            $remaining = ($this->issuedSide ? -1 : 1) * SaldoRepository::settlementAmounts((float) $item['booked_signed'], (float) $item['paid_ratio'])['remaining'];
            if ($remaining >= 0.01) {
                $open[(int) $item['doc_id']] = $remaining;
            }
        }
        return $open;
    }

    /**
     * Zálohy pro párování: otevřené položky převzatých dokladů mimo dobropisy a storna
     * (záporné doklady vrací čerpání své faktury, {@see corrections()}). Datum je den
     * první úhrady (banka, pokladna), u zálohy placené konečnou fakturou den jejího zápisu,
     * u neuhrazené den vystavení.
     *
     * Přijaté zálohy (324) jsou otevřené proformy převzaté převodem, datem je den první
     * evidované úhrady, jinak den vystavení.
     *
     * @param array<int,float> $pool
     * @return list<array{id:int,partner:string,date:string,open:float,fifo:bool,symbol?:string}>
     */
    private function advances(int $supplierId, array $pool): array
    {
        $out = [];
        if ($this->issuedSide) {
            foreach (array_chunk(array_keys($pool), 500) as $chunk) {
                $stmt = $this->db->pdo()->prepare(
                    "SELECT p.id, p.client_id, c.ic, p.issue_date, p.varsymbol, cur.code AS currency,
                            (SELECT MIN(ip.paid_on) FROM invoice_payments ip WHERE ip.supplier_id = p.supplier_id AND ip.invoice_id = p.id) AS paid_on
                       FROM invoices p
                       JOIN clients c ON c.id = p.client_id
                       JOIN currencies cur ON cur.id = p.currency_id
                      WHERE p.supplier_id = ? AND p.id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")
                        AND p.invoice_type = 'proforma' AND p.total_with_vat >= 0
                        AND " . TakenOverRecord::documentSql('invoice', 'p')
                );
                $stmt->execute([$supplierId, ...$chunk]);
                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $out[] = ['id' => (int) $r['id'], 'partner' => self::partner($r['ic'], (int) $r['client_id']),
                        'date' => (string) ($r['paid_on'] ?? $r['issue_date']), 'open' => $pool[(int) $r['id']],
                        'fifo' => $r['currency'] === 'CZK', 'symbol' => (string) $r['varsymbol']];
                }
            }
            return $out;
        }
        foreach (array_chunk(array_keys($pool), 500) as $chunk) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT p.id, p.vendor_id, c.ic, p.issue_date, p.document_kind, cur.code AS currency,
                        LEAST(COALESCE((SELECT MIN(DATE(bt.posted_at)) FROM payment_matches pm JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
                                         WHERE pm.supplier_id = p.supplier_id AND pm.purchase_invoice_id = p.id AND bt.amount < 0), "9999-12-31"),
                              COALESCE((SELECT MIN(cd.issue_date) FROM cash_documents cd
                                         WHERE cd.supplier_id = p.supplier_id AND cd.purchase_invoice_id = p.id), "9999-12-31")) AS paid_on,
                        (SELECT MIN(e.entry_date) FROM journal_entries e
                          WHERE e.supplier_id = p.supplier_id AND e.source_type = "purchase_invoice" AND e.source_id = p.id) AS booked_on
                   FROM purchase_invoices p
                   JOIN clients c ON c.id = p.vendor_id
                   JOIN currencies cur ON cur.id = p.currency_id
                  WHERE p.supplier_id = ? AND p.id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')
                    AND p.document_kind <> "credit_note" AND p.total_with_vat >= 0
                    AND ' . TakenOverRecord::documentSql('purchase_invoice', 'p')
            );
            $stmt->execute([$supplierId, ...$chunk]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $date = (string) $r['issue_date'];
                if ($r['document_kind'] === 'invoice' && $r['booked_on'] !== null) {
                    $date = (string) $r['booked_on'];
                } elseif ((string) $r['paid_on'] !== '9999-12-31') {
                    $date = (string) $r['paid_on'];
                }
                $out[] = ['id' => (int) $r['id'], 'partner' => self::partner($r['ic'], (int) $r['vendor_id']), 'date' => $date,
                    'open' => $pool[(int) $r['id']], 'fifo' => $r['currency'] === 'CZK'];
            }
        }
        return $out;
    }

    /**
     * Opravné doklady konečných faktur s debetem na 314, které saldokonto drží otevřené
     * (vypadají jako záloha placená fakturou): dobropis s vazbou na fakturu, nebo dobropis
     * či storno (záporná faktura) započtené proti faktuře (`credit_note_offsets`).
     *
     * @param array<int,float> $pool
     * @return list<array{id:int,final:int,amount:float}>
     */
    private function corrections(int $supplierId, array $pool): array
    {
        $out = [];
        if ($this->issuedSide) {
            return $out; // saldokonto 324 vede jen proformy, opravný doklad v něm otevřený není
        }
        foreach (array_chunk(array_keys($pool), 500) as $chunk) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT p.id, COALESCE(IF(p.document_kind = 'credit_note', p.parent_purchase_invoice_id, NULL),
                                       (SELECT MIN(o.invoice_id) FROM credit_note_offsets o
                                         WHERE o.supplier_id = p.supplier_id AND o.doc_type = 'purchase_invoice' AND o.credit_note_id = p.id)) AS final_id
                   FROM purchase_invoices p
                  WHERE p.supplier_id = ? AND (p.document_kind = 'credit_note' OR p.total_with_vat < 0)
                    AND p.id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')'
            );
            $stmt->execute([$supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$id, $final]) {
                if ($final !== null) {
                    $out[] = ['id' => (int) $id, 'final' => (int) $final, 'amount' => $pool[(int) $id]];
                }
            }
        }
        return $out;
    }

    /**
     * Strana čerpání převzatých konečných faktur na zálohovém účtu (přijatá faktura kredit
     * 314, vydaná debet 324) od prvního převáděného roku, snížená o evidované čerpání
     * faktury. Přijatá faktura s vazbou na zálohu se nebere (saldokonto jí přičítá celý
     * kredit); vydaná s vazbou na proformu ano, se `skip` = navázaná proforma - kolik strany
     * zbývá mimo vazbu, určí {@see match()} podle služby čerpání. Zápisy faktury (vlastní
     * i skupina zaúčtování) bere stejně jako saldokonto
     * ({@see SaldoRepository::advanceDeductionEntriesSql()}).
     *
     * @return list<array{id:int,partner:string,date:string,amount:float,fifo:bool,skip?:list<int>}>
     */
    private function deductions(int $supplierId, int $accountId, string $from): array
    {
        $table = AdvanceDeductionRepository::table($this->docType);
        $sql = $this->issuedSide
            ? "SELECT d.id, d.client_id AS partner_id, c.ic, cur.code AS currency, e.id AS entry_id, e.entry_date, l.account_id, l.side, l.signed_amount,
                      (SELECT lp.id FROM invoices lp WHERE lp.supplier_id = d.supplier_id AND lp.id = d.parent_invoice_id AND lp.invoice_type = 'proforma') AS linked,
                      (SELECT COALESCE(SUM(t.amount), 0) FROM {$table} t WHERE t.supplier_id = d.supplier_id AND t.final_invoice_id = d.id) AS deducted
                 FROM (" . DocumentPostingGroup::entriesSql('invoice', $supplierId) . ") de
                 JOIN invoices d ON d.id = de.doc_id AND d.supplier_id = {$supplierId}
                 JOIN journal_entries e ON e.supplier_id = d.supplier_id AND e.id = de.entry_id
                 JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                 JOIN chart_of_accounts ca ON ca.id = l.account_id
                 JOIN clients c ON c.id = d.client_id
                 JOIN currencies cur ON cur.id = d.currency_id
                WHERE d.invoice_type = 'invoice' AND d.status NOT IN ('draft', 'cancelled')
                  AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL AND e.entry_date >= ?
                  AND (ca.id = {$accountId} OR ca.parent_id = {$accountId})
                  AND " . TakenOverRecord::documentSql('invoice', 'd') . '
                ORDER BY d.id, e.id, l.id'
            : "SELECT d.id, d.vendor_id AS partner_id, c.ic, cur.code AS currency, e.id AS entry_id, e.entry_date, l.account_id, l.side, l.signed_amount,
                      NULL AS linked,
                      (SELECT COALESCE(SUM(t.amount), 0) FROM {$table} t WHERE t.supplier_id = d.supplier_id AND t.final_invoice_id = d.id) AS deducted
                 FROM (" . DocumentPostingGroup::entriesSql('purchase_invoice', $supplierId) . ") de
                 JOIN purchase_invoices d ON d.id = de.doc_id AND d.supplier_id = {$supplierId}
                 JOIN journal_entries e ON e.supplier_id = d.supplier_id AND e.id = de.entry_id
                 JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                 JOIN chart_of_accounts ca ON ca.id = l.account_id
                 JOIN clients c ON c.id = d.vendor_id
                 JOIN currencies cur ON cur.id = d.currency_id
                WHERE d.document_kind = 'invoice' AND d.status NOT IN ('draft', 'cancelled')
                  AND d.advance_purchase_invoice_id IS NULL
                  AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL AND e.entry_date >= ?
                  AND (ca.id = {$accountId} OR ca.parent_id = {$accountId})
                  AND " . TakenOverRecord::documentSql('purchase_invoice', 'd') . '
                ORDER BY d.id, e.id, l.id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$from]);
        $docs = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $id = (int) $r['id'];
            $docs[$id]['row'] ??= $r;
            $docs[$id]['entries'][(int) $r['entry_id']][] = $r;
        }
        $side = AdvanceDeductionRepository::deductionSide($this->docType);
        $out = [];
        foreach ($docs as $id => $doc) {
            $total = 0.0;
            $date = null;
            foreach ($doc['entries'] as $lines) {
                // Strana čerpání, ke které má tentýž zápis stejně velkou opačnou stranu na jiné
                // analytice, je přeúčtování uvnitř zálohového účtu, ne čerpání (zrcadlo položky
                // zálohy placené v konečné faktuře v saldokontu).
                $opposite = [];
                foreach ($lines as $l) {
                    if ($l['side'] !== $side) {
                        $opposite[] = [(int) $l['account_id'], round((float) $l['signed_amount'], 2)];
                    }
                }
                foreach ($lines as $l) {
                    if ($l['side'] !== $side) {
                        continue;
                    }
                    $amount = round((float) $l['signed_amount'], 2);
                    foreach ($opposite as $i => [$oppositeAccount, $oppositeAmount]) {
                        if ($oppositeAccount !== (int) $l['account_id'] && abs($oppositeAmount - $amount) < self::EPSILON) {
                            unset($opposite[$i]);
                            continue 2;
                        }
                    }
                    $total += $amount;
                    $date = $date === null || (string) $l['entry_date'] < $date ? (string) $l['entry_date'] : $date;
                }
            }
            $amount = round($total - (float) $doc['row']['deducted'], 2);
            if ($amount >= 0.01 && $date !== null) {
                $out[] = ['id' => $id, 'partner' => self::partner($doc['row']['ic'], (int) $doc['row']['partner_id']), 'date' => $date,
                    'amount' => $amount, 'fifo' => $doc['row']['currency'] === 'CZK']
                    + ($doc['row']['linked'] !== null ? ['skip' => [(int) $doc['row']['linked']]] : []);
            }
        }
        return $out;
    }

    /**
     * Zálohy navázané na otevírací zápis (doklady počátečního stavu) po partnerech, s částkou
     * jako v saldokontu ({@see SaldoRepository} - doklad v Kč, bez daně, pokud k němu DDKP
     * převedený není), bez ohledu na to, zda jsou dnes otevřené. Saldokonto 324 doklady
     * počátečního stavu nevede.
     *
     * @return array<string,float>
     */
    private function openingDocuments(int $supplierId, int $accountId): array
    {
        if ($this->issuedSide) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT c.ic, d.vendor_id,
                    CASE WHEN d.exchange_rate IS NULL OR cur.code = 'CZK' THEN d.total_with_vat ELSE ROUND(d.total_with_vat * d.exchange_rate, 2) END AS booked,
                    CASE WHEN d.exchange_rate IS NULL OR cur.code = 'CZK' THEN d.total_vat ELSE ROUND(d.total_vat * d.exchange_rate, 2) END AS vat,
                    (SELECT COUNT(*) FROM purchase_invoices td WHERE td.supplier_id = d.supplier_id AND td.parent_purchase_invoice_id = d.id
                        AND td.document_kind = 'tax_document' AND td.status NOT IN ('draft', 'cancelled')) AS tax_documents
               FROM purchase_invoices d
               JOIN clients c ON c.id = d.vendor_id
               JOIN currencies cur ON cur.id = d.currency_id
              WHERE d.supplier_id = ? AND d.document_kind = 'advance' AND d.status NOT IN ('draft', 'cancelled')
                AND EXISTS (SELECT 1 FROM journal_entry_document_links ol
                              JOIN journal_entries oe ON oe.id = ol.entry_id AND oe.supplier_id = ol.supplier_id
                              JOIN journal_entry_lines oln ON oln.entry_id = oe.id AND oln.supplier_id = oe.supplier_id
                              JOIN chart_of_accounts oca ON oca.id = oln.account_id
                             WHERE ol.supplier_id = d.supplier_id AND ol.doc_type = 'purchase_invoice' AND ol.doc_id = d.id
                               AND oe.source_type = 'opening' AND oe.posted_at IS NOT NULL AND oe.reversed_by IS NULL
                               AND (oca.id = ? OR oca.parent_id = ?))"
        );
        $stmt->execute([$supplierId, $accountId, $accountId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $partner = self::partner($r['ic'], (int) $r['vendor_id']);
            $amount = (float) $r['booked'] - ((int) $r['tax_documents'] === 0 ? (float) $r['vat'] : 0.0);
            $out[$partner] = round(($out[$partner] ?? 0.0) + $amount, 2);
        }
        return $out;
    }

    /**
     * Deník Money: zálohy partnerů z doby před prvním převáděným rokem a čísla dokladů,
     * která uvádějí řádky konečných faktur na zálohovém účtu.
     *
     * Počáteční stavy (`XP`) Money vede bez IČO i bez symbolu. Nesou-li IČO, platí; jinak se
     * zálohy partnera k začátku roku dopočtou z deníku let před převodem ({@see carriedAdvances()}).
     * Zálohu přidává strana zálohového účtu, kterou ji partner platí (314 má dáti, 324 dal).
     *
     * @param list<string> $dirs adresáře převáděných let
     * @param list<string> $olderDirs adresáře let před prvním převáděným rokem
     * @return array{0:array<string,float>,1:array<int,list<array{year:int,no:string,symbol?:bool}>>}
     */
    private function moneyJournal(ImportContext $ctx, string $firstDir, array $dirs, array $olderDirs): array
    {
        $opening = [];
        $refs = [];
        $older = array_fill_keys($olderDirs, true);
        $history = [];
        $documents = $this->issuedSide ? $ctx->issuedInvoices : $ctx->purchaseInvoices;
        $fields = ['Zdroj', 'Doklad', 'Datum', 'UcMD', 'UcD', 'Castka', 'ParICO', 'Popis', ...($this->issuedSide ? ['ParSym'] : [])];
        foreach ($ctx->backup->rowsAcrossYears('UcDenik', $fields, array_merge($dirs, $olderDirs)) as $r) {
            $md = str_starts_with(trim((string) ($r['UcMD'] ?? '')), $this->account);
            $dal = str_starts_with(trim((string) ($r['UcD'] ?? '')), $this->account);
            if (!$md && !$dal) {
                continue;
            }
            $sign = ($md !== $this->issuedSide) ? 1 : -1;
            if (Ms3Journal::isOpening($r)) {
                if ($r['__dir'] === $firstDir && $md !== $dal) {
                    $partner = self::partner($r['ParICO'] ?? null, 0);
                    $opening[$partner] = round(($opening[$partner] ?? 0.0) + $sign * (float) ($r['Castka'] ?? 0), 2);
                }
                continue;
            }
            if (isset($older[$r['__dir']])) {
                // Přeúčtování mezi analytikami zálohového účtu v jednom řádku zálohu nemění.
                $partner = self::partner($r['ParICO'] ?? null, 0);
                if ($md !== $dal && $partner !== '' && !Ms3Journal::isYearEndClosing($r)) {
                    $history[$partner][] = ['date' => (string) ($r['Datum'] ?? ''), 'amount' => $sign * (float) ($r['Castka'] ?? 0)];
                }
                continue;
            }
            $year = $ctx->yearOf($r);
            $docId = $year !== null ? ($documents[$year . '|' . trim((string) ($r['Doklad'] ?? ''))] ?? null) : null;
            if ($docId === null) {
                continue;
            }
            $popis = (string) ($r['Popis'] ?? '');
            if (preg_match_all('/\b[A-Z]{1,5}\d{5,}[A-Z]?\b/u', $popis, $m) >= 1) {
                // Daňový doklad („daň. dokl. DPP230003") rozhoduje: Money v témže textu někdy uvádí
                // číslo jiné zálohy, než ke které odečítaný DDKP patří.
                $numbers = preg_match('/da[nň]\.?\s*dokl\.?\s*([A-Z]{1,5}\d{5,}[A-Z]?)/u', $popis, $t) === 1 ? [$t[1]] : [];
                foreach (array_unique(array_merge($numbers, $m[0])) as $no) {
                    $refs[(int) $docId][] = ['year' => $year, 'no' => $no];
                }
            }
            // Vydaná faktura odečítá zálohy často pod párovacím symbolem jedné z nich; číslo
            // v textu má přednost.
            $symbol = $this->issuedSide ? trim((string) ($r['ParSym'] ?? '')) : '';
            if ($symbol !== '') {
                $refs[(int) $docId][] = ['year' => $year, 'no' => $symbol, 'symbol' => true];
            }
        }
        unset($opening['']);
        if (array_filter($opening, static fn (float $v): bool => abs($v) >= 0.01) === []) {
            $opening = self::carriedAdvances($history);
        }
        return [$opening, $refs];
    }

    /**
     * Čísla dokladů z textu deníku => otevřené zálohy: záloha nebo DDKP téhož či minulého
     * roku (DDKP zálohy se převede na její rodičovskou zálohu), případně záloha uhrazená
     * bankovním dokladem s tím číslem. Párovací symbol vydané faktury odkazuje i na proformu,
     * jejíž variabilní symbol má stejné číslice (Money symbol vede bez prefixu řady).
     *
     * @param array<int,list<array{year:int,no:string,symbol?:bool}>> $refs
     * @param list<array{id:int,partner:string,date:string,open:float,symbol?:string}> $advances
     * @return array<int,list<int>>
     */
    private function referencedAdvances(ImportContext $ctx, array $refs, array $advances): array
    {
        if ($refs === []) {
            return [];
        }
        $pool = array_fill_keys(array_column($advances, 'id'), true);
        $bySymbol = [];
        foreach ($advances as $a) {
            $digits = self::symbolDigits($a['symbol'] ?? '');
            if ($digits !== '') {
                $bySymbol[$digits][] = $a['id'];
            }
        }
        $documents = $this->issuedSide ? $ctx->issuedInvoices : $ctx->purchaseInvoices;
        $docColumn = $this->issuedSide ? 'invoice_id' : 'purchase_invoice_id';
        $bankByDoc = [];
        foreach ($ctx->bankTransactions as $key => $id) {
            $parts = explode('|', (string) $key, 3);
            $bankByDoc[$parts[0] . '|' . ($parts[1] ?? '')][] = (int) $id;
        }
        $paidBy = [];
        foreach (array_chunk(array_keys($pool), 500) as $chunk) {
            $stmt = $this->db->pdo()->prepare("SELECT bank_transaction_id, {$docColumn} FROM payment_matches WHERE supplier_id = ? AND {$docColumn} IN ("
                . implode(',', array_fill(0, count($chunk), '?')) . ')');
            $stmt->execute([$ctx->supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$tx, $doc]) {
                $paidBy[(int) $tx][(int) $doc] = true;
            }
        }
        $parents = [];
        $stmt = $this->db->pdo()->prepare($this->issuedSide
            ? "SELECT id, parent_invoice_id FROM invoices
                WHERE supplier_id = ? AND invoice_type = 'tax_document' AND parent_invoice_id IS NOT NULL"
            : "SELECT id, parent_purchase_invoice_id FROM purchase_invoices
                WHERE supplier_id = ? AND document_kind = 'tax_document' AND parent_purchase_invoice_id IS NOT NULL");
        $stmt->execute([$ctx->supplierId]);
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$id, $parent]) {
            $parents[(int) $id] = (int) $parent;
        }
        $out = [];
        foreach ($refs as $finalId => $list) {
            foreach ($list as $ref) {
                ['year' => $year, 'no' => $no] = $ref;
                foreach ([$year, $year - 1] as $y) {
                    $ids = [];
                    if (isset($documents[$y . '|' . $no])) {
                        $ids[] = (int) $documents[$y . '|' . $no];
                    }
                    if (($ref['symbol'] ?? false) && $ids === []) {
                        $ids = $bySymbol[self::symbolDigits($no)] ?? [];
                    }
                    foreach ($bankByDoc[$y . '|' . $no] ?? [] as $tx) {
                        foreach ($paidBy[$tx] ?? [] as $doc => $_) {
                            $ids[] = (int) $doc;
                        }
                    }
                    foreach ($ids as $id) {
                        $id = $parents[$id] ?? $id;
                        if (isset($pool[$id]) && $id !== $finalId && !in_array($id, $out[$finalId] ?? [], true)) {
                            $out[$finalId][] = $id;
                        }
                    }
                    if ($ids !== []) {
                        break;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Zápis čerpání a mapy převodu v jedné transakci. Čerpání zapisuje
     * {@see AdvanceDeductionService::recordImported()}: nejvýš volný odpočet faktury, dvojici,
     * kterou už spojuje jiné čerpání (i ruční), nemění.
     *
     * @param array<int,array<int,float>> $deductions
     * @param array<string,int> $mapped
     * @return list<int> faktury, ke kterým čerpání přibylo
     */
    private function write(ImportContext $ctx, array $deductions, array $mapped): array
    {
        $pdo = $this->db->pdo();
        $service = self::service($this->db);
        $find = $pdo->prepare('SELECT id FROM ' . AdvanceDeductionRepository::table($this->docType) . ' WHERE supplier_id = ? AND final_invoice_id = ? AND advance_invoice_id = ?');
        $userId = $ctx->userId > 0 ? $ctx->userId : null;
        $prefix = $this->issuedSide ? 'issued_' : '';
        $written = [];
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            foreach ($deductions as $finalId => $advances) {
                foreach ($advances as $advanceId => $amount) {
                    $key = $this->key($finalId, $advanceId);
                    if (isset($mapped[$key]) || $amount < 0.01) {
                        continue;
                    }
                    if ($service->recordImported($ctx->supplierId, $this->docType, $finalId, $advanceId, $amount, $userId) < 0.01) {
                        // Dvojici už spojuje jiné čerpání, nebo faktura nemá volný odpočet; nepřepisuje se.
                        $ctx->protocol->count(DocumentLinker::STEP_PAYMENTS, $prefix . 'advance_deduction_pair_exists');
                        continue;
                    }
                    $find->execute([$ctx->supplierId, $finalId, $advanceId]);
                    $this->map?->put($ctx->supplierId, MoneyS3ImportRepository::KIND_ADVANCE_DEDUCTION, $key, (int) $find->fetchColumn(), $ctx->runId);
                    $ctx->protocol->count(DocumentLinker::STEP_PAYMENTS, $prefix . 'advance_deductions_matched');
                    $written[] = $finalId;
                }
            }
            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $written;
    }

    /** Služba čerpání záloh pro převod (bez DI kontejneru, převod skládá kroky sám). */
    public static function service(Connection $db): AdvanceDeductionService
    {
        return new AdvanceDeductionService($db, new AdvanceDeductionRepository($db), new SaldoRepository($db));
    }

    /** Číslice symbolu bez úvodních nul („ZF026010" i „26010" => „26010"). */
    private static function symbolDigits(string $symbol): string
    {
        return ltrim(preg_replace('/\D+/', '', $symbol) ?? '', '0');
    }

    private static function partner(mixed $ico, int $vendorId): string
    {
        $ico = strtoupper(preg_replace('/\s+/', '', (string) $ico) ?? '');
        return $ico !== '' ? 'ico:' . $ico : ($vendorId > 0 ? 'vendor:' . $vendorId : '');
    }
}
