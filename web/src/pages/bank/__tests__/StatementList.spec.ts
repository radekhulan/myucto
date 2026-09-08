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
  list: vi.fn(),
  clientsList: vi.fn(),
  clientGet: vi.fn(),
  query: {} as Record<string, string>,
  upload: vi.fn(),
  toastSuccess: vi.fn(),
  toastWarning: vi.fn(),
  toastError: vi.fn(),
  push: vi.fn(),
}))

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
    importPdf: vi.fn(),
    scan: vi.fn(),
    delete: vi.fn(),
    downloadUrl: () => '',
    pdfUrl: () => '',
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
    canWrite: () => true,
    isSuperadmin: false,
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
    m.list.mockResolvedValue(emptyPage())
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

    expect(m.upload).toHaveBeenNthCalledWith(1, file, undefined, [])
    expect(m.upload).toHaveBeenNthCalledWith(2, file, undefined, [candidate.confirmation_key])
    expect(m.upload).toHaveBeenNthCalledWith(3, file, undefined, [candidate.confirmation_key, nextCandidate.confirmation_key])
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

    expect(m.upload).toHaveBeenNthCalledWith(2, file, 7, [])
    expect(m.upload).toHaveBeenNthCalledWith(3, file, 7, [candidate.confirmation_key])
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
})
