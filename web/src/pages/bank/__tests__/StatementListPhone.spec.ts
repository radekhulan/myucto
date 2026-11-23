import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import type { BankStatementPage } from '@/api/bank'


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
  download: vi.fn(),
}))

const phoneRef = ref(false)
vi.mock('@/composables/useIsPhone', () => ({ useIsPhone: () => phoneRef, installPhoneViewport: () => {} }))

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
  Teleport: true,
  FilterBar: true,
  SavedFiltersMenu: true,
  EmptyState: true,
}

function emptyPage(): BankStatementPage {
  return { items: [], total: 0, page: 1, limit: 50, years: [], accounts: [], scan_configured: false }
}


function statement(overrides: Record<string, unknown> = {}) {
  return {
    id: 60, source: 'gpc', file_name: 'vypis-ukazka.gpc',
    account_number: '1000000005', bank_code: '0100', currency: 'CZK',
    statement_date: '2093-03-01', curr_balance: 100, prev_balance: 0,
    transaction_count: 2, matched_count: 2, superseded_count: 0, ignored_count: 0,
    unposted_count: 0, has_file: true, has_pdf: true,
    ...overrides,
  }
}

describe('StatementList.vue na telefonu', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.list.mockResolvedValue({ ...emptyPage(), total: 1, items: [statement()] })
  })

  it('mimo telefon karta nese název souboru a tlačítka GPC/PDF/Smazat', async () => {
    phoneRef.value = false
    const wrapper = mount(StatementList, { global: { stubs } })
    await flushPromises()
    expect(wrapper.text()).toContain('vypis-ukazka.gpc')
    expect(wrapper.findAll('[data-testid="statement-pdf"]')).toHaveLength(2)
    expect(wrapper.find('[data-testid="statement-phone-actions"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('na telefonu karta nemá název souboru a stažení i smazání jsou v nabídce', async () => {
    phoneRef.value = true
    const wrapper = mount(StatementList, { global: { stubs } })
    await flushPromises()
    const cards = wrapper.find('[data-testid="statement-phone-actions"]')
    expect(cards.exists()).toBe(true)
    expect(wrapper.find('[data-testid="statement-delete-mobile"]').exists()).toBe(false)
    expect(wrapper.findAll('[data-testid="statement-pdf"]')).toHaveLength(1)
    const menu = wrapper.findComponent({ name: 'RowActionsMenu' })
    const keys = (menu.props('actions') as { key: string; show?: unknown }[]).filter(a => a.show === undefined || a.show).map(a => a.key)
    expect(keys.slice(0, 2)).toEqual(['gpc', 'pdf'])
    expect(menu.props('inlineCount')).toBe(0)
    wrapper.unmount()
  })
})
