import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { DimensionOverview } from '@/api/dimensions'

const m = vi.hoisted(() => ({
  overview: vi.fn(),
  group: vi.fn(),
  setEnabled: vi.fn(),
  createValue: vi.fn(),
  updateValue: vi.fn(),
  updateType: vi.fn(),
  deleteType: vi.fn(),
  deleteValue: vi.fn(),
  createDefaults: vi.fn(),
  responsibleCandidates: vi.fn(),
  accountMap: vi.fn(),
  accountCandidates: vi.fn(),
  saveAccountMap: vi.fn(),
  patchSupplier: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
  commercial: true,
  supplier: { currentSupplierId: 1, currentSupplier: { id: 1, dimensions_enabled: true } as { id: number; dimensions_enabled: boolean } },
}))

vi.mock('@/api/dimensions', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/dimensions')>()),
  dimensionsApi: {
    overview: m.overview,
    group: m.group,
    setEnabled: m.setEnabled,
    createValue: m.createValue,
    updateValue: m.updateValue,
    updateType: m.updateType,
    deleteType: m.deleteType,
    deleteValue: m.deleteValue,
    createDefaults: m.createDefaults,
    responsibleCandidates: m.responsibleCandidates,
    accountMap: m.accountMap,
    accountCandidates: m.accountCandidates,
    saveAccountMap: m.saveAccountMap,
  },
}))
vi.mock('@/api/logbook', () => ({ logbookApi: { listCars: vi.fn().mockResolvedValue([]) } }))
vi.mock('@/api/projects', () => ({ projectsApi: { list: vi.fn().mockResolvedValue({ data: [] }) } }))
vi.mock('@/api/accounting', () => ({ accountingApi: { listCostCenters: vi.fn().mockResolvedValue([]) } }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ ...m.supplier, patchSupplier: m.patchSupplier }) }))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canRead: () => true, canWrite: () => true, isCompanyAdminRole: true, get hasCommercialFeatures() { return m.commercial } }),
}))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: m.toastSuccess, error: m.toastError }) }))
const builtinKey = 'dimensions.system_values.activity.main'
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => (key === builtinKey ? 'Hlavní činnost' : key), te: (key: string) => key === builtinKey, locale: { value: 'cs' } }) }))
vi.mock('vue-router', () => ({
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

import Dimensions from '@/pages/company/Dimensions.vue'

function overview(): DimensionOverview {
  const value = { supplier_id: 1, supplier_group_id: null, level: 'company' as const, responsible_user_id: null, responsible_note: null,
    car_id: null, project_id: null, cost_center_id: null, note: null, sort_order: 0, is_active: true }
  return {
    enabled: true,
    group: null,
    types: [
      { id: 5, supplier_id: 1, supplier_group_id: null, level: 'company', code: 'stredisko', name: 'Středisko', kind: 'cost_center', is_active: true, show_on_documents: true, sort_order: 10 },
      { id: 6, supplier_id: null, supplier_group_id: 9, level: 'global', code: 'projekt', name: 'Projekt', kind: 'project', is_active: true, show_on_documents: true, sort_order: 20 },
    ],
    values: [
      { ...value, id: 11, type_id: 5, parent_id: null, code: 'VYROBA', name: 'Výroba' },
      { ...value, id: 12, type_id: 5, parent_id: 11, code: 'DILNA', name: 'Dílna' },
    ],
  }
}

describe('Dimensions.vue', () => {
  beforeEach(() => {
    for (const fn of [m.updateType, m.deleteType, m.deleteValue, m.overview, m.group, m.setEnabled, m.createValue, m.updateValue, m.createDefaults, m.patchSupplier, m.toastSuccess, m.toastError]) fn.mockReset()
    m.supplier.currentSupplier.dimensions_enabled = true
    m.commercial = true
    m.overview.mockResolvedValue(overview())
    m.group.mockResolvedValue({ group: null, candidates: [] })
    m.responsibleCandidates.mockResolvedValue([{ id: 3, name: 'Jana Syntetická' }])
    for (const fn of [m.accountMap, m.accountCandidates, m.saveAccountMap]) fn.mockReset()
    m.accountMap.mockResolvedValue([])
    m.accountCandidates.mockResolvedValue([])
  })

  it('protects builtin meaning but keeps responsibility editable', async () => {
    const data = overview()
    data.types[0] = { ...data.types[0], system_key: 'activity' }
    data.values = [{ ...data.values[0], code: 'main', name: 'Hlavni cinnost z DB', semantic_key: 'main' }]
    m.overview.mockResolvedValue(data)
    const wrapper = mount(Dimensions, { global: { stubs: { teleport: true } } })
    await flushPromises()
    expect(wrapper.get('[data-test="dimension-system-key"]').text()).toContain('dimensions.system_badge')
    expect(wrapper.get('[data-test="dimension-type-active"] button').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="dimension-type-delete"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-test="dimension-value-new"]').exists()).toBe(false)
    const row = wrapper.get('[data-test="dimension-value-main"]')
    expect(row.text()).toContain('dimensions.builtin_badge')
    expect(row.text()).toContain('Hlavní činnost')
    expect(row.text()).not.toContain('main')
    expect(row.text()).not.toContain('z DB')
    expect(row.get('[data-test="dimension-value-active"] button').attributes('disabled')).toBeDefined()
    expect(row.get('[data-test="dimension-value-delete"]').attributes('disabled')).toBeDefined()
    await row.get('button[title="common.edit"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-test="dimension-value-name"]').attributes('disabled')).toBeDefined()
    expect((wrapper.get('[data-test="dimension-value-name"]').element as HTMLInputElement).value).toBe('Hlavní činnost')
    expect(wrapper.find('[data-test="dimension-value-code"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="dimension-value-parent"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="dimension-value-responsible"]').attributes('disabled')).toBeUndefined()
    await wrapper.get('[data-test="dimension-value-responsible"]').setValue('3')
    await wrapper.get('[data-test="dimension-value-save"]').trigger('click')
    await flushPromises()
    expect(m.updateValue).toHaveBeenCalledWith(11, expect.objectContaining({ responsible_user_id: 3 }))
    expect(m.updateValue.mock.calls[0][1]).not.toHaveProperty('name')
    expect(m.updateValue.mock.calls[0][1]).not.toHaveProperty('parent_id')
    expect(m.updateValue.mock.calls[0][1]).not.toHaveProperty('is_active')
  })

  it('allows retirement of custom activity kinds without inventing defaults or used metadata', async () => {
    const data = overview()
    data.types[0] = { ...data.types[0], system_key: 'activity_kind' }
    data.values = [{ ...data.values[0], semantic_key: null }]
    m.overview.mockResolvedValue(data)
    const wrapper = mount(Dimensions)
    await flushPromises()
    expect(wrapper.find('[data-test="dimension-value-new"]').exists()).toBe(true)
    const row = wrapper.get('[data-test="dimension-value-VYROBA"]')
    expect(row.get('[data-test="dimension-value-active"] button').attributes('disabled')).toBeUndefined()
    await row.get('[data-test="dimension-value-active"] button').trigger('click')
    await flushPromises()
    expect(m.updateValue).toHaveBeenCalledWith(11, { is_active: false })
    expect(m.createValue).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('dimensions.system_assignment_hint')
  })

  it('does not infer system meaning from legacy codes without metadata', async () => {
    const data = overview()
    data.types[0] = { ...data.types[0], code: 'activity', system_key: null }
    data.values = [{ ...data.values[0], code: 'main', semantic_key: null }]
    m.overview.mockResolvedValue(data)
    const wrapper = mount(Dimensions)
    await flushPromises()
    expect(wrapper.find('[data-test="dimension-system-key"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="dimension-type-delete"]').attributes('disabled')).toBeUndefined()
    expect(wrapper.get('[data-test="dimension-value-main"] [data-test="dimension-value-delete"]').attributes('disabled')).toBeUndefined()
    expect(wrapper.find('[data-test="dimension-value-new"]').exists()).toBe(true)
  })

  it('explains backend protection on a custom historical edit and keeps the form open', async () => {
    m.updateValue.mockRejectedValue({ response: { status: 409, data: { error: { code: 'system_dimension_immutable' } } } })
    const wrapper = mount(Dimensions, { global: { stubs: { teleport: true } } })
    await flushPromises()
    await wrapper.get('[data-test="dimension-value-VYROBA"] button[title="common.edit"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="dimension-value-name"]').setValue('New meaning')
    await wrapper.get('[data-test="dimension-value-save"]').trigger('click')
    await flushPromises()
    expect(m.toastError).toHaveBeenCalledWith('dimensions.system_history_conflict')
    expect(wrapper.find('[data-test="dimension-value-form"]').exists()).toBe(true)
  })

  it('ukáže firemní typy a strom hodnot, podřízené se rozbalí', async () => {
    const wrapper = mount(Dimensions)
    await flushPromises()
    expect(wrapper.get('[data-test="dimension-types"]').text()).toContain('Středisko')
    expect(wrapper.get('[data-test="dimension-types"]').text()).not.toContain('Projekt')
    expect(wrapper.find('[data-test="dimension-value-VYROBA"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="dimension-value-DILNA"]').exists()).toBe(false)
    await wrapper.get('[data-test="dimension-value-VYROBA"] button[aria-expanded]').trigger('click')
    expect(wrapper.find('[data-test="dimension-value-DILNA"]').exists()).toBe(true)
  })

  it('globální záložka ukáže skupinu firem a globální typy', async () => {
    const wrapper = mount(Dimensions)
    await flushPromises()
    await wrapper.get('[data-test="dimensions-tab-global"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="dimensions-group"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="dimension-types"]').text()).toContain('Projekt')
  })

  it('založí podřízenou hodnotu pod vybraným rodičem', async () => {
    m.createValue.mockResolvedValue({ id: 13 })
    // Formulář hodnoty je v dialogu (Teleport do body) — ve stubu zůstane v komponentě.
    const wrapper = mount(Dimensions, { global: { stubs: { teleport: true } } })
    await flushPromises()
    const addChild = wrapper.get('[data-test="dimension-value-VYROBA"]').findAll('button').find(b => b.attributes('title') === 'dimensions.value_add_child')
    await addChild!.trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="dimension-value-name"]').setValue('Lakovna')
    await wrapper.get('[data-test="dimension-value-code"]').setValue('LAK')
    await wrapper.get('[data-test="dimension-value-save"]').trigger('click')
    await flushPromises()
    expect(m.createValue).toHaveBeenCalledWith(5, expect.objectContaining({ code: 'LAK', name: 'Lakovna', parent_id: 11 }))
    expect(m.overview).toHaveBeenCalledTimes(2)
  })

  it('u vypnutých dimenzí nabídne zapnutí a založí výchozí typy', async () => {
    m.supplier.currentSupplier.dimensions_enabled = false
    m.setEnabled.mockResolvedValue(overview())
    const wrapper = mount(Dimensions)
    await flushPromises()
    expect(m.overview).not.toHaveBeenCalled()
    const enable = wrapper.findAll('button').find(b => b.text().includes('dimensions.enable'))
    await enable!.trigger('click')
    await flushPromises()
    expect(m.setEnabled).toHaveBeenCalledWith(true, true)
    expect(m.patchSupplier).toHaveBeenCalledWith(1, { dimensions_enabled: true })
  })

  it('účtotvorná dimenze: hodnota ukáže a uloží mapu syntetika → analytika', async () => {
    const data = overview()
    data.types[0] = { ...data.types[0], drives_accounts: true, drives_accounts_mask: '5, 6' }
    m.overview.mockResolvedValue(data)
    m.accountMap.mockResolvedValue([{
      id: 1, dimension_type_id: 5, dimension_value_id: 11, synthetic_account_id: 100, synthetic_code: '518', synthetic_name: 'Služby',
      analytic_account_id: 101, analytic_code: '518.100', analytic_name: 'Služby FVE', valid_from: null, valid_to: null,
    }])
    m.accountCandidates.mockResolvedValue([{ id: 100, code: '518', name: 'Služby', analytics: [
      { id: 101, code: '518.100', name: 'Služby FVE' }, { id: 102, code: '518.200', name: 'Služby kancelář' },
    ] }])
    m.updateValue.mockResolvedValue({ id: 11 })
    m.saveAccountMap.mockResolvedValue([])
    const wrapper = mount(Dimensions, { global: { stubs: { teleport: true } } })
    await flushPromises()
    expect(wrapper.get('[data-test="dimension-value-VYROBA"]').text()).toContain('518 → 518.100')

    const edit = wrapper.get('[data-test="dimension-value-VYROBA"]').findAll('button').find(b => b.attributes('title') === 'common.edit')
    await edit!.trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="dimension-account-map-row-0"]').exists()).toBe(true)
    const dates = wrapper.get('[data-test="dimension-account-map-row-0"]').findAll('input[type="text"]')
    expect(dates).toHaveLength(2)
    for (const date of dates) expect(date.classes()).toContain('h-9')
    await wrapper.get('[data-test="dimension-account-map-add"]').trigger('click')
    const row = () => wrapper.get('[data-test="dimension-account-map-row-1"]')
    await row().findAll('select')[0].setValue('100')
    await flushPromises()
    await row().findAll('select')[1].setValue('102')
    await wrapper.get('[data-test="dimension-value-save"]').trigger('click')
    await flushPromises()
    expect(m.saveAccountMap).toHaveBeenCalledWith(11, [
      { synthetic_account_id: 100, analytic_account_id: 101, valid_from: null, valid_to: null },
      { synthetic_account_id: 100, analytic_account_id: 102, valid_from: null, valid_to: null },
    ])
  })

  it('bez účetní licence nenabídne zapnutí dimenzí', async () => {
    m.commercial = false
    m.supplier.currentSupplier.dimensions_enabled = false
    const wrapper = mount(Dimensions)
    await flushPromises()
    expect(wrapper.text()).toContain('dimensions.license_title')
    expect(m.setEnabled).not.toHaveBeenCalled()
  })
})
