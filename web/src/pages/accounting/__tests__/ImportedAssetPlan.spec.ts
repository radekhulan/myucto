import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { AssetDetail, DepreciationPlan } from '@/api/assets'
import AssetEditor from '../AssetEditor.vue'
import AssetDetailPage from '../AssetDetail.vue'
import AssetPlanRevisionModal from '@/components/accounting/AssetPlanRevisionModal.vue'

const m = vi.hoisted(() => ({
  previewRevision: vi.fn(), revise: vi.fn(), assetWrite: true, depreciationLevel: 2, get: vi.fn(), plan: vi.fn(), update: vi.fn(), create: vi.fn(), putIntoUse: vi.fn(),
  push: vi.fn(), error: vi.fn(), success: vi.fn(), warning: vi.fn(),
  mode: 'double_entry', loadDimensions: vi.fn(), saveDimensions: vi.fn(),
}))
vi.mock('@/api/assets', () => ({ assetsApi: { previewRevision: m.previewRevision, revise: m.revise, get: m.get, plan: m.plan, update: m.update, create: m.create, putIntoUse: m.putIntoUse } }))
vi.mock('@/api/accounting', () => ({ accountingApi: { listAccounts: async () => [] } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: (permission: string) => permission === 'assets.write' ? m.assetWrite : permission === 'assets.depreciation' ? m.depreciationLevel >= 2 : true }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplier: { accounting_mode: m.mode } }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: m.error, success: m.success, warning: m.warning }) }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (value: unknown) => String(value), formatDate: (value: unknown) => String(value), formatMonth: (value: unknown) => String(value), formatDateTime: (value: unknown) => String(value), formatMoneyMinor: (value: unknown) => `minor:${value}` }))
vi.mock('@/composables/useDocumentDimensions', async () => {
  const { ref } = await import('vue')
  return { useDocumentDimensions: () => ({ enabled: ref(false), header: ref({}), canEdit: ref(true), hasAutoFilled: ref(false), load: m.loadDimensions, save: m.saveDimensions, watchDefaults: vi.fn() }) }
})
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string, values?: object) => values ? `${key}:${JSON.stringify(values)}` : key }) }))
vi.mock('vue-router', () => ({ useRoute: () => ({ params: { id: '9' }, query: {} }), useRouter: () => ({ push: m.push }) }))

const sourcePlan = { version: 1 as const, source_program: 'Synthetic Ledger', source_tax_method: 'SYN-TAX', source_accounting_method: 'SYN-ACC', accounting: [{ month: '2030-01', amount_cents: 1000 }], tax: [{ fiscal_year: 2030, amount_cents: 1000 }] }
function asset(overrides: Partial<AssetDetail> = {}): AssetDetail {
  return {
    id: 9, supplier_id: 1, inventory_number: 'SYNTHETIC-9', name: 'Synthetic asset', description: null,
    kind: 'intangible', asset_account_code: '013', accumulated_account_code: '073', acquisition_account_code: '041',
    purchase_invoice_id: null, purchase_invoice_item_id: null, input_price: 20, acquisition_date: '2029-12-01',
    put_into_use_date: '2030-01-01', disposal_date: null, disposal_type: null, disposal_price: null,
    status: 'in_use', tax_method: 'by_accounting', tax_group: null, tax_first_year_increase: 'none', is_first_owner: false,
    is_m1_vehicle: false, m1_limit_exception: false, is_zero_emission: false, opening_tax_years: 0, opening_tax_amount: 0,
    opening_acc_months: 0, opening_acc_amount: 0, acc_useful_life_months: null, acc_method: 'straight_line', acc_residual_value: 0,
    created_at: '2030-01-01', updated_at: '2030-01-01', imported_depreciation_plan: sourcePlan,
    locked: { tax_params: false, acquisition: false }, ...overrides,
  }
}
const plan: DepreciationPlan = { asset_summary: { input_price: 20, increased_input_price: 20, tax_residual: 10, acc_residual: 10, accumulated_depreciation: 10 },
  tax: [{ fiscal_year: 2030, amount: 10, full_amount: 10, residual_start: 10, residual_end: 0, is_half: false, is_paused: false, months_count: 1,
    months: [{ month: '2030-01', amount: 10 }], source: 'imported', note: null }], accounting: [] }
const revisionRecord = { revision: 1, effective_from: '2030-02', remaining_months: 2, remaining_amount_cents: 1000, reason: 'Synthetic reassessment', user_id: 17, created_at: '2030-01-10 12:30:00', fiscal_start_month: 7 }
function revisionPreview() {
  const row = { ...plan.tax[0]!, months: [{ month: '2030-02', amount: 10 }] }
  return { revision: 1, effective_from: '2030-02', remaining_months: 2, remaining_amount: 10, calendar_start_month: 7, calendar_fingerprint: 'synthetic-calendar-v1',
    before: { ...plan, accounting: [row] }, after: { ...plan, accounting: [{ ...row, months: [{ month: '2030-02', amount: 4 }, { month: '2030-03', amount: 6 }] }] } }
}
async function revisionForm(wrapper: Awaited<ReturnType<typeof detail>>) {
  await wrapper.get('[data-testid="revision-effective-from"]').setValue('2030-02')
  await wrapper.get('[data-testid="revision-remaining-months"]').setValue('2')
  await wrapper.get('[data-testid="revision-reason"]').setValue('  Synthetic reassessment  ')
}
const stubs = {
  RouterLink: { props: ['to'], template: '<a><slot /></a>' },
  SearchableSelect: { props: ['disabled', 'modelValue'], template: '<input :disabled="disabled" :value="modelValue" data-test="account-select" />' },
  DateInput: { props: ['disabled', 'modelValue'], template: '<input type="date" :disabled="disabled" :value="modelValue" data-test="date-input" />' },
  DimensionFields: true, DocumentDimensionsPanel: true,
  Modal: { name: 'Modal', template: '<div><slot /></div>' },
  ActionBar: { props: ['actions'], template: '<div><button v-for="action in actions.filter(item => item.show !== false)" :key="action.key" :data-action="action.key" @click="action.run?.()">{{ action.label }}</button></div>' },
}
async function editor() { const wrapper = mount(AssetEditor, { global: { stubs } }); await flushPromises(); return wrapper }
async function detail() { const wrapper = mount(AssetDetailPage, { global: { stubs } }); await flushPromises(); return wrapper }

beforeEach(() => {
  vi.resetAllMocks()
  m.mode = 'double_entry'
  m.assetWrite = true
  m.depreciationLevel = 2
  m.get.mockResolvedValue(asset())
  m.plan.mockResolvedValue(plan)
  m.update.mockResolvedValue({ asset: asset(), warnings: [] })
  m.putIntoUse.mockResolvedValue({ asset: asset(), warnings: [] })
})

describe('Imported asset plan UI', () => {
  it.each([
    [true, 0, false], [false, 2, true], [true, 1, false], [false, 1, false],
  ])('gates revision by depreciation WRITE independently of card writes (cards=%s depreciation=%s)', async (cards, depreciation, allowed) => {
    m.assetWrite = cards
    m.depreciationLevel = depreciation
    m.previewRevision.mockResolvedValue(revisionPreview())
    const wrapper = await detail()
    expect(wrapper.find('[data-action="revise_plan"]').exists()).toBe(allowed)
    if (allowed) {
      await wrapper.get('[data-action="revise_plan"]').trigger('click')
      await revisionForm(wrapper)
      await wrapper.get('[data-testid="revision-form"]').trigger('submit')
      await flushPromises()
      expect(m.previewRevision).toHaveBeenCalledOnce()
    } else expect(m.previewRevision).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it.each(['no_permission', 'native', 'draft', 'disposed', 'tax_evidence'])('hides controlled revision for %s while preserving read access', async variant => {
    if (variant === 'no_permission') m.depreciationLevel = 0
    if (variant === 'tax_evidence') m.mode = 'tax_evidence'
    if (variant === 'native') m.get.mockResolvedValue(asset({ imported_depreciation_plan: null }))
    if (variant === 'draft' || variant === 'disposed') m.get.mockResolvedValue(asset({ status: variant }))
    const wrapper = await detail()
    expect(wrapper.find('[data-action="revise_plan"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Synthetic asset')
    wrapper.unmount()
  })

  it('requires a reason and a server preview before saving the exact previewed revision snapshot', async () => {
    m.previewRevision.mockResolvedValue(revisionPreview())
    m.revise.mockImplementation(async () => {
      const revised = asset({ imported_depreciation_plan: { ...sourcePlan, revisions: [revisionRecord] } })
      m.get.mockResolvedValue(revised)
      return { asset: revised, warnings: [] }
    })
    const wrapper = await detail()
    await wrapper.get('[data-action="revise_plan"]').trigger('click')
    expect(wrapper.get('[data-testid="revision-preview"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="revision-save"]').attributes('disabled')).toBeDefined()
    await revisionForm(wrapper)
    await wrapper.get('[data-testid="revision-form"]').trigger('submit')
    await flushPromises()
    const payload = { effective_from: '2030-02', remaining_months: 2, reason: 'Synthetic reassessment', expected_revision: 0 }
    expect(m.previewRevision).toHaveBeenCalledWith(9, payload)
    for (const phase of ['revision-before', 'revision-after']) {
      const details = wrapper.get(`[data-testid="${phase}"] details`)
      ;(details.element as HTMLDetailsElement).open = true
      await details.trigger('toggle')
    }
    expect(wrapper.get('[data-testid="revision-after"] [data-month="2030-02"]').text()).toContain('4')
    expect(wrapper.get('[data-testid="revision-after"] [data-month="2030-03"]').text()).toContain('6')
    expect(wrapper.get('[data-testid="revision-before"]').text()).toContain('2030-02')
    expect(m.revise).not.toHaveBeenCalled()
    await wrapper.get('[data-testid="revision-save"]').trigger('click')
    await flushPromises()
    expect(m.revise).toHaveBeenCalledWith(9, { ...payload, expected_calendar_start_month: 7, expected_calendar_fingerprint: 'synthetic-calendar-v1' })
    expect(wrapper.find('[data-testid="revision-save"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="asset-plan-revisions"]').text()).toContain('Synthetic reassessment')
    wrapper.unmount()
  })

  it.each([['revision-effective-from', '2030-03'], ['revision-remaining-months', '3'], ['revision-reason', 'Changed synthetic reason']])('invalidates the preview when %s changes', async (field, value) => {
    m.previewRevision.mockResolvedValue(revisionPreview())
    const wrapper = await detail()
    await wrapper.get('[data-action="revise_plan"]').trigger('click')
    await revisionForm(wrapper)
    await wrapper.get('[data-testid="revision-form"]').trigger('submit')
    await flushPromises()
    expect(wrapper.get('[data-testid="revision-save"]').attributes('disabled')).toBeUndefined()
    await wrapper.get(`[data-testid="${field}"]`).setValue(value)
    expect(wrapper.get('[data-testid="revision-save"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-testid="revision-after"]').exists()).toBe(false)
    expect(m.revise).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('ignores a preview response that arrives after its form was changed', async () => {
    let resolve!: (value: ReturnType<typeof revisionPreview>) => void
    m.previewRevision.mockReturnValue(new Promise(done => { resolve = done }))
    const wrapper = await detail()
    await wrapper.get('[data-action="revise_plan"]').trigger('click')
    await revisionForm(wrapper)
    await wrapper.get('[data-testid="revision-form"]').trigger('submit')
    expect(m.previewRevision).toHaveBeenCalledOnce()
    await wrapper.get('[data-testid="revision-reason"]').setValue('New synthetic reason')
    resolve(revisionPreview())
    await flushPromises()
    expect(wrapper.find('[data-testid="revision-after"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="revision-save"]').attributes('disabled')).toBeDefined()
    wrapper.unmount()
  })


  it('ignores async preview results after the dialog asset props change', async () => {
    let resolve!: (value: ReturnType<typeof revisionPreview>) => void
    m.previewRevision.mockReturnValue(new Promise(done => { resolve = done }))
    const wrapper = mount(AssetPlanRevisionModal, { props: { asset: asset() }, global: { stubs } })
    await wrapper.get('[data-testid="revision-effective-from"]').setValue('2030-02')
    await wrapper.get('[data-testid="revision-remaining-months"]').setValue('2')
    await wrapper.get('[data-testid="revision-reason"]').setValue('Synthetic reason')
    await wrapper.get('[data-testid="revision-form"]').trigger('submit')
    expect(m.previewRevision).toHaveBeenCalledOnce()
    await wrapper.setProps({ asset: asset({ id: 10 }) })
    resolve(revisionPreview())
    await flushPromises()
    expect(wrapper.find('[data-testid="revision-after"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="revision-save"]').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('accounting.assets.revision.stale')
    wrapper.unmount()
  })


  it('disables inputs and closing during save, then ignores results after an asset props change', async () => {
    m.previewRevision.mockResolvedValue(revisionPreview())
    let resolve!: (value: { asset: AssetDetail; warnings: [] }) => void
    m.revise.mockReturnValue(new Promise(done => { resolve = done }))
    const wrapper = mount(AssetPlanRevisionModal, { props: { asset: asset() }, global: { stubs } })
    await wrapper.get('[data-testid="revision-effective-from"]').setValue('2030-02')
    await wrapper.get('[data-testid="revision-remaining-months"]').setValue('2')
    await wrapper.get('[data-testid="revision-reason"]').setValue('Synthetic reason')
    await wrapper.get('[data-testid="revision-form"]').trigger('submit')
    await flushPromises()
    await wrapper.get('[data-testid="revision-save"]').trigger('click')
    for (const field of ['revision-effective-from', 'revision-remaining-months', 'revision-reason']) expect(wrapper.get(`[data-testid="${field}"]`).attributes('disabled')).toBeDefined()
    const cancel = wrapper.findAll('button').find(button => button.text().includes('common.cancel'))!
    expect(cancel.attributes('disabled')).toBeDefined()
    await wrapper.findComponent({ name: 'Modal' }).vm.$emit('close')
    expect(wrapper.emitted('close')).toBeUndefined()
    await wrapper.setProps({ asset: asset({ id: 10 }) })
    resolve({ asset: asset(), warnings: [] })
    await flushPromises()
    expect(wrapper.emitted('saved')).toBeUndefined()
    expect(m.success).not.toHaveBeenCalled()
    expect(wrapper.get('[data-testid="revision-save"]').attributes('disabled')).toBeDefined()
    wrapper.unmount()
  })

  it('clears a stale save preview and offers an explicit refresh rather than reusing its revision', async () => {
    m.previewRevision.mockResolvedValue(revisionPreview())
    m.revise.mockRejectedValue({ response: { status: 409, data: { error: { code: 'imported_plan_revision_stale' } } } })
    const wrapper = await detail()
    await wrapper.get('[data-action="revise_plan"]').trigger('click')
    await revisionForm(wrapper)
    await wrapper.get('[data-testid="revision-form"]').trigger('submit')
    await flushPromises()
    await wrapper.get('[data-testid="revision-save"]').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('accounting.assets.revision.stale')
    expect(wrapper.get('[data-testid="revision-save"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-testid="revision-after"]').exists()).toBe(false)
    await wrapper.get('[data-testid="revision-refresh"]').trigger('click')
    await flushPromises()
    expect(m.get).toHaveBeenCalledTimes(2)
    expect(m.revise).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })


  it.each([14, undefined])('uses server effective life rather than frozen original life after a revision (effective=%s)', async effective => {
    m.get.mockResolvedValue(asset({ acc_useful_life_months: 12, imported_depreciation_plan: { ...sourcePlan, revisions: [revisionRecord] } }))
    m.plan.mockResolvedValue({ ...plan, asset_summary: { ...plan.asset_summary, ...(effective === undefined ? {} : { effective_acc_useful_life_months: effective }) } })
    const wrapper = await detail()
    expect(wrapper.get('[data-testid="asset-effective-useful-life"]').text()).toContain(effective === undefined ? '—' : '14')
    expect(wrapper.get('[data-testid="asset-effective-useful-life"]').text()).not.toContain('12')
    const origin = wrapper.get('[data-testid="asset-plan-origin"]')
    ;(origin.element as HTMLDetailsElement).open = true
    await origin.trigger('toggle')
    expect(origin.text()).toContain('accounting.assets.revision.original_life:{"months":12}')
    wrapper.unmount()
  })

  it('uses the current revision count for a second preview, not the new revision number', async () => {
    m.get.mockResolvedValue(asset({ imported_depreciation_plan: { ...sourcePlan, revisions: [revisionRecord] } }))
    m.previewRevision.mockResolvedValue({ ...revisionPreview(), revision: 2 })
    const wrapper = await detail()
    await wrapper.get('[data-action="revise_plan"]').trigger('click')
    await revisionForm(wrapper)
    await wrapper.get('[data-testid="revision-form"]').trigger('submit')
    await flushPromises()
    expect(m.previewRevision).toHaveBeenCalledWith(9, expect.objectContaining({ expected_revision: 1 }))
    wrapper.unmount()
  })

  it('shows the append-only revision history and the original imported audit schedule', async () => {
    m.get.mockResolvedValue(asset({ imported_depreciation_plan: { ...sourcePlan, revisions: [revisionRecord] } }))
    const wrapper = await detail()
    const history = wrapper.get('[data-testid="asset-plan-revisions"]')
    expect(history.text()).toContain('2030-02')
    expect(history.text()).toContain('Synthetic reassessment')
    expect(history.text()).toContain('17')
    expect(history.text()).toContain('2030-01-10 12:30:00')
    const origin = wrapper.get('[data-testid="asset-plan-origin"]')
    ;(origin.element as HTMLDetailsElement).open = true
    await origin.trigger('toggle')
    expect(origin.text()).toContain('2030-01')
    expect(origin.text()).toContain('minor:1000')
    expect(wrapper.find('[data-action="improvement"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it.each(['double_entry', 'tax_evidence'])('explains the imported plan and locks plan fields in the %s editor while saving only metadata', async mode => {
    m.mode = mode
    const wrapper = await editor()
    expect(wrapper.get('[data-testid="asset-imported-plan"]').text()).toContain('Synthetic Ledger')
    expect(wrapper.get('[data-testid="asset-imported-plan"]').text()).toContain('SYN-TAX')
    expect(wrapper.findAll('select').every(control => control.attributes('disabled') !== undefined)).toBe(true)
    expect(wrapper.findAll('input[type="number"]').every(control => control.attributes('disabled') !== undefined)).toBe(true)
    expect(wrapper.findAll('[data-test="account-select"]').every(control => control.attributes('disabled') !== undefined)).toBe(true)
    expect(wrapper.findAll('[data-test="date-input"]').every(control => control.attributes('disabled') !== undefined)).toBe(true)
    const fields = wrapper.findAll('input[type="text"]')
    await fields[1]!.setValue('Renamed synthetic asset')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(m.update).toHaveBeenCalledWith(9, { inventory_number: 'SYNTHETIC-9', name: 'Renamed synthetic asset', description: null })
    expect(m.error).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('keeps the native editor unlocked when no imported plan exists', async () => {
    m.get.mockResolvedValue(asset({ imported_depreciation_plan: null, acc_useful_life_months: 12 }))
    const wrapper = await editor()
    expect(wrapper.find('[data-testid="asset-imported-plan"]').exists()).toBe(false)
    expect(wrapper.findAll('select').some(control => control.attributes('disabled') === undefined)).toBe(true)
    expect(wrapper.findAll('input[type="number"]').some(control => control.attributes('disabled') === undefined)).toBe(true)
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(m.update).toHaveBeenCalledWith(9, expect.objectContaining({ input_price: 20, tax_method: 'by_accounting', acc_useful_life_months: 12 }))
    wrapper.unmount()
  })

  it('marks imported future rows clearly and hides plan-changing operations while retaining disposal and edit', async () => {
    const wrapper = await detail()
    expect(wrapper.get('[data-testid="asset-imported-plan"]').text()).toContain('SYN-ACC')
    expect(wrapper.text()).toContain('accounting.assets.plan.status_imported')
    expect(wrapper.text()).not.toContain('accounting.assets.plan.status_confirmed')
    expect(wrapper.find('[data-action="improvement"]').exists()).toBe(false)
    expect(wrapper.find('[data-action="pause"]').exists()).toBe(false)
    expect(wrapper.find('[data-action="dispose"]').exists()).toBe(true)
    expect(wrapper.find('[data-action="edit"]').exists()).toBe(true)
    await wrapper.findAll('tr').find(row => row.text().includes('accounting.assets.plan.status_imported'))!.trigger('click')
    expect(wrapper.text()).toContain('2030-01')
    wrapper.unmount()
  })

  it.each([false, true])('hides TZ deletion, tax overrides and pause reversal for imported confirmed rows (paused=%s)', async paused => {
    m.get.mockResolvedValue(asset({ improvements: [{ id: 3, supplier_id: 1, asset_id: 9, completed_on: '2030-01-01', amount: 1, description: 'Synthetic improvement', purchase_invoice_id: null, created_at: '2030-01-01' }] }))
    m.plan.mockResolvedValue({ ...plan, tax: [{ ...plan.tax[0]!, source: 'confirmed', is_paused: paused, override_reason: 'Synthetic adjustment' }] })
    const wrapper = await detail()
    const improvement = wrapper.findAll('tr').find(row => row.text().includes('Synthetic improvement'))
    expect(improvement).toBeDefined()
    expect(improvement!.find('button').exists()).toBe(false)
    expect(wrapper.find('[aria-label="accounting.assets.override.action"]').exists()).toBe(false)
    expect(wrapper.find('[aria-label="accounting.assets.override.clear"]').exists()).toBe(false)
    expect(wrapper.find('[aria-label="accounting.assets.lifecycle.unpause"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('keeps native lifecycle and tax adjustment controls available', async () => {
    m.get.mockResolvedValue(asset({ imported_depreciation_plan: null, kind: 'tangible', tax_method: 'straight', tax_group: 1, acc_useful_life_months: 12 }))
    m.plan.mockResolvedValue({ ...plan, tax: [{ ...plan.tax[0]!, source: 'confirmed', override_reason: 'Synthetic adjustment' }] })
    const wrapper = await detail()
    expect(wrapper.find('[data-testid="asset-imported-plan"]').exists()).toBe(false)
    expect(wrapper.find('[data-action="improvement"]').exists()).toBe(true)
    expect(wrapper.find('[data-action="pause"]').exists()).toBe(true)
    expect(wrapper.find('[aria-label="accounting.assets.override.action"]').exists()).toBe(true)
    expect(wrapper.find('[aria-label="accounting.assets.override.clear"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('allows an imported draft to activate only on its original date without posting an acquisition', async () => {
    m.get.mockResolvedValue(asset({ status: 'draft' }))
    const wrapper = await detail()
    await wrapper.get('[data-action="put_into_use"]').trigger('click')
    const date = wrapper.get('[data-test="date-input"]')
    expect(date.attributes('disabled')).toBeDefined()
    expect((date.element as HTMLInputElement).value).toBe('2030-01-01')
    const checkbox = wrapper.findAll('input[type="checkbox"]').find(control => control.attributes('disabled') !== undefined)
    expect(checkbox).toBeDefined()
    expect((checkbox!.element as HTMLInputElement).checked).toBe(false)
    const confirm = wrapper.findAll('button').find(button => button.text().includes('common.confirm'))
    expect(confirm).toBeDefined()
    await confirm!.trigger('click')
    await flushPromises()
    expect(m.putIntoUse).toHaveBeenCalledWith(9, { date: '2030-01-01', book_entry: false })
    wrapper.unmount()
  })
})
