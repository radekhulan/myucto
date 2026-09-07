import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { CashRegister } from '@/api/cash'

const m = vi.hoisted(() => ({
  routeParams: {} as Record<string, string>,
  listRegisters: vi.fn(),
  listRulePresets: vi.fn(),
  createDocument: vi.fn(),
  getDocument: vi.fn(),
  updateDocument: vi.fn(),
  postDocument: vi.fn(),
  searchUnpaid: vi.fn(),
  listAccounts: vi.fn(),
  listPostingRules: vi.fn(),
  taxList: vi.fn(),
  clientsList: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
  toastWarning: vi.fn(),
  push: vi.fn(),
}))

vi.mock('@/api/cash', () => ({
  cashApi: {
    listRegisters: m.listRegisters,
    listRulePresets: m.listRulePresets,
    createDocument: m.createDocument,
    getDocument: m.getDocument,
    updateDocument: m.updateDocument,
    postDocument: m.postDocument,
    searchUnpaid: m.searchUnpaid,
  },
  UNPAID_PAGE_SIZE: 20,
}))
vi.mock('@/api/accounting', () => ({
  accountingApi: { listAccounts: m.listAccounts, listPostingRules: m.listPostingRules },
}))
vi.mock('@/api/taxConstants', () => ({ taxConstantsApi: { list: m.taxList } }))
vi.mock('@/api/clients', () => ({ clientsApi: { list: m.clientsList } }))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError, warning: m.toastWarning }),
}))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (v: number) => String(v) }))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: { accounting_mode: 'double_entry' } }),
}))
vi.mock('@/components/ui/buttonStyles', () => ({
  ICONS: { check: 'M0 0', x: 'M0 0', plus: 'M0 0' },
  btnOutline: () => 'btn-outline',
  btnFilled: () => 'btn-filled',
  btnIconSm: () => 'btn-icon-sm',
  btnOutlineSm: () => 'btn-outline-sm',
  disabledTitle: (d: boolean, r?: string | null) => (d && r ? r : undefined),
  BTN_DISABLED_NOTE: 'note',
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string, p?: Record<string, unknown>) => (p ? `${key}:${JSON.stringify(p)}` : key) }),
}))
vi.mock('vue-router', () => ({
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
  useRoute: () => ({ query: {}, params: m.routeParams }),
  useRouter: () => ({ push: m.push }),
}))

import CashDocumentEditor from '@/pages/accounting/CashDocumentEditor.vue'

function register(overrides: Partial<CashRegister> = {}): CashRegister {
  return {
    id: 1, supplier_id: 1, name: 'Hlavní pokladna', currency_code: 'CZK',
    account_code: '211.100', account_id: 9, account_name: 'Pokladna',
    is_default: true, is_active: true, documents_count: 0,
    balance: 0, balance_date: '2093-01-01', created_at: '2093-01-01',
    ...overrides,
  } as CashRegister
}

function taxYear(year: number, khThreshold: number) {
  return { year, is_override: false, data: { vat_rate_standard: 21, vat_rate_reduced: 12, kh_item_threshold: khThreshold } }
}

async function mountEditor() {
  const wrapper = mount(CashDocumentEditor)
  await flushPromises()
  return wrapper
}

describe('CashDocumentEditor.vue', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    for (const key of Object.keys(m.routeParams)) delete m.routeParams[key]
    m.listRegisters.mockResolvedValue([register()])
    m.listRulePresets.mockResolvedValue([])
    m.listAccounts.mockResolvedValue([])
    m.listPostingRules.mockResolvedValue({})
    m.taxList.mockResolvedValue([taxYear(new Date().getFullYear(), 10000)])
    m.clientsList.mockResolvedValue({ data: [] })
    m.createDocument.mockResolvedValue({ id: 1, doc_number: 'PPD-1', journal_entry_id: 1, status: 'posted', warnings: [] })
    m.searchUnpaid.mockResolvedValue({ items: [], truncated: false })
  })

  // ── H-9: kurz valutového dokladu ──────────────────────────────────────────
  it('valutová pokladna nabízí pole pro kurz a pošle ho v payloadu', async () => {
    m.listRegisters.mockResolvedValue([register({ currency_code: 'EUR', account_code: '211.500' })])
    const wrapper = await mountEditor()

    const rate = wrapper.find('[id$="-cash-fx-rate"]')
    expect(rate.exists()).toBe(true)

    const vm = wrapper.vm as any
    vm.form.total_amount = 100
    vm.form.description = 'Prodej v hotovosti'
    await rate.setValue('25.5')
    await flushPromises()

    await vm.save()
    expect(m.createDocument).toHaveBeenCalledTimes(1)
    const payload = m.createDocument.mock.calls[0][0]
    expect(payload.amount_foreign).toBe(100)
    expect(payload.fx_rate).toBe(25.5)
  })

  it('prázdný kurz se neposílá — backend si vezme denní kurz ČNB', async () => {
    m.listRegisters.mockResolvedValue([register({ currency_code: 'EUR', account_code: '211.500' })])
    const wrapper = await mountEditor()
    const vm = wrapper.vm as any
    vm.form.total_amount = 100
    vm.form.description = 'Prodej v hotovosti'
    await vm.save()

    const payload = m.createDocument.mock.calls[0][0]
    expect(payload.amount_foreign).toBe(100)
    expect('fx_rate' in payload).toBe(false)
  })

  it('korunová pokladna pole pro kurz nemá', async () => {
    const wrapper = await mountEditor()
    expect(wrapper.find('[id$="-cash-fx-rate"]').exists()).toBe(false)
  })

  // ── M-13: práh KH z číselníku, ne natvrdo 10 000 ──────────────────────────
  it('práh nákupu se bere z tax_constants (15 000 → 12 000 Kč projde)', async () => {
    m.taxList.mockResolvedValue([taxYear(new Date().getFullYear(), 15000)])
    const wrapper = await mountEditor()
    const vm = wrapper.vm as any
    vm.form.purpose = 'purchase'
    vm.form.doc_type = 'out'
    vm.form.vat_mode = 'vat'
    vm.form.total_amount = 12000
    vm.form.description = 'Nákup materiálu'
    await flushPromises()

    expect(vm.purchaseOverLimit).toBe(false)
    await vm.save()
    expect(m.createDocument).toHaveBeenCalledTimes(1)
  })

  it('nad prahem z číselníku uložení blokuje', async () => {
    m.taxList.mockResolvedValue([taxYear(new Date().getFullYear(), 15000)])
    const wrapper = await mountEditor()
    const vm = wrapper.vm as any
    vm.form.purpose = 'purchase'
    vm.form.doc_type = 'out'
    vm.form.vat_mode = 'vat'
    vm.form.total_amount = 15000
    vm.form.description = 'Nákup materiálu'
    await flushPromises()

    expect(vm.purchaseOverLimit).toBe(true)
    await vm.save()
    expect(m.createDocument).not.toHaveBeenCalled()
  })

  // ── M-11/M-12: nesedící rozpad DPH neprojde tiše ──────────────────────────
  it('nesedící rozpad DPH blokuje uložení a řekne o kolik', async () => {
    const wrapper = await mountEditor()
    const vm = wrapper.vm as any
    vm.form.purpose = 'sale'
    vm.form.vat_mode = 'vat'
    vm.form.total_amount = 10000
    vm.form.description = 'Prodej'
    await flushPromises()

    // Ruční zásah do rozpadu: základ 100 proti celkové částce 10 000.
    const breakdown = wrapper.findComponent({ name: 'CashVatBreakdown' })
    const bvm = breakdown.vm as any
    bvm.rows[0].gross = 100
    bvm.rows[0].base = 100
    bvm.rows[0].vat = 0
    await flushPromises()

    expect(vm.vatMatches).toBe(false)
    expect(vm.canSubmit).toBe(false)
    expect(wrapper.text()).toContain('cash.form.vat_mismatch_hint')

    await vm.save()
    expect(m.createDocument).not.toHaveBeenCalled()
  })

  it('sedící rozpad DPH uložit nechá', async () => {
    const wrapper = await mountEditor()
    const vm = wrapper.vm as any
    vm.form.purpose = 'sale'
    vm.form.vat_mode = 'vat'
    vm.form.total_amount = 12100
    vm.form.description = 'Prodej'
    await flushPromises()

    expect(vm.vatMatches).toBe(true)
    await vm.save()
    expect(m.createDocument).toHaveBeenCalledTimes(1)
  })

  // ── M-16: koncept / PUT / post jsou z UI dosažitelné ──────────────────────
  it('„Uložit jako koncept" pošle post:false', async () => {
    m.createDocument.mockResolvedValue({ id: 7, doc_number: null, journal_entry_id: null, status: 'draft', warnings: [] })
    const wrapper = await mountEditor()
    const vm = wrapper.vm as any
    vm.form.total_amount = 100
    vm.form.description = 'Koncept'
    await vm.save(false)

    expect(m.createDocument.mock.calls[0][0].post).toBe(false)
  })

  it('výchozí vystavení pořád posílá post:true', async () => {
    const wrapper = await mountEditor()
    const vm = wrapper.vm as any
    vm.form.total_amount = 100
    vm.form.description = 'Prodej'
    await vm.save(true)

    expect(m.createDocument.mock.calls[0][0].post).toBe(true)
  })

  it('editace draftu načte doklad a uloží ho přes PUT bez zaúčtování', async () => {
    m.routeParams.id = '42'
    m.getDocument.mockResolvedValue({
      id: 42, register_id: 1, doc_type: 'out', purpose: 'other', doc_number: null,
      issue_date: '2093-03-01', tax_date: null, partner_name: 'Dodavatel', partner_ic: null, partner_dic: null,
      description: 'Poštovné', total_amount: 250, currency_code: 'CZK', vat_mode: 'none', vat_lines: [],
      invoice_id: null, purchase_invoice_id: null, rule_key: null, counter_account_code: '518',
      status: 'draft', journal_entry_id: null, reversal_entry_id: null, created_by: null, created_at: '2093-03-01',
    })
    m.updateDocument.mockResolvedValue({})
    const wrapper = await mountEditor()
    const vm = wrapper.vm as any

    expect(vm.form.description).toBe('Poštovné')
    // Reset-watchery nesmí při plnění formuláře vymazat protiúčet ani účel.
    expect(vm.form.purpose).toBe('other')
    expect(vm.form.counter_account_code).toBe('518')

    await vm.save(false)
    expect(m.updateDocument).toHaveBeenCalledWith(42, expect.objectContaining({ post: false }))
    expect(m.postDocument).not.toHaveBeenCalled()
    expect(m.createDocument).not.toHaveBeenCalled()
  })

  it('vystavení draftu z editace udělá PUT a pak /post', async () => {
    m.routeParams.id = '42'
    m.getDocument.mockResolvedValue({
      id: 42, register_id: 1, doc_type: 'in', purpose: 'sale', doc_number: null,
      issue_date: '2093-03-01', tax_date: null, partner_name: null, partner_ic: null, partner_dic: null,
      description: 'Prodej', total_amount: 500, currency_code: 'CZK', vat_mode: 'none', vat_lines: [],
      invoice_id: null, purchase_invoice_id: null, rule_key: null, counter_account_code: null,
      status: 'draft', journal_entry_id: null, reversal_entry_id: null, created_by: null, created_at: '2093-03-01',
    })
    m.updateDocument.mockResolvedValue({})
    m.postDocument.mockResolvedValue({ doc_number: 'PPD-2093-0001', journal_entry_id: 3, warnings: ['cash.warning.negative_balance'] })
    const wrapper = await mountEditor()
    const vm = wrapper.vm as any

    await vm.save(true)
    expect(m.updateDocument).toHaveBeenCalledTimes(1)
    expect(m.postDocument).toHaveBeenCalledWith(42)
    expect(m.toastWarning).toHaveBeenCalledWith('cash.warning.negative_balance')
  })

  // ── M1: valutový draft s víc sazbami DPH musí jít upravit ─────────────────
  it('rozpad valutového draftu se při načtení převede zpět do měny pokladny', async () => {
    m.listRegisters.mockResolvedValue([register({ currency_code: 'EUR', account_code: '211.500' })])
    m.routeParams.id = '42'
    // V DB je rozpad v CZK (kurz 25); doklad je na 200 EUR = 5 000 Kč.
    m.getDocument.mockResolvedValue({
      id: 42, register_id: 1, doc_type: 'in', purpose: 'sale', doc_number: null,
      issue_date: '2093-03-01', tax_date: '2093-03-01', partner_name: null, partner_ic: null, partner_dic: null,
      description: 'Prodej v EUR', total_amount: 5000, currency_code: 'EUR',
      fx_rate: 25, amount_foreign: 200, vat_mode: 'vat',
      vat_lines: [
        { vat_rate: 21, base_amount: 2066.12, vat_amount: 433.88 },
        { vat_rate: 12, base_amount: 2232.14, vat_amount: 267.86 },
      ],
      invoice_id: null, purchase_invoice_id: null, rule_key: null, counter_account_code: null,
      status: 'draft', journal_entry_id: null, reversal_entry_id: null, created_by: null, created_at: '2093-03-01',
    })
    m.updateDocument.mockResolvedValue({})
    const wrapper = await mountEditor()
    const vm = wrapper.vm as any

    // Součet rozpadu musí sedět na cizoměnovou částku, jinak `vatMatches` blokuje uložení.
    const sum = vm.vatLines.reduce((s: number, l: any) => s + l.base_amount + l.vat_amount, 0)
    expect(Math.round(sum * 100)).toBe(20000)
    expect(vm.form.total_amount).toBe(200)
    expect(vm.vatMatches).toBe(true)

    await vm.save(false)
    expect(vm.error).toBe('')
    expect(m.updateDocument).toHaveBeenCalledTimes(1)
  })

  // ── M7: poměrný odpočet a uznatelnost přežijí kolečko UI ──────────────────
  it('poměrný odpočet z draftu se uložením nezahodí na serverové defaulty', async () => {
    m.routeParams.id = '42'
    m.getDocument.mockResolvedValue({
      id: 42, register_id: 1, doc_type: 'out', purpose: 'purchase', doc_number: null,
      issue_date: '2093-03-01', tax_date: '2093-03-01', partner_name: 'Dodavatel', partner_ic: null, partner_dic: null,
      description: 'Nákup PHM', total_amount: 1210, currency_code: 'CZK', vat_mode: 'vat',
      vat_lines: [{
        vat_rate: 21, base_amount: 1000, vat_amount: 210,
        vat_deduction: 'proportional', vat_deduction_percent: 70, tax_treatment: 'non_deductible',
      }],
      invoice_id: null, purchase_invoice_id: null, rule_key: null, counter_account_code: null,
      status: 'draft', journal_entry_id: null, reversal_entry_id: null, created_by: null, created_at: '2093-03-01',
    })
    m.updateDocument.mockResolvedValue({})
    const wrapper = await mountEditor()
    const vm = wrapper.vm as any

    await vm.save(false)
    const payload = m.updateDocument.mock.calls[0][1]
    expect(payload.vat_lines[0]).toMatchObject({
      vat_deduction: 'proportional', vat_deduction_percent: 70, tax_treatment: 'non_deductible',
    })
  })

  it('vystavený doklad se přes editaci uložit nedá', async () => {
    m.routeParams.id = '42'
    m.getDocument.mockResolvedValue({
      id: 42, register_id: 1, doc_type: 'in', purpose: 'sale', doc_number: 'PPD-1',
      issue_date: '2093-03-01', tax_date: null, partner_name: null, partner_ic: null, partner_dic: null,
      description: 'Prodej', total_amount: 500, currency_code: 'CZK', vat_mode: 'none', vat_lines: [],
      invoice_id: null, purchase_invoice_id: null, rule_key: null, counter_account_code: null,
      status: 'posted', journal_entry_id: 9, reversal_entry_id: null, created_by: null, created_at: '2093-03-01',
    })
    const wrapper = await mountEditor()
    const vm = wrapper.vm as any

    expect(vm.error).toBe('cash.error.doc_not_draft')
    await vm.save(false)
    expect(m.updateDocument).not.toHaveBeenCalled()
  })

  // ── H-7/H-8: hlášky místo popisků a konkrétní důvod ze serveru ────────────
  it('prázdný popis hlásí větu, ne popisek sloupce', async () => {
    const wrapper = await mountEditor()
    const vm = wrapper.vm as any
    vm.form.total_amount = 100
    await vm.save()
    expect(vm.error).toBe('cash.validation.description')
    expect(vm.error).not.toBe('cash.col.description')
  })

  it('catch-all `validation` ukáže konkrétní hlášku backendu', async () => {
    m.createDocument.mockRejectedValue({
      response: { data: { error: { code: 'cash.error.validation', message: 'Vazba na fakturu je povolena jen u úhrady FV/PF.' } } },
    })
    const wrapper = await mountEditor()
    const vm = wrapper.vm as any
    vm.form.total_amount = 100
    vm.form.description = 'Prodej'
    await vm.save()
    expect(vm.error).toBe('Vazba na fakturu je povolena jen u úhrady FV/PF.')
  })
})

// ── #21: našeptávač partnera hledá na serveru a doplní IČO/DIČ ───────────────
describe('CashDocumentEditor — našeptávač partnera', () => {
  function client(id: number, company_name: string, ic: string | null, dic: string | null) {
    return { id, company_name, ic, dic }
  }
  function clientPage(data: unknown[]) {
    return { data, meta: { total: data.length, page: 1, per_page: 50, pages: 1 } }
  }

  beforeEach(() => {
    vi.clearAllMocks()
    vi.useFakeTimers()
    for (const key of Object.keys(m.routeParams)) delete m.routeParams[key]
    m.listRegisters.mockResolvedValue([register()])
    m.listRulePresets.mockResolvedValue([])
    m.listAccounts.mockResolvedValue([])
    m.listPostingRules.mockResolvedValue({})
    m.taxList.mockResolvedValue([taxYear(new Date().getFullYear(), 10000)])
    m.clientsList.mockResolvedValue(clientPage([]))
  })

  it('načítá klienty hledáním na serveru, ne pevným stropem 100', async () => {
    await mountEditor()

    expect(m.clientsList).toHaveBeenCalledTimes(1)
    const params = m.clientsList.mock.calls[0][0]
    expect(params.per_page).toBe(50)
    expect(params.role).toBe('customers')
    expect(params.q).toBeUndefined()
  })

  it('při psaní pošle dotaz na server s `q` (po debounce)', async () => {
    const wrapper = await mountEditor()
    m.clientsList.mockClear()

    const input = wrapper.find('input[list$="-cash-partners"]')
    await input.setValue('Zeta')

    expect(m.clientsList).not.toHaveBeenCalled()
    vi.advanceTimersByTime(300)
    await flushPromises()

    expect(m.clientsList).toHaveBeenCalledTimes(1)
    expect(m.clientsList.mock.calls[0][0].q).toBe('Zeta')
  })

  it('po výběru klienta předvyplní IČO a DIČ', async () => {
    m.clientsList.mockResolvedValue(clientPage([client(7, 'Zeta s.r.o.', '12345678', 'CZ12345678')]))
    const wrapper = await mountEditor()

    const input = wrapper.find('input[list$="-cash-partners"]')
    await input.setValue('Zeta s.r.o.')
    vi.advanceTimersByTime(300)
    await flushPromises()

    const vm = wrapper.vm as any
    expect(vm.form.partner_ic).toBe('12345678')
    expect(vm.form.partner_dic).toBe('CZ12345678')
  })

  it('ruční úpravu IČO nepřepisuje, dokud se nezmění shoda na jiného klienta', async () => {
    m.clientsList.mockResolvedValue(clientPage([client(7, 'Zeta s.r.o.', '12345678', 'CZ12345678')]))
    const wrapper = await mountEditor()
    const vm = wrapper.vm as any

    const input = wrapper.find('input[list$="-cash-partners"]')
    await input.setValue('Zeta s.r.o.')
    vi.advanceTimersByTime(300)
    await flushPromises()

    vm.form.partner_ic = '99999999'
    await input.setValue('Zeta s.r.o.')
    vi.advanceTimersByTime(300)
    await flushPromises()

    expect(vm.form.partner_ic).toBe('99999999')
  })

  it('selhání načtení klientů není tiché', async () => {
    m.clientsList.mockRejectedValue(new Error('boom'))
    await mountEditor()

    expect(m.toastError).toHaveBeenCalled()
  })
  // -- L-8: oriznuta nabidka neuhrazenych faktur --------------------------------
  it('oříznutá nabídka neuhrazených faktur se přizná', async () => {
    m.searchUnpaid.mockResolvedValue({
      items: [{ id: 5, kind: 'invoice', number: 'FV-1', partner_name: 'Klient', total: 100, paid: 0, remaining: 100, currency_code: 'CZK', issued_on: '2093-01-01' }],
      truncated: true,
    })
    const wrapper = await mountEditor()
    const vm = wrapper.vm as any
    vm.form.purpose = 'invoice_payment'
    await flushPromises()

    vm.onUnpaidSearch()
    vi.advanceTimersByTime(300)
    await flushPromises()

    expect(vm.unpaidTruncated).toBe(true)
    expect(wrapper.html()).toContain('cash.form.unpaid_truncated')
  })

  it('úplná nabídka se za oříznutou nevydává', async () => {
    m.searchUnpaid.mockResolvedValue({
      items: [{ id: 5, kind: 'invoice', number: 'FV-1', partner_name: 'Klient', total: 100, paid: 0, remaining: 100, currency_code: 'CZK', issued_on: '2093-01-01' }],
      truncated: false,
    })
    const wrapper = await mountEditor()
    const vm = wrapper.vm as any
    vm.form.purpose = 'invoice_payment'
    await flushPromises()

    vm.onUnpaidSearch()
    vi.advanceTimersByTime(300)
    await flushPromises()

    expect(vm.unpaidTruncated).toBe(false)
    expect(wrapper.html()).not.toContain('cash.form.unpaid_truncated')
  })
})
