import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import PremierMigration from '../PremierMigration.vue'
import { migrationYearLabel } from '@/utils/migrationYear'

const m = vi.hoisted(() => ({ start: vi.fn(), options: null as any, wizard: null as any }))

vi.mock('@/composables/useMigrationWizard', () => ({
  useMigrationWizard: (options: unknown) => {
    m.options = options
    return m.wizard
  },
}))
vi.mock('@/api/premier', () => ({ premierApi: {}, isPremierUploadReady: () => true }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplier: { accounting_framework: 'cz_500', entity_profile: 'unknown' } }) }))
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

const agenda = { dir: '.', ico: '12345678', dic: 'CZ12345678', company: 'Syntetická firma', has_accounting: true, has_payroll: false }

function wizard(fiscal: boolean) {
  const agendas = fiscal
    // Firma od srpna založená v únoru 2025: hospodářský rok 2024 nemá kalendářní rok 2024.
    ? [{ ...agenda, year: 2024, entries: 3, period_start: '2024-08-01', period_end: '2025-07-31' }, { ...agenda, year: 2025, entries: 2, period_start: '2025-08-01', period_end: '2026-07-31' }]
    : [{ ...agenda, year: 2025, entries: 4 }, { ...agenda, year: 2026, entries: 1 }]
  return {
    currentStep: ref(2), file: ref(null), job: ref(null), jobMode: ref(null), run: ref(null), jobRuns: ref([]), runs: ref([]),
    busy: ref(false), cancelling: ref(false), confirmed: ref(false), dryRunPassed: ref(false), uploadPercent: ref(null),
    processing: ref(false), deletingRun: ref(null), jobRunning: ref(false), jobSucceeded: ref(false), percent: ref(0),
    differencesAcceptable: ref(false), differences: ref([]), acceptDifferences: ref(false), canImport: ref(false), clearDifferences: () => {},
    upload: ref({
      token: 'synthetic', supplier_ico: '12345678', default_year: agendas[1]!.year,
      preflight: Object.fromEntries(agendas.map(a => [String(a.year), []])), agendas,
    }),
    canGoTo: () => true, goTo: vi.fn(), onFile: vi.fn(), doUpload: vi.fn(), resetUpload: vi.fn(),
    start: m.start, cancel: vi.fn(), showRun: vi.fn(), deleteRun: vi.fn(),
  }
}

async function mountPage(fiscal: boolean) {
  m.wizard = wizard(fiscal)
  const wrapper = mount(PremierMigration, {
    global: { stubs: { RouterLink: { props: ['to'], template: '<a><slot /></a>' }, 'i18n-t': { template: '<span />' } } },
  })
  m.options.onReady()
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  vi.clearAllMocks()
  m.start.mockResolvedValue(undefined)
})

describe('PREMIER: roky průvodce podle účetního roku firmy', () => {
  it('hospodářský rok: účetní roky firmy s hranicemi období, výběr jde rokem firmy', async () => {
    const wrapper = await mountPage(true)
    const first = migrationYearLabel(2024, { period_start: '2024-08-01', period_end: '2025-07-31' })
    expect(first).toContain('2024 (')
    expect(wrapper.get('[data-testid="premier-year-label-2024"]').text()).toBe(first)
    expect(wrapper.get('[data-testid="premier-years-selected"]').text()).toContain(first)
    await wrapper.get('[data-testid="premier-year-2025"]').setValue(false)
    await wrapper.get('[data-testid="action-continue"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="action-dry"]').trigger('click')
    await flushPromises()
    expect(m.start).toHaveBeenCalledWith('dry_run', { mode: 'dry_run', years: [2024] })
  })

  it('kalendářní firma: roky beze změny', async () => {
    const wrapper = await mountPage(false)
    expect(wrapper.get('[data-testid="premier-year-label-2025"]').text()).toBe('2025')
    expect(wrapper.get('[data-testid="premier-years-selected"]').text()).toBe('2025, 2026')
    expect(wrapper.find('[data-testid="migration-nonprofit-notice"]').exists()).toBe(false)
  })

  it('neziskovka podle náhledu převodu: výrazné upozornění', async () => {
    m.wizard = wizard(false)
    m.wizard.upload.value.nonprofit_notice = { level: 'warning', code: 'migration_nonprofit_untested', message: '' }
    const wrapper = mount(PremierMigration, {
      global: { stubs: { RouterLink: { props: ['to'], template: '<a><slot /></a>' }, 'i18n-t': { template: '<span />' } } },
    })
    await flushPromises()
    expect(wrapper.get('[data-testid="migration-nonprofit-notice"]').text()).toContain('migration_nonprofit_notice.title')
  })
})
