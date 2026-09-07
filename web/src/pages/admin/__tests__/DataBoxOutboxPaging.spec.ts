import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

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
    inbox: m.inbox,
    mobileKeyProfile: m.mobileKeyProfile,
    unmatchedReceipts: m.unmatchedReceipts,
    inboxStorage: m.inboxStorage,
  },
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

import DataBox from '../DataBox.vue'

/** Odchozí podání č. `id` — jen tolik polí, kolik obrazovka k vykreslení potřebuje. */
function submission(id: number) {
  return {
    id,
    supplier_id: 1,
    environment: 'production',
    channel: 'isds',
    dispatch_mode: 'gateway',
    agenda_code: 'cssz_jmhz',
    recipient_id: null,
    recipient_databox_id: 'iie254d',
    subject: 'JMHZ 2026-0' + id,
    dispatch_state: 'queued',
    acceptance_state: 'unknown',
    external_message_id: null,
    sent_at: null,
    delivered_at: null,
    accepted_at: null,
    last_error_message: null,
    delete_blocked_reason: null,
    row_version: 1,
    created_at: '2026-08-01 10:00:00',
    updated_at: '2026-08-01 10:00:00',
  }
}

async function mountOutbox(total: number, pageSize = 25) {
  m.credentials.mockResolvedValue([])
  m.recipients.mockResolvedValue([])
  m.inbox.mockResolvedValue({ items: [], total: 0, state: null })
  m.mobileKeyProfile.mockResolvedValue({ saved: false, username: null, environment: 'production' })
  m.unmatchedReceipts.mockResolvedValue([])
  m.inboxStorage.mockResolvedValue({ items: [], folders: [] })
  m.outbox.mockResolvedValue({
    items: Array.from({ length: Math.min(total, pageSize) }, (_v, i) => submission(i + 1)),
    total,
    years: [2026, 2025],
  })

  const wrapper = mount(DataBox, {
    global: { stubs: { EmptyState: true, RouterLink: { template: '<a><slot /></a>' } } },
  })
  await flushPromises()
  // Výchozí je záložka přístupu; listování odchozích je vidět až po přepnutí.
  await wrapper.findAll('nav button')[1].trigger('click')
  return wrapper
}

/**
 * Fronta odchozích podání roste každý měsíc a nic se z ní nemaže.
 *
 * Dřív se vracelo prvních 100 řádků bez celkového počtu, takže po pár měsících
 * provozu starší podání z přehledu TICHE zmizela — obrazovka vypadala kompletní,
 * jen v ní chyběl konec. Test proto hlídá, že se o zbytku ví.
 */
describe('DataBox — stránkování odchozích podání', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('si řekne o stránku, ne o celou frontu', async () => {
    await mountOutbox(60)

    expect(m.outbox).toHaveBeenCalledWith('production', 25, 0, null, null)
  })

  /**
   * Filtr musí dojet na SERVER, ne jen proškrtat načtenou stránku — jinak by
   * ukazoval jen to, co se náhodou vešlo do prvních 25 řádků.
   */
  it('vybraný měsíc a rok pošle na server a vrátí se na první stránku', async () => {
    const wrapper = await mountOutbox(60)

    await wrapper.get('[data-test="period-filter-year"]').setValue('2026')
    await flushPromises()
    expect(m.outbox).toHaveBeenLastCalledWith('production', 25, 0, 2026, null)

    await wrapper.get('[data-test="period-filter-month"]').setValue('8')
    await flushPromises()
    expect(m.outbox).toHaveBeenLastCalledWith('production', 25, 0, 2026, 8)
  })

  it('zrušení filtru se vrátí k celému seznamu', async () => {
    const wrapper = await mountOutbox(60)

    await wrapper.get('[data-test="period-filter-year"]').setValue('2026')
    await flushPromises()
    await wrapper.get('[data-test="period-filter-clear"]').trigger('click')
    await flushPromises()

    expect(m.outbox).toHaveBeenLastCalledWith('production', 25, 0, null, null)
  })

  it('nad jednu stránku nabídne listování', async () => {
    const wrapper = await mountOutbox(60)

    expect(wrapper.find('[data-test="outbox-pagination"]').exists()).toBe(true)
  })

  it('do jedné stránky listování neukazuje', async () => {
    const wrapper = await mountOutbox(3)

    expect(wrapper.find('[data-test="outbox-pagination"]').exists()).toBe(false)
  })
})
