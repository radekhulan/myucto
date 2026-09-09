import { describe, expect, it, vi } from 'vitest'
import { flushPromises, shallowMount } from '@vue/test-utils'

const mocks = vi.hoisted(() => ({ retry: vi.fn(async () => ({})), cancel: vi.fn(async () => ({})) }))
vi.mock('@/api/stock', () => ({ stockApi: {
  listWarehouses: vi.fn(async () => []),
  getTake: vi.fn(async () => ({ id: 7, warehouse_id: 1, take_date: '2026-09-01', status: 'preparing', lines: [], preparation_job: { id: 9, status: 'failed', checkpoint: 0, total: 10 } })),
} }))
vi.mock('@/api/catalogJobs', () => ({ catalogJobsApi: { retryTakePreparation: mocks.retry, cancelTakePreparation: mocks.cancel } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (value: string) => value }))
vi.mock('vue-router', () => ({ useRoute: () => ({ params: { id: '7' }, query: {} }), useRouter: () => ({ push: vi.fn() }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

import TakeWizard from '../TakeWizard.vue'

describe('stock take preparation recovery', () => {
  it('shows retry while the take remains preparing after its job failed', async () => {
    const wrapper = shallowMount(TakeWizard, { global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } } })
    try {
      await flushPromises()
      expect(wrapper.text()).toContain('stock.takes.preparation_failed')
      const retry = wrapper.findAll('button').find(button => button.text().includes('common.retry'))
      expect(retry).toBeDefined()
      await retry!.trigger('click')
      expect(mocks.retry).toHaveBeenCalledWith(7)
      const cancel = wrapper.findAll('button').find(button => button.text().includes('common.cancel'))
      expect(cancel).toBeDefined()
      await cancel!.trigger('click')
      expect(mocks.cancel).toHaveBeenCalledWith(7)
    } finally { wrapper.unmount() }
  })
})
