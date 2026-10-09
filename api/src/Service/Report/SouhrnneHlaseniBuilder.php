<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\Accounting\PostingException;

/**
 * Builder XML pro Souhrnné hlášení (DPHSHV1) — EPO portál MFČR.
 *
 * Verze EPO: 06.01 (platná 2025+).
 *
 * **K čemu slouží:**
 * Výkaz EU dodání zboží/služeb (intra-community supplies) v režimu B2B —
 * dodávky plátcům v jiných členských státech EU. Submit per měsíc (povinnost
 * pro plátce DPH s alespoň jednou EU dodávkou v daném měsíci).
 *
 * **Sekce SH:**
 * Per řádek (group by counterparty VAT_ID + kód plnění):
 *   - Kód plnění (k_pln_eu) dle DPHSHV XSD:
 *     - **0** = Dodání zboží do jiného členského státu (ř.20 DPHDP3, VAT kód "20")
 *     - **1** = Přemístění obchodního majetku do JČS (§ 13 odst. 6)
 *     - **2** = Dodání zboží formou třístranného obchodu prostřední osobou (§ 17, ř.31, VAT kód "31")
 *     - **3** = Poskytnutí služby s místem plnění v JČS (§ 9 odst. 1, ř.21, VAT kód "22")
 *   - DIČ kupujícího BEZ prefixu země (kód země je zvlášť v k_stat), např. 1234567890
 *   - Hodnota plnění v CZK (základ daně, bez DPH)
 *   - Počet plnění
 *
 * ⚠️ Vygenerované XML je POUZE POMŮCKA. Před odesláním ověřit s účetní.
 */
final class SouhrnneHlaseniBuilder
{
    /**
     * Mapování VAT klasifikačních kódů na kód plnění SH (k_pln_eu) dle DPHSHV XSD:
     *   0 = dodání zboží do JČS (§13)
     *   1 = přemístění obchodního majetku do JČS (§13/6)
     *   2 = dodání zboží formou třístranného obchodu prostřední osobou (§17)
     *   3 = poskytnutí služby s místem plnění v JČS (§9/1), daň přiznává příjemce
     *
     * Klasifikační kódy číselníku:
     *   "20" (EU dodání zboží)             → 0
     *   "31" (třístranný obchod, ř.31)     → 2  (prostřední osoba)
     *   "22" (EU služby, ř.21)             → 3
     */
    private const VAT_CODE_TO_SH_TYPE = [
        '20' => '0',  // dodání zboží do JČS
        '31' => '2',  // třístranný obchod — dodání zboží prostřední osobou (§17)
        '22' => '3',  // poskytnutí služby do JČS (§9/1)
    ];

    /**
     * Mapa UI variant → EPO `shvies_forma`. XSD povoluje jen [RN]:
     *   R = řádné souhrnné hlášení,
     *   N = NÁSLEDNÉ souhrnné hlášení (§ 102 odst. 6 — do 15 dnů ode dne zjištění změny).
     *
     * Následné SH se NEPODÁVÁ jako celý výkaz znovu (to je KH) ani jako rozdíl částek
     * (to je DPHDP3). Podává se jako OPRAVNÉ ŘÁDKY: řádek, který se má ve VIES zrušit,
     * jde s `k_storno="A"` a párovací trojicí (k_stat, c_vat, k_pln_eu); nová/opravená
     * hodnota jde jako běžný řádek. Řádky, které se nezměnily, se NEOPAKUJÍ — VIES by je
     * započetl podruhé.
     */
    private const VARIANT_FORMA = [
        'radne'    => 'R',
        'nasledne' => 'N',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly VatLedgerService $ledger,
        // Archiv podání — stav, který ve VIES vznikl řádným hlášením a předchozími následnými.
        private readonly TaxSubmissionRepository $submissions,
    ) {}

    /**
     * @param string $variant 'radne'|'nasledne'
     * @param ?string $dZjist datum zjištění změny (Y-m-d) — pro následné; do XML nejde
     *        (DPHSHV ho nezná), slouží k výpočtu 15denní lhůty.
     * @return array{xml: string, summary: array<string,mixed>, warnings: list<string>, missing_rates: list<array<string,mixed>>}
     */
    public function build(
        int $supplierId,
        int $year,
        int $month,
        string $period = 'monthly',
        string $variant = 'radne',
        ?string $dZjist = null,
    ): array {
        $forma = self::VARIANT_FORMA[$variant] ?? null;
        if ($forma === null) {
            throw new PostingException('shv_variant_invalid', "Neznámý typ souhrnného hlášení: {$variant}.", 422);
        }
        $isFollowUp = $forma === 'N';
        $dZjist = $this->normalizeDate($dZjist);
        if ($period === 'quarterly') {
            $quarter = (int) ceil($month / 3);
            $startMonth = ($quarter - 1) * 3 + 1;
            // Konec kvartálu = poslední den měsíce quarter*3, NEZÁVISLE na předaném
            // $month (jinak build(..., 4, 'quarterly') utne období na duben a zahodí
            // květen+červen). Stejná logika jako DphBookBuilder::build().
            $endMonth = $quarter * 3;
            $start = sprintf('%04d-%02d-01', $year, $startMonth);
        } else {
            $quarter = null;
            $endMonth = $month;
            $start = sprintf('%04d-%02d-01', $year, $month);
        }
        $end = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $endMonth)))->modify('last day of this month')->format('Y-m-d');

        // Rozhodný stav plátcovství = POSLEDNÍ DEN období výkazu (EPIC VH-04) —
        // SH sice podávají i identifikované osoby, ale VetaP musí nést konzistentní
        // stav se zbytkem výkazů za totéž období.
        $supplier = $this->loadSupplier($supplierId, $end);
        $warnings = $this->validateSupplier($supplier);

        $missingRates = [];
        $rows = $this->collectEuSupplies($supplierId, $start, $end, $missingRates, $warnings);

        // #238: EU dodávky v cizí měně bez zafixovaného kurzu. NEházíme chybu — vrátíme
        // je v `missing_rates`; akce při stažení je doplní z ČNB, náhled jen varuje.
        if ($missingRates !== []) {
            $warnings[] = 'Chybí kurz u EU dodávek v cizí měně: '
                . implode(', ', VatLedgerService::missingExchangeRateLabels($missingRates))
                . '. Při stažení XML se doplní z ČNB.';
        }

        $periodLabel = $period === 'quarterly' && $quarter !== null
            ? "tomto čtvrtletí"
            : "tomto měsíci";
        if (empty($rows) && !$isFollowUp) {
            // EPO prázdné hlášení odmítá tvrdou chybou („Alespoň jeden řádek … musí být
            // vyplněn" — ověřeno zkušebním předáním), takže tohle není kosmetika: bez
            // jediné EU dodávky se SH nepodává vůbec.
            $warnings[] = "V {$periodLabel} nejsou žádné EU dodávky — souhrnné hlášení se "
                . 'nepodává. EPO prázdné hlášení odmítne.';
        }

        // § 102 odst. 6 ZDPH: kvartální podání SH je přípustné JEN u výhradně
        // poskytovaných služeb (kód plnění 3). Jakmile je v období dodání zboží do JČS
        // (sh_type '0' nebo třístranný obchod '2'), musí se podávat MĚSÍČNĚ.
        if ($period === 'quarterly') {
            foreach ($rows as $r) {
                if (in_array((string) $r['sh_type'], ['0', '2'], true)) {
                    $warnings[] = 'Dodání zboží do JČS vyžaduje měsíční podání souhrnného '
                        . 'hlášení (§ 102 odst. 6 ZDPH) — kvartální podání je přípustné jen '
                        . 'u výhradně poskytovaných služeb. Toto kvartální podání obsahuje zboží.';
                    break;
                }
            }
        }

        [$dom, $shv] = EpoEnvelope::create('DPHSHV', '06.01');

        // VetaD — typ podání + perioda (mesic pro měsíční, ctvrt pro kvartální)
        $vetaD = $dom->createElement('VetaD');
        $vetaD->setAttribute('k_uladis', 'DPH');
        $vetaD->setAttribute('rok', (string) $year);
        if ($period === 'quarterly' && $quarter !== null) {
            $vetaD->setAttribute('ctvrt', (string) $quarter);
        } else {
            $vetaD->setAttribute('mesic', (string) $month);
        }
        // shvies_forma: EPO povoluje pouze [RN] — R = řádné, N = následné (opravné).
        // (Pozn.: dřívější 'B' bylo omylem převzato z KH — DPHSHV žádné 'B' nezná
        //  a EPO ho odmítá „...neodpovídá regulárnímu výrazu [RN]". Issue #238.)
        $vetaD->setAttribute('shvies_forma', $forma);
        $vetaD->setAttribute('dokument', 'SHV');
        $shv->appendChild($vetaD);

        // VetaP — identifikace poplatníka. Sdílený helper (stejný jako DPHDP3/DPHKH1):
        // odstranění akademických titulů + rozdělení jména na jmeno/prijmeni (#200) a
        // adresy na ulice/c_pop/c_orient. `includeContact: false`, protože DPHSHV XSD
        // atributy email/c_telef nezná (na rozdíl od DPH/KH) — EPO by je odmítlo.
        $vetaP = $dom->createElement('VetaP');
        EpoSupplierBlockBuilder::fillVetaP($vetaP, $supplier, includeContact: false);
        $shv->appendChild($vetaP);

        // VetaR — jednotlivé řádky souhrnného hlášení (per VAT_ID + typ plnění).
        // Pozn.: schéma EPO2 přejmenovalo dřívější VetaA1 → VetaR a atributy:
        //   vatid_pod  → c_vat
        //   kod_plneni → k_pln_eu
        // VetaS NENÍ větou pro storna (jak tu dřív stálo): podle XSD (k_cos, c_vat_puv) je
        // to věta REŽIMU SKLADU / call-off stock (§ 18). Storno se dělá atributem
        // `k_storno="A"` na VetaR — viz emise následného hlášení níže.
        $emitted = [];   // reálně vypsané řádky — podklad pro kontrolu duplicit DIČ×kód plnění
        $totalRows = 0;
        $totalAmount = 0.0;
        $stornoRows = 0;

        // Emisní kandidáti: u řádného všechny řádky období, u následného jen ROZDÍL proti
        // stavu, který ve VIES drží řádné hlášení + všechna předchozí následná.
        $baseline = [];
        $baselineSubmissionId = null;
        if ($isFollowUp) {
            [$baseline, $baselineSubmissionId] = $this->loadFollowUpBaseline($supplierId, $year, $month, $quarter);
        }
        $plan = $this->planRows($rows, $baseline, $isFollowUp);
        if ($isFollowUp && $plan === []) {
            throw new PostingException(
                'shv_amendment_no_change',
                'Následné souhrnné hlášení nemá co opravit — proti naposledy podanému stavu se '
                    . 'žádný řádek nezměnil. Nejdřív opravte doklady daného období.',
                422,
            );
        }

        $rowNum = 0;
        foreach ($plan as $p) {
            $rowNum++;
            $v = $dom->createElement('VetaR');
            // c_rad má v XSD u VetaR jen 2 číslice (max 99) — u firmy se 100+ EU
            // protistranami by hlášení neprošlo validací. Atribut je optional a slouží
            // jen k uspořádání řádků ve formuláři, takže nad 99 se prostě vynechá.
            if ($rowNum <= 99) {
                $v->setAttribute('c_rad', (string) $rowNum);
            }
            if ($p['storno']) {
                // Storno řádek: `k_storno="A"` + párovací trojice, podle které se na FÚ ve VIES
                // vyhledá shodný řádek řádného (popř. předchozího následného) hlášení a označí
                // se jako zrušený.
                //
                // Hodnota a počet plnění se na storno řádku OPAKUJÍ z rušeného řádku. XSD je
                // sice vyžaduje jen mimo storno, ale EPO na jejich vynechání upozorňuje:
                // „Počet plnění musí být u stornovacího řádku vyplněn v případě, že tato
                // hodnota byla uvedena v původním (stornovaném) řádku." (ověřeno zkušebním
                // předáním na zkus.mojedane.gov.cz)
                $v->setAttribute('k_storno', 'A');
                $stornoRows++;
            }
            $v->setAttribute('k_stat', $p['k_stat']);
            // c_vat = DIČ BEZ prefixu země (kód země nese k_stat). Issue #238.
            $v->setAttribute('c_vat', $p['vat_id']);
            $v->setAttribute('k_pln_eu', $p['sh_type']);
            $v->setAttribute('pln_hodnota', (string) $p['amount']);
            $v->setAttribute('pln_pocet', (string) $p['count']);
            if (!$p['storno']) {
                $totalRows++;
                $totalAmount += (float) $p['amount'];
            }
            $shv->appendChild($v);
            $emitted[] = $p;

            // Záporná hodnota plnění: dobropis do JČS převyšuje v období dodávky téže
            // protistraně. Do ŘÁDNÉHO hlášení taková věta nepatří — EPO ho se zápornou
            // pln_hodnota odmítne a oprava patří do NÁSLEDNÉHO hlášení se storno řádkem.
            // Musí to být vidět před odesláním, ne až z chybové hlášky portálu.
            if (!$p['storno'] && $p['amount'] < 0 && !$isFollowUp) {
                $warnings[] = sprintf(
                    'Souhrnné hlášení má zápornou hodnotu plnění u protistrany %s %s (%d Kč) — '
                    . 'dobropis v období převyšuje dodávky. Řádné hlášení zápornou hodnotu '
                    . 'nepřipouští; opravu podejte NÁSLEDNÝM souhrnným hlášením (typ podání '
                    . '„Následné"), které původní řádek stornuje a nahradí správnou hodnotou.',
                    $p['k_stat'],
                    $p['vat_id'],
                    $p['amount'],
                );
            }
        }

        // XSD: „Žádné DIČ nesmí být v hlášení uvedeno více než jednou se stejným kódem
        // plnění." Textové pravidlo, které schéma neuhlídá — zachytilo by ho až EPO.
        // Vznikne ze dvou kontaktních karet téže protistrany s různě zapsanou zemí.
        $seenPairs = [];
        foreach ($emitted as $p) {
            if ($p['storno']) {
                continue;
            }
            $pair = $p['k_stat'] . '|' . $p['vat_id'] . '|' . $p['sh_type'];
            if (isset($seenPairs[$pair])) {
                $warnings[] = sprintf(
                    'DIČ %s %s je v hlášení uvedeno dvakrát se stejným kódem plnění (%s) — '
                    . 'souhrnné hlášení to nepřipouští. Zkontrolujte, zda protistrana nemá '
                    . 'dvě kontaktní karty s různě zapsanou zemí.',
                    $p['k_stat'],
                    $p['vat_id'],
                    $p['sh_type'],
                );
            }
            $seenPairs[$pair] = true;
        }

        // Termín podání: 25. dne měsíce následujícího po konci období
        $deadlineMonth = $endMonth + 1;
        $deadlineYear = $year;
        if ($deadlineMonth > 12) { $deadlineMonth -= 12; $deadlineYear++; }
        // § 33/4 DŘ: termín padající na víkend/svátek se posouvá na další pracovní den.
        $deadline = CzechWorkingDays::deadline($deadlineYear, $deadlineMonth);

        // Následné SH má vlastní lhůtu: do 15 dnů ode dne zjištění změny (§ 102 odst. 6),
        // ne 25. den po skončení období — ten je u něj dávno pryč a UI by ho ukazovalo
        // jako „po termínu".
        if ($isFollowUp) {
            if ($dZjist !== null) {
                $deadline = CzechWorkingDays::shiftToWorkingDay(
                    (new \DateTimeImmutable($dZjist))->modify('+15 days')
                )->format('Y-m-d');
            } else {
                $warnings[] = 'Následné souhrnné hlášení se podává do 15 dnů ode dne zjištění '
                    . 'změny (§ 102 odst. 6 ZDPH) — bez data zjištění aplikace lhůtu nespočítá.';
            }
        }

        return [
            'xml'     => $dom->saveXML() ?: '',
            'summary' => [
                'period'              => $period === 'quarterly' && $quarter !== null
                    ? sprintf('%04d-Q%d', $year, $quarter)
                    : sprintf('%04d-%02d', $year, $month),
                'rows_count'          => $totalRows,
                'total_amount'        => round($totalAmount, 2),
                // Součet řádků PŘED zaokrouhlením (v haléřích) = to, co sčítá DPHDP3 ř. 20+21+31.
                // Rozdíl proti total_amount je jen zaokrouhlení po řádcích (viz formatAmount),
                // UI ho vysvětluje, aby nevypadal jako chyba. Následné hlášení nese jen opravné
                // řádky, takže tam srovnání s přiznáním nedává smysl.
                'total_amount_exact'  => $isFollowUp ? null : round(array_sum(array_map(
                    static fn (array $r): float => (float) $r['amount'],
                    $rows,
                )), 2),
                'rows'                => $rows,
                'submission_deadline' => $deadline,
                'variant'             => $variant,
                'shvies_forma'        => $forma,
                'is_follow_up'        => $isFollowUp,
                'd_zjist'             => $dZjist,
                'storno_rows'         => $stornoRows,
                'reference_submission_id' => $baselineSubmissionId,
            ],
            'warnings' => $warnings,
            'missing_rates' => $missingRates,
        ];
    }

    /**
     * Co se má reálně emitovat.
     *
     * Řádné (R): všechny řádky období, beze změny.
     *
     * Následné (N): OPRAVNÉ řádky proti stavu ve VIES. Pro každý klíč (stát, DIČ, kód plnění):
     *   - je v základně, ale ne v aktuálních datech  → jen STORNO (řádek se ruší),
     *   - je v obou a liší se hodnota nebo počet     → STORNO + nový řádek se správnou hodnotou,
     *   - je jen v aktuálních datech                 → jen nový řádek,
     *   - je v obou a shoduje se                     → NEEMITUJE SE (VIES by ho započetl dvakrát).
     *
     * @param list<array<string,mixed>> $rows
     * @param array<string,array{amount:int,count:int,k_stat:string,vat_id:string,sh_type:string}> $baseline
     * @return list<array{storno:bool, k_stat:string, vat_id:string, sh_type:string, amount:int, count:int}>
     */
    private function planRows(array $rows, array $baseline, bool $isFollowUp): array
    {
        $current = [];
        foreach ($rows as $r) {
            $kStat = (string) $r['k_stat'];
            $key = $kStat . '|' . $r['vat_id'] . '|' . $r['sh_type'];
            $current[$key] = [
                'storno'  => false,
                'k_stat'  => $kStat,
                'vat_id'  => (string) $r['vat_id'],
                'sh_type' => (string) $r['sh_type'],
                'amount'  => (int) $this->formatAmount((float) $r['amount']),
                'count'   => (int) $r['count'],
            ];
        }
        if (!$isFollowUp) {
            return array_values($current);
        }

        $plan = [];
        foreach ($baseline as $key => $b) {
            $now = $current[$key] ?? null;
            if ($now !== null && $now['amount'] === $b['amount'] && $now['count'] === $b['count']) {
                unset($current[$key]);   // beze změny — neopakovat
                continue;
            }
            // Hodnota a počet se přebírají z RUŠENÉHO řádku, ne z nových dat — storno říká
            // „tenhle řádek, jak byl podán, zruš".
            $plan[] = [
                'storno'  => true,
                'k_stat'  => $b['k_stat'],
                'vat_id'  => $b['vat_id'],
                'sh_type' => $b['sh_type'],
                'amount'  => $b['amount'],
                'count'   => $b['count'],
            ];
        }
        foreach ($current as $row) {
            $plan[] = $row;
        }
        return $plan;
    }

    /**
     * Stav, který za období drží VIES: řádky posledního podaného ŘÁDNÉHO hlášení, přehrané
     * všemi následujícími podanými NÁSLEDNÝMI (storno klíč odebere, běžný řádek nastaví).
     *
     * Bez podaného řádného hlášení nelze následné sestavit — nemá co stornovat a správce
     * daně by ho neměl s čím spárovat. Stejná brána jako u dodatečného přiznání k DPH:
     * základnou smí být jen prokazatelně podaný snapshot.
     *
     * @return array{0:array<string,array{amount:int,count:int,k_stat:string,vat_id:string,sh_type:string}>, 1:?int}
     */
    private function loadFollowUpBaseline(int $supplierId, int $year, int $month, ?int $quarter): array
    {
        $periodMonth = $quarter !== null ? null : $month;
        $chain = $this->submissions->findFiledChainForPeriod(
            $supplierId, 'dphshv', $year, $periodMonth, $quarter, ['R', 'N'],
        );
        $hasRegular = false;
        foreach ($chain as $c) {
            if ((string) ($c['form_variant'] ?? '') === 'R') {
                $hasRegular = true;
                break;
            }
        }
        if (!$hasRegular) {
            throw new PostingException(
                'shv_no_prior_submission',
                'Za dané období není evidováno podané řádné souhrnné hlášení — následné hlášení '
                    . 'opravuje řádky už podaného a bez něj ho nelze sestavit. Pokud jste řádné SH '
                    . 'podali, označte jeho snapshot v Archivu podání jako podaný.',
                422,
            );
        }

        $state = [];
        $lastId = null;
        foreach ($chain as $c) {
            if (empty($c['xml_content'])) {
                throw new PostingException(
                    'shv_baseline_incomplete',
                    'Archivované souhrnné hlášení nemá uložené XML — stav ve VIES nelze zrekonstruovat.',
                    422,
                );
            }
            $lastId = (int) $c['id'];
            if ((string) ($c['form_variant'] ?? '') === 'R') {
                $state = $this->parseShvRows((string) $c['xml_content'])[0];
                continue;
            }
            [$rows, $stornos] = $this->parseShvRows((string) $c['xml_content']);
            foreach ($stornos as $key => $_) {
                unset($state[$key]);
            }
            foreach ($rows as $key => $row) {
                $state[$key] = $row;
            }
        }
        return [$state, $lastId];
    }

    /**
     * Rozparsuje DPHSHV XML na [běžné řádky, storno klíče]. Klíč = k_stat|c_vat|k_pln_eu —
     * přesně ta trojice, podle které se řádek ve VIES páruje.
     *
     * @return array{0:array<string,array{amount:int,count:int,k_stat:string,vat_id:string,sh_type:string}>, 1:array<string,true>}
     */
    private function parseShvRows(string $xml): array
    {
        $rows = [];
        $stornos = [];
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($xml)) {
            return [$rows, $stornos];
        }
        foreach ($dom->getElementsByTagName('VetaR') as $el) {
            if (!$el instanceof \DOMElement) {
                continue;
            }
            $kStat = $el->getAttribute('k_stat');
            $vatId = $el->getAttribute('c_vat');
            $type  = $el->getAttribute('k_pln_eu');
            if ($kStat === '' || $vatId === '' || $type === '') {
                continue;
            }
            $key = $kStat . '|' . $vatId . '|' . $type;
            if (strtoupper($el->getAttribute('k_storno')) === 'A') {
                $stornos[$key] = true;
                unset($rows[$key]);
                continue;
            }
            $rows[$key] = [
                'amount'  => (int) $el->getAttribute('pln_hodnota'),
                'count'   => (int) $el->getAttribute('pln_pocet'),
                'k_stat'  => $kStat,
                'vat_id'  => $vatId,
                'sh_type' => $type,
            ];
            unset($stornos[$key]);
        }
        return [$rows, $stornos];
    }

    /** Normalizace vstupního data (Y-m-d) — null pokud prázdné/neplatné. */
    private function normalizeDate(?string $date): ?string
    {
        $date = trim((string) $date);
        if ($date === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($date))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Sebere EU dodávky (vystavené faktury s VAT kódem 20/22 + EU klient s DIČ).
     * Agreguje per (k_stat, vat_id, sh_type).
     *
     * `k_stat` je podle XSD kód státu, který DIČ PŘIDĚLIL — ne země sídla protistrany.
     * Německá firma registrovaná k DPH v Rakousku (ATU…) proto dostane `AT`, ne `DE`.
     * Prefix uloženého DIČ má tedy přednost před zemí adresy; při rozporu varujeme,
     * protože to bývá překlep v kartě kontaktu.
     *
     * @return list<array{country_iso2:string, k_stat:string, vat_id:string, sh_type:string,
     *                   amount:float, count:int, counterparty_name:string}>
     */
    private function collectEuSupplies(
        int $supplierId,
        string $start,
        string $end,
        array &$missingRates = [],
        array &$warnings = [],
    ): array {
        // Projekce kanonických řádků (VatLedgerService) — vystavená EU B2B plnění:
        // kód 20/21/22, EU země (≠ CZ) s DIČ. base_czk je už PŘEPOČTENÝ na CZK kurzem
        // faktury (oprava staré chyby — SH dříve sčítalo total_without_vat v cizí měně).
        $result = [];
        $missingSeen = [];
        $missingVatSeen = [];
        foreach ($this->ledger->rows($supplierId, $start, $end, includeDrafts: false) as $r) {
            if ($r['source'] !== 'sale') continue;
            $code = $r['code'];
            if ($code === null || !isset(self::VAT_CODE_TO_SH_TYPE[$code])) continue;
            if (!$r['country_is_eu'] || $r['country_iso2'] === 'CZ' || $r['country_iso2'] === null) continue;

            // k_stat = stát, který DIČ přidělil (prefix DIČ má přednost před zemí adresy),
            // c_vat = DIČ BEZ TOHOTO prefixu (XSD: „bez kódu státu"). Obojí určuje jedna
            // sdílená funkce — dřív se k_stat bral z prefixu, ale strhával se jen prefix
            // země ADRESY, takže při rozdílu šlo `k_stat="PL" c_vat="PL…"`. Řecko EL/GR,
            // alfanumerická francouzská národní část a DIČ bez prefixu viz euVatRegistration().
            $rawDic = trim((string) ($r['counterparty_dic'] ?? ''));
            $addressStat = KontrolniHlaseniBuilder::khCountryCode((string) $r['country_iso2']);
            $registration = KontrolniHlaseniBuilder::euVatRegistration($rawDic, (string) $r['country_iso2']);
            $kStat = $registration['k_stat'];
            $vatId = $registration['vat_id'];
            if ($vatId === '') {
                // Dřív se takový řádek tiše vypustil: dodávka zmizela ze souhrnného hlášení,
                // ale na ř. 20/21 přiznání zůstala — rozdíl, na který nic neupozornilo.
                $doc = (string) ($r['doc_number'] ?? '') ?: ('#' . (string) $r['invoice_id']);
                $label = sprintf('%s (%s)', (string) $r['counterparty_name'], $doc);
                if (!isset($missingVatSeen[$label])) {
                    $missingVatSeen[$label] = true;
                    $warnings[] = sprintf(
                        'EU dodávka bez DIČ protistrany se do souhrnného hlášení nedostane: %s. '
                        . 'V přiznání k DPH přitom na ř. 20/21 zůstává — doplňte DIČ, nebo plnění '
                        . 'překlasifikujte.',
                        $label,
                    );
                }
                continue; // bez DIČ nelze podat SH
            }
            if ($kStat !== $addressStat) {
                $warnings[] = sprintf(
                    'Protistrana %s má adresu v zemi %s, ale DIČ vydané státem %s — do souhrnného '
                    . 'hlášení jde %s (kód státu, který DIČ přidělil). Ověřte, že je to správně.',
                    (string) $r['counterparty_name'],
                    $addressStat,
                    $kStat,
                    $kStat,
                );
            }

            // Daňová pojistka: EU dodávka v cizí měně bez zafixovaného kurzu by se
            // vykázala s náhradním kurzem 1.0 (EUR jako CZK). Sesbíráme ji do
            // $missingRates — akce ji při stažení doplní z ČNB (issue #238).
            if (!empty($r['exchange_rate_missing'])) {
                $key = 'sale:' . (int) $r['invoice_id'];
                if (!isset($missingSeen[$key])) {
                    $missingSeen[$key] = true;
                    $doc = (string) ($r['doc_number'] ?? '') ?: ('#' . (string) $r['invoice_id']);
                    $missingRates[] = [
                        'invoice_id' => (int) $r['invoice_id'],
                        'source'     => 'sale',
                        'currency'   => (string) $r['currency'],
                        'tax_date'   => isset($r['tax_date']) ? (string) $r['tax_date'] : null,
                        'issue_date' => isset($r['issue_date']) ? (string) $r['issue_date'] : null,
                        'doc'        => $doc,
                    ];
                }
            }

            $shType = self::VAT_CODE_TO_SH_TYPE[$code];
            $key = "{$kStat}|{$vatId}|{$shType}";
            if (!isset($result[$key])) {
                $result[$key] = [
                    'country_iso2'      => $r['country_iso2'],
                    'k_stat'            => $kStat,
                    'vat_id'            => $vatId,
                    'sh_type'           => $shType,
                    'amount'            => 0.0,
                    'count'             => 0,
                    'counterparty_name' => (string) $r['counterparty_name'],
                    '_invoice_ids'      => [],
                ];
            }
            $result[$key]['amount'] += (float) $r['base_czk'];
            // Počet plnění = počet DISTINCT faktur (řádky jsou per-položka).
            $result[$key]['_invoice_ids'][(int) $r['invoice_id']] = true;
        }
        // Finalizace: count = počet distinct faktur, odstranit pomocné pole.
        return array_map(static function (array $row): array {
            $row['count'] = count($row['_invoice_ids']);
            unset($row['_invoice_ids']);
            return $row;
        }, array_values($result));
    }

    /**
     * Note: Souhrnné hlášení **nevyžaduje** být plátcem DPH.
     * Podávají ho i **identifikované osoby** (neplátci, kteří poskytují služby EU plátcům
     * nebo nakupují zboží z EU nad limit). DIČ je u identifikované osoby ve formátu
     * CZ + RČ/IČO, prefix CZ se v SH XML ponechává.
     *
     * @return list<string>
     */
    private function validateSupplier(array $s): array
    {
        $w = [];
        if (empty($s['financial_office_code'])) $w[] = 'Chybí kód finančního úřadu.';
        if (empty($s['dic'])) $w[] = 'Chybí DIČ (povinné i pro identifikovanou osobu).';
        return $w;
    }

    private function loadSupplier(int $supplierId, ?string $statusDate = null): array
    {
        return EpoSupplierBlockBuilder::loadSupplier($this->db->pdo(), $supplierId, $statusDate);
    }


    /**
     * Hodnota plnění v celých korunách — zaokrouhlení na plnou částku, tedy OD NULY.
     *
     * Nahoru to předepisuje sama struktura EPO: popis atributu `pln_hodnota` v DPHSHV
     * (api/xsd/dphshv.xsd, shodně s živým https://adisspr.mfcr.cz/adis/jepo/schema/dphshv_epo2.xsd)
     * říká „Celková hodnota plnění se zaokrouhlí na celé koruny nahoru. Celková hodnota
     * plnění musí být vždy celé číslo." a doslova totéž stojí v pokynech k tiskopisu SH
     * (25 5525 vzor č. 7, „Celková hodnota plnění v Kč"). Zaokrouhluje se celková hodnota
     * řádku (stát × DIČ × kód plnění, dobropisy v ní záporně), kdežto přiznání (DPHDP3
     * ř. 20, 21, 31) sčítá haléře a zaokrouhluje až součet. Součet SH proto může přiznání
     * převýšit až o 1 Kč na řádek a není to chyba; VatCrossCheckService kvůli tomu
     * porovnává nezaokrouhlené řádky.
     *
     * Záporná hodnota (dobropis do JČS převyšuje dodávky) jde od nuly, aby se opravovaná
     * částka nezmenšila. Směr u záporné hodnoty pokyny ani struktura EPO neupravují.
     */
    private function formatAmount(float $amount): string
    {
        return (string) EpoAmount::wholeCzkUp($amount);
    }
}
