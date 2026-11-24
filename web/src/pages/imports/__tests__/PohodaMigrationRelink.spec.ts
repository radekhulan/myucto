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
const stock = { cards: 1, price_lists: 0, date: '2026-06-30', warehouses: [{ code: 'HL', name: 'Hlavní sklad', cards: 1, stocked: 1, without_kind: 1, suggested: 'goods' }] }
const ownJournal = { level: 'error', code: 'journal_not_empty', message: 'Období obsahuje zápisy z MyÚčtu.' }
const keepsJournal = { level: 'info', code: 'relink_keeps_own_journal', message: 'Opravný převod zápisy nechá.' }

function wizard(converted: boolean) {
  return {
    currentStep: ref(2), file: ref(null), job: ref(null), jobMode: ref(null), run: ref(null), jobRuns: ref([]), runs: ref([]),
    busy: ref(false), cancelling: ref(false), confirmed: ref(false), dryRunPassed: ref(false), uploadPercent: ref(null),
    processing: ref(false), processingProgress: ref(null), processingSlow: ref(false), loadError: ref(null), deletingRun: ref(null),
    jobRunning: ref(false), jobSucceeded: ref(false), percent: ref(0),
    differencesAcceptable: ref(false), differences: ref([]), acceptDifferences: ref(false), canImport: ref(false), clearDifferences: () => {},
    upload: ref({
      token: 'synthetic', supplier_ico: '12345678', default_year: 2026,
      preflight: { 2026: [ownJournal] },
      pohoda_converted: converted,
      relink_preflight: converted ? { 2026: [keepsJournal] } : {},
      agendas: [{ ico: '12345678', company: '', year: 2026, has_accounting: true, files: [], counts, stock }],
    }),
    canGoTo: () => true, goTo: vi.fn(), onFile: vi.fn(), doUpload: vi.fn(), resetUpload: vi.fn(), retryUpload: vi.fn(), abandonUpload: vi.fn(),
    start: m.start, cancel: vi.fn(), showRun: vi.fn(), deleteRun: vi.fn(), errorMessage: (_e: unknown, fallback: string) => fallback,
  }
}

async function mountPage(converted = true) {
  m.wizard = wizard(converted)
  const wrapper = mount(PohodaMigration, {
    props: { system: 'pohoda' },
    global: { stubs: { RouterLink: { props: ['to'], template: '<a><slot /></a>' } } },
  })
  m.options.onReady()
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  vi.clearAllMocks()
  m.start.mockResolvedValue(undefined)
})

describe('POHODA: opravný převod vazeb', () => {
  it('firmě bez převodu z POHODY volbu nenabízí', async () => {
    const wrapper = await mountPage(false)
    expect(wrapper.find('[data-testid="pohoda-relink"]').exists()).toBe(false)
  })

  it('převedené firmě ukáže kontrolu opravného převodu, schová sklad a pošle relink_only do zkoušky nanečisto', async () => {
    const wrapper = await mountPage()
    expect(wrapper.get('[data-testid="pohoda-relink"]').text()).toContain('pohoda.relink.option')
    expect(wrapper.get('[data-testid="pohoda-preflight-2026"]').text()).toContain(ownJournal.message)
    expect((wrapper.get('[data-testid="action-continue"]').element as HTMLButtonElement).disabled).toBe(true)

    await wrapper.get('[data-testid="pohoda-relink-only"]').setValue(true)
    await flushPromises()
    expect(wrapper.get('[data-testid="pohoda-preflight-2026"]').text()).toContain(keepsJournal.message)
    expect(wrapper.get('[data-testid="pohoda-preflight-2026"]').text()).not.toContain(ownJournal.message)
    expect(wrapper.find('[data-testid="pohoda-stock"]').exists()).toBe(false)

    await wrapper.get('[data-testid="action-continue"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-testid="pohoda-relink-mode"]').text()).toContain('pohoda.relink.mode_note')
    await wrapper.get('[data-testid="action-dry"]').trigger('click')
    await flushPromises()
    expect(m.start).toHaveBeenCalledWith('dry_run', expect.objectContaining({ relink_only: true }))
    expect(m.start.mock.calls[0][1]).not.toHaveProperty('stock')
  })

  it('změna volby zneplatní zkoušku nanečisto a bez volby relink_only neposílá', async () => {
    const wrapper = await mountPage()
    m.wizard.dryRunPassed.value = true
    await wrapper.get('[data-testid="pohoda-relink-only"]').setValue(true)
    await flushPromises()
    expect(m.wizard.dryRunPassed.value).toBe(false)

    await wrapper.get('[data-testid="pohoda-relink-only"]').setValue(false)
    m.wizard.upload.value = { ...m.wizard.upload.value, preflight: { 2026: [] } }
    await flushPromises()
    await wrapper.get('[data-testid="action-continue"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="action-dry"]').trigger('click')
    await flushPromises()
    expect(m.start.mock.calls[0][1]).not.toHaveProperty('relink_only')
  })

  it('obnoví volbu z parametrů běžícího jobu', async () => {
    const wrapper = await mountPage()
    m.options.restoreParams({ mode: 'dry_run', years: [2026], kind: 'accounting', relink_only: true })
    await flushPromises()
    expect((wrapper.get('[data-testid="pohoda-relink-only"]').element as HTMLInputElement).checked).toBe(true)
  })
})
