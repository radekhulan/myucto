import { describe, expect, it } from 'vitest'
import { billingSaveNeedsConfirmation } from './billingSaveConfirmation'
import { newBillingSnapshot } from '@/api/workReportBilling'
function currentMinuteReport(): Parameters<typeof billingSaveNeedsConfirmation>[0] {
  const expected = { description: 'Analýza', quantity: 1 / 3, duration_minutes: 20, unit: 'h', unit_price_without_vat: 1500, vat_rate_id: 1, vat_rate_snapshot: 21, billing_key: 'band:0', total_amount: 500 }
  return { snapshot: newBillingSnapshot(), stale: false, pending: false, reportId: 7,
    preview: { items: [expected] },
    invoiceItems: [{ ...expected, quantity: 0.333, linked_work_report_id: 7, work_report_billing_key: 'band:0', order_index: 0 }] }
}
describe('billingSaveNeedsConfirmation', () => {
  it('aktuální dvacetiminutový řádek neoznačí za změněný kvůli zaokrouhlení kompatibilních hodin', () => {
    expect(billingSaveNeedsConfirmation(currentMinuteReport())).toBe(false)
  })
  it.each(['description', 'unit_price_without_vat', 'duration_minutes', 'vat_rate_snapshot'] as const)('změna navázaného pole %s vyžaduje potvrzení', field => {
    const state = currentMinuteReport()
    const row = state.invoiceItems[0]!
    if (field === 'description') row.description = 'Jiný popis'
    else row[field] = Number(row[field]) + 1
    expect(billingSaveNeedsConfirmation(state)).toBe(true)
  })
  it('jiný číselníkový řádek stejného procenta DPH není změnou účtování', () => {
    const state = currentMinuteReport()
    state.invoiceItems[0]!.vat_rate_id = 99
    expect(billingSaveNeedsConfirmation(state)).toBe(false)
  })
  it('bez uloženého procenta se kontroluje ID sazby', () => {
    const state = currentMinuteReport()
    delete state.invoiceItems[0]!.vat_rate_snapshot
    state.invoiceItems[0]!.vat_rate_id = 99
    expect(billingSaveNeedsConfirmation(state)).toBe(true)
  })
  it('smazání navázaného řádku vyžaduje potvrzení', () => {
    const state = currentMinuteReport()
    state.invoiceItems = []
    expect(billingSaveNeedsConfirmation(state)).toBe(true)
  })
  it('výslovně čekající přenos potvrzení nepotřebuje ani u změněných řádků', () => {
    const state = currentMinuteReport()
    state.pending = true
    state.stale = true
    state.invoiceItems = []
    expect(billingSaveNeedsConfirmation(state)).toBe(false)
  })
})
