import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import PohodaMigration from '../PohodaMigration.vue'

const m = vi.hoisted(() => ({ start: vi.fn(), options: null as any, wizard: null as any }))

vi.mock('@/composables/useMigrationWizard', () => ({
  useMigrationWizard: (options: unknown) => {
    m.options = options
    return m.wizard
  },
}))
vi.mock('@/api/pohoda', () => ({ pohodaApi: {}, isPohodaUploadReady: () => true }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn(), success: vi.fn() }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplier: { ic: '12345678', company_name: 'Syntetická firma' } }) }))
vi.mock('@/components/settings/CompanyProfileBox.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/migration/MoneyS3Protocol.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/exchange/ImportJobProgress.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/ui/ActionBar.vue', () => ({ default: {
  props: ['actions'],
  template: '<div><button v-for="a in actions" :key="a.key" :data-testid="`action-${a.key}`" :disabled="a.disabled" @click="a.run && a.run()">{{ a.label }}</button></div>',
} }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({
  t: (key: string, params?: Record<string, unknown>) => params && Object.keys(params).length ? `${key} ${JSON.stringify(params)}` : key,
  te: () => false, tm: () => [], rt: (value: string) => value,
}) }))

const counts = { journal: 10, opening: 1, first_date: '2026-01-01', last_date: '2026-06-30', later_years: [], issued: 1, purchase: 1, internal: 0, cash: 0, bank: 0, partners: 2 }
const stock = {
  cards: 9, price_lists: 2, date: '2026-06-30',
  warehouses: [
    { code: 'HL', name: 'Hlavní sklad', cards: 6, stocked: 3, without_kind: 3, suggested: 'goods' },
    { code: 'KON', name: 'Konsignační sklad', cards: 1, stocked: 1, without_kind: 1, suggested: 'skip' },
  ],
}

function wizard(extra: Record<string, unknown> = {}, agendaExtra: Record<string, unknown> = {}) {
  return {
    currentStep: ref(2), file: ref(null), job: ref(null), jobMode: ref(null), run: ref(null), jobRuns: ref([]), runs: ref([]),
    busy: ref(false), cancelling: ref(false), confirmed: ref(false), dryRunPassed: ref(false), uploadPercent: ref(null),
    processing: ref(false), processingProgress: ref(null), processingSlow: ref(false), loadError: ref(null), deletingRun: ref(null),
    jobRunning: ref(false), jobSucceeded: ref(false), percent: ref(0),
    differencesAcceptable: ref(false), differences: ref([]), acceptDifferences: ref(false), canImport: ref(false), clearDifferences: () => {},
    upload: ref({
      token: 'synthetic', supplier_ico: '12345678', default_year: 2026, preflight: { 2025: [], 2026: [] },
      agendas: [
        { ico: '12345678', company: '', year: 2025, has_accounting: true, files: [], counts, stock: null },
        { ico: '12345678', company: '', year: 2026, has_accounting: true, files: [], counts, stock, ...agendaExtra },
      ],
      ...extra,
    }),
    canGoTo: () => true, goTo: vi.fn(), onFile: vi.fn(), doUpload: vi.fn(), resetUpload: vi.fn(), retryUpload: vi.fn(), abandonUpload: vi.fn(),
    start: m.start, cancel: vi.fn(), showRun: vi.fn(), deleteRun: vi.fn(), errorMessage: (_e: unknown, fallback: string) => fallback,
  }
}

async function mountPage(extra: Record<string, unknown> = {}, agendaExtra: Record<string, unknown> = {}) {
  m.wizard = wizard(extra, agendaExtra)
  const wrapper = mount(PohodaMigration, {
    props: { system: 'pohoda' },
    global: { stubs: { RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' } } },
  })
  m.options.onReady()
  await flushPromises()
  return wrapper
}

async function dryRun(wrapper: Awaited<ReturnType<typeof mountPage>>) {
  await wrapper.get('[data-testid="action-continue"]').trigger('click')
  await flushPromises()
  await wrapper.get('[data-testid="action-dry"]').trigger('click')
  await flushPromises()
}

const payroll = { employees: 4, months: 10, payslips: 40, first: '2026-01', last: '2026-10', last_overall: '2026-10', open: [] }
function notice(reason: 'unlicensed' | 'module_disabled') {
  return {
    level: 'warning', code: 'payroll_not_transferred', reason, message: 'Export obsahuje mzdy…',
    context: { employees: 4, months: 10, first: '2026-01', last: '2026-10', buy_url: reason === 'unlicensed' ? '/activation/purchase#payroll-addon' : null },
  }
}

beforeEach(() => {
  vi.clearAllMocks()
  m.start.mockResolvedValue(undefined)
})

describe('POHODA: mzdy, které se nepřevedou', () => {
  it('licence bez mzdového doplňku: výrazné upozornění s počtem zaměstnanců, měsíci a nákupem doplňku, převod nezastaví', async () => {
    const wrapper = await mountPage({ payroll_notice: notice('unlicensed') }, { has_payroll: true, payroll })
    const box = wrapper.get('[data-testid="migration-payroll-notice"]')
    expect(box.attributes('role')).toBe('alert')
    expect(box.get('[data-testid="migration-payroll-notice-summary"]').text()).toContain('"employees":4')
    expect(box.get('[data-testid="migration-payroll-notice-summary"]').text()).toContain('"months":10')
    expect(box.text()).toContain('migration_payroll_notice.period_range')
    expect(box.text()).toMatch(/01\/2026.*10\/2026/)
    expect(box.text()).toContain('migration_payroll_notice.reason.unlicensed')
    expect(box.text()).toContain('migration_payroll_notice.accounting')
    expect(box.get('[data-testid="migration-payroll-notice-buy"]').attributes('data-to')).toContain('/activation/purchase#payroll-addon')
    // Upozornění je hned za přehledem agend, před výběrem roků.
    const html = wrapper.html()
    expect(html.indexOf('migration-payroll-notice')).toBeLessThan(html.indexOf('pohoda-years'))
    // Převod mezd se nenabízí, když ho licence nedovolí; účetnictví jde převést dál.
    expect(wrapper.find('[data-testid="pohoda-payroll-wizard-open"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="action-continue"]').attributes('disabled')).toBeUndefined()
    await dryRun(wrapper)
    expect(m.start).toHaveBeenCalledWith('dry_run', expect.objectContaining({ kind: 'accounting' }))
  })

  it('vypnutý modul Mzdy: upozornění vede do nastavení firmy', async () => {
    const wrapper = await mountPage({ payroll_notice: notice('module_disabled') }, { has_payroll: true, payroll })
    const box = wrapper.get('[data-testid="migration-payroll-notice"]')
    expect(box.text()).toContain('migration_payroll_notice.reason.module_disabled')
    expect(box.get('[data-testid="migration-payroll-notice-settings"]').attributes('data-to')).toContain('"tab":"accounting"')
    expect(box.find('[data-testid="migration-payroll-notice-buy"]').exists()).toBe(false)
  })

  it('mzdy, které převzít jdou: bez upozornění, s odkazem na převod mezd', async () => {
    const wrapper = await mountPage({}, { has_payroll: true, payroll })
    expect(wrapper.find('[data-testid="migration-payroll-notice"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="pohoda-payroll-wizard-open"]').attributes('data-to')).toContain('imports-pamica')
  })

  it('export bez mezd: žádné upozornění ani odkaz', async () => {
    const wrapper = await mountPage()
    expect(wrapper.find('[data-testid="migration-payroll-notice"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="pohoda-payroll-wizard-hint"]').exists()).toBe(false)
  })
})

describe('POHODA: sklad u firmy s vypnutým skladem', () => {
  it('nabídne převod skladu s poznámkou, že ho ostrý převod zapne', async () => {
    const wrapper = await mountPage({ stock_module: { enabled: false, can_enable: true } })
    expect(wrapper.get('[data-testid="pohoda-stock-will-enable"]').text()).toContain('pohoda.stock.module_will_enable')
    await dryRun(wrapper)
    expect(m.start).toHaveBeenCalledWith('dry_run', expect.objectContaining({ stock: { warehouses: { HL: 'goods', KON: 'skip' } } }))
  })

  it('bez licence komerčních modulů sklad jen ohlásí a neposílá', async () => {
    const wrapper = await mountPage({ stock_module: { enabled: false, can_enable: false } })
    expect(wrapper.get('[data-testid="pohoda-stock-unavailable"]').text()).toContain('pohoda.stock.module_unavailable')
    expect((wrapper.get('[data-testid="pohoda-stock-enabled"]').element as HTMLInputElement).disabled).toBe(true)
    await dryRun(wrapper)
    expect(m.start.mock.calls[0][1]).not.toHaveProperty('stock')
  })

  it('zapnutý sklad poznámku neukazuje', async () => {
    const wrapper = await mountPage({ stock_module: { enabled: true, can_enable: true } })
    expect(wrapper.find('[data-testid="pohoda-stock-will-enable"]').exists()).toBe(false)
  })
})
