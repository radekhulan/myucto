import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { createI18n } from 'vue-i18n'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Workspace from '../FundingWorkspace.vue'
import MultiSelectFilter from '@/components/ui/MultiSelectFilter.vue'
import { fundingCents } from '../fundingMoney'
import type { FundingPolicy, FundingPreview } from '@/api/nonprofitFunding'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'
const api = vi.hoisted(() => ({ read: vi.fn(), periods: vi.fn(), choices: vi.fn(), preview: vi.fn(), confirm: vi.fn() }))
vi.mock('@/api/nonprofitFunding', () => ({ fundingApi: api }))
const ActionBar = defineComponent({ props: ['actions'], setup: p => () => h('div', (p.actions as Array<{ key: string; label: string; disabled?: boolean; show?: unknown; run: () => void }>).filter(a => a.show !== false).map(a => h('button', { 'data-action': a.key, disabled: a.disabled, onClick: a.run }, a.label))) })
const DateInput = defineComponent({ props: ['modelValue'], emits: ['update:modelValue'], setup: (p, { emit, attrs }) => () => h('input', { ...attrs, value: p.modelValue, onInput: (e: Event) => emit('update:modelValue', (e.target as HTMLInputElement).value) }) })
const period = { id: 11, starts_on: '2026-01-01', ends_on: '2026-12-31', status: 'open' }
const amounts = { recognized_cents: 100000, budget_cents: 70000, drawdown_cents: 60000, budget_remaining_cents: 10000, available_cents: 10000, uses: [] }
const policy: FundingPolicy = { kind: 'gift', title: 'Synthetic gift', purpose: 'Youth sports', evidence: 'Synthetic agreement', provider_id: 21, budget_cents: 70000, valid_from: '2026-01-01', valid_to: '2026-12-31', activity_kind_ids: [31], account_prefixes: ['518'], source_anchor: 'line:41', source_members: ['line:41'] }
function mounted(locale = 'cs', canManage = true) { return mount(Workspace, { props: { supplier: 5, canManage }, global: { plugins: [createI18n({ legacy: false, locale, messages: { cs, en } })], stubs: { ActionBar, DateInput, RouterLink: true } } }) }
async function pick(w: ReturnType<typeof mounted>, field: string, values: string[]) { const select = w.findAllComponents(MultiSelectFilter).find(c => c.attributes('data-field') === field); expect(select).toBeDefined(); select!.vm.$emit('update:modelValue', values); await flushPromises() }
async function fill(w: ReturnType<typeof mounted>) {
 await flushPromises(); await w.get('[data-action="new"]').trigger('click'); await flushPromises()
 for (const [name, value] of Object.entries({ source: 'line:41', provider: '21', title: 'Synthetic gift', budget: '700,00', purpose: 'Youth sports', evidence: 'Synthetic agreement', reason: 'Synthetic reviewed terms' })) await w.get(`[data-field="${name}"]`).setValue(value)
 await pick(w, 'activities', ['31']); await pick(w, 'accounts', ['518'])
}
async function review(w: ReturnType<typeof mounted>) { await w.get('[data-action="preview"]').trigger('click'); await flushPromises(); expect(w.find('[data-section="preview"]').exists()).toBe(true) }
beforeEach(() => {
 vi.clearAllMocks(); api.read.mockResolvedValue({ sources: [], findings: [], complete: true, financial_authorization: false }); api.periods.mockResolvedValue([period])
 api.choices.mockResolvedValue({ period, assessment_current: true, providers: [{ id: 21, label: 'Synthetic donor' }], providers_more: false, activities: [{ id: 31, label: 'SPORT - Sport' }], accounts: [{ code: '518', label: '518 - Services' }], sources: [{ anchor: 'line:41', members: ['line:41'], kind: 'gift', date: '2026-06-01', account_code: '682', amount_cents: 100000, evidence: 'Synthetic accepted gift' }] })
 api.preview.mockImplementation((_supplier, _period, source_key: string, source: FundingPolicy) => Promise.resolve({ source_key, policy: source, input_hash: 'a'.repeat(64), expected_revision: 0, report: amounts, financial_authorization: false })); api.confirm.mockResolvedValue({ id: 9, revision_no: 1 })
})
describe('Funding purpose workspace', () => {
 it('shows loading, empty overview, readonly notice and locale dates like the sibling membership page', async () => {
  let resolve!: (v: unknown) => void; api.read.mockImplementationOnce(() => new Promise(r => { resolve = r })); const w = mounted('cs', false); await flushPromises()
  expect(w.find('[data-section="loading"]').text()).toBe(cs.common.loading); expect(w.get('[data-section="readonly"]').text()).toBe(cs.nonprofitFunding.readOnly)
  resolve({ sources: [], findings: [], complete: true, financial_authorization: false }); await flushPromises(); expect(w.find('[data-section="loading"]').exists()).toBe(false); expect(w.find('[data-section="empty"]').text()).toBe(cs.nonprofitFunding.empty)
  expect(w.text()).toContain('01. 01. 2026'); expect(w.text()).not.toContain('2026-01-01'); w.unmount()
  api.read.mockRejectedValueOnce({ response: { status: 500, data: { error: { message: 'Synthetic failure' } } } }); const e = mounted(); await flushPromises(); expect(e.get('[data-section="workspace-error"]').attributes('role')).toBe('alert'); e.unmount()
 })
 it.each(['cs', 'en'])('renders translated %s terms with human source and provider selections', async locale => {
  const w = mounted(locale); await fill(w); expect(w.text()).toContain((locale === 'cs' ? cs : en).nonprofitFunding.title); expect(w.text()).toContain('Synthetic donor'); expect(w.text()).toContain('Synthetic accepted gift'); expect(w.text()).not.toContain('nonprofitFunding.'); w.unmount()
 })
 it('requires preview and acknowledgement before one exact confirmation', async () => {
  const w = mounted(); await fill(w); expect(api.confirm).not.toHaveBeenCalled(); await review(w); expect(api.preview.mock.calls[0]?.slice(0, 2)).toEqual([5, 11]); expect(api.preview.mock.calls[0]?.[3]).toEqual(policy)
  expect(w.get('[data-action="confirm"]').attributes('disabled')).toBeDefined(); await w.get('[data-field="ack"]').setValue(true); await w.get('[data-action="confirm"]').trigger('click'); await flushPromises()
  expect(api.confirm).toHaveBeenCalledTimes(1); expect(api.confirm.mock.calls[0]?.[2]).toMatchObject({ policy, expected_revision: 0, reason: 'Synthetic reviewed terms', input_hash: 'a'.repeat(64) }); expect(w.find('[data-section="preview"]').exists()).toBe(false); w.unmount()
 })
 it('rejects sub-cent amounts and an empty eligibility selection', async () => {
  const w = mounted(); await fill(w); await w.get('[data-field="budget"]').setValue('700.001'); expect(w.get('[data-action="preview"]').attributes('disabled')).toBeDefined(); await w.get('[data-field="budget"]').setValue('700'); await pick(w, 'activities', []); expect(w.get('[data-action="preview"]').attributes('disabled')).toBeDefined(); expect(api.preview).not.toHaveBeenCalled(); w.unmount()
 })
 it('invalidates changed evidence and never silently retries a stale confirmation', async () => {
  const w = mounted(); await fill(w); await review(w); await w.get('[data-action="cancel"]').trigger('click'); await w.get('[data-field="purpose"]').setValue('New purpose'); expect(w.find('[data-section="preview"]').exists()).toBe(false); await review(w); await w.get('[data-field="ack"]').setValue(true)
  api.confirm.mockRejectedValueOnce({ response: { status: 409 } }); await w.get('[data-action="confirm"]').trigger('click'); await flushPromises(); expect(api.confirm).toHaveBeenCalledTimes(1); expect(w.find('[data-section="preview"]').exists()).toBe(false); expect(w.text()).toContain(cs.nonprofitFunding.stale); w.unmount()
 })
 it('retries an uncertain outcome only on user action with the same key and evidence', async () => {
  const w = mounted(); await fill(w); await review(w); await w.get('[data-field="ack"]').setValue(true); api.confirm.mockRejectedValueOnce(new Error('network')).mockResolvedValueOnce({ id: 9 }); await w.get('[data-action="confirm"]').trigger('click'); await flushPromises(); expect(api.confirm).toHaveBeenCalledTimes(1); expect(w.text()).toContain(cs.nonprofitFunding.uncertain)
  await w.get('[data-action="confirm"]').trigger('click'); await flushPromises(); expect(api.confirm).toHaveBeenCalledTimes(2); expect(api.confirm.mock.calls[1]).toEqual(api.confirm.mock.calls[0]); w.unmount()
 })
 it('discards a previous tenant response and hides mutation actions from readonly users', async () => {
  const w = mounted(); await fill(w); let resolve!: (p: FundingPreview) => void; api.preview.mockImplementationOnce(() => new Promise<FundingPreview>(r => { resolve = r })); await w.get('[data-action="preview"]').trigger('click'); await w.setProps({ supplier: 6 }); await flushPromises(); resolve({ source_key: 'old', policy, input_hash: 'a'.repeat(64), expected_revision: 0, report: amounts, financial_authorization: false }); await flushPromises(); expect(w.find('[data-section="preview"]').exists()).toBe(false); expect(api.confirm).not.toHaveBeenCalled(); w.unmount()
  const readonly = mounted('cs', false); await flushPromises(); expect(readonly.find('[data-action="new"]').exists()).toBe(false); readonly.unmount()
 })
 it('shows incomplete use and preserves the historical provider during terms revision', async () => {
  api.read.mockResolvedValue({ sources: [{ source_key: 'old', revision: { id: 7, period_id: 11, policy, basis: { recognized_cents: 100000, provider: { company_name: 'Historical donor' }, source_rows: { 'line:41': { id: 'line:41', date: '2026-06-01', account_code: '682', amount_cents: 100000, decision: { evidence: 'Synthetic gift', facts: { claim_exemption: true } } } } } }, source_current: false, ...amounts }], complete: false, financial_authorization: false }); const w = mounted(); await flushPromises(); expect(w.text()).toContain('Historical donor'); expect(w.text()).toContain(cs.nonprofitFunding.incomplete); expect(w.text()).toContain(cs.nonprofitFunding.giftExempt)
  const current = await api.choices.mock.results[0]?.value
  api.choices.mockResolvedValue({ ...(current || { period, providers: [], activities: [{ id: 31, label: 'Sport' }], accounts: [{ code: '518', label: 'Services' }] }), assessment_current: true, sources: [{ anchor: 'line:41', members: ['line:41', 'line:42'], kind: 'gift', amount_cents: 80000 }] })
  await w.get('[data-action="edit"]').trigger('click'); await flushPromises(); expect(w.find('[data-field="provider"]').exists()).toBe(false); expect(w.text()).toContain('Historical donor'); expect(w.text()).toContain(cs.nonprofitFunding.correctedSource)
  await w.get('[data-field="reason"]').setValue('Synthetic corrected source'); await review(w); expect(api.preview.mock.calls[0]?.[3].source_members).toEqual(['line:41', 'line:42']); w.unmount()
 })
})
describe('Funding currency input', () => {
 it('parses cents exactly through the journal range', () => { expect(fundingCents('1,01')).toBe(101); expect(fundingCents('9999999999999.99')).toBe(999999999999999) })
 it.each(['0', '-1', '1.001', '1e3', 'NaN', 'Infinity', '10 000', '10000000000000'])('rejects unsupported amount %s', value => { expect(fundingCents(value)).toBeNull() })
})
