import { payrollApi, type PayrollEmploymentJmhzEvidenceOptions } from '@/api/payroll'

/**
 * Číselníky JMHZ (druh činnosti, nástroje APZ, státy, 10502) pro kartu vztahu.
 *
 * Načtou se NEJVÝŠ JEDNOU za běh aplikace, ne při každém otevření karty:
 * jsou to připnuté číselníky jedné verze balíčku, ne data zaměstnance. Tenhle
 * cache je důvod, proč si karta může dovolit ukazovat „Druh činnosti" jako
 * běžné pole rovnou, a ne až po kliknutí do skryté sekce — u člověka se třemi
 * vztahy by to jinak byly tři stejné dotazy.
 *
 * Selhání se nepolyká do prázdna mlčky: vrací se `null`, aby karta uměla říct
 * „číselník se nepodařilo načíst" místo aby nabídla prázdný výběr, který
 * vypadá jako „není z čeho vybírat".
 */
let cached: PayrollEmploymentJmhzEvidenceOptions | null | undefined
let pending: Promise<PayrollEmploymentJmhzEvidenceOptions | null> | null = null

export function loadPayrollJmhzOptions(): Promise<PayrollEmploymentJmhzEvidenceOptions | null> {
  if (cached !== undefined) return Promise.resolve(cached)
  if (pending === null) {
    try {
      pending = payrollApi.employmentJmhzEvidenceOptions()
        .then((options) => {
          cached = options
          return cached
        })
        .catch(() => {
          cached = null
          return cached
        })
        .finally(() => {
          pending = null
        })
    } catch {
      cached = null
      return Promise.resolve(cached)
    }
  }

  return pending
}

/** Jen pro testy — vyprázdní paměť mezi případy. */
export function resetPayrollJmhzOptions(): void {
  cached = undefined
  pending = null
}
