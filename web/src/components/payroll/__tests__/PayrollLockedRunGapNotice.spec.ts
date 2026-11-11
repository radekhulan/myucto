import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, parameters?: Record<string, string | number> | number) =>
      parameters && typeof parameters === 'object' ? `${key} ${Object.values(parameters).join(' ')}` : key,
  }),
}))

import PayrollLockedRunGapNotice from '@/components/payroll/PayrollLockedRunGapNotice.vue'

function mountNotice(props: Record<string, unknown>) {
  return mount(PayrollLockedRunGapNotice, {
    props: { gaps: [], startDate: null, ...props },
    global: {
      stubs: {
        RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' },
      },
    },
  })
}

/*
 * Vztah založený (nebo s nástupem posunutým dozadu) až po schválení měsíce
 * dřív zůstal bez mzdy a karta o tom mlčela.
 */
describe('PayrollLockedRunGapNotice', () => {
  it('bez mezery se nevykreslí', () => {
    const wrapper = mountNotice({ gaps: [], startDate: '2026-12-15' })

    expect(wrapper.find('[data-test="employment-locked-run-gap"]').exists()).toBe(false)
  })

  it('řekne, že nástup spadá do schváleného měsíce, a vede na běh každého dotčeného období', () => {
    const wrapper = mountNotice({
      startDate: '2026-12-15',
      gaps: [
        { run_id: 7, period: '2026-12', status: 'approved' },
        { run_id: 8, period: '2027-01', status: 'closed' },
      ],
    })

    const box = wrapper.get('[data-test="employment-locked-run-gap"]')
    expect(box.text()).toContain('payroll.people.locked_run_gap.title_with_start')
    // Datum nástupu česky, ne ve tvaru ISO.
    expect(box.text()).not.toContain('2026-12-15')
    expect(box.text()).toContain('payroll.people.locked_run_gap.body')
    expect(JSON.parse(box.get('[data-test="employment-locked-run-gap-link-2026-12"]').attributes('data-to')!))
      .toEqual({ name: 'payroll-runs', query: { period: '2026-12' } })
    expect(JSON.parse(box.get('[data-test="employment-locked-run-gap-link-2027-01"]').attributes('data-to')!))
      .toEqual({ name: 'payroll-runs', query: { period: '2027-01' } })
  })

  it('bez známého nástupu použije obecný titulek', () => {
    const wrapper = mountNotice({ gaps: [{ run_id: 7, period: '2026-12', status: 'approved' }] })

    expect(wrapper.get('[data-test="employment-locked-run-gap"]').text())
      .toContain('payroll.people.locked_run_gap.title')
  })
})
