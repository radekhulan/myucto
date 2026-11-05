import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { reactive, ref, h, KeepAlive } from 'vue'

const m = vi.hoisted(() => ({
  getSupplier: vi.fn(),
  getClientBranding: vi.fn(),
  updateSupplier: vi.fn(),
  updateClientBranding: vi.fn(),
  emailPreviewHtml: vi.fn().mockResolvedValue('<p>preview</p>'),
  success: vi.fn(),
  error: vi.fn(),
  scope: { currentSupplierId: 3 },
  permissions: { branding: true },
}))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => m.scope }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => m.permissions.branding }) }))
vi.mock('@/workspace/supplierContext', () => ({ requestSupplierIdNumber: () => m.scope.currentSupplierId }))
vi.mock('@/api/settings', () => ({ settingsApi: m }))
vi.mock('@/composables/useToast', () => ({ useToast: () => m }))
vi.mock('@/composables/useDemoMode', () => ({ useDemoMode: () => ({ blockDemoMutation: () => false }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
import Branding from '../Branding.vue'

function supplier() {
  return {
    id: 3, company_name: 'Syntetická firma', display_name: null, email: null, phone: null, web: null, tagline: null,
    email_branding_enabled: false, email_accent_color: '#3B2D83', pdf_logo_show_name: false,
    invoice_pdf_monochrome: false, branding_profiles_enabled: false, logo_path: null, has_email_logo: false,
  }
}

async function mountBranding(clientScoped: boolean) {
  const wrapper = mount(Branding, {
    props: { clientScoped },
    global: { stubs: { BrandingProfilesSettings: true } },
  })
  await flushPromises()
  await new Promise(resolve => setTimeout(resolve, 0))
  return wrapper
}

describe('úsporná černobílá faktura v Brandingu', () => {
  beforeEach(() => { vi.clearAllMocks(); m.scope = reactive({ currentSupplierId: 3 }); m.permissions = reactive({ branding: true }) })

  it('jde zapnout i bez brandingu a interní role používá cílené oprávnění brandingu', async () => {
    m.getClientBranding.mockResolvedValue(supplier())
    m.updateClientBranding.mockImplementation(async (payload: object) => ({ ...supplier(), ...payload }))
    const wrapper = await mountBranding(false)

    const toggle = wrapper.get('[data-testid="branding-invoice-pdf-monochrome"]')
    expect((toggle.element as HTMLInputElement).checked).toBe(false)
    expect(toggle.attributes('disabled')).toBeUndefined()
    await toggle.setValue(true)
    await flushPromises()

    expect(m.updateClientBranding).toHaveBeenCalledWith(expect.objectContaining({ invoice_pdf_monochrome: true }), 3)
    expect(m.getSupplier).not.toHaveBeenCalled()
    expect(m.updateSupplier).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('klientská role ji ukládá přes brandingový výřez', async () => {
    m.getClientBranding.mockResolvedValue(supplier())
    m.updateClientBranding.mockImplementation(async (payload: object) => ({ ...supplier(), ...payload }))
    const wrapper = await mountBranding(true)

    await wrapper.get('[data-testid="branding-invoice-pdf-monochrome"]').setValue(true)
    await flushPromises()

    expect(m.updateClientBranding).toHaveBeenCalledWith(expect.objectContaining({ invoice_pdf_monochrome: true }), 3)
    wrapper.unmount()
  })
  it('kompaktní položky ukládá úzkým endpointem a nesmaže pozdější volbu starou odpovědí', async () => {
    m.getClientBranding.mockResolvedValue(supplier())
    let finish!: (value: object) => void
    m.updateClientBranding.mockImplementationOnce(() => new Promise(resolve => { finish = resolve }))
      .mockImplementation(async (payload: object) => ({ ...supplier(), ...payload }))
    const wrapper = await mountBranding(true)
    const toggle = wrapper.get('[data-test="invoice-pdf-compact-items"]')
    await toggle.setValue(true)
    await flushPromises()
    await toggle.setValue(false)
    finish({ ...supplier(), invoice_pdf_compact_items: true })
    await flushPromises()
    expect(m.updateClientBranding.mock.calls.map(call => call[0])).toEqual([
      { invoice_pdf_compact_items: true }, { invoice_pdf_compact_items: false },
    ])
    expect((toggle.element as HTMLInputElement).checked).toBe(false)
    wrapper.unmount()
  })

  it('po přepnutí firmy nezapíše čekající změnu původní firmy do nové', async () => {
    m.getClientBranding.mockImplementation(async () => ({ ...supplier(), id: m.scope.currentSupplierId }))
    let finish!: (value: object) => void
    m.updateClientBranding.mockImplementationOnce(() => new Promise(resolve => { finish = resolve }))
    const wrapper = await mountBranding(true)
    await wrapper.get('[data-test="invoice-pdf-compact-items"]').setValue(true)
    await flushPromises()
    await wrapper.get('[data-test="invoice-pdf-bank-in-footer"]').setValue(true)
    m.scope.currentSupplierId = 4
    await flushPromises()
    finish(supplier())
    await flushPromises()
    expect(m.updateClientBranding).toHaveBeenCalledTimes(1)
    expect(m.updateClientBranding).toHaveBeenCalledWith({ invoice_pdf_compact_items: true }, 3)
    expect((wrapper.get('[data-test="invoice-pdf-bank-in-footer"]').element as HTMLInputElement).checked).toBe(false)
    wrapper.unmount()
  })

  it('po odebrání práva skryje volby a neprovede čekající změnu', async () => {
    m.getClientBranding.mockResolvedValue(supplier())
    let finish!: (value: object) => void
    m.updateClientBranding.mockImplementationOnce(() => new Promise(resolve => { finish = resolve }))
    const wrapper = await mountBranding(true)
    await wrapper.get('[data-test="invoice-pdf-compact-items"]').setValue(true)
    await flushPromises()
    await wrapper.get('[data-test="invoice-pdf-bank-in-footer"]').setValue(true)
    m.permissions.branding = false
    await flushPromises()
    finish(supplier())
    await flushPromises()
    expect(wrapper.find('[data-test="invoice-pdf-compact-items"]').exists()).toBe(false)
    expect(m.updateClientBranding).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('při chybě vrátí volbu na uložený stav a zobrazí chybu', async () => {
    m.getClientBranding.mockImplementation(async () => supplier())
    m.updateClientBranding.mockRejectedValueOnce(new Error('failed'))
    const wrapper = await mountBranding(true)
    await wrapper.get('[data-test="invoice-pdf-compact-items"]').setValue(true)
    await flushPromises()
    expect(m.error).toHaveBeenCalled()
    expect((wrapper.get('[data-test="invoice-pdf-compact-items"]').element as HTMLInputElement).checked).toBe(false)
    wrapper.unmount()
  })

  it('po návratu do KeepAlive načte stav až po dokončení probíhajícího uložení', async () => {
    let saved = false
    let finish!: () => void
    m.getClientBranding.mockImplementation(async () => ({ ...supplier(), invoice_pdf_compact_items: saved }))
    m.updateClientBranding.mockImplementationOnce(() => new Promise(resolve => {
      finish = () => { saved = true; resolve({ ...supplier(), invoice_pdf_compact_items: true }) }
    }))
    const visible = ref(true)
    const wrapper = mount({ setup: () => () => h(KeepAlive, null, () => visible.value ? h(Branding, { clientScoped: true }) : null) }, {
      global: { stubs: { BrandingProfilesSettings: true } },
    })
    await flushPromises()
    await wrapper.get('[data-test="invoice-pdf-compact-items"]').setValue(true)
    await flushPromises()
    visible.value = false
    await flushPromises()
    visible.value = true
    await flushPromises()
    expect(m.getClientBranding).toHaveBeenCalledTimes(1)
    finish()
    await flushPromises()
    expect(m.getClientBranding).toHaveBeenCalledTimes(2)
    expect((wrapper.get('[data-test="invoice-pdf-compact-items"]').element as HTMLInputElement).checked).toBe(true)
    wrapper.unmount()
  })

  it('po přepnutí A → B → A načte A až po dokončení jeho zápisu', async () => {
    let saved = false
    let finish!: () => void
    m.getClientBranding.mockImplementation(async () => ({ ...supplier(), id: m.scope.currentSupplierId, invoice_pdf_compact_items: saved }))
    m.updateClientBranding.mockImplementationOnce(() => new Promise(resolve => {
      finish = () => { saved = true; resolve({ ...supplier(), invoice_pdf_compact_items: true }) }
    }))
    const wrapper = await mountBranding(true)
    await wrapper.get('[data-test="invoice-pdf-compact-items"]').setValue(true)
    await flushPromises()
    m.scope.currentSupplierId = 4
    await flushPromises()
    m.scope.currentSupplierId = 3
    await flushPromises()
    expect(m.getClientBranding).toHaveBeenCalledTimes(1)
    finish()
    await flushPromises()
    expect(m.getClientBranding).toHaveBeenCalledTimes(2)
    expect((wrapper.get('[data-test="invoice-pdf-compact-items"]').element as HTMLInputElement).checked).toBe(true)
    wrapper.unmount()
  })

})
