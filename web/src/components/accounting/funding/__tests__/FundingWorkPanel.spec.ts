import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h, KeepAlive, ref } from 'vue'
import { createI18n } from 'vue-i18n'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Panel from '../FundingWorkPanel.vue'
import type { FundingWorkAllocation, FundingWorkDetail, FundingWorkOverview } from '@/api/nonprofitFundingWork'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'

const funding = vi.hoisted(() => ({ periods: vi.fn() }))
const api = vi.hoisted(() => ({
 overview: vi.fn(), choices: vi.fn(), incomeCandidates: vi.fn(), costCandidates: vi.fn(), detail: vi.fn(), create: vi.fn(), update: vi.fn(), cancel: vi.fn(),
 addIncomes: vi.fn(), removeIncome: vi.fn(), relinkIncome: vi.fn(), allocate: vi.fn(), updateAllocation: vi.fn(), removeAllocation: vi.fn(), relinkAllocation: vi.fn(),
}))
vi.mock('@/api/nonprofitFunding', () => ({ fundingApi: funding }))
vi.mock('@/api/nonprofitFundingWork', () => ({ fundingWorkApi: api }))

type Action = { key: string; label: string; disabled?: boolean; show?: unknown; run?: () => void }
const buttons = (actions: Action[]) => actions.filter(a => a.show !== false).map(a => h('button', { 'data-action': a.key, disabled: a.disabled, onClick: a.run }, a.label))
const ActionBar = defineComponent({ props: ['actions'], setup: p => () => h('div', buttons(p.actions as Action[])) })
const DialogActions = defineComponent({ props: ['actions'], setup: p => () => h('div', { 'data-section': 'dialog-actions' }, buttons(p.actions as Action[])) })
const Modal = defineComponent({ props: ['title'], emits: ['close'], setup: (p, { slots }) => () => h('div', { role: 'dialog', 'data-title': p.title }, slots.default?.()) })

const period = { id: 11, starts_on: '2026-01-01', ends_on: '2026-12-31', status: 'open' }
const totals = { budget_cents: 100000, received_cents: 100000, allocated_cents: 30000, documented_cents: 0, remaining_cents: 70000, review_count: 1, review_cents: 30000, income_count: 1, allocation_count: 1 }
const source = { id: 7, period_id: 11, provider_id: 21, provider_label: 'Synthetic provider', kind: 'operating_grant' as const, title: 'Synthetic city grant', contract_ref: 'SYN-1', budget_cents: 100000,
 valid_from: '2026-01-01', valid_to: '2026-12-31', activity_kind_ids: [], account_prefixes: ['518'], legacy_source_key: null, status: 'active' as const, row_version: 1, created_at: '2026-11-01 10:00:00', updated_at: null }
const newLine = { id: 99, entry_id: 50, period_id: 11, account_id: 3, account_code: '518', account_name: 'Services', side: 'debit' as const, amount_cents: 32000, date: '2026-06-01', document_no: 'SYN-7',
 description: 'Synthetic service', source_type: 'manual', source_id: null, posted: true, reversed: false, is_reversal: false, activity_kind_id: null }
const removed: FundingWorkAllocation = { id: 3, source_id: 7, line_id: null, linked_line_id: 41, entry_id: 50, entry_source_type: 'manual', entry_source_id: null, account_id: 3, account_code: '518', entry_date: '2026-06-01', row_version: 1,
 amount_cents: 30000, line_amount_cents: 30000, note: null, state: 'removed', review_reason: 'removed', document_no: 'SYN-6', line: null, relink_candidates: [newLine], payment_state: null, confirmed_source_key: null }
const overview = (sources: FundingWorkOverview['sources'] = []): FundingWorkOverview => ({ period, editable: true, sources })
const detail = (allocations: FundingWorkAllocation[] = [removed]): FundingWorkDetail => ({ period, editable: true, source, totals, incomes: [], allocations, allocations_page: 1, allocations_more: false, history: [] })

function mounted(locale = 'cs', canManage = true) {
 return mount(Panel, { props: { supplier: 5, canManage }, global: { plugins: [createI18n({ legacy: false, locale, messages: { cs, en } })], stubs: { ActionBar, DialogActions, Modal, DateInput: true, MultiSelectFilter: true } } })
}

beforeEach(() => {
 vi.clearAllMocks()
 funding.periods.mockResolvedValue([period])
 api.overview.mockResolvedValue(overview())
 api.detail.mockResolvedValue(detail())
 api.choices.mockResolvedValue({ providers: [{ id: 21, label: 'Synthetic provider' }], providers_more: false, activities: [], accounts: [{ code: '518', label: '518 - Services' }], confirmed_sources: [] })
})

describe('Funding work panel (#403)', () => {
 it('R13: shows empty state, read-only notice and inline error', async () => {
  const w = mounted('cs', false); await flushPromises()
  expect(w.get('[data-section="work-empty"]').text()).toBe(cs.nonprofitFunding.work.empty)
  expect(w.get('[data-section="work-readonly"]').text()).toBe(cs.nonprofitFunding.work.readOnly)
  expect(w.find('[data-action="new"]').exists()).toBe(false)
  w.unmount()
  api.overview.mockRejectedValueOnce({ response: { status: 500, data: { error: { message: 'Synthetic failure' } } } })
  const e = mounted(); await flushPromises()
  expect(e.get('[data-section="work-error"]').attributes('role')).toBe('alert')
  expect(e.get('[data-section="work-error"]').text()).toBe('Synthetic failure')
 })

 it.each(['cs', 'en'])('R13: renders %s desktop table and mobile cards with review badge', async locale => {
  api.overview.mockResolvedValue(overview([{ ...source, totals }]))
  const w = mounted(locale); await flushPromises()
  expect(w.find('[data-section="work-sources"]').exists()).toBe(true)
  expect(w.find('[data-section="work-cards"]').exists()).toBe(true)
  expect(w.text()).toContain('Synthetic city grant')
  expect(w.text()).not.toContain('nonprofitFunding.')
  expect(w.get('[data-badge="review"]').text()).toContain('1')
 })

 it('R5: detail shows a removed line for review and relinks it to the offered line', async () => {
  api.overview.mockResolvedValue(overview([{ ...source, totals }]))
  api.relinkAllocation.mockResolvedValue(detail([{ ...removed, line_id: 99, linked_line_id: 99, state: 'ok', review_reason: null, document_no: 'SYN-7', line: newLine, relink_candidates: [] }]))
  const w = mounted(); await flushPromises()
  await w.get('[data-source="7"]').trigger('click'); await flushPromises()
  expect(w.get('[data-allocation="3"] [data-state]').text()).toBe(cs.nonprofitFunding.work.state_removed)
  expect(w.get('[data-allocation="3"]').text()).toContain('SYN-6')
  expect(w.get('[data-total="review"]').text()).toContain('1')
  await w.get('[data-action="relink"]').trigger('click'); await flushPromises()
  expect((w.get('[data-field="amount"]').element as HTMLInputElement).value).toBe('300.00')
  await w.get('[data-section="dialog-actions"] [data-action="save"]').trigger('click'); await flushPromises()
  expect(api.relinkAllocation).toHaveBeenCalledWith(5, 11, 3, 99, 30000, 1)
  expect(w.get('[data-allocation="3"] [data-state]').text()).toBe(cs.nonprofitFunding.work.state_ok)
  await w.get('[data-section="work-detail"] [data-action="back"]').trigger('click'); await flushPromises()
  expect(w.find('[data-section="work-detail"]').exists()).toBe(false)
  expect(w.find('[data-section="work-sources"]').exists()).toBe(true)
  expect(w.get('[data-section="work-notice"]').text()).toBe(cs.nonprofitFunding.work.relinked)
 })

 it('R2: budget overrun keeps the dialog open with the server message', async () => {
  api.overview.mockResolvedValue(overview([{ ...source, totals }]))
  api.costCandidates.mockResolvedValue({ rows: [{ ...newLine, allocated_cents: 0, capacity_cents: 32000 }], more: false })
  api.allocate.mockRejectedValue({ response: { status: 422, data: { error: { code: 'funding_work_budget_exceeded', message: 'Přiřazení překračuje rozpočet zdroje. Zbývá 100,00 Kč.' } } } })
  const w = mounted(); await flushPromises()
  await w.get('[data-source="7"]').trigger('click'); await flushPromises()
  await w.get('[data-action="add-costs"]').trigger('click'); await flushPromises()
  await w.get('[data-line="99"] input[type="checkbox"]').trigger('change')
  expect((w.get('[data-field="pick-amount"]').element as HTMLInputElement).value).toBe('320.00')
  await w.get('[data-section="dialog-actions"] [data-action="save"]').trigger('click'); await flushPromises()
  expect(api.allocate).toHaveBeenCalledWith(5, 11, 7, [{ line_id: 99, amount_cents: 32000 }])
  expect(w.get('[data-section="dialog-error"]').text()).toContain('Zbývá 100,00 Kč')
  expect(w.find('[role="dialog"]').exists()).toBe(true)
  expect(w.get('[data-section="dialog-actions"] [data-action="close"]').text()).toBe('Zrušit')
  await w.get('[data-section="dialog-actions"] [data-action="close"]').trigger('click'); await flushPromises()
  expect(w.find('[role="dialog"]').exists()).toBe(false)
  expect(w.find('[data-section="work-error"]').exists()).toBe(false)
 })

 it('R2: prefilled amounts of several picked lines stay within the remaining budget', async () => {
  api.overview.mockResolvedValue(overview([{ ...source, totals }]))
  api.detail.mockResolvedValue({ ...detail([]), totals: { ...totals, remaining_cents: 100000 } })
  api.costCandidates.mockResolvedValue({ rows: [{ ...newLine, id: 98, amount_cents: 80000, allocated_cents: 0, capacity_cents: 80000 }, { ...newLine, amount_cents: 80000, allocated_cents: 0, capacity_cents: 80000 }], more: false })
  const w = mounted(); await flushPromises()
  await w.get('[data-source="7"]').trigger('click'); await flushPromises()
  await w.get('[data-action="add-costs"]').trigger('click'); await flushPromises()
  await w.get('[data-line="98"] input[type="checkbox"]').trigger('change')
  await w.get('[data-line="99"] input[type="checkbox"]').trigger('change')
  const amounts = w.findAll('[data-field="pick-amount"]').map(i => (i.element as HTMLInputElement).value)
  expect(amounts).toEqual(['800.00', '200.00'])
 })

 it('R5: choosing another relink line recomputes the prefilled amount', async () => {
  api.overview.mockResolvedValue(overview([{ ...source, totals }]))
  api.detail.mockResolvedValue(detail([{ ...removed, relink_candidates: [newLine, { ...newLine, id: 100, amount_cents: 10000 }] }]))
  const w = mounted(); await flushPromises()
  await w.get('[data-source="7"]').trigger('click'); await flushPromises()
  await w.get('[data-action="relink"]').trigger('click'); await flushPromises()
  expect((w.get('[data-field="amount"]').element as HTMLInputElement).value).toBe('300.00')
  await w.get('[data-field="relink-line"]').setValue('100'); await flushPromises()
  expect((w.get('[data-field="amount"]').element as HTMLInputElement).value).toBe('100.00')
 })

 it('R8 + R9: reader and closed period see no write actions in the detail; cancel is blocked with assigned costs', async () => {
  api.overview.mockResolvedValue(overview([{ ...source, totals }]))
  const reader = mounted('cs', false); await flushPromises()
  await reader.get('[data-source="7"]').trigger('click'); await flushPromises()
  for (const action of ['add-costs', 'add-income', 'edit', 'cancel-source', 'relink', 'remove', 'amount']) expect(reader.find(`[data-action="${action}"]`).exists(), action).toBe(false)
  reader.unmount()
  api.detail.mockResolvedValue({ ...detail(), editable: false })
  const closed = mounted(); await flushPromises()
  await closed.get('[data-source="7"]').trigger('click'); await flushPromises()
  expect(closed.get('[data-action="add-costs"]').attributes('disabled')).toBeDefined()
  expect(closed.find('[data-action="relink"]').exists()).toBe(false)
  expect(closed.find('[data-action="remove"]').exists()).toBe(false)
  closed.unmount()
  api.detail.mockResolvedValue(detail())
  const writer = mounted(); await flushPromises()
  await writer.get('[data-source="7"]').trigger('click'); await flushPromises()
  expect(writer.get('[data-action="cancel-source"]').attributes('disabled')).toBeDefined()
 })

 it('R13: a failed period switch clears the previous period sources; cancelled source is marked on mobile', async () => {
  funding.periods.mockResolvedValue([period, { id: 12, starts_on: '2025-01-01', ends_on: '2025-12-31', status: 'closed' }])
  api.overview.mockResolvedValueOnce(overview([{ ...source, status: 'cancelled', totals }]))
  const w = mounted(); await flushPromises()
  expect(w.get('[data-section="work-cards"]').text()).toContain(cs.nonprofitFunding.work.cancelledBadge)
  api.overview.mockRejectedValueOnce({ response: { status: 500, data: { error: { message: 'Synthetic failure' } } } })
  await w.get('[data-field="work-period"]').setValue('12'); await flushPromises()
  expect(w.find('[data-section="work-sources"]').exists()).toBe(false)
  expect(w.get('[data-section="work-error"]').text()).toBe('Synthetic failure')
 })

 it('R3: version conflict reloads the detail and explains it', async () => {
  api.overview.mockResolvedValue(overview([{ ...source, totals }]))
  api.removeAllocation.mockRejectedValue({ response: { status: 409, data: { error: { code: 'funding_work_version_conflict', message: 'x' } } } })
  const w = mounted(); await flushPromises()
  await w.get('[data-source="7"]').trigger('click'); await flushPromises()
  await w.get('[data-action="remove"]').trigger('click'); await flushPromises()
  await w.get('[data-field="reason"]').setValue('Synthetic correction')
  await w.get('[data-section="dialog-actions"] [data-action="save"]').trigger('click'); await flushPromises()
  expect(api.removeAllocation).toHaveBeenCalledWith(5, 11, 3, 1, 'Synthetic correction')
  expect(api.detail).toHaveBeenCalledTimes(2)
  expect(w.find('[role="dialog"]').exists()).toBe(false)
  expect(w.get('[data-section="work-error"]').text()).toBe(cs.nonprofitFunding.work.stale)
 })

 it('R3: after a conflict the repeated action uses the reloaded version; busy lock has its own message', async () => {
  api.overview.mockResolvedValue(overview([{ ...source, totals }]))
  api.detail.mockResolvedValueOnce(detail()).mockResolvedValueOnce(detail([{ ...removed, row_version: 2 }]))
  api.removeAllocation.mockRejectedValueOnce({ response: { status: 409, data: { error: { code: 'funding_work_version_conflict', message: 'x' } } } })
   .mockRejectedValueOnce({ response: { status: 409, data: { error: { code: 'funding_work_busy', message: 'x' } } } })
   .mockResolvedValueOnce(detail([]))
  const w = mounted(); await flushPromises()
  await w.get('[data-source="7"]').trigger('click'); await flushPromises()
  const remove = async () => {
   await w.get('[data-action="remove"]').trigger('click'); await flushPromises()
   await w.get('[data-field="reason"]').setValue('Synthetic correction')
   await w.get('[data-section="dialog-actions"] [data-action="save"]').trigger('click'); await flushPromises()
  }
  await remove()
  await remove()
  expect(api.removeAllocation).toHaveBeenLastCalledWith(5, 11, 3, 2, 'Synthetic correction')
  expect(w.get('[data-section="dialog-error"]').text()).toBe(cs.nonprofitFunding.work.busy)
  await w.get('[data-section="dialog-actions"] [data-action="save"]').trigger('click'); await flushPromises()
  expect(w.find('[role="dialog"]').exists()).toBe(false)
  expect(w.get('[data-section="work-notice"]').text()).toBe(cs.nonprofitFunding.work.removed)
 })

 it('R3: a conflict while editing the source closes the form instead of resubmitting stale values', async () => {
  api.overview.mockResolvedValue(overview([{ ...source, totals }]))
  api.update.mockRejectedValue({ response: { status: 409, data: { error: { code: 'funding_work_version_conflict', message: 'x' } } } })
  const w = mounted(); await flushPromises()
  await w.get('[data-source="7"]').trigger('click'); await flushPromises()
  await w.get('[data-action="edit"]').trigger('click'); await flushPromises()
  await w.get('[data-field="budget"]').setValue('2000,00')
  await w.get('[data-section="dialog-actions"] [data-action="save"]').trigger('click'); await flushPromises()
  expect(api.update).toHaveBeenCalledTimes(1)
  expect(api.update.mock.calls[0]?.[3]).toMatchObject({ budget_cents: 200000, expected_version: 1 })
  expect(w.find('[role="dialog"]').exists()).toBe(false)
  expect(api.detail).toHaveBeenCalledTimes(2)
 })

 it('R11: reloads the overview when the page is activated again (KeepAlive)', async () => {
  const show = ref(true)
  const Host = defineComponent({ setup: () => () => h(KeepAlive, null, [show.value ? h(Panel, { supplier: 5, canManage: true }) : h('div')]) })
  const w = mount(Host, { global: { plugins: [createI18n({ legacy: false, locale: 'cs', messages: { cs, en } })], stubs: { ActionBar, DialogActions, Modal, DateInput: true, MultiSelectFilter: true } } })
  await flushPromises()
  expect(api.overview).toHaveBeenCalledTimes(1)
  show.value = false; await flushPromises()
  api.overview.mockResolvedValue(overview([{ ...source, totals }]))
  show.value = true; await flushPromises()
  expect(api.overview).toHaveBeenCalledTimes(2)
  expect(w.text()).toContain('Synthetic city grant')
 })

 it('shows why a link needs review, warns about cost drawn in confirmed J6 and pages the assigned costs', async () => {
  api.overview.mockResolvedValue(overview([{ ...source, totals }]))
  const credited: FundingWorkAllocation = { ...removed, line_id: 99, linked_line_id: 99, state: 'changed', review_reason: 'credit_note', line: newLine, confirmed_source_key: 'gift-sport' }
  api.detail.mockResolvedValueOnce({ ...detail([credited]), allocations_more: true }).mockResolvedValueOnce({ ...detail([]), allocations_page: 2 })
  const w = mounted(); await flushPromises()
  await w.get('[data-source="7"]').trigger('click'); await flushPromises()
  expect(w.get('[data-allocation="3"] [data-reason="review"]').text()).toBe(cs.nonprofitFunding.work.reason_credit_note)
  expect(w.get('[data-allocation="3"] [data-warning="confirmed"]').text()).toBe(cs.nonprofitFunding.work.costInConfirmed)
  await w.get('[data-action="allocations-next"]').trigger('click'); await flushPromises()
  expect(api.detail).toHaveBeenLastCalledWith(5, 11, 7, 2)
 })
})
