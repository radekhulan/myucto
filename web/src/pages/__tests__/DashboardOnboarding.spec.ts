import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import Dashboard from '../Dashboard.vue'
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true, canRead: () => true }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ hasSupplier: true, currentSupplier: { company_name: 'Testovací firma' } }) }))
vi.mock('vue-router', () => ({ RouterLink: { template: '<a><slot /></a>' } }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/components/dashboard/board/DashboardBoard.vue', () => ({ default: defineComponent({ name:'DashboardBoard',emits:['empty'],setup(_,{slots}){return()=>h('section',[h('header',slots.heading?.()),slots.before?.()])} }) }))
vi.mock('@/components/dashboard/OnboardingGuide.vue', () => ({ default: defineComponent({ name:'OnboardingGuide',setup(){return()=>h('aside','guide')} }) }))
describe('dashboard onboarding', () => {
  it.each([false, true])('shows initial setup only without received invoices: has_purchase_invoices=%s', async hasPurchases => {
    const wrapper=mount(Dashboard)
    wrapper.findComponent({name:'DashboardBoard'}).vm.$emit('empty',!hasPurchases)
    await nextTick()
    expect(wrapper.findComponent({name:'OnboardingGuide'}).exists()).toBe(!hasPurchases)
    expect(wrapper.find('h1').text()).toBe('Testovací firma')
    wrapper.unmount()
  })
})
