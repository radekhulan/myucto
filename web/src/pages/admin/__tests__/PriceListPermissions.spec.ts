import { beforeEach, describe, expect, it, vi } from 'vitest'
import { RouterLink } from 'vue-router'
import { flushPromises, mount } from '@vue/test-utils'
const m = vi.hoisted(() => ({ write: false, route: 'admin-price-list-view', push: vi.fn(), list: vi.fn(), get: vi.fn(), create: vi.fn(), update: vi.fn(), delete: vi.fn() }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => m.write, canRead: () => true }) }))
vi.mock('vue-router', async importOriginal => ({ ...await importOriginal<typeof import('vue-router')>(), useRouter: () => ({ push: m.push }), useRoute: () => ({ name: m.route, params: { id: 7 } }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key, locale: { value: 'cs' } }) }))
vi.mock('@/api/priceList', () => ({ priceListApi: m }))
vi.mock('@/api/codebooks', () => ({ codebooksApi: { currencies: async () => [{ id: 1, code: 'CZK', is_active: true, is_default: true }], units: async () => [], vatRates: async () => [] } }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn() }) }))
import PriceList from '../PriceList.vue'
import PriceListForm from '../PriceListForm.vue'

const item = { id: 7, code: 'SYNTHETIC', name: 'Synthetic service', description: 'Synthetic description', unit: 'ks', vat_rate_id: 1, vat_rate_percent: 21, base_currency_code: 'CZK', prices: [{ currency_code: 'CZK', unit_price: 100, archived: false }], usage: [], customer_overrides: [{ client_id: 8, client_name: 'Synthetic customer', currency_code: 'CZK', unit_price: 90 }] }

describe('price list delegated permissions', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.write = false
    m.route = 'admin-price-list-view'
    m.list.mockResolvedValue({ data: [item] })
    m.get.mockResolvedValue(item)
  })
  it('offers readers details without creating, editing or deleting', async () => {
    const wrapper = mount(PriceList, { global: { stubs: { EmptyState: true, RouterLink: true } } })
    await flushPromises()
    expect(wrapper.findComponent(RouterLink).exists()).toBe(true)
    expect(wrapper.findComponent(RouterLink).props('to')).toEqual({ name: 'admin-work-report-billing-templates' })
    expect(wrapper.text()).not.toContain('price_list.new')
    expect(wrapper.text()).not.toContain('common.edit')
    expect(wrapper.text()).not.toContain('common.remove')
    await wrapper.findAll('button').find(button => button.text().includes('common.detail'))!.trigger('click')
    expect(m.push).toHaveBeenCalledWith({ name: 'admin-price-list-view', params: { id: 7 } })
    expect(m.delete).not.toHaveBeenCalled()
    wrapper.unmount()
  })
  it('shows management actions for writers', async () => {
    m.write = true
    const wrapper = mount(PriceList, { global: { stubs: { EmptyState: true, RouterLink: true } } })
    await flushPromises()
    expect(wrapper.text()).toContain('price_list.new')
    expect(wrapper.text()).toContain('common.edit')
    expect(wrapper.text()).toContain('common.remove')
    wrapper.unmount()
  })
  it('disables detail inputs and rejects submission for read-only users', async () => {
    const wrapper = mount(PriceListForm, { global: { stubs: { ActionBar: true, SearchableSelect: true } } })
    await flushPromises()
    expect(wrapper.find('fieldset').attributes()).toHaveProperty('disabled')
    expect(wrapper.text()).not.toContain('common.remove')
    expect(wrapper.text()).not.toContain('common.add')
    await wrapper.find('form').trigger('submit')
    expect(m.update).not.toHaveBeenCalled()
    expect(m.create).not.toHaveBeenCalled()
    wrapper.unmount()
  })
})
