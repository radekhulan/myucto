import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { reactive } from 'vue'

const m = vi.hoisted(() => ({
  scope: { currentSupplierId: 3 }, permission: { allowed: true },
  get: vi.fn(), update: vi.fn(), success: vi.fn(), error: vi.fn(), patch: vi.fn(),
}))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ get currentSupplierId() { return m.scope.currentSupplierId }, patchSupplier: m.patch }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => m.permission.allowed }) }))
vi.mock('@/workspace/supplierContext', () => ({ requestSupplierIdNumber: () => m.scope.currentSupplierId }))
vi.mock('@/api/settings', () => ({ settingsApi: { getClientInvoiceDefaults: m.get, updateClientInvoiceDefaults: m.update } }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: m.success, error: m.error }) }))
vi.mock('@/composables/useDemoMode', () => ({ useDemoMode: () => ({ blockDemoMutation: () => false }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
import ClientInvoiceDefaults from './ClientInvoiceDefaults.vue'

function defaults(id = 3) {
  return { id, default_currency: 'CZK', default_payment_due_days: 7, default_payment_due_unit: 'days', default_hourly_rate: 0,
    default_note_below_items_enabled: false, default_note_below_items_cs: null, default_note_below_items_en: null }
}

describe('delegované výchozí hodnoty dokladů', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.scope = reactive({ currentSupplierId: 3 })
    m.permission = reactive({ allowed: true })
    m.get.mockImplementation(async () => defaults(m.scope.currentSupplierId))
    m.update.mockImplementation(async (payload, id) => ({ ...defaults(id), ...payload }))
  })
  it('ukládá pouze povolená pole, nulu a přesnou sazbu', async () => {
    const wrapper = mount(ClientInvoiceDefaults)
    await flushPromises()
    await wrapper.get('[data-test="invoice-default-due-preset"]').setValue('custom')
    await wrapper.get('[data-test="invoice-default-due-days"]').setValue('0')
    expect(wrapper.get('[data-test="invoice-default-due-days"]').attributes('max')).toBe('4294967295')
    await wrapper.get('[data-test="invoice-default-hourly-rate"]').setValue('12.123456')
    await wrapper.get('[data-test="invoice-default-note-enabled"]').setValue(true)
    await wrapper.get('[data-test="invoice-default-note-cs"]').setValue('Poznámka')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(m.update).toHaveBeenCalledWith({ default_payment_due_days: 0, default_payment_due_unit: 'days',
      default_hourly_rate: 12.123456, default_note_below_items_enabled: true,
      default_note_below_items_cs: 'Poznámka', default_note_below_items_en: null }, 3)
    expect(m.patch).toHaveBeenCalledWith(3, expect.objectContaining({ default_hourly_rate: 12.123456, default_payment_due_days: 0 }))
    expect(m.success).toHaveBeenCalled()
    wrapper.unmount()
  })
  it('při přepnutí A → B → A načte A až po doběhnutí jeho zápisu', async () => {
    let finish!: () => void
    let saved = false
    m.get.mockImplementation(async () => ({ ...defaults(m.scope.currentSupplierId), default_payment_due_days: saved ? 14 : 7 }))
    m.update.mockImplementationOnce(() => new Promise(resolve => { finish = () => { saved = true; resolve(defaults()) } }))
    const wrapper = mount(ClientInvoiceDefaults)
    await flushPromises()
    await wrapper.get('[data-test="invoice-default-due-preset"]').setValue('14')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    m.scope.currentSupplierId = 4
    await flushPromises()
    m.scope.currentSupplierId = 3
    await flushPromises()
    finish()
    await flushPromises()
    expect((wrapper.get('[data-test="invoice-default-due-preset"]').element as HTMLSelectElement).value).toBe('14')
    expect(m.update).toHaveBeenCalledWith(expect.objectContaining({ default_payment_due_days: 14 }), 3)
    expect(m.success).not.toHaveBeenCalled()
    wrapper.unmount()
  })
  it('při ztrátě práva skryje formulář a neodešle změny', async () => {
    const wrapper = mount(ClientInvoiceDefaults)
    await flushPromises()
    m.permission.allowed = false
    await flushPromises()
    expect(wrapper.find('form').exists()).toBe(false)
    expect(m.update).not.toHaveBeenCalled()
    wrapper.unmount()
  })
  it('při chybě zobrazí hlášení a nechá změny k opravě', async () => {
    m.update.mockRejectedValueOnce(new Error('failed'))
    const wrapper = mount(ClientInvoiceDefaults)
    await flushPromises()
    await wrapper.get('[data-test="invoice-default-due-preset"]').setValue('month')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(m.error).toHaveBeenCalled()
    expect((wrapper.get('[data-test="invoice-default-due-preset"]').element as HTMLSelectElement).value).toBe('month')
    wrapper.unmount()
  })
  it('nově otevřená záložka počká na uložení zahájené před odmountováním', async () => {
    let finish!: () => void
    let saved = false
    m.get.mockImplementation(async () => ({ ...defaults(), default_payment_due_days: saved ? 14 : 7 }))
    m.update.mockImplementationOnce(() => new Promise(resolve => { finish = () => { saved = true; resolve(defaults()) } }))
    const first = mount(ClientInvoiceDefaults)
    await flushPromises()
    await first.get('[data-test="invoice-default-due-preset"]').setValue('14')
    await first.get('form').trigger('submit')
    await flushPromises()
    first.unmount()
    const second = mount(ClientInvoiceDefaults)
    await flushPromises()
    expect(m.get).toHaveBeenCalledTimes(1)
    finish()
    await flushPromises()
    expect(m.get).toHaveBeenCalledTimes(2)
    expect((second.get('[data-test="invoice-default-due-preset"]').element as HTMLSelectElement).value).toBe('14')
    second.unmount()
  })

  it('zachová a uloží existující splatnost 400 dní', async () => {
    m.get.mockImplementation(async () => ({ ...defaults(), default_payment_due_days: 400 }))
    const wrapper = mount(ClientInvoiceDefaults)
    await flushPromises()
    expect((wrapper.get('[data-test="invoice-default-due-days"]').element as HTMLInputElement).value).toBe('400')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(m.update).toHaveBeenCalledWith(expect.objectContaining({ default_payment_due_days: 400, default_payment_due_unit: 'days' }), 3)
    wrapper.unmount()
  })

  it('zobrazí a zachová vlastní splatnost dvou měsíců', async () => {
    m.get.mockImplementation(async () => ({ ...defaults(), default_payment_due_days: 2, default_payment_due_unit: 'month' }))
    const wrapper = mount(ClientInvoiceDefaults)
    await flushPromises()
    expect((wrapper.get('[data-test="invoice-default-due-days"]').element as HTMLInputElement).value).toBe('2')
    expect(wrapper.text()).toContain('settings.default_due_custom_months_suffix')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(m.update).toHaveBeenCalledWith(expect.objectContaining({ default_payment_due_days: 2, default_payment_due_unit: 'month' }), 3)
    wrapper.unmount()
  })

})
