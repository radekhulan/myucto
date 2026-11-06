import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'

const mocks = vi.hoisted(() => ({
  preview: vi.fn(),
  create: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
  warning: vi.fn(),
}))
vi.mock('@/composables/useSupplierSwitch', () => ({ useSupplierSwitch: () => ({ switchTo: vi.fn() }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplierId: 1, availableSuppliers: [] }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({
  isSuperadmin: false, canRead: () => false, refresh: vi.fn(), user: { totp_enabled: false, mfa_methods: [] },
}) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: mocks.success, error: mocks.error, warning: mocks.warning }) }))
vi.mock('@/security/webauthn', () => ({ isWebAuthnAvailable: () => false, getCredential: vi.fn() }))
vi.mock('@/api/auth', () => ({ authApi: { passkeyStepUpOptions: vi.fn(), passkeyStepUpVerify: vi.fn() } }))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ t: (key: string) => key }),
}))
vi.mock('@/api/suppliers', () => ({ suppliersApi: {
  directory: async () => [],
  carryOverPreview: mocks.preview,
  create: mocks.create,
} }))

import SuppliersPage from '../SuppliersPage.vue'

const fullPreview = {
  ai: { available: true, reason: null, provider: 'anthropic', companies_count: 2, companies: ['Firma A', 'Firma B'] },
  certificates: [{ id: 7, label: 'Syntetický podpis', subject: 'Synthetic Signer', valid_to: '2099-01-01 00:00:00', companies_count: 2 }],
}

async function openModal(): Promise<VueWrapper> {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/admin/suppliers', component: { render: () => null } }] })
  await router.push('/admin/suppliers')
  const wrapper = mount(SuppliersPage, { global: { plugins: [router], stubs: { SupplierUrgency: true, EmptyState: true } } })
  await flushPromises()
  await wrapper.findAll('button').find(button => button.text() === 'supplier.new')!.trigger('click')
  await flushPromises()
  const required = wrapper.findAll('input[required]')
  const values = ['Nová firma', 'Testovací 1', '11111', 'Zkušební Lhota', 'nova@example.test']
  for (const [i, value] of values.entries()) await required[i]!.setValue(value)
  return wrapper
}

describe('převzetí ze stávajících firem při zakládání firmy', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mocks.create.mockResolvedValue({ id: 99, carry_over_result: { ai: { ok: true, provider: 'anthropic' }, certificates: [{ id: 7, ok: true }] } })
  })

  it('nabídne AI i certifikát zaškrtnuté a bez ověření certifikát neodešle', async () => {
    mocks.preview.mockResolvedValue(fullPreview)
    const wrapper = await openModal()

    expect(wrapper.find('[data-testid="supplier-carry-over"]').exists()).toBe(true)
    expect((wrapper.find('[data-testid="carry-over-ai"]').element as HTMLInputElement).checked).toBe(true)
    expect((wrapper.find('[data-testid="carry-over-certificate-7"]').element as HTMLInputElement).checked).toBe(true)
    expect(wrapper.find('[data-testid="carry-over-step-up"]').exists()).toBe(true)

    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(mocks.create).not.toHaveBeenCalled()
    expect(mocks.error).toHaveBeenCalledWith('settings.certificate_vault_step_up_password_missing')
    wrapper.unmount()
  })

  it('odešle převzetí s ověřením a ohlásí výsledek', async () => {
    mocks.preview.mockResolvedValue(fullPreview)
    const wrapper = await openModal()
    await wrapper.find('[data-testid="carry-over-step-password"]').setValue('heslo')

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(mocks.create).toHaveBeenCalledWith(expect.objectContaining({
      carry_over: { ai: true, certificate_ids: [7], step_up: { password: 'heslo', totp_code: undefined, step_up_token: undefined } },
    }))
    expect(mocks.success).toHaveBeenCalledWith('supplier.carry_over.done')
    expect(mocks.warning).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('odškrtnutý certifikát nechce ověření a nepovedené AI ohlásí', async () => {
    mocks.preview.mockResolvedValue(fullPreview)
    mocks.create.mockResolvedValue({ id: 99, carry_over_result: { ai: { ok: false, error: 'different' }, certificates: [] } })
    const wrapper = await openModal()
    await wrapper.find('[data-testid="carry-over-certificate-7"]').setValue(false)
    expect(wrapper.find('[data-testid="carry-over-step-up"]').exists()).toBe(false)

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(mocks.create).toHaveBeenCalledWith(expect.objectContaining({
      carry_over: { ai: true, certificate_ids: [], step_up: undefined },
    }))
    expect(mocks.warning).toHaveBeenCalledWith('supplier.carry_over.failed_ai')
    wrapper.unmount()
  })

  it('bez nabídky zakládá firmu beze změny', async () => {
    mocks.preview.mockResolvedValue({
      ai: { available: false, reason: 'not_available', provider: null, companies_count: 0, companies: [] },
      certificates: [],
    })
    mocks.create.mockResolvedValue({ id: 99 })
    const wrapper = await openModal()

    expect(wrapper.find('[data-testid="supplier-carry-over"]').exists()).toBe(false)
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(mocks.create).toHaveBeenCalledTimes(1)
    expect(mocks.create.mock.calls[0]![0]).not.toHaveProperty('carry_over')
    wrapper.unmount()
  })

  it('nedostupný náhled zakládání neblokuje', async () => {
    mocks.preview.mockRejectedValue(new Error('403'))
    mocks.create.mockResolvedValue({ id: 99 })
    const wrapper = await openModal()

    expect(wrapper.find('[data-testid="supplier-carry-over"]').exists()).toBe(false)
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(mocks.create.mock.calls[0]![0]).not.toHaveProperty('carry_over')
    wrapper.unmount()
  })
})
