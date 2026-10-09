import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import PohodaMigration from '../PohodaMigration.vue'

/**
 * Náhled průvodce (kontroly před převodem) závisí na stavu firmy, který převod mění. Po
 * doběhnutí úlohy ho průvodce načte znovu: ostrý převod roku 2025 nastavil začátek vedení
 * mezd a teprve nový náhled roku 2026 nese volbu „Posunout začátek".
 */
const m = vi.hoisted(() => ({
  show: vi.fn(), start: vi.fn(), runs: vi.fn(), run: vi.fn(), fetchImportJob: vi.fn(),
}))

vi.mock('@/api/pohoda', () => ({
  pohodaApi: { show: m.show, start: m.start, runs: m.runs, run: m.run, uploadChunked: vi.fn() },
  isPohodaUploadReady: (u: { status?: string }) => u.status === 'ready',
}))
vi.mock('@/api/imports', () => ({ fetchImportJob: m.fetchImportJob, cancelImportJob: vi.fn() }))
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

const agenda = (year: number) => ({ ico: '12345678', company: '', year, dir: `12345678_${year}`, has_payroll: true, has_accounting: false, files: [], counts: {}, payroll: { employees: 2, months: 2 } })
function upload(preflight: Record<string, unknown[]>) {
  return { token: 'tok', status: 'ready', supplier_ico: '12345678', default_year: 2026, preflight: {}, agendas: [agenda(2025), agenda(2026)], payroll_preflight: preflight }
}
const willSet = (year: number, start: string, last: string) => ({ level: 'info', code: 'payroll_start_will_set', message: `Synthetic ${year}`, context: { start_period: start, last } })
const behind = { level: 'warning', code: 'payroll_start_behind_takeover', message: 'Synthetic behind', context: { from: '2025-03', to: '2026-03', last: '2026-02' } }

async function mountPage() {
  const wrapper = mount(PohodaMigration, {
    props: { system: 'pamica' },
    global: { stubs: { RouterLink: { props: ['to'], template: '<a><slot /></a>' }, 'i18n-t': { template: '<span />' } } },
  })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  vi.clearAllMocks()
  sessionStorage.clear()
  sessionStorage.setItem('myucto.pamica.token', 'tok')
  m.runs.mockResolvedValue({ items: [{ id: 41, job_id: 9, mode: 'import', status: 'failed', agenda_year: 2026, kind: 'payroll' }] })
  m.run.mockResolvedValue({ id: 41, job_id: 9, mode: 'import', status: 'failed', agenda_year: 2026, kind: 'payroll', protocol: { steps: [] } })
  m.start.mockResolvedValue({ job_id: 9, status: 'queued', mode: 'dry_run' })
  m.fetchImportJob.mockResolvedValue({ id: 9, status: 'failed', processed: 1, total_items: 1, last_error: null })
})

describe('PAMICA: náhled po doběhnutí úlohy', () => {
  it('víc roků bez začátku ukáže začátek podle posledního vybraného roku', async () => {
    m.show.mockResolvedValue(upload({ 2025: [willSet(2025, '2025-03', '2025-02')], 2026: [willSet(2026, '2026-03', '2026-02')] }))
    const wrapper = await mountPage()

    expect(wrapper.get('[data-testid="pohoda-start-will-set"]').text()).toContain('"period":"03/2026"')
    expect(wrapper.text()).not.toContain('Synthetic 2025')
  })

  it('po doběhnutí úlohy načte náhled znovu a nabídne posun začátku', async () => {
    m.show
      .mockResolvedValueOnce(upload({ 2025: [willSet(2025, '2025-03', '2025-02')], 2026: [willSet(2026, '2026-03', '2026-02')] }))
      .mockResolvedValue(upload({ 2025: [], 2026: [behind] }))
    const wrapper = await mountPage()
    expect(wrapper.find('[data-testid="pohoda-start-decision"]').exists()).toBe(false)

    await wrapper.get('[data-testid="action-continue"]').trigger('click')
    await wrapper.get('[data-testid="action-dry"]').trigger('click')
    await flushPromises()

    expect(m.show).toHaveBeenCalledTimes(2)
    await wrapper.findAll('ol button')[1].trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-testid="pohoda-start-decision"]').text()).toContain('"period":"03/2026"')
    expect(wrapper.find('[data-testid="pohoda-start-will-set"]').exists()).toBe(false)
  })
})
