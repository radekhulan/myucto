import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import cs from '@/i18n/cs.json'

const m = vi.hoisted(() => ({
  batches: vi.fn(),
}))

vi.mock('@/api/payrollImports', () => ({
  payrollImportsApi: { attendanceBatches: m.batches },
}))
vi.mock('@/api/payrollAttendanceApproval', () => ({
  payrollAttendanceApprovalApi: {},
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }),
}))

import AttendanceBatchHistory from '../AttendanceBatchHistory.vue'

describe('AttendanceBatchHistory', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.batches.mockResolvedValue([{
      id: 2,
      period: '2026-03',
      created_at: '2026-04-02 10:00:00',
      created_by_name: null,
      files: [{ name: 'dochazka.xlsx' }],
    }])
  })

  // Historie psala „Dávka č. 2 · 2026-03" a „Jen období 2026-03".
  it('píše období dávky jako MM/RRRR', async () => {
    const i18n = createI18n({ legacy: false, locale: 'cs', messages: { cs } })
    const wrapper = mount(AttendanceBatchHistory, {
      props: { period: '2026-03' },
      global: { plugins: [i18n], stubs: { AttendanceTimeApprovalResult: true } },
    })
    await flushPromises()

    expect(wrapper.text()).toContain('Dávka č. 2 · 03/2026')
    expect(wrapper.text()).toContain('Jen období 03/2026')
    expect(wrapper.text()).not.toContain('2026-03')
    wrapper.unmount()
  })
})
