import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick } from 'vue'
import { useSupplierStore } from '@/stores/supplier'
import type { Fx504Preview } from '@/api/fx504'
const m = vi.hoisted(() => ({ preview: vi.fn(), casePreview: vi.fn(), run: vi.fn(), revert: vi.fn(), rate: vi.fn() }))
vi.mock('@/api/fx504', () => ({ fx504Api: m }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
import FxRevaluation504 from '../FxRevaluation504.vue'
const wrappers: VueWrapper[] = []
const sample = (missing = false): Fx504Preview => ({
  securities: { complete: true, as_of: '2098-12-31', inventory_hash: 'empty', evidence: null, findings: [], cells: [], opening_entry_ids: [], profile_periods: [], profile_history: [], posted_entries: [], legal_basis: { valuation_rules: 'cz_504_2024', category_rules: 'act_316_2025_II_2', period_start_from: '2024-01-01', period_end_from: '2024-12-31', period_end_to: '2026-12-31' }, available_accounts: [], monetary_members: [], valuations: [] },
  accounting_decree: '504', snapshot_hash: 'current-proof', complete: !missing,
  positions: [{ key: 'source', account_code: '311', currency_code: 'EUR', booked_czk: '2399.99', foreign_balance: '100.00',
    dimensions: missing ? { '4': 41 } : { '1': 11, '2': 21, '3': 31, '4': 41 }, source_line_ids: [71], diff: '100.01', new_czk: '2500.00', rate: '25.000000',
    classification: 'monetary', finding: missing ? 'profit_dimensions_required' : null }],
  totals: { loss: '0.00', gain: '100.01' }, required_types: [1, 2, 3],
  dimension_types: [1, 2, 3, 4].map(id => ({ id, name: `Axis ${id}`, is_active: 1, system_key: null })),
  dimension_values: [1, 2, 3, 4].map(id => ({ id: id * 10 + 1, type_id: id, name: `Value ${id}`, code: `V${id}`, is_active: 1 })),
  account_decisions: [], saved_decisions: [], rate_info: [],
  case_decisions: [], case_source_cells: [], case_unresolved: [], case_locked_cell_keys: [], case_findings: [],
})
function render(canWrite = true) {
  const w = mount(FxRevaluation504, { props: { supplierId: 7, periodId: 8, rowVersion: 9, endsOn: '2098-12-31', canWrite, canRevert: true } })
  wrappers.push(w)
  return w
}
const button = (w: VueWrapper, name: string) => w.findAll('button').find(b => b.text() === `fx504.${name}`)!
beforeEach(() => {
  vi.clearAllMocks()
  setActivePinia(createPinia())
  useSupplierStore().currentSupplierId = 7
  m.preview.mockResolvedValue(sample())
  m.casePreview.mockImplementation(async (_sid, _pid, body) => ({ ...sample(), account_decisions: body.account_decisions, case_decisions: body.case_decisions }))
  m.run.mockResolvedValue({})
  m.revert.mockResolvedValue({})
  m.rate.mockResolvedValue({})
})
afterEach(() => { wrappers.splice(0).forEach(w => w.unmount()); vi.restoreAllMocks() })

it('blocks posting when separate securities valuation is incomplete even with no ordinary FX positions', async () => {
  const result = sample()
  result.positions = []
  result.complete = false
  result.securities.complete = false
  result.securities.findings = [{ code: 'securities_evidence_required' }]
  m.preview.mockResolvedValue(result)
  const w = render()
  await flushPromises()
  expect(w.get('[data-testid="fx504-save"]').attributes('disabled')).toBeDefined()
  await w.get('[data-testid="fx504-save"]').trigger('click')
  expect(m.run).not.toHaveBeenCalled()
})

it('requires exact cent total and all missing axes, retaining the complete source tuple', async () => {
  m.preview.mockResolvedValue(sample(true))
  const w = render()
  await flushPromises()
  const save = () => w.get('[data-testid="fx504-save"]')
  expect(save().attributes('disabled')).toBeDefined()
  const field = () => w.findAll('fieldset')[1]!
  await field().get('input[maxlength="2000"]').setValue('Synthetic joint allocation evidence')
  for (const [index, select] of field().findAll('select').entries()) await select.setValue(String((index + 1) * 10 + 1))
  await field().get('input[inputmode="decimal"]').setValue('100.00')
  expect(save().attributes('disabled')).toBeDefined()
  await field().get('input[inputmode="decimal"]').setValue('100.01')
  expect(save().attributes('disabled')).toBeUndefined()
  await save().trigger('click')
  await flushPromises()
  expect(m.run).toHaveBeenCalledExactlyOnceWith(7, 8, { row_version: 9, snapshot_hash: 'current-proof', account_decisions: {}, case_decisions: [],
    decisions: { source: { reason: 'Synthetic joint allocation evidence', cells: [{ amount: '100.01', dimensions: { '1': 11, '2': 21, '3': 31, '4': 41 } }] } } })
  expect(w.emitted('updated')).toHaveLength(1)
})

it('discards a late preview for the old supplier', async () => {
  let resolve!: (value: Fx504Preview) => void
  m.preview.mockReturnValueOnce(new Promise<Fx504Preview>(done => { resolve = done }))
  const w = render()
  useSupplierStore().currentSupplierId = 12
  await w.setProps({ supplierId: 12, periodId: 20 })
  await flushPromises()
  resolve({ ...sample(), totals: { loss: '0.00', gain: 'OLD-TENANT-SECRET' } })
  await flushPromises()
  expect(w.text()).not.toContain('OLD-TENANT-SECRET')
  await w.get('[data-testid="fx504-save"]').trigger('click')
  await flushPromises()
  expect(m.run).toHaveBeenCalledWith(12, 20, expect.objectContaining({ snapshot_hash: 'current-proof' }))
})

it('suppresses updates and errors from posting in an obsolete supplier scope', async () => {
  let reject!: (reason: unknown) => void
  m.run.mockReturnValueOnce(new Promise((_resolve, fail) => { reject = fail }))
  const w = render()
  await flushPromises()
  await w.get('[data-testid="fx504-save"]').trigger('click')
  useSupplierStore().currentSupplierId = 12
  await nextTick()
  reject(new Error('OLD-TENANT-ERROR'))
  await flushPromises()
  expect(w.emitted('updated')).toBeUndefined()
  expect(w.text()).not.toContain('OLD-TENANT-ERROR')
  expect(m.run).toHaveBeenCalledOnce()
})

it('requires refresh after changing monetary classification evidence', async () => {
  const w = render()
  await flushPromises()
  await w.get('select').setValue('nonmonetary')
  await w.get('input[maxlength="2000"]').setValue('Synthetic delivery-settled title')
  expect(w.get('[data-testid="fx504-save"]').attributes('disabled')).toBeDefined()
  await button(w, 'refresh').trigger('click')
  await flushPromises()
  expect(m.casePreview).toHaveBeenLastCalledWith(7, 8, { account_decisions: { '311': { classification: 'nonmonetary', reason: 'Synthetic delivery-settled title' } }, case_decisions: [] })
  expect(w.get('[data-testid="fx504-save"]').attributes('disabled')).toBeUndefined()
})

it('keeps readonly preview available without mutation controls', async () => {
  const w = render(false)
  await flushPromises()
  expect(w.text()).toContain('2399.99')
  expect(w.find('[data-testid="fx504-save"]').exists()).toBe(false)
  expect(button(w, 'revert')).toBeUndefined()
  expect(w.get('fieldset').attributes('disabled')).toBeDefined()
  expect(m.run).not.toHaveBeenCalled()
})

it('blocks securities assessment and does not offer a monetary reason bypass', async () => {
  const p = sample()
  p.complete = false
  p.positions[0] = { ...p.positions[0]!, account_code: '061', finding: 'securities_valuation_required', classification: 'securities', diff: null }
  m.preview.mockResolvedValue(p)
  const w = render()
  await flushPromises()
  expect(w.get('[data-testid="fx504-save"]').attributes('disabled')).toBeDefined()
  expect(w.find('select').exists()).toBe(false)
  expect(w.text()).toContain('fx504.finding.securities_valuation_required')
})

it('fetches missing rates only on request and refreshes the authoritative preview', async () => {
  const p = sample()
  p.complete = false
  p.positions[0] = { ...p.positions[0]!, finding: 'rate_required', rate: null, diff: null }
  m.preview.mockResolvedValueOnce(p)
  const w = render()
  await flushPromises()
  expect(m.rate).not.toHaveBeenCalled()
  expect(w.get('[data-testid="fx504-save"]').attributes('disabled')).toBeDefined()
  await button(w, 'fetch_rates').trigger('click')
  await flushPromises()
  expect(m.rate).toHaveBeenCalledExactlyOnceWith(7, 'EUR', '2098-12-31')
  expect(m.casePreview).toHaveBeenCalledOnce()
  expect(w.get('[data-testid="fx504-save"]').attributes('disabled')).toBeUndefined()
})


it('requires explicit case evidence and a new authoritative preview before posting', async () => {
  const p = sample()
  const source = { cell_key: 'historical-cell', line_id: 71, entry_id: 17, line_no: 1, account_id: 31, account_code: '311', currency_code: 'EUR', dimensions: { '4': 41 }, czk_signed: '2399.99', foreign_signed: '100.00', candidates: [] }
  p.case_source_cells = [source]
  p.case_unresolved = [source]
  p.complete = false
  p.positions[0]!.finding = 'case_evidence_required'
  m.preview.mockResolvedValueOnce(p)
  m.casePreview.mockImplementationOnce(async (_sid, _pid, body) => ({ ...sample(), case_source_cells: [source], case_decisions: body.case_decisions }))
  const w = render()
  await flushPromises()
  expect(w.find('[data-testid="fx-case-editor"]').exists()).toBe(true)
  expect(w.get('[data-testid="fx504-save"]').attributes('disabled')).toBeDefined()
  const editor = w.get('[data-testid="fx-case-editor"]')
  await editor.get('select').setValue('declared:71')
  await editor.get('[data-testid="case-reference"]').setValue('Synthetic opening receivable inventory')
  await editor.get('[data-testid="case-reason"]').setValue('Synthetic original claim and payment evidence')
  expect(w.get('[data-testid="fx504-save"]').attributes('disabled')).toBeDefined()
  await button(w, 'refresh').trigger('click')
  await flushPromises()
  const cases = [{ origin: { kind: 'declared_title', origin_line_id: 71, reference: 'Synthetic opening receivable inventory' }, reason: 'Synthetic original claim and payment evidence', contributions: [{ cell_key: 'historical-cell', czk_signed: '2399.99', foreign_signed: '100.00' }] }]
  expect(m.casePreview).toHaveBeenLastCalledWith(7, 8, { account_decisions: {}, case_decisions: cases })
  expect(w.get('[data-testid="fx504-save"]').attributes('disabled')).toBeUndefined()
  await w.get('[data-testid="fx504-save"]').trigger('click')
  await flushPromises()
  expect(m.run).toHaveBeenLastCalledWith(7, 8, expect.objectContaining({ case_decisions: cases }))
})

it('shows unproven native nominal evidence and prevents completion', async () => {
  const p = sample()
  p.complete = false
  p.case_findings = [{ code: 'case_nominal_unproven', line_id: 71, entry_id: 17 }]
  m.preview.mockResolvedValueOnce(p)
  const w = render()
  await flushPromises()
  expect(w.text()).toContain('fx504.case_nominal_unproven')
  expect(w.get('[data-testid="fx504-save"]').attributes('disabled')).toBeDefined()
  expect(m.run).not.toHaveBeenCalled()
})
