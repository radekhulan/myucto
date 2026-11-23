import { describe, expect, it, vi, beforeEach } from 'vitest'
import { ref } from 'vue'
import { flushPromises, shallowMount } from '@vue/test-utils'

const phoneRef = ref(true)
const mocks = vi.hoisted(() => ({ canWrite: vi.fn((_perm: string) => true) }))
vi.mock('@/composables/useIsPhone', () => ({ useIsPhone: () => phoneRef, installPhoneViewport: () => {} }))
vi.mock('vue-i18n', async original => ({ ...await original<typeof import('vue-i18n')>(), useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('vue-router', async original => ({ ...await original<typeof import('vue-router')>(), useRoute: () => ({ params: { id: '1' } }), useRouter: () => ({}) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: mocks.canWrite, canRead: () => true }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({}) }))
vi.mock('@/composables/useWindowTitle', () => ({ useWindowTitle: vi.fn() }))
vi.mock('@/composables/useWindowRefresh', () => ({ useWindowRefresh: vi.fn() }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ info: vi.fn(), success: vi.fn(), error: vi.fn() }) }))
vi.mock('@/api/clients', () => ({
  TAX_NUMBER_LABELS: {},
  clientsApi: {
    get: async () => ({
      id: 7, language: 'cs', company_name: 'Syntetická firma', country_iso2: 'CZ', is_customer: true,
      main_email: 'fakturace@example.test', phone: '+420 600 000 000', bank_accounts: [],
      email_contacts: [{ id: 1, email: 'kontakt@example.test', is_active: true, usages: [] }],
    }),
  },
}))
vi.mock('@/api/invoices', () => ({ invoicesApi: { listGrouped: async () => ({ data: [], meta: { total: 0 } }) } }))
vi.mock('@/api/purchaseInvoices', () => ({ purchaseInvoicesApi: { listGrouped: async () => ({ data: [] }) } }))
vi.mock('@/api/recurring', () => ({ recurringApi: { list: async () => ({ data: [] }) } }))

import ClientDetail from '../ClientDetail.vue'

async function mountDetail() {
  const wrapper = shallowMount(ClientDetail, { global: { stubs: { RouterLink: { template: '<a v-bind="$attrs"><slot /></a>' } } } })
  await flushPromises()
  return wrapper
}

describe('ClientDetail — kontakty na telefonu', () => {
  beforeEach(() => {
    phoneRef.value = true
    mocks.canWrite.mockImplementation(() => true)
  })

  it('na telefonu ukáže Zavolat, E-mail a Novou fakturu', async () => {
    const wrapper = await mountDetail()
    const row = wrapper.find('[data-test="client-phone-actions"]')
    expect(row.exists()).toBe(true)
    expect(wrapper.find('[data-test="client-phone-call"]').attributes('href')).toBe('tel:+420600000000')
    expect(wrapper.find('[data-test="client-phone-email"]').attributes('href')).toBe('mailto:fakturace@example.test')
    expect(wrapper.find('[data-test="client-phone-invoice"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('bez práva zakládat faktury tlačítko Nová faktura chybí', async () => {
    mocks.canWrite.mockImplementation((perm: string) => perm !== 'invoices.create')
    const wrapper = await mountDetail()
    expect(wrapper.find('[data-test="client-phone-invoice"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="client-phone-call"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('mimo telefon řádek tlačítek není, e-mail a telefon jsou jen odkazy', async () => {
    phoneRef.value = false
    const wrapper = await mountDetail()
    expect(wrapper.find('[data-test="client-phone-actions"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="client-email-link"]').attributes('href')).toBe('mailto:fakturace@example.test')
    expect(wrapper.find('[data-test="client-phone-link"]').attributes('href')).toBe('tel:+420600000000')
    expect(wrapper.find('a[href="mailto:kontakt@example.test"]').exists()).toBe(true)
    wrapper.unmount()
  })
})
