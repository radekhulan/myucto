<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Repository\TaxReturnRepository;
use MyInvoice\Service\Tax\Return\DppoEpoXmlParser;
use MyInvoice\Service\Tax\Return\DppoReconciliationDiffBuilder;
use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\DppoReturnDataProvider;
use MyInvoice\Service\Tax\Return\TaxLossService;
use MyInvoice\Service\Tax\Return\TaxReturnException;
use MyInvoice\Service\Tax\Return\TaxReturnService;

/**
 * Převzetí podaného přiznání k DPPO (EPO XML DPPDP9, řádné, opravné i dodatečné) do
 * rozpracovaného přiznání MyÚčta a do evidence daňových ztrát.
 *
 * Co se z podání přebírá a co MyÚčto spočte z účetnictví, určuje sdílené pravidlo
 * {@see FiledDppoInputs} (totéž volá převod z PREMIER). Náhled ({@see preview()}) nic
 * neukládá: ukáže navržené vstupy, dosavadní vstupy přiznání a porovnání výpočtu
 * s navrženými vstupy proti podání, rozdělené na řádky spočtené z účetnictví a na řádky
 * ze vstupů. Převzetí ({@see apply()}) vstupy uloží přes {@see TaxReturnService::saveInputs()}
 * (stejná sanitizace a kontrola verze jako ruční uložení) a zapíše do evidence ztrát
 * ztrátu vzniklou v roce podání a ztrátu uplatněnou na ř. 230.
 *
 * Finální přiznání se nikdy nemění. Rozpracované se přepíše jen na výslovné přání
 * (`$replaceDraft`); přepíšou se jen vstupy, které převzetí vlastní ({@see OWNED_KEYS}),
 * ostatní (lhůta, účet pro přeplatek, poznámky…) zůstanou.
 */
final class FiledDppoImporter
{
    /** Vstupy přiznání, které převzetí nastaví (nebo vynuluje, když je podání nemá). */
    public const OWNED_KEYS = [
        'manual_increase_items', 'manual_decrease_items', 'loss_carryforward', 'rnd_deduction',
        'education_deduction', 'donations', 'donation_items', 'disabled_employees_avg',
        'disabled_employees_severe_avg', 'stopped_execution_credit',
    ];

    /** Řádky spočtené z účetnictví a karet majetku; rozdíl na nich převzetí neřeší. */
    public const ACCOUNTING_LINES = [10, 40, 50, 150, 160];

    /** Mezisoučty a výsledné řádky; rozdíl na nich je jen důsledkem rozdílu jinde. */
    public const DERIVED_LINES = [70, 170, 200, 250, 270, 290, 310, 340, 360];

    public const TEXTS = [
        'line' => 'Úprava základu z podaného přiznání (ř. %s)',
        'line40' => 'Nedaňové výdaje z podaného přiznání nad nedaňové účty (ř. 40)',
        'line40_travel' => 'Vrácení PHM do základu u paušálu na dopravu (ř. 40 z podaného přiznání)',
        'travel' => 'Paušální výdaj na dopravu (§ 24 odst. 2 písm. zt), ř. 112 z podaného přiznání',
    ];

    /** Atributy mimo řádky II. oddílu, které jsou jen informativní (převzetí je nepotřebuje). */
    private const INFORMATIVE_EXTRA = ['kc_ii_220', 'kc_ii320_330', 'kc_ii270_280'];

    private const FORMA_VARIANT = ['B' => 'radne', 'O' => 'opravne', 'D' => 'dodatecne', 'E' => 'dodatecne'];

    public function __construct(
        private readonly DppoEpoXmlParser $parser,
        private readonly DppoReturnDataProvider $data,
        private readonly DppoReturnCalculator $calc,
        private readonly DppoReconciliationDiffBuilder $diffBuilder,
        private readonly TaxConstantsRepository $constants,
        private readonly TaxReturnRepository $returns,
        private readonly TaxReturnService $service,
        private readonly TaxLossService $losses,
        private readonly Connection $db,
    ) {}

    /**
     * Náhled převzetí: nic neukládá.
     *
     * @return array<string,mixed>
     */
    public function preview(int $supplierId, string $xml, ?int $year = null, string $variant = 'radne', int $variantSeq = 1): array
    {
        $parsed = $this->parser->parse($xml);
        $filedYear = $this->filedYear($parsed);
        if ($year !== null && $filedYear !== $year) {
            throw new TaxReturnException('reconcile_year_mismatch', sprintf(
                'Nahrané přiznání je za rok %d, ale přebíráte ho do roku %d. Nahrajte přiznání za rok %d.', $filedYear, $year, $year
            ), 422);
        }
        $this->assertSameCompany($supplierId, $parsed);
        try {
            $const = $this->constants->forExactYear($filedYear);
        } catch (\OutOfRangeException $e) {
            throw new TaxReturnException('missing_tax_constants', $e->getMessage(), 422);
        }

        $row = $this->returns->find($supplierId, $filedYear, 'po', $variant, $variantSeq);
        $current = $row !== null ? (array) $row['inputs'] : [];
        $gathered = $this->data->gather($supplierId, $filedYear, $current);
        $notices = [];
        $proposed = $this->proposedInputs($parsed, $gathered, $const, $current, $notices);
        $proposed['filed_source'] = [
            'forma' => $parsed['dapdpp_forma'],
            'verze_pis' => $parsed['verze_pis'],
            'period_to' => (string) ($parsed['zdobd_do'] ?? ''),
            'file_sha1' => sha1($xml),
            'imported_at' => date('Y-m-d\TH:i:s'),
        ];

        $result = $this->calc->compute($gathered, $proposed, $const);
        $diff = $this->diffBuilder->build((array) $result['lines'], $parsed['lines']);
        $inputMismatches = 0;
        foreach ($diff['rows'] as &$r) {
            $r['kind'] = in_array($r['line'], self::ACCOUNTING_LINES, true) ? 'accounting'
                : (in_array($r['line'], self::DERIVED_LINES, true) ? 'derived' : 'input');
            if ($r['kind'] === 'input' && !$r['match']) {
                $inputMismatches++;
            }
        }
        unset($r);

        $formaVariant = self::FORMA_VARIANT[$parsed['dapdpp_forma']] ?? 'radne';
        if ($formaVariant !== $variant) {
            $notices[] = sprintf('Podání je %s přiznání, přebírá se do přiznání druhu „%s". Hodnoty dodatečného přiznání jsou plné (ne rozdílové), takže řádné přiznání převzetím dostane stav po dodatečném.',
                $this->formaLabel($parsed['dapdpp_forma']), $variant);
        }

        [$yearLoss, $appliedLoss] = $this->lossFigures($parsed);
        $blocked = null;
        if ($row !== null && $row['status'] === 'final') {
            $blocked = 'Přiznání za tento rok je finální. Převzít lze jen do rozpracovaného přiznání; nejdřív ho vraťte do rozpracovaného stavu.';
        }

        return [
            'year' => $filedYear,
            'variant' => $variant,
            'variant_seq' => $variantSeq,
            'filing' => [
                'dapdpp_forma' => $parsed['dapdpp_forma'],
                'verze_pis' => $parsed['verze_pis'],
                'zdobd_od' => $parsed['zdobd_od'],
                'zdobd_do' => $parsed['zdobd_do'],
                'supplier' => $parsed['supplier'],
            ],
            'current' => [
                'exists' => $row !== null,
                'status' => $row['status'] ?? null,
                'row_version' => $row !== null ? (int) $row['row_version'] : 0,
                'inputs' => $current,
            ],
            'proposed' => $proposed,
            'losses' => ['year_loss' => $yearLoss, 'applied' => $appliedLoss],
            'diff' => $diff,
            'input_mismatches' => $inputMismatches,
            'notices' => $notices,
            'blocked' => $blocked,
        ];
    }

    /**
     * Převezme podání do rozpracovaného přiznání a do evidence ztrát.
     *
     * @return array<string,mixed> náhled + `status` (created|replaced|kept) a hlášení evidence ztrát
     */
    public function apply(
        int $supplierId,
        string $xml,
        ?int $userId,
        ?int $year = null,
        string $variant = 'radne',
        int $variantSeq = 1,
        bool $replaceDraft = false,
        bool $registerLosses = true,
    ): array {
        return (new \MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $xml, $userId, $year, $variant, $variantSeq, $replaceDraft, $registerLosses): array {
            $this->losses->lockNativeContext($supplierId, 'po');
            $preview = $this->preview($supplierId, $xml, $year, $variant, $variantSeq);
            if ($preview['blocked'] !== null) {
                throw new TaxReturnException('return_finalized', $preview['blocked'], 409);
            }
            $filedYear = (int) $preview['year'];
            $status = 'created';
            if ($preview['current']['exists']) {
                if (!$replaceDraft) {
                    $preview['status'] = 'kept';
                    $preview['notices'][] = 'Rozpracované přiznání za tento rok už existuje, vstupy se nepřepsaly.';
                    return $preview;
                }
                $status = 'replaced';
            }
            $this->service->saveInputs($supplierId, $filedYear, 'po', (array) $preview['proposed'], (int) $preview['current']['row_version'], $userId, $variant, $variantSeq);
            $preview['status'] = $status;

            if ($registerLosses) {
                $preview['notices'] = array_merge($preview['notices'], $this->registerLosses($supplierId, $filedYear, $preview['losses']['year_loss'], $preview['losses']['applied']));
            }
            return $preview;
        });
    }

    /**
     * Ztráta vzniklá v roce podání a uplatněná na ř. 230 do evidence ztrát. Rok, jehož
     * přiznání je v MyÚčtu finální, má evidenci z vlastního výpočtu a nemění se.
     *
     * @return list<string> hlášení pro uživatele
     */
    public function registerLosses(int $supplierId, int $year, float $yearLoss, float $appliedLoss): array
    {
        return (new \MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $year, $yearLoss, $appliedLoss): array {
            $this->losses->lockNativeContext($supplierId, 'po');
            return $this->registerLossesInTransaction($supplierId, $year, $yearLoss, $appliedLoss);
        });
    }

    private function registerLossesInTransaction(int $supplierId, int $year, float $yearLoss, float $appliedLoss): array
    {
        foreach ($this->returns->listVariants($supplierId, $year, 'po') as $v) {
            if (($v['status'] ?? '') === 'final') {
                return [sprintf('Evidence daňových ztrát za rok %d se nemění: přiznání za tento rok je v MyÚčtu finální.', $year)];
            }
        }
        $notices = [];
        $available = (float) ($this->losses->card($supplierId, $year, 'po')['available_total'] ?? 0.0);
        if ($appliedLoss > $available + 0.005) {
            $notices[] = sprintf(
                'Podání uplatňuje na ř. 230 ztrátu %s Kč, evidence ztrát ale k roku %d eviduje k uplatnění jen %s Kč. Převezměte nejdřív přiznání roku, ve kterém ztráta vznikla, nebo ztrátu doplňte do evidence (CLI tax-return-import.php --loss=ROK:ČÁSTKA). Uplatnění se zapsalo jen do výše evidované ztráty.',
                number_format($appliedLoss, 0, ',', ' '), $year, number_format($available, 0, ',', ' ')
            );
        }
        $returnId = $this->returns->find($supplierId, $year, 'po')['id'] ?? null;
        try {
            $this->losses->reconcileFinalize($supplierId, 'po', $year, $yearLoss, $appliedLoss, $returnId !== null ? (int) $returnId : null);
        } catch (\DomainException $e) {
            $notices[] = $e->getMessage();
            return $notices;
        }
        if ($yearLoss > 0.0) {
            $notices[] = sprintf('Do evidence ztrát zapsána daňová ztráta roku %d: %s Kč.', $year, number_format($yearLoss, 0, ',', ' '));
        }
        if (min($appliedLoss, $available) > 0.0) {
            $notices[] = sprintf('Do evidence ztrát zapsáno uplatnění ztráty v roce %d: %s Kč.', $year, number_format(min($appliedLoss, $available), 0, ',', ' '));
        }
        return $notices;
    }

    /**
     * Rok zdaňovacího období podaného přiznání (konec, u chybějícího konce začátek).
     *
     * @param array<string,mixed> $parsed výstup {@see DppoEpoXmlParser::parse()}
     */
    public function filedYear(array $parsed): int
    {
        $date = $parsed['zdobd_do'] ?? $parsed['zdobd_od'] ?? null;
        if (!is_string($date) || $date === '') {
            throw new TaxReturnException('filed_period_missing', 'V podaném přiznání chybí zdaňovací období (zdobd_od/zdobd_do).', 422);
        }
        return (int) substr($date, 0, 4);
    }

    /**
     * @param array<string,mixed> $parsed
     * @param array<string,mixed> $gathered
     * @param array<string,mixed> $const
     * @param array<string,mixed> $current
     * @param list<string> $notices
     * @return array<string,mixed>
     */
    private function proposedInputs(array $parsed, array $gathered, array $const, array $current, array &$notices): array
    {
        $lines = (array) $parsed['lines'];
        $appendix = (array) ($parsed['appendix'] ?? []);
        $travel = false;
        foreach ((array) ($appendix[FiledDppoInputs::TRAVEL_LINE] ?? []) as $text) {
            $travel = $travel || DppoReturnCalculator::looksLikeFlatRateTravel((string) $text);
        }
        $built = FiledDppoInputs::build($lines, DppoReturnCalculator::accountingAdjustments($gathered), $travel, self::TEXTS, (float) ($parsed['advances_paid'] ?? 0), ReconciliationTolerance::FILING_ROUNDING);

        foreach ($built['shortfalls'] as $line => $s) {
            $notices[] = $line === 40
                ? sprintf('Část ř. 40 spočtená z účetnictví (nedaňové účty a rozdíl zůstatkových cen vyřazeného majetku, %s Kč) je vyšší než ř. 40 podání (%s Kč), z ř. 40 se nic nepřevzalo. Zkontrolujte daňovou uznatelnost účtů a vyřazení majetku; chybí-li podání část ř. 40, doplňte ji ručně.',
                    number_format($s['computed'], 2, ',', ' '), number_format($s['filed'], 2, ',', ' '))
                : sprintf('Rozdíl zůstatkových cen vyřazeného majetku podle karet (%s Kč) je vyšší než ř. 160 podání (%s Kč). Zkontrolujte vyřazení majetku.',
                    number_format($s['computed'], 2, ',', ' '), number_format($s['filed'], 2, ',', ' '));
        }

        $inputs = $current;
        foreach (self::OWNED_KEYS as $key) {
            unset($inputs[$key]);
        }
        $imported = $built['inputs'];
        foreach (['manual_increase_items', 'manual_decrease_items'] as $key) {
            if (isset($imported[$key])) {
                $imported[$key] = array_map(fn (array $item): array => $this->withAppendixText($item, $appendix), $imported[$key]);
            }
        }
        if (!isset($imported['tax_paid_advances']) && isset($current['tax_paid_advances'])) {
            // Podání zálohy neuvádí (kc_v_1 prázdné): zálohy spárované z banky zůstanou.
            $imported['tax_paid_advances'] = $current['tax_paid_advances'];
        }
        $inputs = array_merge($inputs, $imported, $this->credits($parsed, (float) ($lines[300] ?? 0), $const, $notices));

        $unsupported = [];
        foreach ((array) $parsed['extra'] as $attr => $e) {
            if (!in_array($attr, self::INFORMATIVE_EXTRA, true) && (float) ($e['value'] ?? 0) !== 0.0) {
                $unsupported[] = $attr . ' = ' . number_format((float) $e['value'], 0, ',', ' ');
            }
        }
        if ($unsupported !== []) {
            $notices[] = 'Podání má vyplněné údaje, které převzetí do vstupů nepřenáší (doplňte je v přiznání ručně): ' . implode(', ', $unsupported) . '.';
        }
        return $inputs;
    }

    /**
     * Slevy § 35 (ř. 300) z tabulky H: zaměstnanci se zdravotním postižením se převedou
     * na průměrný přepočtený počet (výpočet slevu spočte z konstanty roku), zastavená
     * exekuce přímo částkou.
     *
     * @param array<string,mixed> $parsed
     * @param array<string,mixed> $const
     * @param list<string> $notices
     * @return array<string,float>
     */
    private function credits(array $parsed, float $line300, array $const, array &$notices): array
    {
        $credits = (array) ($parsed['credits'] ?? []);
        $out = [];
        $perDisabled = (float) ($const['disabled_employee_credit'] ?? 0);
        $perSevere = (float) ($const['disabled_employee_credit_severe'] ?? 0);
        $f1 = (float) ($credits['kc_dpp_f1'] ?? 0);
        $f2 = (float) ($credits['kc_dpp_f2'] ?? 0);
        $f3 = (float) ($credits['kc_dpp_f3'] ?? 0);
        if ($f1 > 0.0 && $perDisabled > 0.0) {
            $out['disabled_employees_avg'] = round($f1 / $perDisabled, 4);
        }
        if ($f2 > 0.0 && $perSevere > 0.0) {
            $out['disabled_employees_severe_avg'] = round($f2 / $perSevere, 4);
        }
        if ($f3 > 0.0) {
            $out['stopped_execution_credit'] = round($f3, 2);
        }
        if ($line300 > 0.0 && $f1 + $f2 + $f3 <= 0.0 && $perDisabled > 0.0) {
            // Bez rozpisu tabulky H je nejčastější sleva na zaměstnance se zdravotním
            // postižením (§ 35 odst. 1 písm. a); přepočtený počet může být i desetinný.
            $out['disabled_employees_avg'] = round($line300 / $perDisabled, 4);
            $notices[] = sprintf('Podání uplatňuje slevu na dani %s Kč (ř. 300) bez rozpisu v tabulce H. Převzata jako sleva na zaměstnance se zdravotním postižením (přepočtený počet %s); jde-li o těžší postižení nebo zastavenou exekuci, opravte ji v přiznání.',
                number_format($line300, 0, ',', ' '), rtrim(rtrim(number_format($out['disabled_employees_avg'], 4, ',', ''), '0'), ','));
        }
        return $out;
    }

    /**
     * Text položky ze zvláštní přílohy podání, když má řádek právě jeden záznam.
     *
     * @param array<string,mixed> $item
     * @param array<int,list<string>> $appendix
     * @return array<string,mixed>
     */
    private function withAppendixText(array $item, array $appendix): array
    {
        $line = (int) ($item['line'] ?? 0);
        $texts = (array) ($appendix[$line] ?? []);
        if (count($texts) === 1 && empty($item['kind'])) {
            $item['text'] = mb_substr(trim((string) $texts[0]) . ' (ř. ' . $line . ' z podaného přiznání)', 0, 255);
        }
        return $item;
    }

    /**
     * Ztráta vzniklá v roce (záporný základ ř. 220, bez něj ř. 200) a ztráta uplatněná (ř. 230).
     *
     * @param array<string,mixed> $parsed
     * @return array{0:float,1:float}
     */
    private function lossFigures(array $parsed): array
    {
        $base = isset($parsed['extra']['kc_ii_220'])
            ? (float) $parsed['extra']['kc_ii_220']['value']
            : (float) ($parsed['lines'][200] ?? 0);
        return [$base < 0.0 ? round(-$base, 2) : 0.0, round(max(0.0, (float) ($parsed['lines'][230] ?? 0)), 2)];
    }

    /** @param array<string,mixed> $parsed */
    private function assertSameCompany(int $supplierId, array $parsed): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT ic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $ic = $stmt->fetchColumn();
        if ($ic === false) {
            throw new TaxReturnException('supplier_not_found', 'Firma nenalezena.', 404);
        }
        $filedIc = trim((string) ($parsed['supplier']['ic'] ?? ''));
        $ic = trim((string) $ic);
        if ($filedIc !== '' && $ic !== '' && ltrim($filedIc, '0') !== ltrim($ic, '0')) {
            throw new TaxReturnException('filed_other_company', sprintf(
                'Nahrané přiznání patří IČO %s, aktuální firma má IČO %s. Převzít lze jen přiznání této firmy.', $filedIc, $ic
            ), 422);
        }
    }

    private function formaLabel(string $forma): string
    {
        return match ($forma) {
            'O' => 'opravné',
            'D', 'E' => 'dodatečné',
            default => 'řádné',
        };
    }
}
