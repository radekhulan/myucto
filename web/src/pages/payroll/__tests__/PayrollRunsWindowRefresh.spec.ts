import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { computed, defineComponent, h, provide, ref } from 'vue'
import type { PayrollRun, PayrollRunReadiness } from '@/api/payroll'
import { windowContextKey } from '@/workspace/windowContext'

/*
 * Okno Mzdových běhů zůstává otevřené v pozadí, zatímco účetní v jiném okně
 * hromadně ukončí dohody nebo schválí docházku. Kontrola před během se pak
 * musí obnovit sama, ne až po F5. A pomalá odpověď za dříve zvolené období
 * nesmí přepsat zvolený měsíc.
 */
const m = vi.hoisted(() => ({
  runs: vi.fn(),
  readiness: vi.fn(),
  runHistory: vi.fn(),
  monthlyChecklist: vi.fn(),
  commandRun: vi.fn(),
  replace: vi.fn(),
}))

vi.mock('@/workspace/supplierContext', () => ({ getWindowSupplierScope: () => ({ supplierId: 4, windowId: 'window-runs' }) }))
vi.mock('vue-router', () => ({
  useRouter: () => ({ push: vi.fn(), replace: m.replace }),
  useRoute: () => ({ query: {} }),
}))
vi.mock('@/api/payroll', () => ({
  payrollApi: {
    runsPage: (period?: string, page?: { limit?: number, offset?: number }) =>
      m.runs(period).then((runs: PayrollRun[]) => ({
        runs,
        total: runs.length,
        limit: page?.limit ?? 12,
        offset: page?.offset ?? 0,
        suggested_payment_date: null,
        readiness: m.readiness(period),
        missing_previous_periods: [],
        advance_start_to: null,
      })),
    runHistory: m.runHistory,
    peopleOptions: vi.fn().mockResolvedValue([]),
    monthlyChecklist: m.monthlyChecklist,
    commandRun: m.commandRun,
  },
}))
vi.mock('@/api/payrollAbsences', () => ({ payrollAbsenceApi: { fillProbableFromRate: vi.fn() } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }) }))
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ t: (key: string) => key, locale: ref('cs-CZ') }),
}))

import PayrollRuns from '@/pages/payroll/PayrollRuns.vue'

function runFor(period: string, overrides: Partial<PayrollRun> = {}): PayrollRun {
  return {
    id: period === '2026-01' ? 1 : 2, supplier_id: 4, office_id: null, period_start: `${period}-01`,
    payment_date: `${period}-27`, status: 'draft', current_revision_no: 0, row_version: 1, revision_id: null,
    revision_no: null, revision_kind: null, revision_status: null, payment_materialization_supported: false,
    can_delete: true, delete_blocker: null, result_snapshot: null, available_commands: [], validations: [],
    ...overrides,
  }
}

function finding(code: string, period = '2026-08'): PayrollRunReadiness {
  return {
    period_start: `${period}-01`, payment_date: `${period}-15`, office_id: null, ready: false, has_findings: true,
    findings: [{
      code, severity: 'warning', impact: 'revision', scope: 'monthly',
      message: 'Nález.', remediation_path: null, count: 20, entities: [],
    }],
  } as PayrollRunReadiness
}

function changedElsewhere() {
  window.dispatchEvent(new CustomEvent('myucto:window-data-changed', { detail: { supplierId: 4, resource: 'payroll' } }))
}

const wrappers: ReturnType<typeof mount>[] = []

function openInWindow(visible = true) {
  const shown = ref(visible)
  const wrapper = mount(defineComponent({
    setup() {
      provide(windowContextKey, {
        active: computed(() => shown.value), visible: computed(() => shown.value),
        paneId: computed(() => shown.value ? 'primary' as const : null), root: document.body, portal: document.body,
      })
      return () => h(PayrollRuns)
    },
  }), { attachTo: document.body })
  wrappers.push(wrapper)
  return { wrapper, shown }
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2026-09-15T12:00:00'))
  vi.clearAllMocks()
  m.runs.mockImplementation((period: string) => Promise.resolve([runFor(period)]))
  m.readiness.mockReturnValue(finding('time_month_not_approved'))
  m.runHistory.mockResolvedValue({ run_id: 2, revisions: [], events: [] })
  m.commandRun.mockResolvedValue({ outcome: null })
  m.monthlyChecklist.mockResolvedValue({
    period: '2026-08', environment: 'production', items: [],
    summary: { total: 0, send: 0, generate: 0, manual: 0, done: 0 },
  })
})
afterEach(() => {
  wrappers.splice(0).forEach(wrapper => wrapper.unmount())
  document.body.innerHTML = ''
  vi.useRealTimers()
})

describe('PayrollRuns v okně pracovní plochy', () => {
  it('po změně mezd v jiném okně kontrolu před během tiše načte znovu', async () => {
    const { wrapper } = openInWindow()
    await flushPromises()
    expect(wrapper.find('[data-testid="run-readiness-time_month_not_approved"]').exists()).toBe(true)
    expect(m.runs).toHaveBeenCalledTimes(1)

    m.readiness.mockReturnValue(null)
    changedElsewhere()
    await flushPromises()

    expect(m.runs).toHaveBeenCalledTimes(2)
    expect(wrapper.find('[data-testid="run-readiness-time_month_not_approved"]').exists()).toBe(false)
  })

  it('okno v pozadí se obnoví až při zobrazení', async () => {
    const { wrapper, shown } = openInWindow(false)
    await flushPromises()
    m.readiness.mockReturnValue(null)
    changedElsewhere()
    await flushPromises()
    expect(m.runs).toHaveBeenCalledTimes(1)

    shown.value = true
    await flushPromises()
    expect(m.runs).toHaveBeenCalledTimes(2)
    expect(wrapper.find('[data-testid="run-readiness-time_month_not_approved"]').exists()).toBe(false)
  })

  it('tichá obnova nechá otevřenou historii běhu a načte ji znovu', async () => {
    m.readiness.mockReturnValue(null)
    const { wrapper } = openInWindow()
    await flushPromises()
    await wrapper.get('[data-testid="payroll-run-2-history-toggle"]').trigger('click')
    await flushPromises()
    expect(m.runHistory).toHaveBeenCalledTimes(1)

    changedElsewhere()
    await flushPromises()

    expect(m.runHistory).toHaveBeenCalledTimes(2)
    expect(wrapper.find('[data-testid="payroll-run-2-history"]').exists()).toBe(true)
  })
})

describe('PayrollRuns a pořadí načtení', () => {
  /*
   * Agentura s DPP: stránka se otevřela na únoru, kontrola před během za únor
   * běžela desítky sekund. Mezitím účetní přepnula na leden a vyžádala jeho
   * opravu. Pozdní únorová odpověď pak pod lednem ukázala kontrolu a kartu
   * února a „Otevřít opravu" zmizelo až do F5.
   */
  it('pozdní odpověď za dříve zvolený měsíc nepřepíše zvolené období', async () => {
    let releaseFebruary: (runs: PayrollRun[]) => void = () => {}
    m.runs.mockImplementation((period: string) => period === '2026-08'
      ? new Promise<PayrollRun[]>((resolve) => { releaseFebruary = resolve })
      : Promise.resolve([runFor(period, { status: 'correction_pending', available_commands: ['reopen'] })]))
    m.readiness.mockImplementation((period: string) => period === '2026-08' ? finding('time_month_not_approved') : null)

    const wrapper = mount(PayrollRuns)
    await flushPromises()

    const input = wrapper.get('input[type="month"]')
    await input.setValue('2026-01')
    await input.trigger('change')
    await flushPromises()
    expect(wrapper.find('[data-testid="payroll-run-1-reopen"]').exists()).toBe(true)

    releaseFebruary([runFor('2026-08')])
    await flushPromises()

    expect(wrapper.find('[data-testid="run-readiness-time_month_not_approved"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="payroll-run-2-reopen"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="payroll-run-1-reopen"]').exists()).toBe(true)
    wrapper.unmount()
  })
})
