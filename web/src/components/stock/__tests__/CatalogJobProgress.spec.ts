import { describe, expect, it } from 'vitest'
import { shallowMount } from '@vue/test-utils'
import { vi } from 'vitest'

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

import CatalogJobProgress from '../CatalogJobProgress.vue'

describe('CatalogJobProgress', () => {
  it('maps catalog checkpoints to the shared progress component and preserves cancellation state', () => {
    const wrapper = shallowMount(CatalogJobProgress, {
      props: {
        job: {
          id: 21,
          supplier_id: 1,
          kind: 'stock_valuation',
          input_version: 1,
          status: 'running',
          checkpoint: 3,
          total: 12,
          report: { failed_items: [{ item_id: 4, error_code: 'missing_purchase_cost' }] },
          error_code: null,
          cancel_requested: true,
          created_at: '2026-09-09 10:00:00',
          updated_at: '2026-09-09 10:00:01',
          finished_at: null,
          attempts: 1,
        },
        canCancel: true,
      },
      global: {
        stubs: {
          ImportJobProgress: { name: 'ImportJobProgress', props: ['job', 'percent', 'showCancel'], template: '<div />' },
        },
      },
    })

    const progress = wrapper.findComponent({ name: 'ImportJobProgress' })
    expect(progress.props('percent')).toBe(25)
    expect(progress.props('showCancel')).toBe(false)
    expect(progress.props('job')).toMatchObject({
      processed: 3,
      total_items: 12,
      failed_count: 1,
    })
    expect(wrapper.text()).toContain('eshop.jobs.cancel_requested')
    expect(wrapper.text()).toContain('eshop.jobs.cancel_boundary')
  })
})
