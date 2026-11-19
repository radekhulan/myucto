import { describe, expect, it, vi } from 'vitest'
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

// Přehled nahraný dřív počet zakázek nenese.
const counts = { journal: 10, opening: 1, first_date: '2026-01-01', last_date: '2026-06-30', later_years: [], issued: 1, purchase: 1, internal: 0, cash: 0, bank: 0, partners: 2 }

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
        { ico: '12345678', company: '', year: 2026, has_accounting: true, files: [], counts: { ...counts, contracts: 4 }, stock: null },
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

describe('POHODA: zakázky v náhledu exportu', () => {
  it('ukáže počet zakázek agendy, starší přehled bez počtu pomlčkou', async () => {
    const wrapper = await mountPage()
    expect(wrapper.text()).toContain('pohoda.col_contracts')
    expect(wrapper.findAll('[data-testid="pohoda-contracts-cell"]').map((cell) => cell.text())).toEqual(['—', '4'])
  })
})
