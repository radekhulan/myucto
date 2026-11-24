import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { nextTick, reactive } from 'vue'
import { routeLocationKey, routerKey, type LocationQuery } from 'vue-router'
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
      pdfUrl: (id: number) => `/api/reports/submissions/${id}/pdf`,
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

const route = reactive({ query: {} as LocationQuery })
const router = { replace: vi.fn(async ({ query }: { query: LocationQuery }) => { route.query = query }) }
const provide = { [routeLocationKey as symbol]: route, [routerKey as symbol]: router }

async function mountPage(query: LocationQuery = {}, rows = [submission(1, 'downloaded')]) {
  route.query = query
  m.list.mockResolvedValue(respond(rows, 412))
  const wrapper = mount(TaxSubmissions, { global: { provide, stubs: { RouterLink: true } } })
  await flushPromises()
  return wrapper
}

function expectOpenRow(wrapper: VueWrapper, openIndex: number | null) {
  const rows = wrapper.findAll('tbody > tr')
  const cards = wrapper.findAll('.md\\:hidden > button')
  expect(cards).toHaveLength(rows.length)
  rows.forEach((row, index) => {
    const open = index === openIndex
    expect(row.classes().includes('bg-primary-50')).toBe(open)
    expect(row.classes().includes('hover:bg-primary-50')).toBe(open)
    expect(row.classes().includes('hover:bg-neutral-50')).toBe(!open)
    expect(row.find('td').classes().includes('shadow-[inset_3px_0_0_var(--color-primary-500)]')).toBe(open)
    const card = cards[index]!
    expect(card.classes().includes('bg-primary-50')).toBe(open)
    expect(card.classes().includes('bg-surface')).toBe(!open)
    expect(card.classes().includes('border-primary-500')).toBe(open)
    expect(card.classes().includes('border-neutral-200')).toBe(!open)
    expect(card.attributes('aria-expanded')).toBe(String(open))
  })
}

describe('TaxSubmissions.vue — serverový filtr a stránkování', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    route.query = {}
    router.replace.mockClear()
    m.list.mockReset()
    m.credentials.mockReset()
    m.settings.mockReset()
    m.credentials.mockResolvedValue([])
    m.settings.mockResolvedValue({ epo_environment: 'production' })
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('zvýraznění v tabulce i kartách sleduje otevřený detail a po zavření zmizí', async () => {
    const wrapper = await mountPage({}, [submission(1, 'downloaded'), submission(2, 'downloaded')])
    expect(wrapper.find('section').exists()).toBe(false)

    await wrapper.findAll('tbody > tr')[0]!.trigger('click')
    expectOpenRow(wrapper, 0)
    expect(wrapper.find('section').text()).toContain('reports.submissions.snapshot #1')

    await wrapper.findAll('.md\\:hidden > button')[1]!.trigger('click')
    expectOpenRow(wrapper, 1)
    expect(wrapper.find('section').text()).toContain('reports.submissions.snapshot #2')

    await wrapper.findAll('.md\\:hidden > button')[1]!.trigger('click')
    expectOpenRow(wrapper, null)
    expect(wrapper.find('section').exists()).toBe(false)
    wrapper.unmount()
  })

  it('otevře konkrétní podání z kalendáře i mimo první stránku archivu', async () => {
    const wrapper = await mountPage({ submission_id: '990' }, [submission(990, 'downloaded')])
    expect((wrapper.vm as unknown as { expandedId: number }).expandedId).toBe(990)
    expectOpenRow(wrapper, 0)
    expect(wrapper.find('input[type=search]').exists()).toBe(false)
    expect(m.list).toHaveBeenLastCalledWith(expect.objectContaining({ submission_id: 990, offset: 0 }))
    m.list.mockResolvedValue(respond([submission(991, 'submitted')], 1))
    route.query = { submission_id: '991' }
    await flushPromises()
    const vm = wrapper.vm as unknown as { expandedId: number; selected: TaxSubmission }
    expect(m.list).toHaveBeenLastCalledWith(expect.objectContaining({ submission_id: 991, offset: 0 }))
    expect(vm.expandedId).toBe(991)
    expect(vm.selected.id).toBe(991)
    expectOpenRow(wrapper, 0)
    wrapper.unmount()
  })

  it('z cíleného podání umožní zobrazit celý archiv', async () => {
    const wrapper = await mountPage({ submission_id: '990', keep: 'value' })
    const showAll = wrapper.findAll('button').find(button => button.text() === 'reports.submissions.show_all')
    expect(showAll).toBeDefined()
    await showAll!.trigger('click')
    await flushPromises()
    expect(route.query).toEqual({ keep: 'value' })
    expect(m.list).toHaveBeenLastCalledWith(expect.objectContaining({ submission_id: undefined }))
    wrapper.unmount()
  })

  it('nepřenáší query cíleného daňového podání do vloženého mzdového archivu', async () => {
    route.query = { submission_id: '990' }
    m.list.mockResolvedValue(respond([], 0))
    const wrapper = mount(TaxSubmissions, { props: { embedded: true, scope: 'payroll' }, global: { provide, stubs: { RouterLink: true } } })
    await flushPromises()
    expect(m.list).toHaveBeenLastCalledWith(expect.objectContaining({ scope: 'payroll' }))
    expect(m.list.mock.lastCall?.[0].submission_id).toBeUndefined()
    expect(wrapper.findAll('button').some(button => button.text() === 'reports.submissions.show_all')).toBe(false)
    wrapper.unmount()
  })

  it.each(['0', '-1', '2oops', '1.2', ['1', '2'], '9007199254740992'])('ignoruje neplatné id podání %j', async id => {
    const wrapper = await mountPage({ submission_id: id })
    expect(m.list.mock.lastCall?.[0].submission_id).toBeUndefined()
    wrapper.unmount()
  })

  it('Daně → Podání neukazují mzdová vyúčtování, záložka pod Mzdami jen je', async () => {
    await mountPage()
    expect(m.list).toHaveBeenLastCalledWith(expect.objectContaining({ scope: 'tax' }))

    m.list.mockResolvedValue(respond([submission(3, 'downloaded')], 1))
    const payroll = mount(TaxSubmissions, { props: { embedded: true, scope: 'payroll' } })
    await flushPromises()
    expect(m.list).toHaveBeenLastCalledWith(expect.objectContaining({ scope: 'payroll' }))
    payroll.unmount()
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
