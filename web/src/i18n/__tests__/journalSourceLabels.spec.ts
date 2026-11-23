import { describe, expect, it } from 'vitest'
import cs from '../cs.json'
import en from '../en.json'

/** Zdroje zápisů, které deník ukazuje ve sloupci Zdroj a ve filtru; bez překladu by se ukázal syrový kód. */
const SOURCES = ['depreciation', 'payroll', 'payroll_payment', 'manual', 'invoice', 'purchase_invoice', 'bank', 'cash', 'asset', 'vat_clearing']

describe('accounting.journal.source', () => {
  it.each([['cs', cs], ['en', en]] as const)('%s překládá zdroje zápisů', (_locale, messages) => {
    const labels = (messages as any).accounting.journal.source as Record<string, string>
    for (const source of SOURCES) expect(labels[source], source).toBeTruthy()
  })
})
