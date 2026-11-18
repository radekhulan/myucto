import { describe, expect, it } from 'vitest'
import { createI18n } from 'vue-i18n'
import cs from '@/i18n/cs.json'
import { bulkIssueConfirmMessage } from '@/pages/invoices/invoiceBulkIssue'

const i18n = createI18n({ legacy: false, locale: 'cs', messages: { cs } })
const t = (key: string, params?: Record<string, unknown>) => i18n.global.t(key, params ?? {})

describe('potvrzení hromadného vystavení konceptů', () => {
  it('rozliší ručně očíslované koncepty od konceptů bez čísla', () => {
    const msg = bulkIssueConfirmMessage([{ varsymbol: '2026001' }, { varsymbol: null }, { varsymbol: '' }], t)
    expect(msg).toContain('Vystavit 3 konceptů?')
    expect(msg).toContain('1 si ponechá vyplněné číslo')
    expect(msg).toContain('2 dostane číslo z číselné řady')
    expect(msg).not.toContain('šablony')
  })

  it('všechny koncepty s vyplněným číslem si ho ponechají', () => {
    const msg = bulkIssueConfirmMessage([{ varsymbol: 'A1' }, { varsymbol: 'A2' }], t)
    expect(msg).toContain('ponechají vyplněné číslo')
    expect(msg).not.toContain('číselné řady')
  })

  it('koncepty bez čísla dostanou číslo z řady, mezery se za číslo nepočítají', () => {
    const msg = bulkIssueConfirmMessage([{ varsymbol: '  ' }, {}], t)
    expect(msg).toContain('dostanou číslo z číselné řady')
    expect(msg).not.toContain('ponech')
  })
})
