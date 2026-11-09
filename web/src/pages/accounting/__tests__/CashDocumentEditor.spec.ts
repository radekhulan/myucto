import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { CashRegister } from '@/api/cash'

const m = vi.hoisted(() => ({
  accountingMode: 'double_entry',
  routeParams: {} as Record<string, string>,
  routeQuery: {} as Record<string, string>,
  replace: vi.fn(),
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
  byEntity: vi.fn(),
  docUpload: vi.fn(),
  docAddLink: vi.fn(),
  docRemove: vi.fn(),
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
  useSupplierStore: () => ({ currentSupplier: { accounting_mode: m.accountingMode } }),
}))
// Dimenze jsou u firmy vypnuté (currentSupplier bez dimensions_enabled) — editor nesmí nic načítat.
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canRead: () => true, canWrite: () => true }),
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
  useRoute: () => ({ query: m.routeQuery, params: m.routeParams }),
  useRouter: () => ({ push: m.push, replace: m.replace }),
}))
vi.mock('@/composables/useUserPrefs', () => ({
  ensurePrefsLoaded: () => Promise.resolve(),
  getPagePrefs: () => ({ value: { flags: { preview_open: true } } }),
  patchPagePrefs: vi.fn(),
}))
vi.mock('@/api/documents', () => ({
  documentsApi: {
    byEntity: m.byEntity, upload: m.docUpload, addLink: m.docAddLink, remove: m.docRemove,
    previewUrl: (id: number) => `/documents/${id}/preview`, thumbUrl: () => '', downloadUrl: () => '',
  },
}))

vi.mock('@/api/numberSeries', () => ({
  numberSeriesApi: {
    options: vi.fn().mockResolvedValue({
      kind: 'cash_receipt', multiple: true, default_id: 1,
      series: [
        { id: 1, name: 'Příjmové', label: '', storage: 'native', prefix: 'PPD', template: '{PREFIX}-{YYYY}-{NNNN}', valid_to: null },
        { id: 2, name: 'Pokladna B', label: '', storage: 'native', prefix: 'PB', template: '{PREFIX}-{YYYY}-{NNNN}', valid_to: null },
      ],
    }),
  },
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
    m.accountingMode = 'double_entry'
    for (const key of Object.keys(m.routeParams)) delete m.routeParams[key]
    for (const key of Object.keys(m.routeQuery)) delete m.routeQuery[key]
    m.listRegisters.mockResolvedValue([register()])
    m.listRulePresets.mockResolvedValue([])
    m.listAccounts.mockResolvedValue([])
    m.listPostingRules.mockResolvedValue({})
    m.taxList.mockResolvedValue([taxYear(new Date().getFullYear(), 10000)])
    m.clientsList.mockResolvedValue({ data: [] })
    m.createDocument.mockResolvedValue({ id: 1, doc_number: 'PPD-1', journal_entry_id: 1, status: 'posted', warnings: [] })
    m.searchUnpaid.mockResolvedValue({ items: [], truncated: false })
    m.byEntity.mockResolvedValue([])
  })

  it('u uloženého konceptu ukáže přílohu vedle formuláře a na novém dokladu přílohy nečte', async () => {
    const width = vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockReturnValue({ width: 976, right: 976 } as DOMRect)
    const fresh = await mountEditor()
    expect(m.byEntity).not.toHaveBeenCalled()
    expect(fresh.find('[data-test="cash-preview-toggle"]').exists()).toBe(false)

    m.routeParams.id = '42'
    m.getDocument.mockResolvedValue({
      id: 42, status: 'draft', register_id: 1, doc_type: 'out', purpose: 'other', issue_date: '2093-03-01', tax_date: null,
      partner_name: null, partner_ic: null, partner_dic: null, description: 'Účtenka', vat_mode: 'none', total_amount: 100,
      amount_foreign: null, fx_rate: null, invoice_id: null, purchase_invoice_id: null, rule_key: null, counter_account_code: null, vat_lines: [],
    })
    m.byEntity.mockResolvedValue([{ id: 7, title: 'uctenka.pdf', doc_type: 'pdf', size_bytes: 1, has_thumb: false }])
    const edit = await mountEditor()

    expect(m.byEntity).toHaveBeenCalledWith('cash_document', 42)
    expect(edit.find('[data-test="document-preview-splitter"]').exists()).toBe(true)
    expect(edit.get('iframe').attributes('src')).toContain('/documents/7/preview')
    width.mockRestore()
  })

  async function pickOriginal(wrapper: Awaited<ReturnType<typeof mountEditor>>) {
    const input = wrapper.get('[data-test="cash-original-file"]')
    const file = new File(['%PDF-1.4'], 'uctenka.pdf', { type: 'application/pdf' })
    Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
    await input.trigger('change')
    await flushPromises()
  }

  it('nový doklad ukáže vybraný originál vedle formuláře a po uložení ho nahraje a připojí', async () => {
    const width = vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockReturnValue({ width: 976, right: 976 } as DOMRect)
    const createUrl = vi.fn(() => 'blob:cash-original')
    const revokeUrl = vi.fn()
    Object.assign(URL, { createObjectURL: createUrl, revokeObjectURL: revokeUrl })
    m.createDocument.mockResolvedValue({ id: 55, doc_number: null, journal_entry_id: null, status: 'draft', warnings: [] })
    m.docUpload.mockResolvedValue({ root_ids: [91], errors: [] })
    m.docAddLink.mockResolvedValue([])
    const wrapper = await mountEditor()
    await pickOriginal(wrapper)

    expect(m.docUpload).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="document-preview-splitter"]').exists()).toBe(true)
    expect(wrapper.get('embed').attributes('src')).toBe('blob:cash-original')

    const vm = wrapper.vm as any
    vm.form.total_amount = 100
    vm.form.description = 'Účtenka'
    await vm.save(false)

    expect(m.docUpload).toHaveBeenCalledWith([expect.objectContaining({ name: 'uctenka.pdf' })], { zipMode: 'keep' })
    expect(m.docAddLink).toHaveBeenCalledWith(91, 'cash_document', 55)
    expect(m.docRemove).not.toHaveBeenCalled()
    expect(revokeUrl).toHaveBeenCalledWith('blob:cash-original')
    expect(m.push).toHaveBeenCalledWith('/accounting/cash')
    width.mockRestore()
  })

  it('když se originál nepodaří připojit, smaže nahraný dokument a doklad nechá uložený', async () => {
    Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:x'), revokeObjectURL: vi.fn() })
    m.createDocument.mockResolvedValue({ id: 56, doc_number: null, journal_entry_id: null, status: 'draft', warnings: [] })
    m.docUpload.mockResolvedValue({ root_ids: [92], errors: [] })
    m.docAddLink.mockRejectedValue(new Error('403'))
    m.docRemove.mockResolvedValue({ ok: true })
    const wrapper = await mountEditor()
    await pickOriginal(wrapper)
    const vm = wrapper.vm as any
    vm.form.total_amount = 100
    vm.form.description = 'Účtenka'
    await vm.save(false)

    expect(m.docRemove).toHaveBeenCalledWith(92)
    expect(m.toastWarning).toHaveBeenCalledWith('cash.original.attach_failed')
    expect(m.push).toHaveBeenCalledWith('/accounting/cash')
  })

  it('nákup nabídne aktivní nákladové účty a zvolenou analytiku promítne do náhledu i payloadu', async () => {
    m.listAccounts.mockResolvedValue([
      { account_code: '518.200', name: 'Hosting', account_type: 'expense', is_active: true },
      { account_code: '501', name: 'Materiál', account_type: 'expense', is_active: true },
      { account_code: '518.299', name: 'Neaktivní', account_type: 'expense', is_active: false },
      { account_code: '518.298', name: 'Chybný druh', account_type: 'asset', is_active: true },
      { account_code: '321', name: 'Dodavatelé', account_type: 'liability', is_active: true },
    ])
    const wrapper = await mountEditor()
    const vm = wrapper.vm as any
    vm.form.doc_type = 'out'
    vm.form.purpose = 'purchase'
    vm.form.total_amount = 100
    vm.form.description = 'Hosting za hotové'
    await flushPromises()

    const picker = wrapper.findComponent({ name: 'SearchableSelect' })
    expect(picker.exists()).toBe(true)
    expect(picker.props('options')).toEqual([
      { value: '501', label: '501 - Materiál' },
      { value: '518.200', label: '518.200 - Hosting' },
    ])
    expect(picker.props('modelValue')).toBeNull()
    expect(vm.previewLines[0]).toMatchObject({ account_code: '501', side: 'debit', amount: 100 })
    picker.vm.$emit('update:modelValue', '518.200')
    await flushPromises()
    expect(vm.previewLines[0]).toMatchObject({ account_code: '518.200', side: 'debit', amount: 100 })
    await vm.save(false)
    expect(m.createDocument).toHaveBeenCalledWith(expect.objectContaining({ counter_account_code: '518.200', rule_key: null }))

    picker.vm.$emit('update:modelValue', null)
    await flushPromises()
    expect(vm.previewLines[0].account_code).toBe('501')
    await vm.save(false)
    expect(m.createDocument.mock.calls[1][0].counter_account_code).toBeNull()
    vm.form.counter_account_code = '518.200'
    vm.form.purpose = 'purchase_payment'
    await flushPromises()
    expect(wrapper.find('[data-testid="cash-expense-account"]').exists()).toBe(false)
    expect(vm.form.counter_account_code).toBe('')
    expect(vm.previewLines[0].account_code).toBe('321')
  })

  it('prázdná volba nákupu zachová výchozí kontaci a nevybere první analytiku', async () => {
    m.listAccounts.mockResolvedValue([
      { account_code: '518.200', name: 'Hosting', account_type: 'expense', is_active: true },
    ])
    m.listPostingRules.mockResolvedValue({ 'cash.purchase': { debit_account_code: '501.100' } })
    const wrapper = await mountEditor()
    const vm = wrapper.vm as any
    vm.form.doc_type = 'out'
    vm.form.purpose = 'purchase'
    vm.form.total_amount = 100
    vm.form.description = 'Nákup'
    await flushPromises()
    const picker = wrapper.findComponent({ name: 'SearchableSelect' })
    expect(picker.props('modelValue')).toBeNull()
    expect(picker.props('placeholder')).toContain('501.100')
    expect(vm.previewLines[0].account_code).toBe('501.100')
    await vm.save(false)
    expect(m.createDocument.mock.calls[0][0].counter_account_code).toBeNull()
  })

  it('daňová evidence nenabízí nákladové účty ani je neposílá', async () => {
    m.accountingMode = 'tax_evidence'
    const wrapper = await mountEditor()
    const vm = wrapper.vm as any
    vm.form.doc_type = 'out'
    vm.form.purpose = 'purchase'
    vm.form.total_amount = 100
    vm.form.description = 'Nákup'
    await flushPromises()
    expect(wrapper.find('[data-testid="cash-expense-account"]').exists()).toBe(false)
    expect(m.listAccounts).not.toHaveBeenCalled()
    vm.form.counter_account_code = '518.200'
    await vm.save(false)
    expect(m.createDocument.mock.calls[0][0].counter_account_code).toBeNull()
  })

  it('nákladový účet draftu nákupu přežije načtení a uložení', async () => {
    m.routeParams.id = '42'
    m.listAccounts.mockResolvedValue([
      { account_code: '518.200', name: 'Hosting', account_type: 'expense', is_active: true },
    ])
    m.getDocument.mockResolvedValue({
      id: 42, register_id: 1, doc_type: 'out', purpose: 'purchase', doc_number: null,
      issue_date: '2093-03-01', tax_date: null, partner_name: 'Dodavatel', partner_ic: null, partner_dic: null,
      description: 'Hosting', total_amount: 100, currency_code: 'CZK', vat_mode: 'none', vat_lines: [],
      invoice_id: null, purchase_invoice_id: null, rule_key: null, counter_account_code: '518.200',
      status: 'draft', journal_entry_id: null, reversal_entry_id: null, created_by: null, created_at: '2093-03-01',
    })
    m.updateDocument.mockResolvedValue({})
    const wrapper = await mountEditor()
    const vm = wrapper.vm as any
    expect(vm.form.counter_account_code).toBe('518.200')
    expect(vm.previewLines[0].account_code).toBe('518.200')
    await vm.save(false)
    expect(m.updateDocument).toHaveBeenCalledWith(42, expect.objectContaining({ counter_account_code: '518.200', post: false }))
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

  it('prázdný kurz jde jako null — backend si vezme denní kurz ČNB', async () => {
    m.listRegisters.mockResolvedValue([register({ currency_code: 'EUR', account_code: '211.500' })])
    const wrapper = await mountEditor()
    const vm = wrapper.vm as any
    vm.form.total_amount = 100
    vm.form.description = 'Prodej v hotovosti'
    await vm.save()

    const payload = m.createDocument.mock.calls[0][0]
    expect(payload.amount_foreign).toBe(100)
    expect(payload.fx_rate).toBeNull()
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
  it('nápověda k číslu ukazuje vybranou řadu, ne napevno PPD', async () => {
    const wrapper = await mountEditor()
    await (wrapper.vm as any).$nextTick()
    expect(wrapper.find('[data-test="cash-number-hint"]').text()).toContain('"series":"PPD"')

    const select = wrapper.find('select option[value="2"]').element.parentElement as HTMLSelectElement
    select.value = '2'
    await select.dispatchEvent(new Event('change'))
    await flushPromises()

    expect(wrapper.find('[data-test="cash-number-hint"]').text()).toContain('"series":"PB"')
  })

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

  // ── N-04: Vystavit a další ────────────────────────────────────────────────
  it('„Vystavit a další" vystaví doklad a otevře prázdný se stejnou pokladnou, typem a datem', async () => {
    m.listRegisters.mockResolvedValue([register(), register({ id: 2, name: 'Druhá', is_default: false })])
    const wrapper = await mountEditor()
    const vm = wrapper.vm as any
    vm.form.register_id = 2
    vm.form.doc_type = 'out'
    await flushPromises()
    vm.form.purpose = 'purchase'
    vm.form.issue_date = '2093-03-15'
    vm.form.total_amount = 250
    vm.form.description = 'Poštovné'
    vm.form.partner_name = 'Pošta'
    await flushPromises()

    await wrapper.get('[data-test="cash-post-and-next"]').trigger('click')
    await flushPromises()

    expect(m.createDocument).toHaveBeenCalledWith(expect.objectContaining({ register_id: 2, doc_type: 'out', post: true }))
    expect(m.toastSuccess).toHaveBeenCalledWith('cash.new_document PPD-1')
    expect(m.push).not.toHaveBeenCalled()
    expect(m.replace).toHaveBeenCalledWith({
      path: '/accounting/cash/new',
      query: { register_id: '2', doc_type: 'out', issue_date: '2093-03-15' },
    })
    expect(vm.form).toMatchObject({
      register_id: 2, doc_type: 'out', issue_date: '2093-03-15', tax_date: '2093-03-15',
      purpose: 'purchase', description: '', total_amount: null, partner_name: '',
    })
  })

  it('„Vystavit a další" u úpravy draftu není', async () => {
    m.routeParams.id = '42'
    m.getDocument.mockResolvedValue({
      id: 42, register_id: 1, doc_type: 'in', purpose: 'sale', doc_number: null,
      issue_date: '2093-03-01', tax_date: null, partner_name: null, partner_ic: null, partner_dic: null,
      description: 'Prodej', total_amount: 500, currency_code: 'CZK', vat_mode: 'none', vat_lines: [],
      invoice_id: null, purchase_invoice_id: null, rule_key: null, counter_account_code: null,
      status: 'draft', journal_entry_id: null, reversal_entry_id: null, created_by: null, created_at: '2093-03-01',
    })
    const wrapper = await mountEditor()
    expect(wrapper.find('[data-test="cash-post-and-next"]').exists()).toBe(false)
  })

  it('datum z query převezme jen otevření z „Vystavit a další", jinak je dnešní', async () => {
    m.routeQuery.issue_date = '2093-03-15'
    m.routeQuery.doc_type = 'out'
    const fromNext = (await mountEditor()).vm as any
    expect(fromNext.form.issue_date).toBe('2093-03-15')
    expect(fromNext.form.doc_type).toBe('out')

    for (const key of Object.keys(m.routeQuery)) delete m.routeQuery[key]
    const plain = (await mountEditor()).vm as any
    expect(plain.form.issue_date).not.toBe('2093-03-15')
    expect(plain.form.issue_date).toMatch(/^\d{4}-\d{2}-\d{2}$/)
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

// ── Plátce na úhradě faktury: předvyplní se z faktury, jde upravit a odejde v payloadu ──
describe('CashDocumentEditor: partner u úhrady faktury', () => {
  const unpaid = {
    id: 5, kind: 'invoice', number: 'FV-1', partner_name: 'Klient s.r.o.', partner_ic: '12345679', partner_dic: 'CZ12345679',
    total: 100, paid: 0, remaining: 100, currency_code: 'CZK', issued_on: '2093-01-01',
  }

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
  })

  it('výběr faktury předvyplní název, IČ i DIČ a uložení je pošle', async () => {
    const wrapper = await mountEditor()
    const vm = wrapper.vm as any
    vm.form.purpose = 'invoice_payment'
    await flushPromises()

    vm.pickUnpaid(unpaid)
    await flushPromises()
    expect(vm.form.partner_ic).toBe('12345679')
    expect(vm.form.partner_dic).toBe('CZ12345679')

    await vm.save(true)
    expect(m.createDocument).toHaveBeenCalledWith(expect.objectContaining({
      invoice_id: 5, partner_name: 'Klient s.r.o.', partner_ic: '12345679', partner_dic: 'CZ12345679',
    }))
  })

  it('partner úhrady je ve formuláři a jde přepsat na skutečného plátce', async () => {
    const wrapper = await mountEditor()
    const vm = wrapper.vm as any
    vm.form.purpose = 'invoice_payment'
    await flushPromises()
    vm.pickUnpaid(unpaid)
    await flushPromises()

    const input = wrapper.find('input[list$="-cash-partners"]')
    expect(input.exists()).toBe(true)
    await input.setValue('Jan Plátce')
    vm.form.partner_ic = ''
    vm.form.partner_dic = ''

    await vm.save(true)
    expect(m.createDocument).toHaveBeenCalledWith(expect.objectContaining({
      partner_name: 'Jan Plátce', partner_ic: null, partner_dic: null,
    }))
  })

  it('úhrada přijaté faktury nabízí v našeptávači partnera dodavatele', async () => {
    const wrapper = await mountEditor()
    const vm = wrapper.vm as any
    vm.form.purpose = 'purchase_payment'
    await flushPromises()

    expect(m.clientsList.mock.calls.at(-1)?.[0].role).toBe('vendors')
  })

  it('změna účelu partnera vymaže, ať na jiném dokladu nezůstane cizí plátce', async () => {
    const wrapper = await mountEditor()
    const vm = wrapper.vm as any
    vm.form.purpose = 'invoice_payment'
    await flushPromises()
    vm.pickUnpaid(unpaid)
    await flushPromises()

    vm.form.purpose = 'sale'
    await flushPromises()
    expect(vm.form.partner_name).toBe('')
    expect(vm.form.partner_ic).toBe('')
    expect(vm.form.partner_dic).toBe('')
  })

  it('draft úhrady si při načtení partnera nechá', async () => {
    m.routeParams.id = '42'
    m.getDocument.mockResolvedValue({
      id: 42, register_id: 1, doc_type: 'in', purpose: 'invoice_payment', doc_number: null,
      issue_date: '2093-03-01', tax_date: null, partner_name: 'Klient s.r.o.', partner_ic: '12345679', partner_dic: null,
      description: 'Úhrada', total_amount: 100, currency_code: 'CZK', vat_mode: 'none', vat_lines: [],
      invoice_id: 5, purchase_invoice_id: null, rule_key: null, counter_account_code: null,
      status: 'draft', journal_entry_id: null, reversal_entry_id: null, created_by: null, created_at: '2093-03-01',
    })
    m.updateDocument.mockResolvedValue({})
    const wrapper = await mountEditor()
    const vm = wrapper.vm as any

    await vm.save(false)
    expect(m.updateDocument).toHaveBeenCalledWith(42, expect.objectContaining({
      invoice_id: 5, partner_name: 'Klient s.r.o.', partner_ic: '12345679', partner_dic: null,
    }))
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
