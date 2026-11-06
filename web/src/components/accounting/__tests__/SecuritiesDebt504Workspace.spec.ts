import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { createI18n } from 'vue-i18n'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Workspace from '../SecuritiesDebt504Workspace.vue'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'
import type { DebtPreview } from '@/api/securitiesDebt504'
const mocks = vi.hoisted(() => ({ list: vi.fn(), sources: vi.fn(), profileChoices: vi.fn(), detail: vi.fn(), preview: vi.fn(), execute: vi.fn() }))
vi.mock('@/api/securitiesDebt504', () => ({ securitiesDebt504Api: mocks }))
const ActionBar = defineComponent({ props: ['actions'], setup: p => () => h('div', (p.actions as Array<{ key: string; label: string; disabled?: boolean; run: () => void }>).map(a => h('button', { 'data-action': a.key, disabled: a.disabled, onClick: a.run }, a.label))) })
const DateInput = defineComponent({ props: ['modelValue'], emits: ['update:modelValue','change'], setup: (p, { emit, attrs }) => () => h('input', { ...attrs, value: p.modelValue, onInput: (e: Event) => emit('update:modelValue', (e.target as HTMLInputElement).value) }) })
const terms = { lot_reference: 'Synthetic bond', debt_type: 'coupon', classification: 'trading', acquired_on: '2025-12-15', maturity_on: '2026-02-01' }
const state = { id: 9, facts: { terms, currency: 'CZK' }, earned: '17.00', amortised: '0.00', unpaid_interest: '31.00', principal_balance: '1000.00', book_czk: '1031.00', hash: 'a'.repeat(64), events: [{ id: 71, request: { operation: 'accrual', accounting_date: '2025-12-31', evidence: 'Synthetic accrual' }, receipt: { amount: '17.00', book_change_czk: '17.00', ledger: {} } }] }
const preview = { state_hash: 'a'.repeat(64), preview_hash: 'b'.repeat(64), plan: { operation: 'accrual', amount: '17.00', book_change_czk: '17.00', lines: [{ account_code: '253', side: 'debit', amount: '17.00' }, { account_code: '644', side: 'credit', amount: '17.00' }] } } as DebtPreview
function mounted(locale = 'cs') { return mount(Workspace, { props: { supplier: 5 }, global: { plugins: [createI18n({ legacy: false, locale, messages: { cs, en } })], stubs: { ActionBar, DateInput } } }) }
async function choose(w: ReturnType<typeof mounted>) { await flushPromises(); await w.get('[data-lot="9"]').trigger('click'); await flushPromises(); await w.get('[data-field="evidence"]').setValue('Synthetic confirmed operation') }
async function review(w: ReturnType<typeof mounted>) { await w.get('[data-action="preview"]').trigger('click'); await flushPromises(); expect(w.find('[data-section="preview"]').exists()).toBe(true) }
beforeEach(() => {
 vi.resetAllMocks(); vi.spyOn(HTMLFormElement.prototype,'reportValidity').mockReturnValue(true)
 mocks.list.mockResolvedValue({ rows: [{ id: 9, ...terms }], financial_enabled: true, can_write: true }); mocks.detail.mockResolvedValue(structuredClone(state))
 mocks.sources.mockResolvedValue({ rows: [{ id: 31, line_id: 41, document_no: 'Synthetic bank receipt', entry_date: '2026-02-01', account_code: '253', amount: '1000.00', currency_code: null, amount_foreign: null }] })
 mocks.profileChoices.mockResolvedValue({ periods: [{ id: 81, starts_on: '2025-01-01', ends_on: '2025-12-31' }], history: [] })
 mocks.preview.mockResolvedValue(structuredClone(preview)); mocks.execute.mockResolvedValue({ lot_id: 9, event_id: 72 })
})
describe('Debt securities workspace', () => {
 it('retains the new dated detail when the calendar emits update and change synchronously', async () => {
  const w = mounted(); await choose(w)
  mocks.detail.mockResolvedValueOnce({ ...structuredClone(state), book_czk: '2031.00' })
  const calendar = w.findAllComponents(DateInput).find(c => c.attributes('data-field') === 'date')!
  calendar.vm.$emit('update:modelValue','2026-01-15'); calendar.vm.$emit('change'); await flushPromises()
  expect(mocks.detail).toHaveBeenLastCalledWith(5,9,'2026-01-15'); expect(w.text()).toMatch(/2\s031,00\sKč/); w.unmount()
 })
 it('loads the next source page without losing previously available physical lines', async () => {
  const w = mounted(); await choose(w); await w.get('[data-field="operation"]').setValue('principal_redemption')
  mocks.sources.mockResolvedValueOnce({ rows: [{ id: 31, line_id: 41, document_no: 'Synthetic first receipt', amount: '1.00' }], next_cursor: { entry_id: 31, line_id: 41 } })
  mocks.sources.mockResolvedValueOnce({ rows: [{ id: 30, line_id: 40, document_no: 'Synthetic next receipt', amount: '1000.00' }], next_cursor: null })
  await w.get('[data-action="sources"]').trigger('click'); await flushPromises(); await w.get('[data-action="sources-more"]').trigger('click'); await flushPromises()
  expect(mocks.sources).toHaveBeenLastCalledWith(5,'receipts',expect.any(String),{ entry_id: 31, line_id: 41 }); expect(w.get('[data-field="source"]').text()).toContain('Synthetic first receipt'); expect(w.get('[data-field="source"]').text()).toContain('Synthetic next receipt'); expect(w.find('[data-action="sources-more"]').exists()).toBe(false); w.unmount()
 })
 it.each(['cs','en'])('uses global %s locale for real operations', async locale => { const w = mounted(locale); await choose(w); expect(w.text()).toContain((locale === 'cs' ? cs : en).securitiesDebt.title); expect(w.get('[data-field="operation"]').text()).toContain((locale === 'cs' ? cs : en).securitiesDebt.principal_redemption); expect(w.text()).not.toContain('securitiesDebt.'); w.unmount() })
 it('requires a reviewed plan and acknowledgement before one financial confirmation', async () => { const w = mounted(); await choose(w); expect(mocks.execute).not.toHaveBeenCalled(); await review(w); expect(mocks.preview.mock.calls[0]?.[1]).toMatchObject({ operation: 'accrual', lot_id: 9 }); expect(w.get('[data-action="confirm"]').attributes('disabled')).toBeDefined(); await w.get('[data-field="ack"]').setValue(true); await w.get('[data-action="confirm"]').trigger('click'); await flushPromises(); expect(mocks.execute).toHaveBeenCalledTimes(1); expect(mocks.execute.mock.calls[0]?.[0]).toBe(5); w.unmount() })
 it('retains server source entry and line identity with explicit contract nominal', async () => { const w = mounted(); await choose(w); await w.get('[data-field="operation"]').setValue('principal_redemption'); await w.get('[data-action="sources"]').trigger('click'); await flushPromises(); expect(w.get('[data-field="source"]').text()).toContain('Synthetic bank receipt'); await w.get('[data-field="source"]').setValue('31:41'); await review(w); expect(mocks.preview.mock.calls[0]?.[1]).toMatchObject({ operation: 'principal_redemption', entry_id: 31, line_id: 41, nominal_amount: '1000.00' }); expect(w.find('input[data-field="entry_id"]').exists()).toBe(false); w.unmount() })
 it('clears a stale review without retrying finances automatically', async () => { mocks.execute.mockRejectedValue({ response: { status: 409, data: { error: { code: 'debt_preview_stale' } } } }); const w = mounted(); await choose(w); await review(w); await w.get('[data-field="ack"]').setValue(true); await w.get('[data-action="confirm"]').trigger('click'); await flushPromises(); expect(mocks.execute).toHaveBeenCalledTimes(1); expect(mocks.preview).toHaveBeenCalledTimes(1); expect(w.find('[data-section="preview"]').exists()).toBe(false); expect(w.text()).toContain(cs.securitiesDebt.stale); w.unmount() })
 it('repeats an uncertain result only on explicit confirmation with the identical key and command', async () => { mocks.execute.mockRejectedValueOnce(new Error('network')); const w = mounted(); await choose(w); await review(w); await w.get('[data-field="ack"]').setValue(true); await w.get('[data-action="confirm"]').trigger('click'); await flushPromises(); expect(mocks.execute).toHaveBeenCalledTimes(1); await w.get('[data-action="confirm"]').trigger('click'); await flushPromises(); expect(mocks.execute.mock.calls[1]).toEqual(mocks.execute.mock.calls[0]); w.unmount() })
 it('fails closed while native financial hooks are unavailable', async () => { mocks.list.mockResolvedValue({ rows: [], financial_enabled: false, can_write: true }); const w = mounted(); await flushPromises(); expect(w.get('[data-action="new"]').attributes('disabled')).toBeDefined(); expect(w.text()).toContain(cs.securitiesDebt.guardUnavailable); expect(mocks.preview).not.toHaveBeenCalled(); w.unmount() })
 it('uses the exact canonical last generated event for owner reversal', async () => { const w = mounted(); await choose(w); await w.get('[data-field="operation"]').setValue('reverse'); await w.get('[data-field="reverse_event"]').setValue('71'); await review(w); expect(mocks.preview.mock.calls[0]?.[1]).toMatchObject({ operation: 'reverse', event_id: 71, lot_id: 9 }); w.unmount() })
 it('loads period IDs from server choices and discards responses after unmount', async () => { const w = mounted(); await choose(w); await w.get('[data-field="operation"]').setValue('valuation'); await w.get('[data-action="profile"]').trigger('click'); await flushPromises(); await review(w); expect(mocks.preview.mock.calls[0]?.[1].profile.category_facts[0].period_id).toBe(81); await w.get('[data-action="cancel"]').trigger('click'); let resolve!: (p: DebtPreview) => void; mocks.preview.mockImplementationOnce(() => new Promise<DebtPreview>(r => { resolve = r })); await w.get('[data-action="preview"]').trigger('click'); w.unmount(); resolve(preview); await flushPromises(); expect(mocks.execute).not.toHaveBeenCalled() })

 it('explains a company outside Decree 504 instead of a generic error and blocks new records', async () => {
  mocks.list.mockResolvedValue({ rows: [], financial_enabled: true, framework_supported: false, can_write: true })
  const w = mounted()
  await flushPromises()
  expect(w.get('[data-section="framework"]').text()).toBe(cs.securitiesDebt.frameworkUnsupported)
  expect(w.get('[data-action="new"]').attributes('disabled')).toBeDefined()
  w.unmount()
 })

 it('formats amounts and dates in the summary, preview and history', async () => {
  const w = mounted()
  await choose(w)
  expect(w.get('[data-section="summary"]').text()).toMatch(/1\s031,00\sKč/)
  expect(w.get('[data-section="history"]').text()).toMatch(/31\.\s?12\.\s?2025/)
  expect(w.get('[data-section="history"]').text()).not.toContain('2025-12-31')
  await review(w)
  expect(w.get('[data-section="preview-lines"]').text()).toMatch(/17,00\sKč/)
  w.unmount()
 })

 it('binds sale proceeds from the 653 source list', async () => {
  const w = mounted()
  await choose(w)
  await w.get('[data-field="operation"]').setValue('sale')
  await w.get('[data-action="sources"]').trigger('click')
  await flushPromises()
  expect(mocks.sources).toHaveBeenLastCalledWith(5, 'proceeds', expect.any(String), null)
  await w.get('[data-field="source"]').setValue('31:41')
  await review(w)
  expect(mocks.preview.mock.calls[0]?.[1]).toMatchObject({ operation: 'sale', lot_id: 9, entry_id: 31, line_id: 41 })
  w.unmount()
 })

 it('sends the sold nominal for a partial sale and offers a sale of a foreign lot', async () => {
  const w = mounted()
  await choose(w)
  await w.get('[data-field="operation"]').setValue('sale')
  expect(w.get('[data-field="sale-scope"]').text()).toBe(cs.securitiesDebt.saleScope)
  await w.get('[data-field="nominal_amount"]').setValue('400.00')
  await w.get('[data-action="sources"]').trigger('click')
  await flushPromises()
  await w.get('[data-field="source"]').setValue('31:41')
  await review(w)
  expect(mocks.preview.mock.calls[0]?.[1]).toMatchObject({ operation: 'sale', nominal_amount: '400.00', entry_id: 31, line_id: 41 })
  w.unmount()
  mocks.detail.mockResolvedValue({ ...structuredClone(state), facts: { terms, currency: 'EUR' } })
  const foreign = mounted()
  await choose(foreign)
  expect(foreign.get('[data-field="operation"]').text()).toContain(cs.securitiesDebt.sale)
  foreign.unmount()
 })

 it('maps a known server error code to a translated explanation', async () => {
  mocks.preview.mockRejectedValue({ response: { status: 409, data: { error: { code: 'debt_sale_accrual_required', message: 'raw' } } } })
  const w = mounted()
  await choose(w)
  await w.get('[data-action="preview"]').trigger('click')
  await flushPromises()
  expect(w.get('[role="alert"]').text()).toBe(cs.securitiesDebt.errors.debt_sale_accrual_required)
  w.unmount()
 })

 it('sends the coupon frequency for ACT/ACT periods on a mobile-friendly single column form', async () => {
  mocks.sources.mockResolvedValueOnce({ rows: [{ id: 30, line_id: 40, document_no: 'Synthetic purchase', entry_date: '2025-07-01', account_code: '063', amount: '980000.00', currency_code: null, amount_foreign: null }] })
  const w = mounted()
  await flushPromises()
  await w.get('[data-action="new"]').trigger('click')
  await w.get('[data-action="period"]').trigger('click')
  expect(w.get('[data-section="coupon-periods"] article').classes()).toContain('grid-cols-1')
  expect((w.get('[data-field="frequency-0"]').element as HTMLSelectElement).value).toBe('1')
  await w.get('[data-field="frequency-0"]').setValue('2')
  await w.get('[data-action="sources"]').trigger('click')
  await flushPromises()
  await w.get('[data-field="source"]').setValue('30:40')
  await w.get('[data-action="preview"]').trigger('click')
  await flushPromises()
  const command = mocks.preview.mock.calls[0]?.[1]
  expect(command.terms.coupon_periods[0]).toMatchObject({ day_count: 'ACT/ACT-ICMA', frequency: 2 })
  w.unmount()
 })
})
