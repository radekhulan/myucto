import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@/composables/useFormat', () => ({ formatMoney: (v: number) => `${v} Kč` }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

import AccrualAgendaCheckValue from '../AccrualAgendaCheckValue.vue'

const RouterLinkStub = { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' }

describe('AccrualAgendaCheckValue', () => {
  it('ukáže rozdíl po účtech a odkaz do agendy k datu kontroly', () => {
    const wrapper = mount(AccrualAgendaCheckValue, {
      props: { value: { as_of: '2099-12-31', difference: 150, accounts: [{ account_code: '381', account_name: 'NPO', difference: 150 }] } },
      global: { stubs: { RouterLink: RouterLinkStub } },
    })
    expect(wrapper.text()).toContain('381: 150 Kč')
    expect(JSON.parse(wrapper.get('a').attributes('data-to')!)).toEqual({ name: 'accounting-accruals', query: { as_of: '2099-12-31' } })
  })

  it('bez rozpadu po účtech ukáže celkový rozdíl', () => {
    const wrapper = mount(AccrualAgendaCheckValue, {
      props: { value: { as_of: '2099-12-31', difference: 0, accounts: [] } },
      global: { stubs: { RouterLink: RouterLinkStub } },
    })
    expect(wrapper.text()).toContain('0 Kč')
  })
})
