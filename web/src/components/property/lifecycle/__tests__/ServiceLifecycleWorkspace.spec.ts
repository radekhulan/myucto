import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { createI18n } from 'vue-i18n'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Workspace from '../ServiceLifecycleWorkspace.vue'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'
import type { LifecyclePreview } from '@/api/serviceLifecycle'
const mocks = vi.hoisted(() => ({ list: vi.fn(), choices: vi.fn(), sources: vi.fn(), detail: vi.fn(), preview: vi.fn(), execute: vi.fn(), policies: vi.fn(), declarePolicy: vi.fn() }))
vi.mock('@/api/serviceLifecycle', () => ({ serviceLifecycleApi: mocks }))
const ActionBar = defineComponent({ props: ['actions'], setup: p => () => h('div', (p.actions as Array<{ key: string; label: string; disabled?: boolean; show?: unknown; run: () => void }>).filter(a => a.show !== false).map(a => h('button', { 'data-action': a.key, disabled: a.disabled, onClick: a.run }, a.label))) })
const DateInput = defineComponent({ props: ['modelValue'], emits: ['update:modelValue'], setup: (p, { emit, attrs }) => () => h('input', { ...attrs, value: p.modelValue, onInput: (e: Event) => emit('update:modelValue', (e.target as HTMLInputElement).value) }) })
const caps = { manage: true, post: true, reverse: true, unwind: true }
const scope = { unit_id: 1, service_id: 2, period_from: '2026-01-01', period_to: '2026-12-31', policy: 'service_balance_sheet', evidence: 'Approved policy', service_cost_account: '315.210' }
const plan = { operation: 'start', policy: 'service_balance_sheet', period_from: scope.period_from, period_to: scope.period_to, estimate_cents: '11000000', final_cents: null, source_fingerprint: 'c'.repeat(64), journals: [] }
const preview = { expected_state: 'a'.repeat(64), preview_hash: 'b'.repeat(64), plan } as LifecyclePreview
const details = (phase = 'estimated', capabilities = caps) => ({ id: 9, case: scope, phase, estimate_cents: '11000000', final_cents: phase === 'finalized' ? '11200000' : null, cost_line_id: null, events: [], capabilities })
function mounted(locale = 'cs') { return mount(Workspace, { props: { supplier: 5 }, global: { plugins: [createI18n({ legacy: false, locale, messages: { cs, en } })], stubs: { ActionBar, DateInput } } }) }
async function setupStart(w: ReturnType<typeof mounted>, policy = 'service_balance_sheet', opening = false) {
 await flushPromises(); await w.get('[data-action="new"]').trigger('click')
 await w.get('[data-field="unit"]').setValue('1'); await w.get('[data-field="service"]').setValue('2'); await w.get('[data-field="policy"]').setValue(policy)
 await w.get('[data-field="account"]').setValue('315.210'); await w.get('[data-field="evidence"]').setValue('Approved policy')
 if (opening) await w.get('[data-field="source-kind"]').setValue('opening')
 await w.get('[data-action="sources"]').trigger('click'); await flushPromises()
 if (opening) await w.get('[data-field="opening"]').setValue('31')
 else { await w.get('[data-field="source0"]').setValue('31'); if (policy === 'service_profit_and_loss') await w.get('[data-field="source1"]').setValue('32'); await w.get('[data-field="coverage-title"]').setValue('Original legal title') }
}
async function review(w: ReturnType<typeof mounted>) { await w.get('[data-action="preview"]').trigger('click'); await flushPromises(); expect(w.find('[data-section="preview"]').exists()).toBe(true) }
beforeEach(() => {
 vi.clearAllMocks()
 mocks.list.mockResolvedValue({ items: [], next_cursor: null, capabilities: caps })
 mocks.choices.mockResolvedValue({ units: [{ id: 1, label: 'U01 Apartment' }], services: [{ id: 2, label: 'Heating' }], accounts: [{ id: 3, code: '315.210', label: 'Service costs' }] })
 mocks.sources.mockResolvedValue({ items: [{ id: 31, label: 'Original estimate31', lines: [{ id: 41, account_code: '388.210', side: 'debit', amount: '110000.00' }] }, { id: 32, label: 'Original estimate32', lines: [] }], next_cursor: null })
 mocks.policies.mockResolvedValue({ items: [], next_cursor: null, capabilities: caps }); mocks.declarePolicy.mockResolvedValue({})
 mocks.preview.mockResolvedValue(structuredClone(preview)); mocks.execute.mockResolvedValue({ case_id: 9, operation: 'start', plan }); mocks.detail.mockImplementation(() => Promise.resolve(details()))
})
describe('Service lifecycle confirmation', () => {
 it.each(['cs', 'en'])('uses global %s translations for the workspace and both approved policies', async locale => {
  const w = mounted(locale); await flushPromises(); await w.get('[data-action="new"]').trigger('click')
  const messages = locale === 'cs' ? cs : en
  expect(w.text()).toContain(messages.propertyServiceLifecycle.title)
  expect(w.get('[data-field="policy"]').text()).toContain(messages.propertyServiceLifecycle.service_balance_sheet)
  expect(w.get('[data-field="policy"]').text()).toContain(messages.propertyServiceLifecycle.service_profit_and_loss)
  expect(w.text()).not.toContain('propertyServiceLifecycle.'); w.unmount()
 })
 it('uses the accounting day at Prague winter and summer midnight', async () => {
  vi.useFakeTimers({ toFake: ['Date'] })
  try {
   for (const [instant, expected] of [['2026-01-01T23:30:00Z', '2026-01-02'], ['2026-07-01T22:30:00Z', '2026-07-02']]) {
    vi.setSystemTime(new Date(instant!)); const w = mounted(); await flushPromises(); expect(mocks.choices.mock.calls.at(-1)).toEqual([5, expected]); w.unmount()
   }
  } finally { vi.useRealTimers() }
 })

 it('uses human source selections and explicit preview/ack before one command', async () => {
  const w = mounted(); await setupStart(w); expect(w.text()).toContain('U01 Apartment'); expect(mocks.execute).not.toHaveBeenCalled()
  await review(w); expect(mocks.preview.mock.calls[0]?.[1]).toMatchObject({ operation: 'start', entry_ids: [31] }); expect(w.get('[data-action="confirm"]').attributes('disabled')).toBeDefined()
  await w.get('[data-field="ack"]').setValue(true); await w.get('[data-action="confirm"]').trigger('click'); await flushPromises()
  expect(mocks.execute).toHaveBeenCalledTimes(1); expect(mocks.execute.mock.calls[0]?.[0]).toBe(5); expect(w.find('[data-section="preview"]').exists()).toBe(false); w.unmount()
 })
 it('requires two distinct P&L estimate sources and supports S6 selection', async () => {
  let w = mounted(); await setupStart(w, 'service_profit_and_loss'); await review(w)
  expect(mocks.preview.mock.calls[0]?.[1]).toMatchObject({ policy: 'service_profit_and_loss', entry_ids: [31, 32] }); w.unmount()
  mocks.preview.mockClear(); w = mounted(); await setupStart(w, 'service_profit_and_loss', true); await review(w)
  expect(mocks.preview.mock.calls[0]?.[1]).toMatchObject({ operation: 'start_opening', opening_item_id: 31 }); w.unmount()
 })
 it('clears stale confirmation without financial automatic retry', async () => {
  mocks.execute.mockRejectedValue({ response: { status: 409 } }); const w = mounted(); await setupStart(w); await review(w); await w.get('[data-field="ack"]').setValue(true)
  await w.get('[data-action="confirm"]').trigger('click'); await flushPromises(); expect(mocks.execute).toHaveBeenCalledTimes(1); expect(mocks.preview).toHaveBeenCalledTimes(1); expect(w.find('[data-section="preview"]').exists()).toBe(false); expect(w.text()).toContain('Podklady se změnily'); w.unmount()
 })
 it('retains the exact key and command for an explicitly repeated uncertain confirmation', async () => {
  mocks.execute.mockRejectedValueOnce(new Error('network')).mockResolvedValueOnce({ case_id: 9, operation: 'start', plan }); const w = mounted(); await setupStart(w); await review(w); await w.get('[data-field="ack"]').setValue(true)
  await w.get('[data-action="confirm"]').trigger('click'); await flushPromises(); expect(mocks.execute).toHaveBeenCalledTimes(1)
  await w.get('[data-action="confirm"]').trigger('click'); await flushPromises(); expect(mocks.execute).toHaveBeenCalledTimes(2); expect(mocks.execute.mock.calls[1]).toEqual(mocks.execute.mock.calls[0]); w.unmount()
 })
 it('discards review when inputs change and ignores an unmounted tenant response', async () => {
  const w = mounted(); await setupStart(w); await review(w); await w.get('[data-action="cancel"]').trigger('click'); await w.get('[data-field="coverage-title"]').setValue('Changed legal title'); expect(w.find('[data-section="preview"]').exists()).toBe(false)
  let resolve!: (p: LifecyclePreview) => void; mocks.preview.mockImplementationOnce(() => new Promise<LifecyclePreview>(r => { resolve = r })); await w.get('[data-action="preview"]').trigger('click'); w.unmount(); resolve(preview); await flushPromises(); expect(mocks.execute).not.toHaveBeenCalled()
 })
 it('gates exact unwind separately from manage and makes final invoice selection explicit', async () => {
  mocks.list.mockResolvedValue({ items: [{ id: 9, unit_label: 'U01', service_label: 'Heating', ...scope }], next_cursor: null, capabilities: caps }); mocks.detail.mockResolvedValue(details('estimated', { ...caps, unwind: false })); let w = mounted(); await flushPromises(); await w.get('section button').trigger('click'); await flushPromises(); await w.get('[data-field="step"]').setValue('unwind'); expect(w.get('[data-action="preview"]').attributes('disabled')).toBeDefined(); w.unmount()
  mocks.detail.mockResolvedValue(details('unwound')); mocks.sources.mockResolvedValue({ items: [{ id: 90, invoice_id: 70, label: 'Vendor final112000', lines: [{ id: 80, side: 'debit', account_code: '315.210', amount: '112000.00' }] }], next_cursor: null }); w = mounted(); await flushPromises(); await w.get('section button').trigger('click'); await flushPromises(); await w.get('[data-action="sources"]').trigger('click'); await flushPromises(); await w.get('[data-field="invoice"]').setValue('70:80'); await w.get('[data-field="final-title"]').setValue('Final legal title'); await review(w); expect(mocks.preview.mock.calls.at(-1)?.[1]).toMatchObject({ operation: 'finalize', invoice_id: 70, line_id: 80 }); w.unmount()
 })
 it('finalizes an estimated case directly so the estimate is released in the same period', async () => {
  mocks.list.mockResolvedValue({ items: [{ id: 9, unit_label: 'U01', service_label: 'Heating', ...scope }], next_cursor: null, capabilities: caps }); mocks.detail.mockResolvedValue(details('estimated', { ...caps, reverse: false, unwind: false }))
  mocks.sources.mockResolvedValue({ items: [{ id: 90, invoice_id: 70, label: 'Vendor final112000', lines: [{ id: 80, side: 'debit', account_code: '315.210', amount: '112000.00' }] }], next_cursor: null })
  const w = mounted(); await flushPromises(); await w.get('section button').trigger('click'); await flushPromises()
  expect((w.get('[data-field="step"]').element as HTMLSelectElement).value).toBe('finalize')
  await w.get('[data-action="sources"]').trigger('click'); await flushPromises(); await w.get('[data-field="invoice"]').setValue('70:80'); await w.get('[data-field="final-title"]').setValue('Final legal title'); await review(w)
  expect(mocks.preview.mock.calls.at(-1)?.[1]).toMatchObject({ operation: 'finalize', case_id: 9, invoice_id: 70, line_id: 80 }); w.unmount()
 })
 it('takes the method from the governing service policy and records a new version', async () => {
  mocks.policies.mockResolvedValue({ items: [{ id: 4, service_id: 2, service_label: 'Heating', valid_from: '2025-01-01', policy: 'service_profit_and_loss', evidence: 'Board decision', origin: 'declared', recorded_at: '2025-01-02 10:00:00' }], next_cursor: null, capabilities: caps })
  const w = mounted(); await flushPromises(); expect(w.get('[data-section="policies"]').text()).toContain(cs.propertyServiceLifecycle.origin.declared)
  await w.get('[data-action="new"]').trigger('click'); await w.get('[data-field="service"]').setValue('2'); await flushPromises()
  expect((w.get('[data-field="policy"]').element as HTMLSelectElement).value).toBe('service_profit_and_loss'); expect(w.get('[data-field="policy"]').attributes('disabled')).toBeDefined(); expect(w.find('[data-field="governing"]').exists()).toBe(true)
  await w.get('[data-field="policy-service"]').setValue('2'); await w.get('[data-field="policy-from"]').setValue('2027-01-01'); await w.get('[data-field="policy-value"]').setValue('service_balance_sheet'); await w.get('[data-field="policy-evidence"]').setValue('Board decision 2027')
  await w.get('[data-action="declare-policy"]').trigger('click'); await flushPromises()
  expect(mocks.declarePolicy).toHaveBeenCalledWith(5, { service_id: 2, valid_from: '2027-01-01', policy: 'service_balance_sheet', evidence: 'Board decision 2027' }); w.unmount()
 })
})
