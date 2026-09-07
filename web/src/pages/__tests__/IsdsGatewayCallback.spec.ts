import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  complete: vi.fn(),
  completePayroll: vi.fn(),
}))

vi.mock('@/api/dataBox', () => ({
  dataBoxApi: {
    gatewayComplete: m.complete,
    gatewayCompletePayroll: m.completePayroll,
  },
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' },
    t: (key: string, params?: Record<string, unknown>) =>
      params?.value ? `${key}:${String(params.value)}` : key,
  }),
}))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (_error: unknown, fallback: string) => fallback }))

import IsdsGatewayCallback from '../IsdsGatewayCallback.vue'

beforeEach(() => {
  vi.clearAllMocks()
  window.history.replaceState({}, '', '/isds-gateway/callback')
})

describe('ISDS gateway callback', () => {
  it('dokončí mzdovou relaci po zamítnutí obecného oprávnění a odstraní tokeny z URL', async () => {
    window.history.replaceState({}, '', '/isds-gateway/callback?appToken=app-1&sessionId=session-1')
    m.complete.mockRejectedValue({ isAxiosError: true, response: { status: 403 } })
    m.completePayroll.mockResolvedValue({
      state: 'approved',
      outbox_id: 7,
      redirect_url: null,
      external_message_id: 'DM-123',
      message: 'Odesláno.',
    })

    const wrapper = mount(IsdsGatewayCallback, {
      global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } },
    })
    await flushPromises()

    expect(m.complete).toHaveBeenCalledWith('app-1', 'session-1')
    expect(m.completePayroll).toHaveBeenCalledWith('app-1', 'session-1')
    expect(window.location.search).toBe('')
    expect(wrapper.get('[data-test="isds-callback-result"]').text()).toContain('DM-123')
  })

  it('bez identifikátorů relace nevolá API', async () => {
    const wrapper = mount(IsdsGatewayCallback, {
      global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } },
    })
    await flushPromises()

    expect(m.complete).not.toHaveBeenCalled()
    expect(m.completePayroll).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="isds-callback-error"]').text())
      .toBe('databox.gateway.callback.invalid')
  })
})
