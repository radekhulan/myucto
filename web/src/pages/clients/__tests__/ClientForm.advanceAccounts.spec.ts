import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

/**
 * Účty poskytnutých záloh na kartě dodavatele (314.610 / 314.611): pole jsou jen
 * v podvojném účetnictví pro roli s právem zápisu do účetnictví. Jinak se do API
 * vůbec neposílají, takže uložení karty účty nezmění.
 */
const m = vi.hoisted(() => ({
  create: vi.fn(async (payload: Record<string, unknown>) => ({ id: 9, ...payload })),
  canWrite: vi.fn((permission: string) => permission === 'accounting'),
  mode: { value: 'double_entry' },
}))

vi.mock('@/api/clients', () => ({
  clientsApi: {
    create: m.create,
    update: vi.fn(),
    get: vi.fn(),
    findDuplicates: vi.fn(async () => []),
    lookupVies: vi.fn(async () => ({ source: 'error' })),
    lookupBank: vi.fn(async () => ({ source: 'error' })),
    lookupAres: vi.fn(async () => ({})),
  },
  TAX_NUMBER_LABELS: {},
}))
vi.mock('@/api/accounting', () => ({
  accountingApi: {
    listAccounts: vi.fn(async () => [
      { id: 1, account_code: '314', name: 'Poskytnuté zálohy', is_active: true, parent_id: null },
      { id: 2, account_code: '314.610', name: 'Zálohy dodavatel A', is_active: true, parent_id: 1 },
      { id: 3, account_code: '314.611', name: 'Zálohy dodavatel A, DPH', is_active: true, parent_id: 1 },
      { id: 4, account_code: '321', name: 'Dodavatelé', is_active: true, parent_id: null },
    ]),
  },
}))
vi.mock('@/api/invoices', () => ({ PAYMENT_METHODS: [] }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (d: string | null | undefined) => d ?? '' }))
vi.mock('@/api/codebooks', () => ({
  codebooksApi: {
    countries: vi.fn(async () => [{ iso2: 'CZ', name_cs: 'Česko', name_en: 'Czechia' }]),
    currencies: vi.fn(async () => [{ id: 1, code: 'CZK', is_default: true }]),
  },
}))
vi.mock('@/api/expenseCategories', () => ({ expenseCategoriesApi: { list: vi.fn(async () => []) } }))
vi.mock('@/api/revenueCategories', () => ({ revenueCategoriesApi: { list: vi.fn(async () => []) } }))
vi.mock('@/api/settings', () => ({ settingsApi: { listAvailableBrandingProfiles: vi.fn(async () => []) } }))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ error: vi.fn(), warning: vi.fn(), success: vi.fn() }),
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ hasCommercialFeatures: true, canRead: () => true, canWrite: m.canWrite }),
}))
vi.mock('@/composables/useDemoMode', () => ({
  useDemoMode: () => ({ blockDemoMutation: () => false }),
}))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: { accounting_mode: m.mode.value, country_iso2: 'CZ' } }),
}))
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: {}, query: {} }),
  useRouter: () => ({ push: vi.fn() }),
  RouterLink: { template: '<a><slot /></a>' },
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key, locale: { value: 'cs' } }),
}))

import ClientForm from '../ClientForm.vue'

const vendor = { company_name: 'Dodavatel s.r.o.', street: 'Ulice 1', city: 'Praha', zip: '11000', is_vendor: true, is_customer: false }

async function mountVendor() {
  const wrapper = mount(ClientForm, { props: { embedded: true, defaults: vendor } })
  await flushPromises()
  return wrapper
}

describe('ClientForm — účty poskytnutých záloh dodavatele', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.mode.value = 'double_entry'
    m.canWrite.mockImplementation((permission: string) => permission === 'accounting')
    Element.prototype.scrollIntoView = vi.fn()
  })

  it('pošle zadané účty záloh do API', async () => {
    const wrapper = await mountVendor()
    const section = wrapper.find('[data-test="client-advance-accounts"]')
    expect(section.exists()).toBe(true)

    await wrapper.find('#client-advance-paid-account').setValue('314.610')
    await wrapper.find('#client-advance-paid-vat-account').setValue(' 314.611 ')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(m.create).toHaveBeenCalledTimes(1)
    expect(m.create.mock.calls[0][0]).toMatchObject({
      advance_paid_account_code: '314.610',
      advance_paid_vat_account_code: '314.611',
    })
  })

  it('prázdné pole pošle jako null (předkontace firmy)', async () => {
    const wrapper = await mountVendor()
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(m.create.mock.calls[0][0]).toMatchObject({ advance_paid_account_code: null, advance_paid_vat_account_code: null })
  })

  it('bez práva zápisu do účetnictví pole nezobrazí a účty neposílá', async () => {
    m.canWrite.mockImplementation(() => false)
    const wrapper = await mountVendor()
    expect(wrapper.find('[data-test="client-advance-accounts"]').exists()).toBe(false)

    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(m.create.mock.calls[0][0]).not.toHaveProperty('advance_paid_account_code')
    expect(m.create.mock.calls[0][0]).not.toHaveProperty('advance_paid_vat_account_code')
  })

  it('v daňové evidenci pole nezobrazí', async () => {
    m.mode.value = 'tax_evidence'
    const wrapper = await mountVendor()
    expect(wrapper.find('[data-test="client-advance-accounts"]').exists()).toBe(false)
  })

  it('ukáže chybu účtu u pole a sekci otevře', async () => {
    m.create.mockImplementationOnce(() => Promise.reject({
      response: { data: { error: { code: 'invalid_account', message: 'Neplatný účet', fields: {
        advance_paid_vat_account_code: ['Účet 321 není účet poskytnutých záloh.'],
      } } } },
    }))
    const wrapper = await mountVendor()
    await wrapper.find('#client-advance-paid-vat-account').setValue('321')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[data-test="client-advance-accounts"]').attributes('open')).toBeDefined()
    expect(wrapper.text()).toContain('Účet 321 není účet poskytnutých záloh.')
  })
})
