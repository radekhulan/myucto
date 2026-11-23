import { describe, expect, it, vi } from 'vitest'
import { flushPromises, shallowMount } from '@vue/test-utils'

// Telefon: filtry ceníku a schvalování výkazů jsou za tlačítkem „Filtry".
// Tlačítko i skrytí jsou jen `max-md:` třídy, takže desktop (i úzký panel) je beze změny.

vi.mock('vue-i18n', async original => ({ ...await original<typeof import('vue-i18n')>(), useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('vue-router', async original => ({ ...await original<typeof import('vue-router')>(), useRouter: () => ({ push: vi.fn() }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true, canRead: () => true }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))
vi.mock('@/api/priceList', () => ({ priceListApi: { list: async () => ({ data: [] }) } }))
vi.mock('@/api/codebooks', () => ({ codebooksApi: { currencies: async () => [] } }))
vi.mock('@/api/admin', () => ({ adminApi: { listApprovals: async () => ({ data: [], meta: { total: 0, pages: 1, status_counts: { all: 0, requested: 0, approved: 0, rejected: 0 } } }) } }))

import PriceList from '../PriceList.vue'
import Approvals from '../Approvals.vue'

const stubs = { global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } } }

describe('filtry na telefonu', () => {
  it('ceník: měna a stav se na telefonu skryjí za „Filtry"', async () => {
    const wrapper = shallowMount(PriceList, stubs)
    await flushPromises()
    const toggle = wrapper.find('[data-test="price-list-filters-toggle"]')
    expect(toggle.classes()).toEqual(expect.arrayContaining(['hidden', 'max-md:inline-flex']))
    expect(wrapper.find('[data-test="price-list-currency"]').classes()).toContain('max-md:hidden')
    expect(wrapper.find('[data-test="price-list-status"]').classes()).toContain('max-md:hidden')
    await toggle.trigger('click')
    expect(wrapper.find('[data-test="price-list-currency"]').classes()).not.toContain('max-md:hidden')
    expect(wrapper.find('[data-test="price-list-status"]').classes()).not.toContain('max-md:hidden')
    wrapper.unmount()
  })

  it('schvalování: stavy a „jen po termínu" za „Filtry", zaškrtávátko 20 px s plochou 40 px', async () => {
    const wrapper = shallowMount(Approvals, stubs)
    await flushPromises()
    const toggle = wrapper.find('[data-test="approvals-filters-toggle"]')
    expect(toggle.classes()).toEqual(expect.arrayContaining(['hidden', 'max-md:inline-flex']))
    const statuses = wrapper.findAll('[data-test="approvals-status-filter"]')
    expect(statuses).toHaveLength(4)
    expect(statuses.every(s => s.classes().includes('max-md:hidden'))).toBe(true)
    const overdue = wrapper.find('[data-test="approvals-overdue"]')
    expect(overdue.classes()).toEqual(expect.arrayContaining(['max-md:hidden', 'max-md:min-h-10']))
    expect(overdue.find('input').classes()).toEqual(expect.arrayContaining(['max-md:w-5', 'max-md:h-5']))
    await toggle.trigger('click')
    expect(wrapper.findAll('[data-test="approvals-status-filter"]').some(s => s.classes().includes('max-md:hidden'))).toBe(false)
    expect(wrapper.find('[data-test="approvals-overdue"]').classes()).not.toContain('max-md:hidden')
    wrapper.unmount()
  })
})
