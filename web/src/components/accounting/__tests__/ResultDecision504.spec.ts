import { beforeEach, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { ProfitDistribution504Preview } from '@/api/closing'
const m = vi.hoisted(() => ({ preview: vi.fn(), post: vi.fn(), revert: vi.fn() }))
vi.mock('@/api/closing', () => ({ closingApi: { profitDistributionPreview: m.preview, profitDistribution: m.post, profitDistributionRevert: m.revert } }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (value: number) => String(value) }))
import ResultDecision504 from '../ResultDecision504.vue'
const sample = (): ProfitDistribution504Preview => ({
  accounting_framework: 'cz_504', as_of: '2026-12-31', approved_period: { id: 1, fiscal_year: 2025, status: 'approved' },
  target_period: { id: 2, fiscal_year: 2026, row_version: 5, starts_on: '2026-01-01', ends_on: '2026-12-31' },
  cells: [{ key: 'joint-cell', account_code: '931', dimensions: { 1: 11, 2: 21, 3: 31 }, available: 100.01, available_cents: 10001, source_line_ids: [5], cost_center: null, project_id: null }],
  resources: [], dimension_labels: { 11: 'Main activity', 21: 'Sport', 31: 'Preliminary classification' }, snapshot_hash: 'proof',
  available_profit: 100.01, pending_account: '931', target_roles: [{ role: 'result.retained_profit', account_code: '932' }, { role: 'result.funds', account_code: '911' }],
  decision: null, existing_entry_id: null,
})
const global = { stubs: { DateInput: { props: ['modelValue', 'disabled'], emits: ['update:modelValue'], template: '<input type="date" :value="modelValue" :disabled="disabled" @input="$emit(\'update:modelValue\', $event.target.value)" />' } } }
beforeEach(() => { vi.clearAllMocks(); m.post.mockResolvedValue({ entry_id: 9 }) })

it('requires a dated refreshed preview and decision evidence before posting exact allocations', async () => {
  const initial = sample()
  m.preview.mockResolvedValue({ ...initial, as_of: '2026-05-01' })
  const w = mount(ResultDecision504, { props: { periodId: 1, initial, canWrite: true }, global })
  const save = () => w.findAll('button').find(b => b.text() === 'accounting.result504.save')!
  expect(save().attributes('disabled')).toBeDefined()
  expect(w.text()).toContain('Main activity · Sport · Preliminary classification')
  await w.get('input[type=date]').setValue('2026-05-01')
  await w.findAll('button').find(b => b.text() === 'accounting.result504.refresh')!.trigger('click')
  await flushPromises()
  const evidence = w.findAll('input[maxlength="500"]')
  await evidence[0]!.setValue('SYN-RESOLUTION-1')
  await evidence[1]!.setValue('Synthetic authorised body')
  await save().trigger('click')
  await flushPromises()
  expect(m.post).toHaveBeenCalledWith(1, {
    decision_date: '2026-05-01', decision_reference: 'SYN-RESOLUTION-1', decision_authority: 'Synthetic authorised body',
    target_row_version: 5, snapshot_hash: 'proof', allocations: [{ cell_key: 'joint-cell', role: 'result.retained_profit', amount: 100.01 }],
  })
})

it('shows the recorded fund allocation instead of replacing it with retained profit', () => {
  const initial = sample()
  initial.existing_entry_id = 9
  initial.decision = { decision_date: '2026-05-01', decision_reference: 'SYN-1', decision_authority: 'Synthetic body', entry_id: 9, target_period_id: 2, cells: initial.cells, snapshot_hash: 'proof', allocations: [{ cell_key: 'joint-cell', role: 'result.funds', amount: 100.01 }] }
  const w = mount(ResultDecision504, { props: { periodId: 1, initial, canWrite: false }, global })
  expect((w.get('select').element as HTMLSelectElement).value).toBe('result.funds')
  expect(w.findAll('button').some(b => b.text() === 'accounting.result504.save')).toBe(false)
})

it('refreshes the displayed decision when the parent supplies another period', async () => {
  const w = mount(ResultDecision504, { props: { periodId: 1, initial: sample(), canWrite: true }, global })
  const next = sample()
  next.dimension_labels[11] = 'Next period activity'
  next.snapshot_hash = 'next-proof'
  await w.setProps({ periodId: 7, initial: next })
  expect(w.text()).toContain('Next period activity')
  expect(w.text()).not.toContain('Main activity')
})

it('ignores a preview response belonging to the previous period', async () => {
  let resolve!: (value: ProfitDistribution504Preview) => void
  m.preview.mockReturnValue(new Promise<ProfitDistribution504Preview>(done => { resolve = done }))
  const w = mount(ResultDecision504, { props: { periodId: 1, initial: sample(), canWrite: true }, global })
  await w.get('input[type=date]').setValue('2026-05-01')
  await w.findAll('button').find(b => b.text() === 'accounting.result504.refresh')!.trigger('click')
  const next = sample()
  next.dimension_labels[11] = 'Next period activity'
  next.snapshot_hash = 'next-proof'
  await w.setProps({ periodId: 7, initial: next })
  resolve({ ...sample(), as_of: '2026-05-01' })
  await flushPromises()
  expect(w.text()).toContain('Next period activity')
  expect(w.text()).not.toContain('Main activity')
})
it('requires a specific analytical resource and sends the selected account for loss coverage', async () => {
  const initial = sample()
  initial.cells[0]!.available = -100.01
  initial.cells[0]!.available_cents = -10001
  initial.resources = ['911.100', '911.200'].map((account_code, index) => ({ ...initial.cells[0]!, key: `resource-${index}`, account_code, available: 200.01, available_cents: 20001 }))
  m.preview.mockResolvedValue({ ...initial, as_of: '2026-05-01' })
  const w = mount(ResultDecision504, { props: { periodId: 1, initial, canWrite: true }, global })
  await w.get('input[type=date]').setValue('2026-05-01')
  await w.findAll('button').find(b => b.text() === 'accounting.result504.refresh')!.trigger('click')
  await flushPromises()
  const evidence = w.findAll('input[maxlength="500"]')
  await evidence[0]!.setValue('SYN-ANALYTICAL-DECISION')
  await evidence[1]!.setValue('Synthetic authorised body')
  await w.get('select').setValue('result.funds')
  const save = () => w.findAll('button').find(b => b.text() === 'accounting.result504.save')!
  expect(save().attributes('disabled')).toBeDefined()
  await w.get('[data-testid="result-resource-account"]').setValue('911.200')
  expect(save().attributes('disabled')).toBeUndefined()
  await save().trigger('click')
  await flushPromises()
  expect(m.post).toHaveBeenCalledWith(1, expect.objectContaining({ allocations: [{ cell_key: 'joint-cell', role: 'result.funds', amount: 100.01, account_code: '911.200' }] }))
})