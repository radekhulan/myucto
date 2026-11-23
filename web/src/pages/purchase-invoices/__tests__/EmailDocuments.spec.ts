import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, shallowMount } from '@vue/test-utils'
import type { EmailIntakeAccount, EmailIntakeStatus } from '@/api/purchaseInvoiceSubmissions'

const m = vi.hoisted(() => ({
  emailStatus: vi.fn(),
  auth: { isCompanyAdminRole: true, canWrite: vi.fn((_permission: string) => true) },
  supplier: { currentSupplierId: 1 },
}))
vi.mock('@/api/purchaseInvoiceSubmissions', () => ({ purchaseInvoiceSubmissionsApi: { emailStatus: m.emailStatus } }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (e: Error) => e.message }))
vi.mock('@/composables/useFormat', () => ({ formatDateTime: (value: string) => value }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => m.auth }))
vi.mock('@/stores/supplier', async () => {
  const { reactive } = await import('vue')
  m.supplier = reactive(m.supplier)
  return { useSupplierStore: () => m.supplier }
})
vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) => params ? `${key} ${JSON.stringify(params)}` : key,
    tm: (key: string) => [`${key}.0`, `${key}.1`],
    rt: (value: string) => value,
  }),
}))

import EmailDocuments from '../EmailDocuments.vue'

function account(overrides: Partial<EmailIntakeAccount> = {}): EmailIntakeAccount {
  return {
    id: 1, name: 'Faktury', enabled: true, ingest_pdf_invoices: true, ingest_pdf_statements: false,
    distribute_to_suppliers: false, last_scan_at: '2093-01-02 10:00:00', last_scan_status: 'ok', last_scan_message: null,
    ...overrides,
  }
}

function status(overrides: Partial<EmailIntakeStatus> = {}): EmailIntakeStatus {
  return { accounts: [account()], active: true, days: 30, received: 4, waiting: 2, ...overrides }
}

async function mountPage(data: EmailIntakeStatus) {
  m.emailStatus.mockResolvedValueOnce(data)
  const wrapper = shallowMount(EmailDocuments, {
    global: { stubs: { RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' } } },
  })
  await flushPromises()
  return wrapper
}

describe('Doklady z e-mailu', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    m.auth.isCompanyAdminRole = true
    m.auth.canWrite.mockReturnValue(true)
  })

  it('ukáže přehled schránky, počty a odkaz na příchozí doklady filtrované na e-mail', async () => {
    const wrapper = await mountPage(status())
    expect(wrapper.get('[data-testid="email-state"]').text()).toBe('purchase_email.state_active')
    expect(wrapper.get('[data-testid="email-received"]').text()).toBe('4')
    expect(wrapper.get('[data-testid="email-waiting"]').text()).toBe('2')
    expect(wrapper.get('[data-testid="email-open-inbox"]').attributes('href')).toBe('/purchase-invoices/incoming?source=email')
    expect(wrapper.get('[data-testid="email-setup"]').attributes('href')).toBe('/bank?tab=email')
    expect(wrapper.find('[data-testid="email-empty"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="email-last-error"]').exists()).toBe(false)
  })

  it('vypíše poslední chybu načtení a upozorní na vypnuté načítání faktur', async () => {
    const wrapper = await mountPage(status({
      active: false,
      accounts: [account({ ingest_pdf_invoices: false, last_scan_status: 'error', last_scan_message: 'Přihlášení selhalo.' })],
    }))
    expect(wrapper.get('[data-testid="email-state"]').text()).toBe('purchase_email.state_invoices_off')
    expect(wrapper.get('[data-testid="email-last-error"]').text()).toContain('Přihlášení selhalo.')
    expect(wrapper.text()).toContain('purchase_email.invoices_off_hint')
  })

  it('bez nastavené schránky ukáže prázdný stav a návod', async () => {
    const wrapper = await mountPage(status({ accounts: [], active: false, received: 0, waiting: 0 }))
    expect(wrapper.get('[data-testid="email-empty"]').text()).toContain('purchase_email.empty_admin')
    expect(wrapper.find('[data-testid="email-open-inbox"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('purchase_email.steps.0')
  })

  it('uživatel bez práva na nastavení banky dostane jen vysvětlení bez tlačítka nastavení', async () => {
    m.auth.isCompanyAdminRole = false
    const wrapper = await mountPage(status({ accounts: [] }))
    expect(wrapper.find('[data-testid="email-setup"]').exists()).toBe(false)
    expect(wrapper.findAll('a').some(a => a.attributes('href') === '/bank?tab=email')).toBe(false)
    expect(wrapper.get('[data-testid="email-empty"]').text()).toContain('purchase_email.empty_other')
    expect(wrapper.find('[data-testid="email-no-setup"]').exists()).toBe(true)
  })

  it('admin bez zápisu importu banky tlačítko nastavení nevidí', async () => {
    m.auth.canWrite.mockImplementation(permission => permission !== 'bank.import')
    const wrapper = await mountPage(status())
    expect(wrapper.find('[data-testid="email-setup"]').exists()).toBe(false)
  })

  it('při chybě načtení nabídne opakování', async () => {
    m.emailStatus.mockRejectedValueOnce(new Error('Síť nedostupná'))
    const wrapper = shallowMount(EmailDocuments, { global: { stubs: { RouterLink: true } } })
    await flushPromises()
    expect(wrapper.text()).toContain('Síť nedostupná')
    m.emailStatus.mockResolvedValueOnce(status())
    await wrapper.get('[data-testid="email-status"] button').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-testid="email-received"]').text()).toBe('4')
  })
})
