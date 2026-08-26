import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string) => key,
  }),
}))

import PayrollGuide from '@/pages/payroll/PayrollGuide.vue'

function mountGuide() {
  return mount(PayrollGuide, {
    global: {
      stubs: {
        RouterLink: {
          props: ['to'],
          template: '<a :data-to="JSON.stringify(to)"><slot /></a>',
        },
      },
    },
  })
}

describe('PayrollGuide', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('teaches the monthly order of operations this app actually uses', async () => {
    const wrapper = mountGuide()
    await nextTick()

    const steps = wrapper.findAll('ol li a')
    expect(steps.map(step => step.attributes('data-to'))).toEqual([
      '{"name":"payroll-absences"}',
      '{"name":"payroll-time"}',
      '{"name":"payroll-travel"}',
      '{"name":"payroll-quick-inputs"}',
      '{"name":"payroll-runs"}',
      '{"name":"payroll-posting-reconciliation"}',
      '{"name":"payroll-payments"}',
      '{"name":"payroll-documents"}',
      '{"name":"payroll-submissions"}',
    ])
    expect(steps[0].text()).toContain('1')
    expect(steps[8].text()).toContain('9')
  })

  it('points a first-time user at settings and the manual chapter', async () => {
    const wrapper = mountGuide()
    await nextTick()

    expect(wrapper.find('a[href="/manual?ch=58_Uplne_mzdy"]').exists()).toBe(true)
    const settings = wrapper.findAll('a')
      .find(link => link.attributes('data-to') === '{"name":"payroll-settings"}')
    expect(settings).toBeDefined()
  })

  it('stays dismissed across visits and can be brought back', async () => {
    const wrapper = mountGuide()
    await nextTick()
    await wrapper.get('[data-test="payroll-guide-dismiss"]').trigger('click')

    expect(wrapper.find('[data-test="payroll-guide"]').exists()).toBe(false)
    expect(localStorage.getItem('myinvoice.payroll.guide.off')).toBe('1')

    const next = mountGuide()
    await nextTick()
    expect(next.find('[data-test="payroll-guide"]').exists()).toBe(false)

    next.vm.reopen()
    await nextTick()
    expect(next.find('[data-test="payroll-guide"]').exists()).toBe(true)
  })
})
