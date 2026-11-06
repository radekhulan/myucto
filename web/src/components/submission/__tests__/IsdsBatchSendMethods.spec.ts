import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  credentials: vi.fn(),
  passwordSendBatch: vi.fn(),
  smsSendStartBatch: vi.fn(),
  smsSendCompleteBatch: vi.fn(),
  certificateSendBatch: vi.fn(),
}))

vi.mock('@/api/dataBox', () => ({ dataBoxApi: m }))
vi.mock('@/api/errors', () => ({
  apiErrorMessage: (_error: unknown, fallback: string) => fallback,
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}|${JSON.stringify(params)}` : key,
  }),
}))

import IsdsBatchSendMethods from '../IsdsBatchSendMethods.vue'

const SingleStub = {
  name: 'IsdsSendMethods',
  props: { outboxId: Number, environment: String, transport: Object, gatewayStart: Function },
  emits: ['sent'],
  template: '<div data-test="single-stub" />',
}
const MobileKeyBatchStub = {
  name: 'MobileKeyBatchSendButton',
  props: { outboxIds: Array, environment: String },
  emits: ['sent'],
  template: '<div data-test="mobile-key-batch-stub" />',
}

function mountBatch(outboxIds: number[]) {
  return mount(IsdsBatchSendMethods, {
    props: { outboxIds, environment: 'production' },
    global: { stubs: { IsdsSendMethods: SingleStub, MobileKeyBatchSendButton: MobileKeyBatchStub } },
  })
}

async function choose(wrapper: ReturnType<typeof mountBatch>, method: string) {
  await wrapper.get(`[data-test="isds-batch-method-${method}"] input`).setValue(true)
  await wrapper.get(`[data-test="isds-batch-method-${method}"] input`).trigger('change')
}

const sentResults = [
  { id: 11, dispatched: true, row: null, error_code: null, error_message: null },
  { id: 12, dispatched: true, row: null, error_code: null, error_message: null },
]

beforeEach(() => {
  vi.clearAllMocks()
  m.credentials.mockResolvedValue([])
})

describe('IsdsBatchSendMethods', () => {
  it('u jedné zprávy použije výběr pro jednu zprávu včetně brány', async () => {
    const wrapper = mountBatch([42])
    await flushPromises()

    expect(wrapper.getComponent(SingleStub).props('outboxId')).toBe(42)
    wrapper.getComponent(SingleStub).vm.$emit('sent', true)
    expect(wrapper.emitted('sent')?.[0]?.[0]).toEqual([
      { id: 42, dispatched: true, row: null, error_code: null, error_message: null },
    ])
  })

  it('dávka nabídne všechny způsoby, bránu ale poctivě vysvětlí jako nedostupnou', async () => {
    const wrapper = mountBatch([11, 12])
    await flushPromises()

    for (const method of ['gateway', 'mobile_key', 'password', 'sms', 'certificate']) {
      expect(wrapper.find(`[data-test="isds-batch-method-${method}"]`).exists()).toBe(true)
    }
    expect(wrapper.get('[data-test="isds-batch-method-gateway"] input').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-test="isds-batch-unavailable-gateway"]').exists()).toBe(true)
    expect(wrapper.getComponent(MobileKeyBatchStub).props('outboxIds')).toEqual([11, 12])
  })

  it('jménem a heslem odešle celou dávku jedním přihlášením', async () => {
    m.passwordSendBatch.mockResolvedValue(sentResults)
    const wrapper = mountBatch([11, 12])
    await flushPromises()

    await choose(wrapper, 'password')
    await wrapper.get('[data-test="isds-batch-username"]').setValue('synthetic')
    await wrapper.get('[data-test="isds-batch-password"]').setValue('secret')
    await wrapper.get('[data-test="isds-batch-password-submit"]').trigger('click')
    await flushPromises()

    expect(m.passwordSendBatch).toHaveBeenCalledWith([11, 12], 'production', 'synthetic', 'secret')
    expect(wrapper.emitted('sent')?.[0]?.[0]).toEqual(sentResults)
    expect((wrapper.get('[data-test="isds-batch-password"]').element as HTMLInputElement).value).toBe('')
  })

  it('SMS kódem si vyžádá kód a odešle dávku v jedné relaci', async () => {
    m.smsSendStartBatch.mockResolvedValue({ flow_token: 'flow-1', description: 'Kód odeslán.', expires_at: '' })
    m.smsSendCompleteBatch.mockResolvedValue(sentResults)
    const wrapper = mountBatch([11, 12])
    await flushPromises()

    await choose(wrapper, 'sms')
    await wrapper.get('[data-test="isds-batch-username"]').setValue('synthetic')
    await wrapper.get('[data-test="isds-batch-password"]').setValue('secret')
    await wrapper.get('[data-test="isds-batch-sms-request"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="isds-batch-sms-code"]').setValue('123456')
    await wrapper.get('[data-test="isds-batch-sms-confirm"]').trigger('click')
    await flushPromises()

    expect(m.smsSendStartBatch).toHaveBeenCalledWith('production', 'synthetic', 'secret')
    expect(m.smsSendCompleteBatch).toHaveBeenCalledWith([11, 12], 'production', 'flow-1', '123456')
    expect(wrapper.emitted('sent')?.[0]?.[0]).toEqual(sentResults)
  })

  it('systémovým certifikátem odešle až po vědomém potvrzení', async () => {
    m.credentials.mockResolvedValue([{ environment: 'production', credential_missing: false }])
    m.certificateSendBatch.mockResolvedValue(sentResults)
    const wrapper = mountBatch([11, 12])
    await flushPromises()

    await choose(wrapper, 'certificate')
    expect(wrapper.get('[data-test="isds-batch-certificate-submit"]').attributes('disabled')).toBeDefined()
    await wrapper.get('[data-test="isds-batch-certificate-confirm"]').setValue(true)
    await wrapper.get('[data-test="isds-batch-certificate-submit"]').trigger('click')
    await flushPromises()

    expect(m.certificateSendBatch).toHaveBeenCalledWith([11, 12], 'production')
    expect(wrapper.emitted('sent')).toHaveLength(1)
  })
})
