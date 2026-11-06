<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Jmhz;

use MyInvoice\Service\Payroll\IncomeTax\StudentTaxCredit;
use MyInvoice\Service\Payroll\IncomeTax\TaxCreditKind;

/**
 * Co se z hlášení propíše do zákonné evidence osoby — prohlášení poplatníka,
 * nepřenositelné slevy a slevu pracujícího důchodce.
 *
 * Náhled i zápis volají TYTÉŽ funkce nad sekcemi z `editorView()`, takže
 * náhled nemůže slíbit něco jiného, než zápis udělá. Každá funkce vrací cílový
 * stav všech sekcí; zapisovač ho pošle celý do `save()` (repozitář ukládá
 * cílový stav, vynechaný řádek by smazal).
 *
 * ── Proč jsou 10299–10302 nárok, ne částka ──────────────────────────────────
 * {@see \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1DocumentResolver::taxCreditsCzk()}
 * vykazuje v těchto prvcích NÁROK podle prohlášení (rozpad
 * `claimed_non_refundable_credit_breakdown`), ne částku, která se do zálohy
 * vešla — ta je rozdíl 10298 − 10305. Přítomnost prvku s kladnou částkou je
 * proto tvrzení „poplatník slevu uplatňuje" a jako takové se převezme; výše
 * nároku se neukládá, evidence ji počítá sama podle rulesetu.
 */
final class JmhzStatutoryChanges
{
    public const CREDIT_KINDS = [
        'taxpayer',
        'disability-basic',
        'disability-extended',
        'ztp-p',
        'student',
        'student-doctoral',
    ];

    public const CREDIT_LABELS = [
        'taxpayer' => 'Sleva na poplatníka',
        'disability-basic' => 'Sleva na invaliditu I. a II. stupně',
        'disability-extended' => 'Sleva na invaliditu III. stupně',
        'ztp-p' => 'Sleva na držitele průkazu ZTP/P',
        'student' => 'Sleva na studenta',
        'student-doctoral' => 'Sleva na studenta (doktorské studium)',
    ];

    /**
     * Druh slevy na studenta podle hlášení: 10565 výše slevy, 10566 příznak
     * doktorského studijního programu (StudentTaxCredit drží obě podoby jako
     * samostatné druhy, liší se věkovou hranicí).
     */
    public static function studentCreditKind(bool $doctoral): string
    {
        return $doctoral ? TaxCreditKind::StudentDoctoral->value : TaxCreditKind::Student->value;
    }

    /**
     * @param array<string,list<array<string,mixed>>> $sections
     * @return array{sections:array<string,list<array<string,mixed>>>,changed:bool,current:?string,imported:string,warning:?string}
     */
    public static function declaration(
        array $sections,
        ?string $frozenThrough,
        string $monthStart,
        bool $signed,
        string $reference,
    ): array {
        $imported = $signed ? 'signed' : 'not-signed';
        $result = JmhzEvidenceTimeline::setFrom(
            $sections['tax_declarations'] ?? [],
            $monthStart,
            ['status' => $imported, 'evidence_reference' => $reference],
            ['status'],
            true,
            $frozenThrough,
        );
        $sections['tax_declarations'] = $result['rows'];

        return [
            'sections' => $sections,
            'changed' => $result['changed'],
            'current' => isset($result['current']['status']) ? (string) $result['current']['status'] : null,
            'imported' => $imported,
            'warning' => $result['warning'],
        ];
    }

    /**
     * Uplatněné slevy se převezmou od měsíce hlášení. Slevu, kterou hlášení
     * neuvádí, import ukončí jen tehdy, když ji sám dřív založil (odkaz na
     * doklad začíná `jmhz-import:`); ručně zapsanou slevu jen ohlásí.
     *
     * @param array<string,list<array<string,mixed>>> $sections
     * Sleva na studenta má interval nároku vždy ukončený věkovou hranicí
     * (§ 35bc odst. 2 ZDP, {@see StudentTaxCredit::intervalProblem()}). Nově
     * založený řádek proto končí nejpozději posledním měsícem nároku podle
     * data narození; bez data narození, mimo věkovou hranici nebo před
     * účinností slevy se nepřevezme a import to ohlásí.
     *
     * @param array<string,int> $claims druh => částka nároku z hlášení
     * @return array{sections:array<string,list<array<string,mixed>>>,changes:list<array{kind:string,action:string,current:?string}>,warnings:list<string>}
     */
    public static function credits(
        array $sections,
        ?string $frozenThrough,
        string $monthStart,
        array $claims,
        string $reference,
        ?string $birthDate = null,
    ): array {
        $rows = $sections['tax_credit_claims'] ?? [];
        $changes = [];
        $warnings = [];
        foreach (self::CREDIT_KINDS as $kind) {
            $series = [];
            $others = [];
            foreach ($rows as $row) {
                if (($row['credit_kind'] ?? null) === $kind) {
                    $series[] = $row;
                } else {
                    $others[] = $row;
                }
            }
            $creditKind = TaxCreditKind::from($kind);
            $studentLimit = null;
            if (($claims[$kind] ?? 0) > 0 && StudentTaxCredit::isStudent($creditKind)) {
                $studentLimit = self::studentClaimLimit($creditKind, $monthStart, $birthDate);
                if ($studentLimit === null) {
                    $warnings[] = self::CREDIT_LABELS[$kind] . ' z hlášení za ' . substr($monthStart, 0, 7)
                        . ' se nepřevzala: bez data narození zaměstnance, mimo věkovou hranici nebo před'
                        . ' účinností slevy (§ 35bc ZDP) nejde nárok doložit. Zapište ji ručně v zákonné'
                        . ' evidenci osoby.';
                    $rows = [...$others, ...$series];
                    continue;
                }
            }
            if (($claims[$kind] ?? 0) > 0) {
                $result = JmhzEvidenceTimeline::setFrom(
                    $series,
                    $monthStart,
                    ['credit_kind' => $kind, 'evidence_status' => 'verified', 'evidence_reference' => $reference],
                    ['evidence_status'],
                    false,
                    $frozenThrough,
                );
                if ($studentLimit !== null) {
                    $result['rows'] = array_map(
                        static fn (array $row): array => ($row['id'] ?? null) === null
                            && ($row['effective_to'] === null || (string) $row['effective_to'] > $studentLimit)
                                ? ['effective_to' => $studentLimit] + $row
                                : $row,
                        $result['rows'],
                    );
                }
                if ($result['changed']) {
                    $changes[] = [
                        'kind' => $kind,
                        'action' => 'claim',
                        'current' => isset($result['current']['evidence_status'])
                            ? (string) $result['current']['evidence_status']
                            : null,
                    ];
                }
            } else {
                $result = JmhzEvidenceTimeline::endBefore(
                    $series,
                    $monthStart,
                    static fn (array $row): bool => JmhzReportPlanner::isImported($row['evidence_reference'] ?? null),
                    $frozenThrough,
                );
                if ($result['changed']) {
                    $changes[] = ['kind' => $kind, 'action' => 'end', 'current' => 'verified'];
                } elseif ($result['current'] !== null) {
                    $warnings[] = self::CREDIT_LABELS[$kind] . ' v hlášení za ' . substr($monthStart, 0, 7)
                        . ' není, evidence ji ale uplatňuje. Import ručně zapsanou slevu neukončuje — '
                        . 'zkontrolujte ji v zákonné evidenci osoby.';
                }
            }
            if ($result['warning'] !== null) {
                $warnings[] = $result['warning'];
            }
            $rows = [...$others, ...$result['rows']];
        }
        $sections['tax_credit_claims'] = $rows;

        return ['sections' => $sections, 'changes' => $changes, 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * Poslední den nároku na slevu na studenta pro řádek založený od
     * `$monthStart`, nebo `null`, když nárok v tom měsíci doložit nejde.
     */
    private static function studentClaimLimit(TaxCreditKind $kind, string $monthStart, ?string $birthDate): ?string
    {
        if ($birthDate === null
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $birthDate) !== 1
            || $monthStart < StudentTaxCredit::EFFECTIVE_FROM
            || !StudentTaxCredit::isAgeEligibleMonth($birthDate, $kind, $monthStart)
        ) {
            return null;
        }

        return StudentTaxCredit::latestEffectiveTo($birthDate, $kind);
    }

    /**
     * Sleva na pojistném pracujícího důchodce (10490 → `social_discount_claims`).
     *
     * @param array<string,list<array<string,mixed>>> $sections
     * @return array{sections:array<string,list<array<string,mixed>>>,changed:bool,current:?string,imported:string,warning:?string}
     */
    public static function socialDiscount(
        array $sections,
        ?string $frozenThrough,
        string $monthStart,
        bool $claimed,
        string $reference,
    ): array {
        $imported = $claimed ? 'verified' : 'not_claimed';
        $result = JmhzEvidenceTimeline::setFrom(
            $sections['social_discount_claims'] ?? [],
            $monthStart,
            ['status' => $imported, 'evidence_reference' => $claimed ? $reference : null],
            ['status'],
            true,
            $frozenThrough,
        );
        $sections['social_discount_claims'] = $result['rows'];

        return [
            'sections' => $sections,
            'changed' => $result['changed'],
            'current' => isset($result['current']['status']) ? (string) $result['current']['status'] : null,
            'imported' => $imported,
            'warning' => $result['warning'],
        ];
    }
}
