import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import MoneyS3Migration from '../MoneyS3Migration.vue'

const m = vi.hoisted(() => ({ start: vi.fn(), options: null as any, wizard: null as any }))

vi.mock('@/composables/useMigrationWizard', () => ({
  useMigrationWizard: (options: unknown) => {
    m.options = options
    return m.wizard
  },
}))
vi.mock('@/api/moneyS3', () => ({ moneyS3Api: {}, isUploadReady: () => true }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn(), success: vi.fn() }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true }) }))
vi.mock('@/components/settings/CompanyProfileBox.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/migration/MoneyS3Protocol.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/migration/MoneyS3BatchWizard.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/migration/MigrationDifferences.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/migration/MigrationNonprofitNotice.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/exchange/ImportJobProgress.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/ui/ActionBar.vue', () => ({ default: {
  props: ['actions'],
  template: '<div><button v-for="a in actions" :key="a.key" :data-testid="`action-${a.key}`" :disabled="a.disabled" @click="a.run && a.run()">{{ a.label }}</button></div>',
} }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({
  t: (key: string, params?: Record<string, unknown>) => params && Object.keys(params).length ? `${key} ${JSON.stringify(params)}` : key,
  te: () => false, tm: () => [], rt: (value: string) => value, locale: ref('cs'),
}) }))

const year = (dir: string, fiscal: number) => ({ dir, money_year: fiscal, fiscal_year: fiscal, accounting_mode: 'double_entry', journal_rows: 10 })

function wizard(preflight: unknown[], taxRecords = false) {
  return {
    currentStep: ref(2), file: ref(null), job: ref(null), jobMode: ref(null), run: ref(null), runs: ref([]),
    busy: ref(false), cancelling: ref(false), confirmed: ref(false), dryRunPassed: ref(false), uploadPercent: ref(null),
    processing: ref(false), deletingRun: ref(null), jobRunning: ref(false), percent: ref(0),
    differencesAcceptable: ref(false), differences: ref([]), acceptDifferences: ref(false), canImport: ref(false),
    upload: ref({
      token: 'synthetic', file_name: 'agenda.lz', sha256: '', uploaded_at: '', uploaded_by: 1, reports: [], preflight,
      agenda: {
        name: 'Vzorová účetní s.r.o.', ico: '12345679', dic: '', street: '', city: '', zip: '', version: '26.600', version_verified: true,
        backup_at: '', partners: 2, warnings: [], tax_records: taxRecords,
        years: [year('ROK.001', 2025), year('ROK.002', 2026), year('ROK.003', 2027)],
      },
    }),
    canGoTo: () => true, goTo: vi.fn(), onFile: vi.fn(), doUpload: vi.fn(), resetUpload: vi.fn(),
    start: m.start, cancel: vi.fn(), showRun: vi.fn(), deleteRun: vi.fn(), errorMessage: (_e: unknown, fallback: string) => fallback,
  }
}

async function mountPage(preflight: unknown[] = [], taxRecords = false) {
  m.wizard = wizard(preflight, taxRecords)
  const wrapper = mount(MoneyS3Migration, { global: { stubs: { RouterLink: { props: ['to'], template: '<a><slot /></a>' } } } })
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

describe('Money S3: volba „Převést do roku"', () => {
  it('bez volby posílá automatický rok (null) a ukáže výchozí rok z kontroly před převodem', async () => {
    const wrapper = await mountPage([{ level: 'warning', code: 'to_year_default', message: 'Rok 2027 už má vlastní zápisy.', context: { to_year: 2026, own_years: [2027] } }])
    const select = wrapper.get('[data-testid="to-year"]')
    expect(select.findAll('option')[0]!.text()).toBe('money_s3.to_year_auto_year {"year":2026}')
    await dryRun(wrapper)
    expect(m.start).toHaveBeenCalledWith('dry_run', expect.objectContaining({ to_year: null, from_year: null }))
  })

  it('výslovně zvolený rok jde do startu převodu a nabízí jen roky od zvoleného „od roku"', async () => {
    const wrapper = await mountPage()
    await wrapper.get('[data-testid="from-year"]').setValue('2026')
    const options = wrapper.get('[data-testid="to-year"]').findAll('option').map(o => o.text())
    expect(options).toHaveLength(3)
    expect(options[0]).toBe('money_s3.to_year_auto')
    await wrapper.get('[data-testid="to-year"]').setValue('2026')
    await dryRun(wrapper)
    expect(m.start).toHaveBeenCalledWith('dry_run', expect.objectContaining({ from_year: 2026, to_year: 2026 }))
  })

  it('obnovený job bez volby „Převést do roku" (starší parametry) zůstane na automatice', async () => {
    const wrapper = await mountPage()
    m.options.restoreParams({ mode: 'dry_run', close_history: true, first_period_start: null, from_year: 2025 })
    await flushPromises()
    await dryRun(wrapper)
    expect(m.start).toHaveBeenCalledWith('dry_run', expect.objectContaining({ from_year: 2025, to_year: null }))
  })

  it('daňová evidence volbu nemá', async () => {
    const wrapper = await mountPage([], true)
    expect(wrapper.find('[data-testid="to-year"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="opening-only-next"]').exists()).toBe(false)
  })

  it('počáteční stavy dalšího roku jsou ve výchozím stavu vypnuté a zaškrtnutím jdou do startu', async () => {
    await dryRun(await mountPage([]))
    expect(m.start).toHaveBeenLastCalledWith('dry_run', expect.objectContaining({ opening_only_next: false }))
    const wrapper = await mountPage([])
    await wrapper.get('[data-testid="opening-only-next"]').setValue(true)
    await dryRun(wrapper)
    expect(m.start).toHaveBeenLastCalledWith('dry_run', expect.objectContaining({ opening_only_next: true }))
  })
})
