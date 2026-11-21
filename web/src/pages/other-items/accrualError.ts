import { apiErrorCode } from '@/api/errors'

const PREFIX = 'other_items.error.'

// Chyby období časového rozlišení: přeložená hláška místo serverového textu, ostatní
// chyby beze změny. `period_closed` a `is_draft` jsou obecné kódy (storno položky, smazání se
// zápisem), proto je volající předává jen tam, kde je vrací pojistka či změna období.
export const ACCRUAL_ERRORS = [
  'period_closed', 'accrual_deferral_posted', 'accrual_period_incomplete', 'accrual_period_invalid',
  'accrual_not_posted', 'is_draft', 'accrual_double_entry_only',
]

// Uložení či smazání konceptu: `period_closed` tam vrací jen pojistka období, `is_draft` nikdy.
export const DRAFT_ACCRUAL_ERRORS = ACCRUAL_ERRORS.filter(code => code !== 'is_draft')

// Klientská kontrola období se stejnými pravidly jako server (`accrual_period_incomplete`,
// `accrual_period_invalid`): obě data, nebo žádné, a začátek nejpozději v den konce.
export function accrualPeriodErrorKey(from: string | null | undefined, to: string | null | undefined): string | null {
  if (!!from !== !!to) return 'other_items.accrual.error.accrual_period_incomplete'
  if (from && to && from > to) return 'other_items.accrual.error.accrual_period_invalid'
  return null
}

// Jen kódy ostatních položek (`other_items.error.*`): holý `period_closed` z jiné vrstvy
// (zaúčtování) není chyba období rozlišení.
export function accrualErrorKey(error: any, codes: readonly string[]): string | null {
  const full = apiErrorCode(error)
  if (!full.startsWith(PREFIX)) return null
  const code = full.slice(PREFIX.length)
  return codes.includes(code) ? `other_items.accrual.error.${code}` : null
}
