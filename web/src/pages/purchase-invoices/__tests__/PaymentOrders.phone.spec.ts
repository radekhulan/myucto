import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import type { PaymentCandidate, PayerAccount } from '@/api/paymentOrders'

// Telefon: nastavení příkazu sbalené, sekundární akce karet v „…" a dole lišta
// „Vybráno N · Stáhnout příkaz" místo plovoucí BulkActionBar. Mimo telefon beze změny.

const m = vi.hoisted(() => ({
  list: vi.fn(), candidates: vi.fn(), create: vi.fn(), markOrdered: vi.fn(), download: vi.fn(),
}))
const phoneRef = ref(false)

vi.mock('@/composables/useIsPhone', () => ({ useIsPhone: () => phoneRef, installPhoneViewport: () => {} }))
vi.mock('@/api/paymentOrders', () => ({
  paymentOrdersApi: {
    list: m.list, candidates: m.candidates, create: m.create, markOrdered: m.markOrdered,
    downloadPaymentOrder: m.download,
  },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true, canRead: () => true }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() }) }))
vi.mock('vue-i18n', async importOriginal => ({ ...await importOriginal<typeof import('vue-i18n')>(), useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {} }),
  useRouter: () => ({ push: vi.fn() }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

import PaymentOrders from '../PaymentOrders.vue'

const czk = { id: 7, code: 'CZK', label: 'Synthetic account', account_number: '1000000005', bank_code: '0100', iban: null, bic: null, is_default: true, is_active: true } as unknown as PayerAccount
const candidate = (id: number): PaymentCandidate => ({
  id, vendor_id: 5, vendor_company_name: 'Syntetický dodavatel', vendor_dic: null, vendor_invoice_number: `FP-${id}`,
  varsymbol: String(id), document_kind: 'invoice', issue_date: '2099-01-01', due_date: '2099-01-15',
  currency: 'CZK', currency_symbol: 'Kč', amount_to_pay: 1000, total_with_vat: 1000,
  account_number: '1000000005', bank_code: '0100', iban: null, bic: null, variable_symbol: String(id),
  constant_symbol: null, payment_account_source: null, payment_ordered_at: null, payment_method: 'bank_transfer',
  payment_method_source: 'default', has_account: true, has_pdf: true, can_verify: true, account_verified: 'na',
  abo_eligible: true, sepa_eligible: false,
} as unknown as PaymentCandidate)
const empty = { data: [], meta: { total: 0, page: 1, pages: 1, per_page: 50 } }

const stubs = {
  WorkspaceTeleport: { template: '<div><slot /></div>' },
  BulkActionBar: { name: 'BulkActionBar', props: ['count'], template: '<div data-test="bulk-action-bar"><slot /></div>' },
  PdfFrame: true,
  JournalSourceDrawer: true,
  BankPaymentSubmission: true,
  DateInput: { props: ['modelValue'], template: '<input />' },
}

type Vm = { toggleSelected: (c: PaymentCandidate) => void; candidates: PaymentCandidate[] }

async function mountPage() {
  const wrapper = mount(PaymentOrders, { global: { stubs } })
  await flushPromises()
  return wrapper
}

const isShown = (el: { attributes: (n: string) => string | undefined }) => !(el.attributes('style') ?? '').includes('display: none')

beforeEach(() => {
  vi.clearAllMocks()
  phoneRef.value = false
  m.list.mockResolvedValue(empty)
  m.candidates.mockResolvedValue({ ...empty, data: [candidate(11), candidate(12)], meta: { ...empty.meta, total: 2 }, payer_accounts: [czk] })
  m.create.mockResolvedValue({ order_id: 5, view: { item_count: 1 }, skipped: [], clamped_date: false })
})

describe('Platební příkazy - telefon', () => {
  it('nastavení příkazu je na telefonu sbalené a rozbalí ho přepínač', async () => {
    phoneRef.value = true
    const wrapper = await mountPage()
    const fields = wrapper.get('[data-test="po-settings-fields"]')
    expect(isShown(fields)).toBe(false)
    await wrapper.get('[data-test="po-settings-toggle"]').trigger('click')
    expect(isShown(wrapper.get('[data-test="po-settings-fields"]'))).toBe(true)
  })

  it('karta má na telefonu Doklad inline a ostatní akce v nabídce „…"', async () => {
    phoneRef.value = true
    const wrapper = await mountPage()
    const actions = wrapper.findAll('[data-test="po-card-phone-actions"]')
    expect(actions).toHaveLength(2)
    const first = actions[0]!
    const pdf = first.findAll('button').find(b => b.text().includes('payment_order.view_pdf'))!
    expect(pdf.classes()).toEqual(expect.arrayContaining(['h-9', 'min-w-11', 'text-xs']))
    expect(first.text()).not.toContain('payment_order.detail')
    await first.get('[aria-haspopup="menu"]').trigger('click')
    await flushPromises()
    const menu = wrapper.get('[role="menu"]')
    expect(menu.text()).toContain('payment_order.detail')
    expect(menu.text()).toContain('payment_order.verify_account')
    expect(menu.text()).toContain('payment_order.qr_code')
  })

  it('po výběru ukáže lištu Vybráno N a Stáhnout příkaz stáhne ABO', async () => {
    phoneRef.value = true
    const wrapper = await mountPage()
    expect(wrapper.find('[data-test="po-phone-bar"]').exists()).toBe(false)
    const vm = wrapper.vm as unknown as Vm
    vm.toggleSelected(vm.candidates[0]!)
    await flushPromises()
    const bar = wrapper.get('[data-test="po-phone-bar"]')
    expect(bar.classes()).toContain('sticky')
    expect(bar.classes()).toContain('bottom-[calc(var(--app-footer-height,0px)+0.5rem)]')
    expect(wrapper.find('[data-test="bulk-action-bar"]').exists()).toBe(false)
    await bar.get('[data-test="po-phone-download"]').trigger('click')
    await flushPromises()
    expect(m.create).toHaveBeenCalledWith(expect.objectContaining({ invoice_ids: [11], payer_currency_id: 7 }))
    expect(m.download).toHaveBeenCalledWith(5, 'abo')
  })

  it('mimo telefon zůstává rozbalené nastavení, plovoucí lišta a původní akce karet', async () => {
    const wrapper = await mountPage()
    expect(wrapper.find('[data-test="po-settings-toggle"]').exists()).toBe(false)
    expect(isShown(wrapper.get('[data-test="po-settings-fields"]'))).toBe(true)
    expect(wrapper.find('[data-test="po-card-phone-actions"]').exists()).toBe(false)
    const vm = wrapper.vm as unknown as Vm
    vm.toggleSelected(vm.candidates[0]!)
    await flushPromises()
    expect(wrapper.find('[data-test="po-phone-bar"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="bulk-action-bar"]').exists()).toBe(true)
  })
})
