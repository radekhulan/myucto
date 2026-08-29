import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { nextTick } from 'vue'
import type { TaxSubmission } from '@/api/epoSubmissions'

/**
 * Filtr stavu a hledání dřív běžely jen v prohlížeči nad prvními sto řádky,
 * takže volba „zamítnuté" mohla ukázat prázdno, i když zamítnutá podání byla —
 * jen o pár stránek dál. Tenhle test hlídá, že stránka posílá filtry na server,
 * nefiltruje si je sama a že souhrnné dlaždice bere z odpovědi, ne z výpisu.
 */

const m = vi.hoisted(() => ({
  list: vi.fn(),
  credentials: vi.fn(),
  settings: vi.fn(),
}))

vi.mock('@/api/epoSubmissions', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/epoSubmissions')>()
  return {
    ...actual,
    epoSubmissionsApi: {
      list: m.list,
      credentials: m.credentials,
      settings: m.settings,
      xmlUrl: (id: number) => `/api/reports/submissions/${id}/xml`,
      artifactDownloadUrl: (id: number) => `/api/documents/${id}`,
    },
  }
})

vi.mock('@/api/auth', () => ({ authApi: {} }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (e: unknown) => String(e) }))
vi.mock('@/security/webauthn', () => ({
  getCredential: vi.fn(),
  isWebAuthnAvailable: () => false,
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), info: vi.fn() }),
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}|${JSON.stringify(params)}` : key,
    locale: { value: 'cs' },
  }),
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    user: { id: 1, totp_enabled: false, passkey_count: 0, mfa_methods: [] },
    canWrite: () => true,
    canRead: () => true,
  }),
}))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplierId: 1 }),
}))

import TaxSubmissions from '@/pages/reports/TaxSubmissions.vue'

function submission(id: number, status: TaxSubmission['status']): TaxSubmission {
  return {
    id,
    form_code: 'dphkh1',
    period_year: 2026,
    period_month: 7,
    period_quarter: null,
    xml_size_bytes: 2048,
    xml_sha256: `hash${id}`,
    validation_status: 'passed',
    validation_errors: [],
    status,
    submitted_at: null,
    submission_ref: null,
    summary: null,
    generated_at: '2026-08-20T10:00:00Z',
    notes: null,
    attempts: [],
    artifacts: [],
    deletable: true,
    delete_blocker: null,
    delete_needs_acknowledgement: false,
  } as TaxSubmission
}

function respond(rows: TaxSubmission[], total: number) {
  return {
    data: rows,
    meta: {
      total,
      limit: 50,
      offset: 0,
      stats: { total: 412, waiting: 7, submitted: 300, problems: 12 },
      form_codes: ['dphdp3', 'dphkh1'],
    },
  }
}

async function mountPage() {
  m.list.mockResolvedValue(respond([submission(1, 'downloaded')], 412))
  const wrapper = mount(TaxSubmissions)
  await flushPromises()
  return wrapper
}

describe('TaxSubmissions.vue — serverový filtr a stránkování', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    m.list.mockReset()
    m.credentials.mockReset()
    m.settings.mockReset()
    m.credentials.mockResolvedValue([])
    m.settings.mockResolvedValue({ epo_environment: 'production' })
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('posílá filtr stavu na server a nefiltruje si ho sám', async () => {
    const wrapper = await mountPage()
    const vm = wrapper.vm as unknown as { statusFilter: string; filtered: TaxSubmission[] }

    // Server vrátí zamítnuté podání, které je v archivu daleko za první stránkou.
    m.list.mockResolvedValue(respond([submission(990, 'rejected')], 1))
    vm.statusFilter = 'rejected'
    await flushPromises()

    expect(m.list).toHaveBeenLastCalledWith(expect.objectContaining({
      status: 'rejected',
      limit: 50,
      offset: 0,
    }))
    expect(vm.filtered.map(item => item.id)).toEqual([990])
  })

  it('hledání jde na server a vrací se na první stránku', async () => {
    const wrapper = await mountPage()
    const vm = wrapper.vm as unknown as { search: string; page: number }
    vm.page = 3
    await flushPromises()

    m.list.mockResolvedValue(respond([submission(2, 'downloaded')], 1))
    vm.search = 'CZ12345'
    await nextTick()
    vi.advanceTimersByTime(400)
    await flushPromises()

    expect(m.list).toHaveBeenLastCalledWith(expect.objectContaining({ q: 'CZ12345', offset: 0 }))
    expect(vm.page).toBe(1)
  })

  it('stránkuje přes offset', async () => {
    const wrapper = await mountPage()
    const vm = wrapper.vm as unknown as { page: number }

    m.list.mockResolvedValue(respond([submission(51, 'downloaded')], 412))
    vm.page = 2
    await flushPromises()

    expect(m.list).toHaveBeenLastCalledWith(expect.objectContaining({ offset: 50, limit: 50 }))
  })

  it('souhrnné dlaždice bere z odpovědi, ne z načtené stránky', async () => {
    const wrapper = await mountPage()
    const vm = wrapper.vm as unknown as {
      stats: { total: number; waiting: number; submitted: number; problems: number }
      total: number
    }
    // Na stránce je jediný řádek — kdyby se dlaždice počítaly z něj, byla by tu 1.
    expect(vm.stats).toEqual({ total: 412, waiting: 7, submitted: 300, problems: 12 })
    expect(vm.total).toBe(412)
  })

  it('nabídka výkazů pochází z archivu, ne z aktuální stránky', async () => {
    const wrapper = await mountPage()
    const vm = wrapper.vm as unknown as { formOptions: Array<{ code: string }> }
    expect(vm.formOptions.map(option => option.code)).toEqual(['dphdp3', 'dphkh1'])
  })
})
