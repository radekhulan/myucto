import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import DppAnnualHoursBadge from '../DppAnnualHoursBadge.vue'
vi.mock('vue-i18n', () => ({ useI18n: () => ({ locale: ref('cs'), t: (key: string, params?: Record<string, unknown>) => params ? `${key}:${JSON.stringify(params)}` : key }) }))

describe('neověřené roční hodiny DPP', () => {
  it('zobrazí samostatné varování a neformátuje null jako nulu', () => {
    const wrapper = mount(DppAnnualHoursBadge, { props: { testId: 'dpp', hours: { year: 2026, total_minutes: null, remaining_minutes: null, takeover_minutes: 0, limit_minutes: 18000, warning_minutes: 14400, status: 'manual_review_required' } } })
    expect(wrapper.text()).toBe('payroll.people.dpp_hours.label_manual_review_required')
    expect(wrapper.attributes('title')).toContain('"hours":"payroll.worked_hours.unknown"')
    expect(wrapper.attributes('title')).toContain('"remaining":"payroll.worked_hours.unknown"')
    expect(wrapper.classes()).toContain('text-warning-700')
  })
  it('potvrzená nula zůstane číslem', () => {
    const wrapper = mount(DppAnnualHoursBadge, { props: { testId: 'dpp', hours: { year: 2026, total_minutes: 0, remaining_minutes: 18000, takeover_minutes: 0, limit_minutes: 18000, warning_minutes: 14400, status: 'ok' } } })
    expect(wrapper.text()).toContain('"hours":"0"')
    expect(wrapper.text()).not.toContain('unknown')
  })
})
