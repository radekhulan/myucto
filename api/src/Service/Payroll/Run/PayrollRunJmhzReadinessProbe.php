<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollPeopleRepository;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotService;
use PDO;

/**
 * Nálezy MĚSÍČNÍHO HLÁŠENÍ, vytažené na začátek měsíce.
 *
 * ── Co bylo špatně ──────────────────────────────────────────────────────────
 * Chybějící zařazení mzdové složky do JMHZ a chybějící identifikátory od ČSSZ
 * se účetní ozvaly až u ZMRAZENÍ hlášení — tedy po zamknutí vstupů, výpočtu,
 * schválení a zaúčtování. Obojí se přitom dá doplnit kdykoli předtím a s mzdovým
 * výpočtem to nemá nic společného. Účetní tak dvakrát prošla celý měsíc, aby na
 * konci narazila na údaj, který jí nikdo neukázal na začátku.
 *
 * ── Pravidlo teď ────────────────────────────────────────────────────────────
 * Táž pravidla se pustí nasucho nad snímkem vstupů, který kontrola před během
 * stejně staví ({@see PayrollRunReadinessService}). Pravidla se sem NEOPISUJÍ —
 * volá se {@see JmhzPreparationSnapshotService::probeIssues()}, tedy tentýž kód,
 * který pak zmrazení použije doopravdy.
 *
 * ── Co to NEDĚLÁ ────────────────────────────────────────────────────────────
 * Neblokuje. Ani jeden z těch nálezů nebrání spočítat mzdu; hlášení se podává
 * až po běhu. Zmrazení JMHZ je jiná brána a ta blokovat smí — pole, které
 * v hlášení opravdu musí být, se nedá obejít. Sem se ta blokace nepropisuje.
 *
 * Nekouká na složky bez pohybu: nezařazená složka, kterou firma v období
 * nepoužila, je normální stav (u sporných složek je zařazení úsudek účetní).
 */
final class PayrollRunJmhzReadinessProbe
{
    /** Kolik konkrétních jmen se u nálezu vypíše, než se přejde na počet. */
    private const MAX_ENTITIES = 25;

    /**
     * Strop, nad kterým se identita nezkouší.
     *
     * Ověření identity pro ČSSZ stojí na KAŽDÝ vztah dvě transakce se zámkem
     * a dešifrování uloženého identifikátoru. To je v pořádku u firmy s deseti
     * lidmi, ale ne u pěti set — a tohle je obyčejné GET na seznam běhů.
     * U velké firmy proto kontrolu nedělá vůbec a odkáže na seznam osob, který
     * totéž zvládne JEDNÍM dotazem přes celou firmu
     * ({@see \MyInvoice\Service\Payroll\People\PayrollPersonDataGapCatalog}).
     */
    private const MAX_PROBED_EMPLOYMENTS = 60;

    public function __construct(
        private readonly Connection $db,
        private readonly JmhzPreparationSnapshotService $preparation,
    ) {}

    /**
     * @param array<string,mixed> $snapshotData snímek vstupů postavený nasucho
     * @return list<array{
     *   code:string,severity:string,impact:string,scope:string,message:string,
     *   remediation_path:?string,count:int,
     *   entities:list<array{entity_type:string,entity_id:?int,label:?string}>
     * }>
     */
    public function inspect(int $supplierId, string $periodStart, array $snapshotData): array
    {
        $periodEnd = (new \DateTimeImmutable($periodStart))
            ->modify('last day of this month')
            ->format('Y-m-d');

        try {
            $issues = $this->preparation->probeIssues(
                $supplierId,
                'production',
                $periodEnd,
                $snapshotData,
                self::countEmployments($snapshotData) > self::MAX_PROBED_EMPLOYMENTS,
            );
        } catch (\Throwable $e) {
            // Fail-soft. Kontrola, která spadne, nesmí zastavit práci — vrátí
            // nález a pustí dál.
            return [[
                'code' => 'jmhz_readiness_check_failed',
                'severity' => 'info',
                'impact' => PayrollRunReadinessImpact::IMPACT_ANYTIME,
                'scope' => PayrollRunReadinessImpact::SCOPE_MONTHLY,
                'message' => 'Předběžnou kontrolu podkladů měsíčního hlášení '
                    . 'se nepodařilo dokončit: ' . $e->getMessage()
                    . ' Mzdový běh tím není nijak omezený; kontrola se spustí '
                    . 'znovu při přípravě hlášení.',
                'remediation_path' => null,
                'count' => 1,
                'entities' => [],
            ]];
        }

        if ($issues === []) {
            return [];
        }

        $componentLabels = $this->componentLabels($supplierId, self::idsOf($issues, 'component'));
        $employmentLabels = $this->employmentLabels($supplierId, self::idsOf($issues, 'employment'));

        /** @var array<string,array<string,mixed>> $groups */
        $groups = [];
        foreach ($issues as $issue) {
            $code = self::normalizeCode($issue['code']);
            $label = match ($issue['entity_type']) {
                'component' => $componentLabels[$issue['entity_id']] ?? null,
                'employment' => $employmentLabels[$issue['entity_id']] ?? null,
                default => null,
            };
            if (!isset($groups[$code])) {
                $classification = PayrollRunReadinessImpact::describe($code);
                $groups[$code] = [
                    'code' => $code,
                    'severity' => $classification['severity'],
                    'impact' => $classification['impact'],
                    'scope' => $classification['scope'],
                    'message' => '',
                    'remediation_path' => self::remediationPath($code),
                    'count' => 0,
                    'entities' => [],
                    'labels' => [],
                ];
            }
            ++$groups[$code]['count'];
            if (count($groups[$code]['entities']) < self::MAX_ENTITIES) {
                $groups[$code]['entities'][] = [
                    'entity_type' => $issue['entity_type'],
                    'entity_id' => $issue['entity_id'],
                    'label' => $label,
                ];
                if ($label !== null && !in_array($label, $groups[$code]['labels'], true)) {
                    $groups[$code]['labels'][] = $label;
                }
            }
        }

        $findings = [];
        foreach ($groups as $group) {
            /** @var list<string> $labels */
            $labels = $group['labels'];
            $group['message'] = self::message($group['code'], $labels, $group['count']);
            unset($group['labels']);
            $findings[] = $group;
        }

        /** @var list<array{code:string,severity:string,impact:string,scope:string,message:string,remediation_path:?string,count:int,entities:list<array{entity_type:string,entity_id:?int,label:?string}>}> $findings */
        return $findings;
    }

    /**
     * Nálezy identity pro ČSSZ mají JEDNU příčinu: vztah nemá uložené OIČ ani
     * ID PPV. Rozpad na `jmhz_identity_oic_missing` a `jmhz_identity_id_ppv_missing`
     * je pravda o pořadí kontrol, ne o práci účetní — dva řádky se stejným
     * tlačítkem vypadají jako dva úkoly. Slévají se proto do jednoho.
     */
    private static function normalizeCode(string $code): string
    {
        return in_array($code, [
            'jmhz_identity_oic_missing',
            'jmhz_identity_id_ppv_missing',
            'jmhz_identity_incomplete',
        ], true)
            ? 'jmhz_identity_missing'
            : $code;
    }

    /** @param array<string,mixed> $snapshotData */
    private static function countEmployments(array $snapshotData): int
    {
        $people = $snapshotData['people'] ?? [];
        if (!is_array($people)) {
            return 0;
        }
        $count = 0;
        foreach ($people as $person) {
            $employments = is_array($person) ? ($person['employments'] ?? []) : [];
            if (is_array($employments)) {
                $count += count($employments);
            }
        }

        return $count;
    }

    /**
     * @param list<array{code:string,entity_type:string,entity_id:?int,attribute_ids:list<string>}> $issues
     * @return list<int>
     */
    private static function idsOf(array $issues, string $entityType): array
    {
        $ids = [];
        foreach ($issues as $issue) {
            if ($issue['entity_type'] === $entityType && is_int($issue['entity_id'])) {
                $ids[$issue['entity_id']] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Text nálezu, který JMENUJE konkrétní věc.
     *
     * Nález bez jména je k nepoužití: „Mzdová složka nemá určené zařazení" pošle
     * účetní na seznam patnácti složek hádat, která to je. Názvy polí jsou
     * doslova ty ze slovníku a z formuláře — kdo je hledá očima, musí je najít.
     *
     * @param list<string> $labels
     */
    private static function message(string $code, array $labels, int $count): string
    {
        $named = $labels === []
            ? ''
            : implode(', ', array_slice($labels, 0, 10))
                . (count($labels) > 10 ? ' a další' : '');

        return match ($code) {
            'component_jmhz_mapping_missing' => sprintf(
                'Zařazení do měsíčního hlášení nemá: %s. Složka se v tomhle '
                . 'období použila, takže se bez zařazení hlášení nesestaví. '
                . 'Doplňte ho v Mzdy → Mzdové složky. Mzdový běh tím omezený '
                . 'není — spočítat a vyplatit jde i teď.',
                $named !== '' ? $named : $count . '× mzdová složka',
            ),
            'component_jmhz_manual_review' => sprintf(
                'Zařazení do měsíčního hlášení čeká na potvrzení u: %s. '
                . 'Otevřete Mzdy → Mzdové složky a zařazení potvrďte.',
                $named !== '' ? $named : $count . '× mzdová složka',
            ),
            'component_jmhz_treatment_invalid' => sprintf(
                'Neplatné nastavení pro měsíční hlášení má: %s. Otevřete '
                . 'Mzdy → Mzdové složky a nastavení opravte.',
                $named !== '' ? $named : $count . '× mzdová složka',
            ),
            'jmhz_identity_missing' => sprintf(
                'Chybí „OIČ / IK MPSV osoby" a „ID PPV pracovního vztahu" u: %s. '
                . 'Obě čísla přiděluje ČSSZ při registraci zaměstnance — '
                . 'aplikace je vymyslet nemůže. Když zaměstnanec u ČSSZ ještě '
                . 'registrovaný není, podejte nejdřív přihlášku (PREZEC/REGZEC '
                . 'A1) a čísla se doplní z protokolu sama. Když registrovaný je '
                . '(typicky u firmy, která běží roky), opište je z protokolu '
                . 'ČSSZ nebo z ePortálu. Vyplňují se na kartě pracovního vztahu '
                . 'v části „Identifikátory přidělené ČSSZ pro JMHZ". Mzdový běh '
                . 'to nijak neomezuje — doplnit to stačí před podáním hlášení.',
                $named !== '' ? $named : $count . '× pracovní vztah',
            ),
            'jmhz_identity_unresolved' => sprintf(
                'Nedokončený úkol identity pro ČSSZ má: %s. Dokončete ho na '
                . 'kartě pracovního vztahu; hlášení se do té doby nesestaví, '
                . 'mzdový běh ale běží dál.',
                $named !== '' ? $named : $count . '× pracovní vztah',
            ),
            default => sprintf(
                'Podklad měsíčního hlášení (%s) není úplný u: %s. Doplní se '
                . 'kdykoli před podáním; mzdový běh tím omezený není.',
                $code,
                $named !== '' ? $named : (string) $count,
            ),
        };
    }

    private static function remediationPath(string $code): ?string
    {
        return match ($code) {
            'component_jmhz_mapping_missing',
            'component_jmhz_manual_review',
            'component_jmhz_treatment_invalid' => '/payroll/components',
            default => '/payroll/people',
        };
    }

    /**
     * @param list<int> $ids
     * @return array<int,string>
     */
    private function componentLabels(int $supplierId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, code, name
               FROM payroll_component_definitions
              WHERE supplier_id = ? AND id IN (' . $placeholders . ')',
        );
        $stmt->execute([$supplierId, ...$ids]);

        $labels = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $labels[(int) $row['id']] = sprintf(
                '%s (%s)',
                (string) $row['name'],
                (string) $row['code'],
            );
        }

        return $labels;
    }

    /**
     * @param list<int> $ids
     * @return array<int,string>
     */
    private function employmentLabels(int $supplierId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT employment.id, employment.code,
                    ' . PayrollPeopleRepository::fullNameExpression() . ' AS full_name
               FROM payroll_employments employment
               JOIN payroll_employees employee
                 ON employee.id = employment.employee_id
                AND employee.supplier_id = employment.supplier_id
              WHERE employment.supplier_id = ?
                AND employment.id IN (' . $placeholders . ')',
        );
        $stmt->execute([$supplierId, ...$ids]);

        $labels = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $code = (string) ($row['code'] ?? '');
            $labels[(int) $row['id']] = $code === ''
                ? (string) $row['full_name']
                : sprintf('%s (%s)', (string) $row['full_name'], $code);
        }

        return $labels;
    }
}
