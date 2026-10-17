import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { BankStatementPage, ImportResult } from '@/api/bank'
import type { BankReconciliationCandidate } from '@/types/bankReconciliation'

// #19: dnes se v BE opravila tichá ztráta pohybů při GPC importu (commit 6d09abe6) —
// `/bank-statements/upload` teď vrací `parsed_transactions`/`skipped_duplicates`/
// `warnings[]`, ale FE to dřív ignoroval. Testy ověřují, že se to dotáhlo do UI a že
// jde rozlišit dva různé případy:
//   - celý soubor je znovunahraný (duplicate=true) → klidné, žádné varování navíc,
//   - jinak nový výpis, ale část pohybů uvnitř se s něčím shoduje a přeskočila se
//     (warnings: transactions_skipped_as_duplicate) → musí to být toast.warning,
//     ne jen tichá součást jednořádkového souhrnu.

const m = vi.hoisted(() => ({
  admin: false,
  bankWrite: true,
  clientRole: false,
  list: vi.fn(),
  clientsList: vi.fn(),
  clientGet: vi.fn(),
  query: {} as Record<string, string>,
  upload: vi.fn(),
  toastSuccess: vi.fn(),
  toastWarning: vi.fn(),
  toastError: vi.fn(),
  push: vi.fn(),
  download: vi.fn(),
}))

vi.mock('@/utils/downloadFile', () => ({ downloadApiFile: m.download }))

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: m.push, replace: vi.fn() }),
  useRoute: () => ({ query: m.query }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

vi.mock('vue-i18n', async (importOriginal) => {
  const actual = await importOriginal<typeof import('vue-i18n')>()
  return {
    ...actual,
    useI18n: () => ({
      t: (key: string, params?: Record<string, unknown>) =>
        params ? `${key}:${JSON.stringify(params)}` : key,
      tm: () => [],
      rt: (v: unknown) => v,
      locale: { value: 'cs' },
    }),
  }
})

vi.mock('@/api/bank', () => ({
  bankApi: {
    list: m.list,
    upload: m.upload,
    importPdf: m.upload,
    scan: vi.fn(),
    delete: vi.fn(),
    downloadUrl: (id: number) => `/download/${id}`,
    gpcExportUrl: (id: number) => `/export-gpc/${id}`,
    pdfUrl: (id: number) => `/pdf/${id}`,
  },
}))

vi.mock('@/api/clients', () => ({
  clientsApi: { list: m.clientsList, get: m.clientGet },
}))

vi.mock('@/api/errors', () => ({
  apiErrorCode: (e: any) => e?.response?.data?.error?.code ?? '',
  apiErrorMessage: (e: unknown) => String((e as { message?: string })?.message ?? e),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({
    success: m.toastSuccess,
    warning: m.toastWarning,
    error: m.toastError,
    info: vi.fn(),
  }),
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canWrite: (key: string) => key !== 'bank' || m.bankWrite,
    get isClientRole() { return m.clientRole },
    isSuperadmin: false,
    get isCompanyAdminRole() { return m.admin },
    hasCommercialFeatures: false,
  }),
}))

vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: null }),
}))

vi.mock('@/composables/useSavedFilters', () => ({
  useSavedFilters: () => ({
    filters: { value: [] },
    activeId: { value: null },
    clearActive: vi.fn(),
    apply: vi.fn(),
    applyDefaultIfAny: vi.fn().mockResolvedValue(false),
  }),
  savedFilterTone: () => 'neutral',
}))

import StatementList from '@/pages/bank/StatementList.vue'


const stubs = {
  Teleport: true,
  FilterBar: true,
  SavedFiltersMenu: true,
  EmptyState: true,
}

function emptyPage(): BankStatementPage {
  return { items: [], total: 0, page: 1, limit: 50, years: [], accounts: [], scan_configured: false }
}

function gpcFile(name = 'vypis.gpc'): File {
  return new File(['some gpc content'], name, { type: 'text/plain' })
}

function reconciliationCandidate(): BankReconciliationCandidate {
  return {
    confirmation_key: 'a'.repeat(64),
    posted_at: '2026-03-12',
    amount: '925.18',
    currency: 'CZK',
    existing_transaction_id: 81,
    existing_statement_id: 18,
    description: 'Nový popis',
    existing_description: 'Původní popis',
    counterparty_account: '',
    existing_counterparty_account: '',
    variable_symbol: '',
    existing_variable_symbol: '',
  }
}

function importedResult(): ImportResult {
  return {
    statement_id: 42,
    transactions: 0,
    matched: 0,
    duplicate: false,
    parsed_transactions: 1,
    skipped_duplicates: 1,
    warnings: [],
  }
}

function reconciliationConflict(candidate = reconciliationCandidate()) {
  return {
    response: {
      data: {
        error: {
          code: 'statement_reconciliation_required',
          reconciliation_candidates: [candidate],
        },
      },
    },
  }
}

async function selectFiles(wrapper: ReturnType<typeof mount>, files: File[]) {
  const input = wrapper.get('input[type="file"]')
  Object.defineProperty(input.element, 'files', { configurable: true, value: files })
  await input.trigger('change')
  await flushPromises()
}

describe('StatementList.vue — varování z importu bankovního výpisu (#19)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.upload.mockReset()
    m.list.mockResolvedValue(emptyPage())
  })

  it.each([
    ['calculated', 1000, 1250, undefined, '/export-gpc/42'],
    ['calculated', null, 1250, undefined, undefined],
    ['confirmed', null, 0, 81, '/download/81'],
    ['missing_anchor', null, null, undefined, undefined],
    ['mismatch', 1000, 1250, undefined, undefined],
    ['unavailable', null, null, undefined, undefined],
  ] as const)('bank_api: %s zobrazí zůstatek a odpovídající GPC akci v tabulce i kartě', async (status, opening, closing, bankStatementId, href) => {
    m.list.mockResolvedValue({ ...emptyPage(), total: 1, items: [{
      id: 42, source: 'bank_api', file_name: 'API-2026-03',
      account_number: '1000000005', bank_code: '0100', currency: 'CZK',
      statement_date: '2026-03-31', curr_balance: null, prev_balance: null,
      transaction_count: 1, matched_count: 0, unposted_count: 0, has_file: false, has_pdf: false,
      balance_calculation: { status, opening, closing: status === 'confirmed' ? 999 : closing, confirmed_closing: status === 'confirmed' ? closing : null, bank_statement_id: bankStatementId },
    }] })
    const wrapper = mount(StatementList, { global: { stubs } })
    await flushPromises()
    const actions = wrapper.findAll('button').filter(a => a.text() === 'GPC')
    expect(actions).toHaveLength(2)
    for (const action of actions) {
      // Holý href by stažení poslal bez X-Supplier-Id a server by vzal výchozí firmu.
      expect(action.attributes('href')).toBeUndefined()
      if (!href) expect(action.attributes('aria-disabled')).toBe('true')
    }
    const { formatMoney } = await import('@/composables/useFormat')
    if (closing !== null) expect(wrapper.text().split(formatMoney(closing, 'CZK')).length - 1).toBe(2)
    else expect(wrapper.find('tbody tr td:nth-child(5)').text()).toBe('-')
    for (const action of actions) await action.trigger('click')
    await flushPromises()
    expect(m.push).not.toHaveBeenCalled()
    if (href) {
      expect(m.download).toHaveBeenCalledTimes(2)
      expect(m.download).toHaveBeenNthCalledWith(1, href, 'vypis.gpc')
      expect(m.download).toHaveBeenNthCalledWith(2, href, 'vypis.gpc')
    } else {
      expect(m.download).not.toHaveBeenCalled()
    }
    wrapper.unmount()
  })

  it('stažení GPC a PDF jde přes downloadApiFile (hlavička firmy), chybu ukáže toastem', async () => {
    m.list.mockResolvedValue({ ...emptyPage(), total: 1, items: [{
      id: 44, source: 'gpc', file_name: 'EUR-2026-09.gpc',
      account_number: '1000000005', bank_code: '0100', currency: 'EUR',
      statement_date: '2026-09-30', curr_balance: 10, prev_balance: 5,
      transaction_count: 1, matched_count: 0, unposted_count: 0, has_file: true, has_pdf: true,
    }] })
    m.download.mockResolvedValueOnce({}).mockRejectedValueOnce(new Error('Výpis nenalezen.'))
    const wrapper = mount(StatementList, { global: { stubs } })
    await flushPromises()
    expect(wrapper.findAll('a').filter(a => ['GPC', 'PDF'].includes(a.text()))).toHaveLength(0)

    await wrapper.findAll('[data-testid="statement-gpc"]')[0]!.trigger('click')
    await wrapper.findAll('[data-testid="statement-pdf"]')[0]!.trigger('click')
    await flushPromises()

    expect(m.download).toHaveBeenNthCalledWith(1, '/download/44', 'vypis.gpc')
    expect(m.download).toHaveBeenNthCalledWith(2, '/pdf/44', 'vypis.pdf')
    expect(m.toastError).toHaveBeenCalledWith('Výpis nenalezen.')
    expect(m.push).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('výpis převzatý z jiného programu (import, bez souboru) nabídne GPC složené ze zůstatků', async () => {
    m.list.mockResolvedValue({ ...emptyPage(), total: 1, items: [{
      id: 43, source: 'import', file_name: 'money-s3-BU_2026_03.import',
      account_number: '1000000005', bank_code: '0100', currency: 'CZK',
      statement_date: '2026-03-31', curr_balance: 1250, prev_balance: 1000,
      transaction_count: 1, matched_count: 0, unposted_count: 0, has_file: false, has_pdf: false,
      balance_calculation: { status: 'confirmed', opening: 1000, closing: 1250, confirmed_closing: 1250, bank_statement_id: undefined },
    }] })
    const wrapper = mount(StatementList, { global: { stubs } })
    await flushPromises()
    const actions = wrapper.findAll('button').filter(a => a.text() === 'GPC')
    expect(actions).toHaveLength(2)
    await actions[0]!.trigger('click')
    await flushPromises()
    expect(m.download).toHaveBeenCalledWith('/export-gpc/43', 'vypis.gpc')
    wrapper.unmount()
  })

  it('jinak nový výpis s přeskočenými duplicitami uvnitř → toast.warning s přesnými čísly, ne tichý souhrn', async () => {
    const result: ImportResult = {
      statement_id: 42,
      transactions: 7,
      matched: 3,
      duplicate: false,
      parsed_transactions: 10,
      skipped_duplicates: 3,
      warnings: [{
        code: 'transactions_skipped_as_duplicate',
        message: 'Soubor obsahuje 10 pohybů, založeno 7. 3 pohybů se shoduje s už evidovanými a nebylo založeno.',
        parsed: 10,
        inserted: 7,
        skipped: 3,
      }],
    }
    m.upload.mockResolvedValue(result)

    const wrapper = mount(StatementList, { global: { stubs } })
    await flushPromises()

    await selectFiles(wrapper, [gpcFile()])

    expect(m.upload).toHaveBeenCalledOnce()
    expect(m.toastWarning).toHaveBeenCalledWith(
      'bank.warning.transactions_skipped_as_duplicate:' + JSON.stringify({ parsed: 10, inserted: 7, skipped: 3 }),
    )
    // Single-file redirect na detail zůstává zachovaný i s varováním navíc.
    expect(m.push).toHaveBeenCalledWith('/bank/42')
  })

  it('znovunahraný TÝŽ výpis (duplicate=true) → žádné toast.warning navíc, jen klidný souhrn', async () => {
    const result: ImportResult = {
      statement_id: 42,
      transactions: 0,
      matched: 0,
      duplicate: true,
      parsed_transactions: 10,
      skipped_duplicates: 0,
      warnings: [],
    }
    m.upload.mockResolvedValue(result)

    const wrapper = mount(StatementList, { global: { stubs } })
    await flushPromises()

    await selectFiles(wrapper, [gpcFile()])

    expect(m.upload).toHaveBeenCalledOnce()
    expect(m.toastWarning).not.toHaveBeenCalled()
    // Celý soubor je duplicitní → žádný redirect na nový výpis (lastNonDuplicate zůstává null).
    expect(m.push).not.toHaveBeenCalled()
  })

  it('hromadný upload: agreguje přeskočené duplicity napříč soubory do JEDNOHO samostatného varování', async () => {
    const withSkips: ImportResult = {
      statement_id: 1,
      transactions: 4,
      matched: 2,
      duplicate: false,
      parsed_transactions: 6,
      skipped_duplicates: 2,
      warnings: [{ code: 'transactions_skipped_as_duplicate', parsed: 6, inserted: 4, skipped: 2 }],
    }
    const clean: ImportResult = {
      statement_id: 2,
      transactions: 5,
      matched: 5,
      duplicate: false,
      parsed_transactions: 5,
      skipped_duplicates: 0,
      warnings: [],
    }
    m.upload.mockResolvedValueOnce(withSkips).mockResolvedValueOnce(clean)

    const wrapper = mount(StatementList, { global: { stubs } })
    await flushPromises()

    await selectFiles(wrapper, [gpcFile('a.gpc'), gpcFile('b.gpc')])

    expect(m.upload).toHaveBeenCalledTimes(2)
    // Souhrnný toast beze zmínky o přeskočených duplicitách...
    expect(m.toastSuccess).toHaveBeenCalledWith('bank.upload_batch_done:' + JSON.stringify({ ok: 2, dup: 0 }))
    // ...a samostatné varování s agregovaným počtem 2.
    expect(m.toastWarning).toHaveBeenCalledWith(
      'bank.warning.transactions_skipped_as_duplicate_batch:' + JSON.stringify({ count: 2 }),
    )
  })

  it('po potvrzení kandidáta zopakuje tentýž GPC upload s confirmation key', async () => {
    const candidate = reconciliationCandidate()
    const result = importedResult()
    const nextCandidate = { ...candidate, confirmation_key: 'b'.repeat(64) }
    m.upload.mockRejectedValueOnce(reconciliationConflict(candidate))
      .mockRejectedValueOnce(reconciliationConflict(nextCandidate)).mockResolvedValueOnce(result)
    const file = gpcFile()
    const wrapper = mount(StatementList, { global: { stubs } })
    await flushPromises()

    const selection = selectFiles(wrapper, [file])
    await flushPromises()
    expect(wrapper.find('[data-testid="reconciliation-panel"]').exists()).toBe(true)
    await wrapper.get('[data-testid="confirm-reconciliation"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="confirm-reconciliation"]').trigger('click')
    await selection

    expect(m.upload).toHaveBeenNthCalledWith(1, file, undefined, [], undefined)
    expect(m.upload).toHaveBeenNthCalledWith(2, file, undefined, [candidate.confirmation_key], undefined)
    expect(m.upload).toHaveBeenNthCalledWith(3, file, undefined, [candidate.confirmation_key, nextCandidate.confirmation_key], undefined)
    await flushPromises()
    expect(m.push).toHaveBeenCalledWith('/bank/42')
  })

  it('po volbě účtu zachová account_id i při navazujícím potvrzeném retry', async () => {
    const candidate = reconciliationCandidate()
    m.upload
      .mockRejectedValueOnce({
        response: {
          data: {
            error: {
              code: 'ambiguous_account_currency',
              candidates: [{ account_id: 7, currency: 'CZK', bank_code: '0100', label: 'CZK /0100' }],
            },
          },
        },
      })
      .mockRejectedValueOnce(reconciliationConflict(candidate))
      .mockResolvedValueOnce(importedResult())
    const file = gpcFile()
    const wrapper = mount(StatementList, { global: { stubs } })
    await flushPromises()

    const selection = selectFiles(wrapper, [file])
    await flushPromises()
    const accountConfirm = wrapper.findAll('button').find(button => button.text() === 'bank.choose_account_confirm')
    expect(accountConfirm).toBeDefined()
    await accountConfirm!.trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="confirm-reconciliation"]').trigger('click')
    await selection

    expect(m.upload).toHaveBeenNthCalledWith(2, file, 7, [], undefined)
    expect(m.upload).toHaveBeenNthCalledWith(3, file, 7, [candidate.confirmation_key], undefined)
  })

  it('výpis s nezpracovanými pohyby: štítek u výpisu a souhrnné upozornění nad seznamem', async () => {
    m.list.mockResolvedValue({ ...emptyPage(), total: 1, processing_failed_statements: 3, items: [{
      id: 45, source: 'gpc', file_name: 'synthetic-failed.gpc',
      account_number: '1000000005', bank_code: '0100', currency: 'CZK',
      statement_date: '2026-09-30', curr_balance: 10, prev_balance: 5,
      transaction_count: 2, matched_count: 0, unposted_count: 0, has_file: true, has_pdf: false,
      unprocessed_count: 2, processing_failed: true,
    }] })
    const wrapper = mount(StatementList, { global: { stubs } })
    await flushPromises()
    expect(wrapper.get('[data-testid="statements-processing-failed"]').text())
      .toBe('bank.processing_failed_summary:' + JSON.stringify({ count: 3 }))
    const badge = wrapper.get('[data-testid="statement-processing-failed-badge"]')
    expect(badge.text()).toBe('bank.processing_failed_badge')
    expect(badge.attributes('title')).toBe('bank.processing_failed_badge_hint:' + JSON.stringify({ count: 2 }))
    wrapper.unmount()
  })

  it('bez nezpracovaných výpisů se upozornění nevykreslí', async () => {
    m.list.mockResolvedValue({ ...emptyPage(), processing_failed_statements: 0 })
    const wrapper = mount(StatementList, { global: { stubs } })
    await flushPromises()
    expect(wrapper.find('[data-testid="statements-processing-failed"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('nahraný výpis s neúspěšným zpracováním pohybů → toast.warning a přesměrování na detail', async () => {
    m.upload.mockResolvedValue({
      ...importedResult(),
      transactions: 2,
      skipped_duplicates: 0,
      processing_failed: true,
      warnings: [{ code: 'processing_failed', error: 'Synthetic failure' }],
    })
    const wrapper = mount(StatementList, { global: { stubs } })
    await flushPromises()
    await selectFiles(wrapper, [gpcFile()])
    expect(m.toastWarning).toHaveBeenCalledWith('bank.warning.processing_failed')
    expect(m.push).toHaveBeenCalledWith('/bank/42')
    wrapper.unmount()
  })

  it('zrušení review neodesílá potvrzení ani další upload', async () => {
    m.upload.mockRejectedValueOnce(reconciliationConflict())
    const wrapper = mount(StatementList, { global: { stubs } })
    await flushPromises()

    const selection = selectFiles(wrapper, [gpcFile()])
    await flushPromises()
    await wrapper.get('[data-testid="cancel-reconciliation"]').trigger('click')
    await selection

    expect(m.upload).toHaveBeenCalledOnce()
    expect(m.push).not.toHaveBeenCalled()
  })
})

describe('StatementList counterparty lookup', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.upload.mockReset()
    m.query = {}
    m.list.mockResolvedValue(emptyPage())
    m.clientsList.mockReset().mockResolvedValue({ data: [], meta: { pages: 10, total: 500 } })
    m.clientGet.mockReset()
  })

  function mountLookup() {
    return mount(StatementList, { global: { stubs: { ...stubs, FilterBar: { template: '<div><slot /></div>' } } } })
  }

  it('does not download counterparties until the lookup is opened and fetches one limited page', async () => {
    const wrapper = mountLookup()
    await flushPromises()
    expect(m.clientsList).not.toHaveBeenCalled()
    expect(m.clientGet).not.toHaveBeenCalled()
    wrapper.getComponent({ name: 'SearchableSelect' }).vm.$emit('search', '')
    await flushPromises()
    expect(m.clientsList).toHaveBeenCalledExactlyOnceWith({ q: undefined, role: 'all', page: 1, per_page: 50, sort: 'name' })
    expect(wrapper.getComponent({ name: 'SearchableSelect' }).props('truncated')).toBe(true)
    expect(wrapper.getComponent({ name: 'SearchableSelect' }).props('truncatedLabel')).toContain('common.loaded_count')
    wrapper.unmount()
  })

  it('resolves the selected deep-link label independently of search results', async () => {
    m.query = { client_id: '17' }
    m.clientGet.mockResolvedValue({ id: 17, company_name: 'Synthetic selected' })
    m.clientsList.mockResolvedValue({ data: [{ id: 22, company_name: 'Synthetic search' }], meta: { pages: 1 } })
    const wrapper = mountLookup()
    await flushPromises()
    expect(m.clientGet).toHaveBeenCalledExactlyOnceWith(17)
    expect(m.clientsList).not.toHaveBeenCalled()
    const select = wrapper.getComponent({ name: 'SearchableSelect' })
    expect(select.props('selectedOption')).toEqual({ value: 17, label: 'Synthetic selected' })
    select.vm.$emit('search', ' search ')
    await flushPromises()
    expect(m.clientsList).toHaveBeenLastCalledWith({ q: 'search', role: 'all', page: 1, per_page: 50, sort: 'name' })
    expect(select.props('selectedOption')).toEqual({ value: 17, label: 'Synthetic selected' })
    wrapper.unmount()
  })

  it('ignores stale search and selected-label responses', async () => {
    let resolveSearch!: (value: unknown) => void
    let resolveClient!: (value: unknown) => void
    m.query = { client_id: '17' }
    m.clientGet.mockImplementationOnce(() => new Promise(resolve => { resolveClient = resolve }))
    m.clientsList.mockImplementationOnce(() => new Promise(resolve => { resolveSearch = resolve }))
    const wrapper = mountLookup()
    await flushPromises()
    const select = wrapper.getComponent({ name: 'SearchableSelect' })
    select.vm.$emit('search', 'old')
    await flushPromises()
    m.clientsList.mockResolvedValue({ data: [{ id: 22, company_name: 'Synthetic current' }], meta: { pages: 1 } })
    select.vm.$emit('search', 'current')
    await flushPromises()
    select.vm.$emit('update:modelValue', 22)
    await flushPromises()
    resolveSearch({ data: [{ id: 17, company_name: 'Synthetic old' }], meta: { pages: 1 } })
    resolveClient({ id: 17, company_name: 'Synthetic old' })
    await flushPromises()
    expect(select.props('options')).toEqual([{ value: 22, label: 'Synthetic current' }])
    expect(select.props('selectedOption')).toEqual({ value: 22, label: 'Synthetic current' })
    wrapper.unmount()
  })
  function ignoreConflict(fingerprint = 'preview') {
    return { response: { data: { error: {
      code: 'ignored_notices_confirmation', fingerprint,
      candidates: [2, 5].map(index => ({ index, notice_id: index + 10, posted_at: '2026-09-16', notice_date: '2026-09-15',
        amount: -101, currency: 'CZK', counterparty: 'Synthetic party', reason: 'variable_symbol', variable_symbol: '123', ignore_note: 'Synthetic note' })),
    } } } }
  }

  it('přenese hromadný výběr a zachová předchozí potvrzení deduplikace', async () => {
    const candidate = reconciliationCandidate()
    m.upload.mockRejectedValueOnce(reconciliationConflict(candidate)).mockRejectedValueOnce(ignoreConflict())
      .mockResolvedValueOnce({ ...importedResult(), ignored_transferred: 2 })
    const wrapper = mount(StatementList, { global: { stubs } })
    await flushPromises()
    const file = gpcFile()
    await selectFiles(wrapper, [file])
    await wrapper.get('[data-testid="confirm-reconciliation"]').trigger('click')
    await flushPromises()
    const checks = () => wrapper.findAll<HTMLInputElement>('input[type="checkbox"]')
    expect(checks()).toHaveLength(3)
    expect(checks().every(c => !c.element.checked)).toBe(true)
    await checks()[1]!.setValue(true)
    await flushPromises()
    expect(wrapper.findAll<HTMLInputElement>('input[type="checkbox"]')[0]!.element.indeterminate).toBe(true)
    await checks()[0]!.setValue(true)
    expect(checks().every(c => c.element.checked)).toBe(true)
    await checks()[0]!.setValue(false)
    expect(checks().every(c => !c.element.checked)).toBe(true)
    await checks()[0]!.setValue(true)
    await wrapper.findAll('button').find(b => b.text().startsWith('bank.ignore_transfer.confirm'))!.trigger('click')
    await flushPromises()
    expect(m.upload).toHaveBeenLastCalledWith(file, undefined, [candidate.confirmation_key], { fingerprint: 'preview', selected: [2, 5] })
    expect(m.toastSuccess).toHaveBeenCalledWith('bank.ignore_transfer.done:{"count":2}')
    wrapper.unmount()
  })

  it('PDF předá vybrané ignorování jako třetí argument', async () => {
    m.upload.mockRejectedValueOnce(ignoreConflict()).mockResolvedValueOnce({ ...importedResult(), ignored_transferred: 2 })
    const wrapper = mount(StatementList, { global: { stubs } })
    await flushPromises()
    const file = gpcFile('synthetic.pdf')
    await selectFiles(wrapper, [file])
    await wrapper.findAll('input[type="checkbox"]')[0]!.setValue(true)
    await wrapper.findAll('button').find(b => b.text().startsWith('bank.ignore_transfer.confirm'))!.trigger('click')
    await flushPromises()
    expect(m.upload).toHaveBeenLastCalledWith(file, undefined, { fingerprint: 'preview', selected: [2, 5] })
    wrapper.unmount()
  })

  it.each(['cancel', 'skip', 'unmount'])('přenos ignorování: %s', async mode => {
    m.upload.mockRejectedValueOnce(ignoreConflict()).mockResolvedValueOnce(importedResult())
    const wrapper = mount(StatementList, { global: { stubs } })
    await flushPromises()
    const file = gpcFile()
    await selectFiles(wrapper, [file])
    if (mode === 'unmount') wrapper.unmount()
    else await wrapper.findAll('button').find(b => b.text() === (mode === 'cancel' ? 'common.cancel' : 'bank.ignore_transfer.skip'))!.trigger('click')
    await flushPromises()
    if (mode === 'skip') expect(m.upload).toHaveBeenLastCalledWith(file, undefined, [], { skip: true })
    else expect(m.upload).toHaveBeenCalledTimes(1)
    if (mode !== 'unmount') wrapper.unmount()
    m.upload.mockReset()
  })

  it('změněný náhled vyžaduje nový výběr', async () => {
    m.upload.mockRejectedValueOnce(ignoreConflict()).mockRejectedValueOnce(ignoreConflict('changed'))
    const wrapper = mount(StatementList, { global: { stubs } })
    await flushPromises()
    await selectFiles(wrapper, [gpcFile()])
    await wrapper.findAll('input[type="checkbox"]')[0]!.setValue(true)
    await wrapper.findAll('button').find(b => b.text().startsWith('bank.ignore_transfer.confirm'))!.trigger('click')
    await flushPromises()
    expect(wrapper.findAll<HTMLInputElement>('input[type="checkbox"]').every(c => !c.element.checked)).toBe(true)
    wrapper.unmount()
  })

})


describe('email notice deletion eligibility', () => {
  it('blocks deletion despite zero invoice matches when confirmation is incomplete', async () => {
    m.admin = true
    m.list.mockResolvedValue({ ...emptyPage(), total: 1, items: [{
      id: 96, source: 'email_notice', file_name: 'synthetic-notices', account_number: '1000000005',
      bank_code: '0100', currency: 'CZK', statement_date: '2026-09-01', transaction_count: 4,
      matched_count: 0, unposted_count: 0, has_file: false, has_pdf: false, can_delete_notices: false,
      confirmation_summary: { pending: 1, review: 1, confirmed: 2, total: 4 },
    }] })
    const wrapper = mount(StatementList, { global: { stubs } })
    await flushPromises()
    const deletes = wrapper.findAll('button').filter(button => button.attributes('title') === 'bank.notice_confirmation.delete_blocked' || button.text() === 'bank.delete')
    expect(deletes).toHaveLength(2)
    expect(deletes.every(button => button.attributes('disabled') !== undefined)).toBe(true)
    expect(wrapper.findAll('[data-testid="notice-summary"]')).toHaveLength(2)
    wrapper.unmount()
    m.admin = false
  })
})


describe('notice deletion permission', () => {
  it.each([[true, false, 2], [false, false, 0], [true, true, 0]] as const)('bank write %s / client %s exposes %s delete buttons', async (bankWrite, clientRole, count) => {
    m.admin = false
    m.bankWrite = bankWrite
    m.clientRole = clientRole
    m.list.mockResolvedValue({ ...emptyPage(), total: 1, items: [{
      id: 157, source: 'email_notice', file_name: 'Synthetic notice', account_number: '1000000005', bank_code: '0100', currency: 'CZK',
      statement_date: '2099-06-30', transaction_count: 0, matched_count: 0, can_delete_notices: true,
      confirmation_summary: { total: 0, pending: 0, review: 0, confirmed: 0 },
    }] })
    const wrapper = mount(StatementList, { global: { stubs } })
    try {
      await flushPromises()
      const buttons = wrapper.findAll('button').filter(button => button.attributes('title') === 'bank.delete' || button.text() === 'bank.delete')
      expect(buttons).toHaveLength(count)
      expect(buttons.every(button => button.attributes('disabled') === undefined)).toBe(true)
    } finally {
      wrapper.unmount()
      m.bankWrite = true
      m.clientRole = false
    }
  })
})
