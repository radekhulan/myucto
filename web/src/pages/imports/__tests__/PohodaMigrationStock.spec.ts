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

function wizard() {
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
        { ico: '12345678', company: '', year: 2026, has_accounting: true, files: [], counts, stock },
      ],
    }),
    canGoTo: () => true, goTo: vi.fn(), onFile: vi.fn(), doUpload: vi.fn(), resetUpload: vi.fn(), retryUpload: vi.fn(), abandonUpload: vi.fn(),
    start: m.start, cancel: vi.fn(), showRun: vi.fn(), deleteRun: vi.fn(), errorMessage: (_e: unknown, fallback: string) => fallback,
  }
}

async function mountPage() {
  m.wizard = wizard()
  const wrapper = mount(PohodaMigration, {
    props: { system: 'pohoda' },
    global: { stubs: { RouterLink: { props: ['to'], template: '<a><slot /></a>' } } },
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

beforeEach(() => {
  vi.clearAllMocks()
  m.start.mockResolvedValue(undefined)
})

describe('POHODA: převod skladu', () => {
  it('předvolí konsignační sklad jako nepřevádět a pošle volbu po skladech', async () => {
    const wrapper = await mountPage()
    expect(wrapper.get('[data-testid="pohoda-stock"]').text()).toContain('"date":"2026-06-30"')
    expect((wrapper.get('[data-testid="pohoda-stock-choice-KON"]').element as HTMLSelectElement).value).toBe('skip')
    await wrapper.get('[data-testid="pohoda-stock-choice-HL"]').setValue('material')
    await dryRun(wrapper)
    expect(m.start).toHaveBeenCalledWith('dry_run', expect.objectContaining({ stock: { warehouses: { HL: 'material', KON: 'skip' } } }))
  })

  it('bez zaškrtnutí sklad neposílá', async () => {
    const wrapper = await mountPage()
    await wrapper.get('[data-testid="pohoda-stock-enabled"]').setValue(false)
    await dryRun(wrapper)
    expect(m.start.mock.calls[0][1]).not.toHaveProperty('stock')
  })

  it('obnovení náhledu po doběhu zkoušky ji nezneplatní, změna volby skladu ano', async () => {
    const wrapper = await mountPage()
    m.wizard.dryRunPassed.value = true
    m.wizard.upload.value = JSON.parse(JSON.stringify(m.wizard.upload.value))
    await flushPromises()
    expect(m.wizard.dryRunPassed.value).toBe(true)
    await wrapper.get('[data-testid="pohoda-stock-choice-HL"]').setValue('material')
    await flushPromises()
    expect(m.wizard.dryRunPassed.value).toBe(false)
  })

  it('bez nejnovější agendy ve výběru sklad nenabízí', async () => {
    const wrapper = await mountPage()
    await wrapper.get('[data-testid="pohoda-year-2026"]').setValue(false)
    await flushPromises()
    expect(wrapper.find('[data-testid="pohoda-stock"]').exists()).toBe(false)
  })
})
