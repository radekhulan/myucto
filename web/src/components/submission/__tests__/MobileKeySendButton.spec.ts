import { describe, expect, it, vi, beforeEach } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  mobileKeyProfile: vi.fn(),
  startMobileKeyOutbox: vi.fn(),
  mobileKeyOutboxConfirm: vi.fn(),
}))

vi.mock('@/api/dataBox', () => ({
  dataBoxApi: {
    mobileKeyProfile: m.mobileKeyProfile,
    startMobileKeyOutbox: m.startMobileKeyOutbox,
    mobileKeyOutboxConfirm: m.mobileKeyOutboxConfirm,
  },
}))

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string) => key,
  }),
}))

import MobileKeySendButton from '../MobileKeySendButton.vue'

describe('MobileKeySendButton', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.useFakeTimers()
    m.mobileKeyProfile.mockResolvedValue({ saved: false, username: null, environment: 'production' })
  })

  function pick(wrapper: ReturnType<typeof mount>, testId: string) {
    return wrapper.find(`[data-test="${testId}"]`)
  }

  it('shows the action button first, opens the credential form on click', async () => {
    const wrapper = mount(MobileKeySendButton, {
      props: { outboxId: 42, environment: 'production' },
    })
    expect(pick(wrapper, 'mobile-key-send-form').exists()).toBe(false)

    await pick(wrapper, 'mobile-key-send-action').trigger('click')
    await flushPromises()

    expect(pick(wrapper, 'mobile-key-send-form').exists()).toBe(true)
    expect(m.mobileKeyProfile).toHaveBeenCalledWith('production')
  })

  it('requires username and code when there is no saved profile', async () => {
    const wrapper = mount(MobileKeySendButton, {
      props: { outboxId: 42, environment: 'production' },
    })
    await pick(wrapper, 'mobile-key-send-action').trigger('click')
    await flushPromises()

    await pick(wrapper, 'mobile-key-send-request').trigger('click')
    await flushPromises()

    expect(pick(wrapper, 'mobile-key-send-error').exists()).toBe(true)
    expect(m.startMobileKeyOutbox).not.toHaveBeenCalled()
  })

  it('starts the flow, polls, and emits "sent" once the session is confirmed and dispatched', async () => {
    m.startMobileKeyOutbox.mockResolvedValue({
      flow_token: 'flow-1',
      state: 1,
      description: 'Čeká se na potvrzení v mobilu.',
      expires_at: '2026-01-01T00:00:00Z',
    })
    m.mobileKeyOutboxConfirm
      .mockResolvedValueOnce({ state: 1, description: 'Čeká se dál.', result: null })
      .mockResolvedValueOnce({
        state: 2,
        description: 'Potvrzeno.',
        result: { row: { id: 42 }, dispatched: true },
      })

    const wrapper = mount(MobileKeySendButton, {
      props: { outboxId: 42, environment: 'production' },
    })
    await pick(wrapper, 'mobile-key-send-action').trigger('click')
    await flushPromises()

    await wrapper.find('input[type="text"]').setValue('jan.novak')
    await wrapper.find('input[type="password"]').setValue('kod123')
    await pick(wrapper, 'mobile-key-send-request').trigger('click')
    await flushPromises()

    expect(m.startMobileKeyOutbox).toHaveBeenCalledWith(42, 'production', 'jan.novak', 'kod123', false)
    expect(m.mobileKeyOutboxConfirm).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(2000)
    await flushPromises()

    expect(m.mobileKeyOutboxConfirm).toHaveBeenCalledTimes(2)
    expect(wrapper.emitted('sent')).toEqual([[true]])
    // Po odeslání se formulář zavře a nabídne se znovu jen holé tlačítko.
    expect(pick(wrapper, 'mobile-key-send-form').exists()).toBe(false)
  })

  it('surfaces a rejected/expired session as an error and does not retry silently', async () => {
    m.startMobileKeyOutbox.mockResolvedValue({
      flow_token: 'flow-1',
      state: 1,
      description: 'Čeká se na potvrzení v mobilu.',
      expires_at: '2026-01-01T00:00:00Z',
    })
    m.mobileKeyOutboxConfirm.mockRejectedValue({
      response: { data: { error: { message: 'Přihlášení bylo zamítnuto.' } } },
    })

    const wrapper = mount(MobileKeySendButton, {
      props: { outboxId: 42, environment: 'test' },
    })
    await pick(wrapper, 'mobile-key-send-action').trigger('click')
    await flushPromises()
    await wrapper.find('input[type="text"]').setValue('jan.novak')
    await wrapper.find('input[type="password"]').setValue('kod123')
    await pick(wrapper, 'mobile-key-send-request').trigger('click')
    await flushPromises()

    expect(pick(wrapper, 'mobile-key-send-error').exists()).toBe(true)
    expect(wrapper.emitted('sent')).toBeUndefined()
  })
})
