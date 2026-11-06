import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { computed, ref } from 'vue'
import { windowContextKey } from '@/workspace/windowContext'

const m = vi.hoisted(() => ({
  credentials: vi.fn(),
  recipients: vi.fn(),
  outbox: vi.fn(),
  inbox: vi.fn(),
  mobileKeyProfile: vi.fn(),
  unmatchedReceipts: vi.fn(),
  inboxStorage: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
}))

vi.mock('@/api/dataBox', () => ({
  dataBoxApi: {
    credentials: m.credentials,
    recipients: m.recipients,
    outbox: m.outbox,
    browseInbox: m.inbox,
    mobileKeyProfile: m.mobileKeyProfile,
    unmatchedReceipts: m.unmatchedReceipts,
    inboxStorage: m.inboxStorage,
  },
}))
vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => ({ query: {} }),
  useRouter: () => ({ replace: vi.fn() }),
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }) }))
vi.mock('@/composables/useFormat', () => ({ formatUtcDateTime: (value: string) => value }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (error: unknown) => String(error) }))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError }),
}))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: { company_name: 'Testovací firma' } }),
}))
vi.mock('@/workspace/supplierContext', () => ({
  getWindowSupplierScope: () => ({ supplierId: 7, windowId: 'window-databox' }),
}))

import DataBox from '../DataBox.vue'

function submission(id: number, subject: string) {
  return {
    id,
    supplier_id: 7,
    environment: 'production',
    channel: 'isds',
    dispatch_mode: 'gateway',
    agenda_code: 'cssz_jmhz',
    recipient_id: null,
    recipient_databox_id: 'iie254d',
    subject,
    dispatch_state: 'sent',
    acceptance_state: 'unknown',
    external_message_id: null,
    sent_at: '2026-08-01 10:00:00',
    delivered_at: null,
    accepted_at: null,
    last_error_message: null,
    delete_blocked_reason: null,
    receipt_document_id: 1,
    row_version: 1,
    created_at: '2026-08-01 10:00:00',
    updated_at: '2026-08-01 10:00:00',
  }
}

function changedElsewhere(resource = 'payroll') {
  window.dispatchEvent(new CustomEvent('myucto:window-data-changed', { detail: { supplierId: 7, resource } }))
}

const wrappers: ReturnType<typeof mount>[] = []
afterEach(() => {
  wrappers.splice(0).forEach(wrapper => wrapper.unmount())
})

async function mountParkedDataBox() {
  m.credentials.mockResolvedValue([])
  m.recipients.mockResolvedValue([])
  m.inbox.mockResolvedValue({ items: [], total: 0, state: null })
  m.mobileKeyProfile.mockResolvedValue({ saved: false, username: null, environment: 'production' })
  m.unmatchedReceipts.mockResolvedValue([])
  m.inboxStorage.mockResolvedValue({ items: [], folders: [] })
  m.outbox.mockResolvedValue({ items: [submission(1, 'JMHZ 2026-07')], total: 1, years: [] })

  const active = ref(true)
  const wrapper = mount(DataBox, {
    attachTo: document.body,
    global: {
      stubs: { EmptyState: true, RouterLink: { template: '<a><slot /></a>' } },
      provide: {
        [windowContextKey as symbol]: {
          active: computed(() => active.value),
          paneId: computed(() => 'primary' as const),
          root: document.body,
          portal: document.body,
        },
      },
    },
  })
  wrappers.push(wrapper)
  await flushPromises()
  await wrapper.findAll('nav button')[1]!.trigger('click')
  active.value = false
  await flushPromises()
  return { wrapper, active }
}

/**
 * Okno datové schránky zůstane zaparkované, zatímco účetní v jiném okně odešle
 * JMHZ. Po návratu musí nové podání v odchozích být vidět bez F5.
 */
describe('DataBox — obnova zapamatovaného okna', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('po odeslání v jiném okně načte odchozí podání při návratu, bez skeletonu a bez přístupu', async () => {
    const { wrapper, active } = await mountParkedDataBox()
    const outboxCalls = m.outbox.mock.calls.length
    const credentialCalls = m.credentials.mock.calls.length
    m.outbox.mockResolvedValue({
      items: [submission(2, 'JMHZ 2026-08'), submission(1, 'JMHZ 2026-07')],
      total: 2,
      years: [],
    })

    changedElsewhere('payroll')
    await flushPromises()
    expect(m.outbox).toHaveBeenCalledTimes(outboxCalls)

    active.value = true
    await flushPromises()

    expect(m.outbox).toHaveBeenCalledTimes(outboxCalls + 1)
    expect(m.credentials).toHaveBeenCalledTimes(credentialCalls)
    expect(wrapper.text()).toContain('JMHZ 2026-08')
    expect(wrapper.text()).toContain('JMHZ 2026-07')
  })

  it('bez změny dat se při návratu nic nenačítá', async () => {
    const { active } = await mountParkedDataBox()
    const outboxCalls = m.outbox.mock.calls.length

    active.value = true
    await flushPromises()

    expect(m.outbox).toHaveBeenCalledTimes(outboxCalls)
  })
})
