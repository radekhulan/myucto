<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

/**
 * Porovnání zmrazených hodinových nároků bez technického pořadí zápisu.
 *
 * Obnova podkladů běhu znovu materializuje hodinovou mzdu a každá materializace
 * zapíše do řetězu nároku další řádek s vyšším `sequence_no`, i když se částka,
 * doba ani podklad nezměnily. Pořadí se propisuje do snímku (`hourly_wage_claims`),
 * takže u firmy s hodinovými mzdami měl každý snímek jiný otisk: obnova podkladů
 * „beze změny" pokaždé založila revizi a detektor změn hlásil u schváleného běhu
 * změnu podkladů, která žádnou změnou nebyla.
 *
 * Skutečná změna nároku mění i částku, dobu nebo otisk podkladu, takže pro
 * otázku „změnilo se něco?" stačí `sequence_no` vynechat. Otisk snímku ani
 * zapsaný řetěz se nemění; tohle pravidlo slouží jen porovnání.
 */
final class PayrollHourlyWageClaimComparison
{
    /** Hodnota `hourly_wage_claims` bez `sequence_no` jednotlivých nároků. */
    public static function withoutSequence(mixed $claims): mixed
    {
        if (!is_array($claims)) {
            return $claims;
        }
        if (array_key_exists('sequence_no', $claims) && !is_array($claims['sequence_no'])) {
            unset($claims['sequence_no']);

            return $claims;
        }
        foreach ($claims as $key => $claim) {
            $claims[$key] = self::withoutSequence($claim);
        }

        return $claims;
    }

    /**
     * Celý snímek běhu, ve kterém mají všechny hodinové nároky vynechané
     * `sequence_no`.
     *
     * @param array<mixed> $snapshot
     * @return array<mixed>
     */
    public static function snapshot(array $snapshot): array
    {
        foreach ($snapshot as $key => $value) {
            if ($key === 'hourly_wage_claims') {
                $snapshot[$key] = self::withoutSequence($value);
            } elseif (is_array($value)) {
                $snapshot[$key] = self::snapshot($value);
            }
        }

        return $snapshot;
    }

    /**
     * Jsou dva snímky běhu shodné až na pořadí zápisu hodinových nároků?
     *
     * @param array<mixed> $stored
     * @param array<mixed> $current
     */
    public static function sameSnapshot(array $stored, array $current): bool
    {
        return \MyInvoice\Service\Payroll\Ruleset\CanonicalJson::encode(self::snapshot($stored))
            === \MyInvoice\Service\Payroll\Ruleset\CanonicalJson::encode(self::snapshot($current));
    }
}
