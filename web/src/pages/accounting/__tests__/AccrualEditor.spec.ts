import { flushPromises, shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  create: vi.fn(),
  update: vi.fn(),
  get: vi.fn(),
  planPreview: vi.fn(),
  sourceCandidates: vi.fn(),
  push: vi.fn(),
  replace: vi.fn(),
  route: { params: {} as Record<string, string>, query: {} as Record<string, string> },
  toastError: vi.fn(),
  toastSuccess: vi.fn(),
}))

vi.mock('@/api/accruals', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/accruals')>()
  return { ...actual, accrualsApi: { create: m.create, update: m.update, get: m.get, planPreview: m.planPreview, sourceCandidates: m.sourceCandidates } }
})
vi.mock('@/api/accounting', () => ({
  accountingApi: {
    listAccounts: vi.fn().mockResolvedValue([
      { id: 1, account_code: '381', name: 'Náklady příštích období', is_active: true, is_synthetic: true },
      { id: 2, account_code: '384', name: 'Výnosy příštích období', is_active: true, is_synthetic: true },
      { id: 3, account_code: '548', name: 'Ostatní provozní náklady', is_active: true, is_synthetic: true },
    ]),
    listCostCenters: vi.fn().mockResolvedValue([]),
  },
}))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: m.toastError, success: m.toastSuccess }) }))
vi.mock('@/composables/useDemoMode', () => ({ useDemoMode: () => ({ blockDemoMutation: () => false }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number) => `${v} Kč` }))
vi.mock('vue-router', () => ({
  useRoute: () => m.route,
  useRouter: () => ({ push: m.push, replace: m.replace }),
  RouterLink: { template: '<a><slot /></a>' },
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

import AccrualEditor from '../AccrualEditor.vue'
import ChartAccountSelect from '@/components/accounting/ChartAccountSelect.vue'

const DateInputStub = { name: 'DateInput', props: ['modelValue'], emits: ['update:modelValue'], template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />' }

function mountEditor() {
  return shallowMount(AccrualEditor, { global: { stubs: { DateInput: DateInputStub } } })
}

describe('AccrualEditor', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.useFakeTimers({ shouldAdvanceTime: true })
    m.route = { params: {}, query: {} }
    m.planPreview.mockResolvedValue({ rows: [
      { kind: 'release', due_on: '2099-01-31', from_on: '2099-01-01', to_on: '2099-01-31', days: 31, amount_planned: 600 },
      { kind: 'release', due_on: '2099-02-28', from_on: '2099-02-01', to_on: '2099-02-28', days: 28, amount_planned: 600 },
    ] })
  })
  afterEach(() => { vi.useRealTimers() })

  it('předvyplní formulář z řádku bez položky, ukáže náhled plánu a založí položku s vazbou zápisu', async () => {
    m.route.query = { account: '384', amount: '1200', origin_entry_id: '77', origin_amount: '1200', from: '2099-01-01', to: '2099-02-28',
      source_type: 'invoice', source_id: '9', title: 'Syntetické předplatné' }
    m.create.mockResolvedValue({ id: 33 })
    const wrapper = mountEditor()
    await flushPromises()
    expect((wrapper.get('[data-test="kind"]').element as HTMLSelectElement).value).toBe('deferred_revenue')
    expect(wrapper.findAllComponents(ChartAccountSelect)[0].props('modelValue')).toBe('384')
    expect(wrapper.find('[data-test="prefill-source"]').exists()).toBe(true)
    expect(m.planPreview).toHaveBeenCalledWith({ method: 'monthly_days', basis_amount: 1200, period_from: '2099-01-01', period_to: '2099-02-28' })
    expect(wrapper.findAll('[data-test="plan-row"]')).toHaveLength(2)
    expect(wrapper.get('[data-test="plan-total"]').text()).toBe('1200 Kč')

    wrapper.findAllComponents(ChartAccountSelect)[1].vm.$emit('update:modelValue', '602')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(m.create).toHaveBeenCalledWith(expect.objectContaining({
      title: 'Syntetické předplatné', kind: 'deferred_revenue', accrual_account_code: '384', counter_account_code: '602',
      basis_amount: 1200, period_from: '2099-01-01', period_to: '2099-02-28', method: 'monthly_days', activate: true,
      source_type: 'invoice', source_id: 9, origin_entry_id: 77, origin_amount: 1200,
    }))
    expect(m.push).toHaveBeenCalledWith({ name: 'accounting-accrual-detail', params: { id: 33 } })
  })

  it('změna metody přepočte náhled plánu se zpožděním', async () => {
    m.route.query = { account: '381', amount: '1200', from: '2099-01-01', to: '2099-02-28' }
    const wrapper = mountEditor()
    await flushPromises()
    m.planPreview.mockClear()
    await wrapper.get('[data-test="method"]').setValue('monthly_equal')
    expect(m.planPreview).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(450)
    await flushPromises()
    expect(m.planPreview).toHaveBeenCalledWith(expect.objectContaining({ method: 'monthly_equal' }))
  })

  it('bez základu a období náhled nevolá a uložení odmítne', async () => {
    const wrapper = mountEditor()
    await flushPromises()
    expect(m.planPreview).not.toHaveBeenCalled()
    await wrapper.get('[data-test="title"]').setValue('Syntetická položka')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(m.create).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="error"]').text()).toBe('accruals.editor.validation_required')
  })

  it('úprava pošle row_version a neaktivuje znovu', async () => {
    m.route = { params: { id: '5' }, query: {} }
    m.get.mockResolvedValue({ id: 5, title: 'Syntetické pojištění', kind: 'prepaid_expense', accrual_account_code: '381',
      counter_account_code: '548', termination_account_code: null, basis_amount: 1200, period_from: '2099-01-01', period_to: '2099-02-28',
      method: 'monthly_days', partner_id: null, partner_name: null, contract_ref: null, cost_center: null, note: null,
      source_type: 'manual', source_id: null, status: 'active', row_version: 4, can: { edit: true } })
    m.update.mockResolvedValue({ id: 5 })
    const wrapper = mountEditor()
    await flushPromises()
    expect(wrapper.find('[data-test="activate"]').exists()).toBe(false)
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    const payload = m.update.mock.calls[0][1]
    expect(m.update.mock.calls[0][0]).toBe(5)
    expect(payload).toMatchObject({ row_version: 4, accrual_account_code: '381' })
    expect(payload).not.toHaveProperty('activate')
  })

  it('chyba načtení upravované položky neukáže prázdný formulář', async () => {
    m.route = { params: { id: '5' }, query: {} }
    m.get.mockRejectedValue({ response: { data: { error: { message: 'Výpadek' } } } })
    const wrapper = mountEditor()
    await flushPromises()
    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.get('[data-test="editor-load-error"]').text()).toContain('Výpadek')
  })

  it('předání z uzávěrky pošle řádek dokladu a příznak převzetí', async () => {
    m.route.query = { source_type: 'purchase_invoice', source_id: '12', source_item_id: '44', closing_handover: '1', account: '381',
      amount: '36600', from: '2099-07-01', to: '2100-06-30', title: 'Syntetické pojištění' }
    m.planPreview.mockResolvedValue({ rows: [{ kind: 'release', due_on: '2099-07-31', from_on: '2099-07-01', to_on: '2099-07-31', days: 31, amount_planned: 3100 }] })
    m.create.mockResolvedValue({ id: 9 })
    const wrapper = mountEditor()
    await flushPromises()
    wrapper.findAllComponents(ChartAccountSelect)[1].vm.$emit('update:modelValue', '548')
    await vi.advanceTimersByTimeAsync(500)
    await flushPromises()
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(m.create.mock.calls[0][0]).toMatchObject({ source_type: 'purchase_invoice', source_id: 12, source_item_id: 44, closing_handover: true })
  })

  it('u faktury s řádky s obdobím nabídne výběr řádku s návrhem podle shody částky a období', async () => {
    m.route.query = { source_type: 'purchase_invoice', source_id: '12', account: '381', amount: '12000', from: '2099-10-01', to: '2100-09-30', title: 'Syntetické pojištění' }
    m.sourceCandidates.mockResolvedValue({ source_type: 'purchase_invoice', source_id: 12, document_no: 'PF-1', document_date: null, partner_id: null, partner_name: null,
      title: null, accrual_lines: [], items: [], periods: [
        { item_id: 51, from: '2099-07-01', to: '2100-06-30', amount: 24400, description: 'Předplatné' },
        { item_id: 52, from: '2099-10-01', to: '2100-09-30', amount: 12000, description: 'Pojištění' },
      ] })
    m.planPreview.mockResolvedValue({ rows: [{ kind: 'release', due_on: '2099-10-31', from_on: '2099-10-01', to_on: '2099-10-31', days: 31, amount_planned: 1000 }] })
    m.create.mockResolvedValue({ id: 9 })
    const wrapper = mountEditor()
    await flushPromises()
    const select = wrapper.get('[data-test="source-line-select"]')
    expect((select.element as HTMLSelectElement).value).toBe('52')
    expect(wrapper.findAll('[data-test="source-line-select"] option')).toHaveLength(3)
    wrapper.findAllComponents(ChartAccountSelect)[1].vm.$emit('update:modelValue', '548')
    await select.setValue('0')
    await vi.advanceTimersByTimeAsync(500)
    await flushPromises()
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(m.create.mock.calls[0][0]).toMatchObject({ source_type: 'purchase_invoice', source_id: 12, source_item_id: 0 })
  })

  it('u změny základu upozorní, že plán sleduje zůstatek podle vazeb', async () => {
    m.route = { params: { id: '5' }, query: {} }
    m.get.mockResolvedValue({ id: 5, title: 'Syntetické pojištění', kind: 'prepaid_expense', accrual_account_code: '381',
      counter_account_code: '548', termination_account_code: null, basis_amount: 1200, period_from: '2099-01-01', period_to: '2099-02-28',
      method: 'monthly_days', partner_id: null, partner_name: null, contract_ref: null, cost_center: null, note: null,
      source_type: 'manual', source_id: null, status: 'active', row_version: 4, can: { edit: true } })
    const wrapper = mountEditor()
    await flushPromises()
    expect(wrapper.find('[data-test="basis-warning"]').exists()).toBe(false)
    await wrapper.get('[data-test="basis-amount"]').setValue('2400')
    expect(wrapper.find('[data-test="basis-warning"]').exists()).toBe(true)
  })
})
