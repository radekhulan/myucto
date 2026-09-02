import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  status: vi.fn(),
  closeYear: vi.fn(),
  reopenYear: vi.fn(),
  canWrite: vi.fn((permission: string) => permission === 'payroll.approve'),
  routeQuery: {} as Record<string, string | undefined>,
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    yearCloseStatus: m.status,
    closeYear: m.closeYear,
    reopenYear: m.reopenYear,
  },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: m.canWrite }),
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn() }),
}))
// `useFormat` (sdílené formátování) táhne @/i18n, které volá skutečné
// `createI18n` — továrna proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, parameters?: Record<string, string | number>) =>
      parameters ? `${key} ${Object.values(parameters).join(' ')}` : key,
    te: (key: string) => !key.endsWith('.exotic_new_blocker'),
    locale: { value: 'cs' },
  }),
}))
vi.mock('vue-router', () => ({
  RouterLink: {
    props: ['to'],
    template: '<a><slot /></a>',
  },
  // Panel čte rok z adresy (proklik „rok je uzavřený" z jiné agendy).
  useRoute: () => ({ query: m.routeQuery }),
}))

import PayrollYearClosePanel from '@/pages/payroll/PayrollYearClosePanel.vue'

function openYear(blockers: unknown[]) {
  return {
    closure: { year: 2026, status: 'open', closed_at: null, row_version: 1 },
    blockers,
  }
}

describe('PayrollYearClosePanel', () => {
  beforeEach(() => {
    m.canWrite.mockImplementation((permission: string) => permission === 'payroll.approve')
    m.status.mockResolvedValue(openYear([
      { code: 'open_submissions', count: 5 },
      { code: 'missing_months', months: ['2026-03', '2026-04'] },
    ]))
  })

  /**
   * Překážka bez odkazu je slepá ulička: uzávěrka se dělá jednou za rok,
   * takže si nikdo nepamatuje, ve které z deseti záložek se řeší.
   */
  it('u každé známé překážky nabídne cestu, kde se řeší', async () => {
    const wrapper = mount(PayrollYearClosePanel, { props: { initialYear: 2026 } })
    await flushPromises()

    expect(wrapper.find('[data-test="year-close-blocker-link-open_submissions"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="year-close-blocker-link-missing_months"]').exists()).toBe(true)
  })

  /** „2026-03" vedle „01.03.2026" ve zbytku appky vypadá jako useknuté datum. */
  it('chybějící období vypíše česky, ne jako YYYY-MM', async () => {
    const wrapper = mount(PayrollYearClosePanel, { props: { initialYear: 2026 } })
    await flushPromises()

    const blocker = wrapper.get('[data-test="year-close-blocker-missing_months"]').text()
    expect(blocker).toContain('březen 2026')
    expect(blocker).toContain('duben 2026')
    expect(blocker).not.toContain('2026-03')
  })

  /** Nový kód ze serveru se nesmí vypsat jako překladový klíč. */
  it('neznámou překážku pojmenuje větou, ne klíčem překladu', async () => {
    m.status.mockResolvedValue(openYear([{ code: 'exotic_new_blocker', count: 2 }]))
    const wrapper = mount(PayrollYearClosePanel, { props: { initialYear: 2026 } })
    await flushPromises()

    const blocker = wrapper.get('[data-test="year-close-blocker-exotic_new_blocker"]').text()
    expect(blocker).toContain('payroll.year_close.blocker.unknown')
    expect(blocker).not.toContain('payroll.year_close.blocker.exotic_new_blocker')
  })

  /** Čtenář viděl prázdno pod stavem a nevěděl, jestli se uzávěrka dělá jinde. */
  it('bez práva schvalovat řekne proč, místo aby jen nic nenabídl', async () => {
    m.canWrite.mockReturnValue(false)
    m.status.mockResolvedValue(openYear([]))
    const wrapper = mount(PayrollYearClosePanel, { props: { initialYear: 2026 } })
    await flushPromises()

    expect(wrapper.get('[data-test="year-close-read-only"]').text())
      .toContain('payroll.year_close.close_read_only')
  })

  /** Deset znaků vynucuje server; zhasnuté tlačítko po vyplnění „ok" vypadalo rozbitě. */
  it('u znovuotevření napíše, proč je tlačítko zhasnuté', async () => {
    m.canWrite.mockImplementation((permission: string) => permission === 'payroll.reopen')
    m.status.mockResolvedValue({
      closure: { year: 2026, status: 'closed', closed_at: '2027-01-15 08:00:00', row_version: 3 },
      blockers: [],
    })
    const wrapper = mount(PayrollYearClosePanel, { props: { initialYear: 2026 } })
    await flushPromises()

    expect(wrapper.get('[data-test="year-close-reopen-hint"]').text())
      .toContain('payroll.year_close.reopen_reason_hint')

    await wrapper.get('input[type="text"]').setValue('Doplněná oprava mzdy za březen')
    expect(wrapper.find('[data-test="year-close-reopen-hint"]').exists()).toBe(false)
  })
})
