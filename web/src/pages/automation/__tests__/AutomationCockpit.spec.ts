import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { reactive } from 'vue'

const m = vi.hoisted(() => ({ supplier: {} as any, automation: {} as any, route: {} as any, feed: vi.fn(), stats: vi.fn() }))
vi.mock('@/api/automation', () => ({ automationApi: { feed: m.feed, stats: m.stats } }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => m.supplier }))
vi.mock('@/stores/automation', () => ({ useAutomationStore: () => m.automation }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => false, clearPermissions: vi.fn(), refresh: vi.fn() }) }))
vi.mock('vue-router', () => ({ useRoute: () => m.route, useRouter: () => ({ replace: vi.fn() }) }))
vi.mock('vue-i18n', async importOriginal => ({ ...await importOriginal<typeof import('vue-i18n')>(), useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/api/autoPosting', () => ({ autoPostingApi: { getPolicy: async () => ({ rows: [] }) } }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))
vi.mock('@/composables/useHotkey', () => ({ useHotkey: () => {} }))
import AutomationCockpit from '../AutomationCockpit.vue'

describe('recommendations company context', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.feed.mockResolvedValue({ items: [], total: 0 })
    m.stats.mockResolvedValue({ auto_count: 0, approved_count: 0, rejected_count: 0, manual_bank_count: 0, saved_seconds: 0, top_reasons: [] })
    m.route = reactive({ query: {} })
    m.supplier = reactive({ currentSupplierId: 1, availableSuppliers: [
      { id: 1, accounting_mode: 'double_entry' }, { id: 2, accounting_mode: 'double_entry' },
    ], setSupplier: vi.fn() })
    m.automation = reactive({ scopeSupplierId: 'all', counts: null, refresh: vi.fn(), setScopeSupplier: vi.fn() })
  })

  it('hides the company selector and follows the global company, never the saved all-company scope', async () => {
    const wrapper = shallowMount(AutomationCockpit)
    await flushPromises()
    expect(wrapper.findAll('select')).toHaveLength(0)
    expect(wrapper.findComponent({ name: 'AutomationRecommendations' }).props('suppliers')).toEqual([1])
    m.supplier.currentSupplierId = 2
    await flushPromises()
    expect(wrapper.findComponent({ name: 'AutomationRecommendations' }).props('suppliers')).toEqual([2])
    wrapper.unmount()
  })

  it('does not let an old URL switch the global company on the recommendations tab', async () => {
    m.route.query.suppliers = '2'
    const wrapper = shallowMount(AutomationCockpit)
    await flushPromises()
    expect(m.supplier.setSupplier).not.toHaveBeenCalled()
    expect(wrapper.findComponent({ name: 'AutomationRecommendations' }).props('suppliers')).toEqual([1])
    wrapper.unmount()
  })

  it('passes an empty scope when the current company is not eligible instead of querying other companies', async () => {
    m.supplier.currentSupplierId = 0
    const wrapper = shallowMount(AutomationCockpit)
    await flushPromises()
    expect(wrapper.findComponent({ name: 'AutomationRecommendations' }).props('suppliers')).toEqual([])
    wrapper.unmount()
  })

  it.each(['auto', 'pending', 'needs_input', 'rules', 'checklist', 'history'])('keeps %s scoped to the global company and ignores URL overrides', async tab => {
    m.route.query = { tab, suppliers: '2' }
    const wrapper = shallowMount(AutomationCockpit)
    await flushPromises()
    expect(wrapper.text()).not.toContain('automation.filter_supplier')
    expect(wrapper.text()).not.toContain('automation.filter_all_suppliers')
    expect(m.supplier.setSupplier).not.toHaveBeenCalled()
    if (['auto', 'pending', 'needs_input'].includes(tab)) {
      expect(m.feed).toHaveBeenCalledWith(expect.objectContaining({ suppliers: '1' }))
      m.supplier.currentSupplierId = 2
      await flushPromises()
      expect(m.feed).toHaveBeenLastCalledWith(expect.objectContaining({ suppliers: '2' }))
    } else if (tab === 'history') {
      expect(wrapper.findComponent({ name: 'AutomationHistory' }).props('suppliers')).toEqual([1])
    } else if (tab === 'checklist') {
      expect(wrapper.findComponent({ name: 'AutomationChecklist' }).props('supplierId')).toBe(1)
    } else {
      const before = wrapper.findComponent({ name: 'AutomationRules' }).vm
      m.supplier.currentSupplierId = 2
      await flushPromises()
      expect(wrapper.findComponent({ name: 'AutomationRules' }).vm).not.toBe(before)
    }
    wrapper.unmount()
  })

  it('never sends an empty company scope to the feed or history API', async () => {
    m.supplier.currentSupplierId = 0
    m.route.query = { tab: 'pending' }
    const wrapper = shallowMount(AutomationCockpit)
    await flushPromises()
    expect(m.feed).not.toHaveBeenCalled()
    m.route.query.tab = 'history'
    await flushPromises()
    expect(wrapper.findComponent({ name: 'AutomationHistory' }).exists()).toBe(false)
    wrapper.unmount()
  })
})
