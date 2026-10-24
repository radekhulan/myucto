import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  enqueueIsds: vi.fn(),
  gatewayStartTax: vi.fn(),
  cancelIsds: vi.fn(),
}))

vi.mock('@/api/epoSubmissions', () => ({
  epoSubmissionsApi: { enqueueIsds: m.enqueueIsds, cancelIsds: m.cancelIsds },
}))
vi.mock('@/api/dataBox', () => ({
  dataBoxApi: { gatewayStartTax: m.gatewayStartTax },
}))
vi.mock('@/api/errors', () => ({
  apiErrorMessage: (_error: unknown, fallback: string) => fallback,
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    locale: { value: 'cs' },
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}|${JSON.stringify(params)}` : key,
  }),
}))

import TaxIsdsSendDialog from '../TaxIsdsSendDialog.vue'

function queued(overrides: Record<string, unknown> = {}) {
  return {
    outbox_id: 77,
    created: true,
    row: { id: 77, dispatch_state: 'ready' },
    recipient: { id: 5, box_id: 'abc1234', name: 'Finanční úřad pro Testovací kraj', code: 'fu_451', source: 'office_code' },
    xml_office_code: '451',
    office_mismatch: false,
    subject: 'DPH 2026-09',
    attachment: { filename: 'dphdp3.xml', mime: 'application/xml', sha256: '0123456789abcdef0123', bytes: 2048 },
    transport: { automatic: false, channel: 'mobile_key', reason: null },
    ...overrides,
  }
}

const IsdsSendMethodsStub = {
  name: 'IsdsSendMethods',
  props: ['outboxId', 'environment', 'transport', 'gatewayStart', 'gatewayOnly'],
  emits: ['sent'],
  template: '<div data-test="isds-send-methods-stub" />',
}

function mountDialog(clientMode = false) {
  return mount(TaxIsdsSendDialog, {
    props: { submissionId: 12, title: 'DPH · 2026-09', clientMode },
    global: { stubs: { IsdsSendMethods: IsdsSendMethodsStub } },
  })
}

beforeEach(() => {
  vi.clearAllMocks()
})

describe('TaxIsdsSendDialog', () => {
  it('zařadí podání do fronty a předá společnému výběru cest odeslání daňovou bránu', async () => {
    m.enqueueIsds.mockResolvedValue(queued())
    const wrapper = mountDialog()
    await flushPromises()

    expect(m.enqueueIsds).toHaveBeenCalledWith(12)
    expect(wrapper.get('[data-test="tax-isds-recipient"]').text()).toContain('abc1234')
    expect(wrapper.get('[data-test="tax-isds-attachment"]').text()).toContain('dphdp3.xml')
    expect(wrapper.get('[data-test="tax-isds-attachment"]').text()).toContain('0123456789ab')
    const methods = wrapper.getComponent(IsdsSendMethodsStub)
    expect(methods.props('outboxId')).toBe(77)
    expect(methods.props('environment')).toBe('production')
    expect(methods.props('transport')).toEqual({ automatic: false, channel: 'mobile_key', reason: null })
    expect(methods.props('gatewayStart')).toBe(m.gatewayStartTax)
    expect(wrapper.find('[data-test="tax-isds-mismatch"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="tax-isds-open-outbox"]').attributes('href'))
      .toBe('/admin/databox?tab=outbox&outbox=77')
  })

  it('varuje, když XML míří na jiný finanční úřad než vybraná schránka', async () => {
    m.enqueueIsds.mockResolvedValue(queued({ office_mismatch: true, xml_office_code: '461' }))
    const wrapper = mountDialog()
    await flushPromises()

    expect(wrapper.get('[data-test="tax-isds-mismatch"]').text())
      .toContain('reports.submissions.isds.office_mismatch')
    expect(wrapper.get('[data-test="tax-isds-mismatch"]').text()).toContain('461')
  })

  it('po odeslání ohlásí podání jako podané a výběr cest schová', async () => {
    m.enqueueIsds.mockResolvedValue(queued())
    const wrapper = mountDialog()
    await flushPromises()

    wrapper.getComponent(IsdsSendMethodsStub).vm.$emit('sent', true)
    await flushPromises()

    expect(wrapper.emitted('sent')).toHaveLength(1)
    expect(wrapper.find('[data-test="tax-isds-sent"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="isds-send-methods-stub"]').exists()).toBe(false)
  })

  it('neodeslanou zprávu nehlásí jako podanou', async () => {
    m.enqueueIsds.mockResolvedValue(queued())
    const wrapper = mountDialog()
    await flushPromises()

    wrapper.getComponent(IsdsSendMethodsStub).vm.$emit('sent', false)
    await flushPromises()

    expect(wrapper.emitted('sent')).toBeUndefined()
    expect(wrapper.find('[data-test="tax-isds-sent"]').exists()).toBe(false)
  })

  it('zprávu ve frontě zruší přes daňový endpoint podle ID podání', async () => {
    m.enqueueIsds.mockResolvedValue(queued())
    m.cancelIsds.mockResolvedValue({ id: 12 })
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const wrapper = mountDialog()
    await flushPromises()

    expect(wrapper.get('[data-test="tax-isds-open-access"]').attributes('href'))
      .toBe('/admin/databox?tab=access')
    await wrapper.get('[data-test="tax-isds-cancel"]').trigger('click')
    await flushPromises()

    expect(m.cancelIsds).toHaveBeenCalledWith(12)
    expect(wrapper.emitted('close')).toHaveLength(1)
    confirm.mockRestore()
  })

  it('chybu zařazení ukáže a cesty odeslání nenabídne', async () => {
    m.enqueueIsds.mockRejectedValue({ response: { status: 422 } })
    const wrapper = mountDialog()
    await flushPromises()

    expect(wrapper.get('[data-test="tax-isds-enqueue-error"]').text())
      .toBe('reports.submissions.isds.enqueue_failed')
    expect(wrapper.find('[data-test="isds-send-methods-stub"]').exists()).toBe(false)
  })
})

it('klientovi nabídne jen daňovou bránu a neschová za ni správu schránky', async () => {
  m.enqueueIsds.mockResolvedValue(queued())
  const wrapper = mountDialog(true)
  await flushPromises()
  expect(wrapper.getComponent(IsdsSendMethodsStub).props('gatewayOnly')).toBe(true)
  expect(wrapper.getComponent(IsdsSendMethodsStub).props('gatewayStart')).toBe(m.gatewayStartTax)
  expect(wrapper.find('[data-test="tax-isds-open-outbox"]').exists()).toBe(false)
  expect(wrapper.find('[data-test="tax-isds-open-access"]').exists()).toBe(false)
})
