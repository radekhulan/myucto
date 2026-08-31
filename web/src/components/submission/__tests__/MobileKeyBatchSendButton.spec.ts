import { describe, expect, it, vi, beforeEach } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  mobileKeyProfile: vi.fn(),
  startMobileKeyOutboxBatch: vi.fn(),
  mobileKeyOutboxConfirmBatch: vi.fn(),
}))

vi.mock('@/api/dataBox', () => ({
  dataBoxApi: {
    mobileKeyProfile: m.mobileKeyProfile,
    startMobileKeyOutboxBatch: m.startMobileKeyOutboxBatch,
    mobileKeyOutboxConfirmBatch: m.mobileKeyOutboxConfirmBatch,
  },
}))

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, parameters?: Record<string, string | number>) =>
      parameters ? `${key} ${Object.values(parameters).join(' ')}` : key,
  }),
}))

import MobileKeyBatchSendButton from '../MobileKeyBatchSendButton.vue'

describe('MobileKeyBatchSendButton', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.useFakeTimers()
    m.mobileKeyProfile.mockResolvedValue({ saved: false, username: null, environment: 'production' })
  })

  function pick(wrapper: ReturnType<typeof mount>, testId: string) {
    return wrapper.find(`[data-test="${testId}"]`)
  }

  it('disables the action button when nothing is selected', () => {
    const wrapper = mount(MobileKeyBatchSendButton, {
      props: { outboxIds: [], environment: 'production' },
    })
    expect(pick(wrapper, 'mobile-key-batch-send-action').attributes('disabled')).toBeDefined()
  })

  it('one confirmation dispatches every id and reports mixed results', async () => {
    m.startMobileKeyOutboxBatch.mockResolvedValue({
      flow_token: 'batch-flow-1',
      state: 1,
      description: 'Čeká se na potvrzení.',
      expires_at: '2026-08-25T15:00:00Z',
    })
    m.mobileKeyOutboxConfirmBatch
      .mockResolvedValueOnce({ state: 1, description: 'Čeká se dál.', results: null })
      .mockResolvedValueOnce({
        state: 2,
        description: 'Potvrzeno.',
        results: [
          { id: 11, dispatched: true, row: { id: 11 }, error_code: null, error_message: null },
          { id: 12, dispatched: false, row: null, error_code: 'submission_not_found', error_message: 'Podání ve frontě není.' },
        ],
      })

    const wrapper = mount(MobileKeyBatchSendButton, {
      props: { outboxIds: [11, 12], environment: 'production' },
    })
    await pick(wrapper, 'mobile-key-batch-send-action').trigger('click')
    await flushPromises()
    await wrapper.find('input[type="text"]').setValue('jan.novak')
    await wrapper.find('input[type="password"]').setValue('kod123')
    await pick(wrapper, 'mobile-key-batch-send-request').trigger('click')
    await flushPromises()

    expect(m.startMobileKeyOutboxBatch).toHaveBeenCalledWith('production', 'jan.novak', 'kod123', false)
    expect(m.mobileKeyOutboxConfirmBatch).toHaveBeenNthCalledWith(1, [11, 12], 'batch-flow-1', 'production')

    await vi.advanceTimersByTimeAsync(2000)
    await flushPromises()

    const emitted = wrapper.emitted('sent')
    expect(emitted).toHaveLength(1)
    const results = emitted![0]![0] as Array<{ id: number; dispatched: boolean }>
    expect(results).toHaveLength(2)
    expect(results[0]!.dispatched).toBe(true)
    expect(results[1]!.dispatched).toBe(false)
    // Formulář se po odeslání zavře.
    expect(pick(wrapper, 'mobile-key-batch-send-form').exists()).toBe(false)
  })
})
