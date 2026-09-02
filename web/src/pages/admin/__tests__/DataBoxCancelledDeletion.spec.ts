import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { DataBoxCredential, OutboxSubmission } from '@/api/dataBox'

/**
 * Zrušená odchozí zpráva: kdy zmizí a kdy zůstane.
 *
 * Co tenhle test hlídá:
 *   1. zrušená zpráva bez stopy po odeslání jde smazat, s potvrzením, které
 *      pojmenuje agendu, období i spisovou značku,
 *   2. zpráva, která z aplikace odešla, mazání ani NENABÍDNE a řekne proč,
 *   3. u zrušené zprávy je vidět, že samotné podání dál čeká na odeslání —
 *      to je kořen původního zmatku: „zrušil jsem to" ≠ „je to smazané".
 */

const m = vi.hoisted(() => ({
  credentials: vi.fn(),
  recipients: vi.fn(),
  outbox: vi.fn(),
  inbox: vi.fn(),
  mobileKeyProfile: vi.fn(),
  unmatchedReceipts: vi.fn(),
  remove: vi.fn(),
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
    remove: m.remove,
  },
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}|${JSON.stringify(params)}` : key,
  }),
}))
vi.mock('@/composables/useFormat', () => ({ formatUtcDateTime: (value: string) => value }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (e: unknown) => String(e) }))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError }),
}))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplier: { company_name: 'Testovací firma' } }),
}))

import DataBox from '../DataBox.vue'

const credential: DataBoxCredential = {
  id: 1,
  supplier_id: 1,
  environment: 'production',
  channel: 'isds',
  label: 'Naše schránka',
  box_id: 'abcdefg',
  auth_mode: 'certificate',
  credential_id: null,
  credential_label: null,
  credential_subject: null,
  credential_missing: false,
  certificate_fingerprint: null,
  certificate_valid_to: null,
  last_verified_at: null,
  inbox_polling_enabled: false,
  inbox_polling_enabled_at: null,
  inbox_polling_enabled_by: null,
}

function submission(overrides: Partial<OutboxSubmission> = {}): OutboxSubmission {
  return {
    id: 4,
    environment: 'production',
    channel: 'isds',
    dispatch_mode: 'channel',
    agenda_code: 'JMHZ25',
    recipient_id: 1,
    recipient_box_id: 'zzzzzzz',
    subject: 'Měsíční hlášení ČSSZ',
    artifact_kind: 'payroll_submission',
    artifact_id: 5,
    artifact_filename: 'mzdove-podani-4-5.xml',
    dispatch_state: 'cancelled',
    acceptance_state: 'unknown',
    acceptance_evidence_kind: null,
    acceptance_note: null,
    correlation_reference: 'JMHZ25-20260801-ABCDEF',
    external_message_id: null,
    artifact_validation_status: null,
    recipient_box_verified_at: null,
    receipt_document_id: null,
    receipt_signature_status: 'unverified',
    receipt_matched_by: null,
    receipt_inbox_message_id: null,
    receipt_attached_at: null,
    confirmed_by: null,
    confirmed_at: null,
    sent_at: null,
    delivered_at: null,
    accepted_at: null,
    rejected_at: null,
    last_error_code: null,
    last_error_message: null,
    row_version: 2,
    created_at: '2026-08-01 08:00:00',
    deletable: true,
    delete_blocked_reason: null,
    source_obligation: {
      kind: 'payroll_submission',
      status: 'ready',
      pending: true,
      agenda_code: 'JMHZ25',
      period: '2026-08',
    },
    ...overrides,
  }
}

async function mountWith(rows: OutboxSubmission[]) {
  m.credentials.mockResolvedValue([credential])
  m.recipients.mockResolvedValue([])
  m.outbox.mockResolvedValue(rows)
  m.inbox.mockResolvedValue({ items: [], state: null })
  m.unmatchedReceipts.mockResolvedValue([])
  m.mobileKeyProfile.mockResolvedValue({ saved: false, username: null, environment: 'production' })

  const wrapper = mount(DataBox, {
    global: { stubs: { EmptyState: true, RouterLink: { template: '<a><slot /></a>' } } },
  })
  await flushPromises()
  await wrapper.findAll('nav button')[1].trigger('click')
  return wrapper
}

describe('DataBox — smazání zrušené odchozí zprávy', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
    m.remove.mockReset()
    m.remove.mockResolvedValue({ deleted: true })
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('zrušenou zprávu bez stopy po odeslání smaže a v otázce pojmenuje, co mizí', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const wrapper = await mountWith([submission()])

    const button = wrapper.find('[data-test="outbox-delete"]')
    expect(button.exists()).toBe(true)
    await button.trigger('click')
    await flushPromises()

    // Otázka musí říct, CO mizí — u osmi podání za měsíc se to jinak splete.
    const question = String(confirm.mock.calls[0][0])
    expect(question).toContain('databox.outbox.deleteConfirm')
    expect(question).toContain('JMHZ25')
    expect(question).toContain('2026-08')
    expect(question).toContain('JMHZ25-20260801-ABCDEF')

    expect(m.remove).toHaveBeenCalledWith(4)
    // Po smazání se seznam načte znovu (mount + reload).
    expect(m.outbox).toHaveBeenCalledTimes(2)
  })

  it('bez potvrzení nemaže nic', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(false)
    const wrapper = await mountWith([submission()])

    await wrapper.find('[data-test="outbox-delete"]').trigger('click')
    await flushPromises()

    expect(m.remove).not.toHaveBeenCalled()
  })

  it('u zprávy, která z aplikace odešla, mazání vůbec nenabídne a řekne proč', async () => {
    const wrapper = await mountWith([
      submission({ deletable: false, delete_blocked_reason: 'sent' }),
    ])

    expect(wrapper.find('[data-test="outbox-delete"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="outbox-delete-blocked"]').text())
      .toContain('databox.outbox.deleteBlocked.sent')
  })

  it('u zprávy s doručenkou mazání nenabídne a uvede doručenku jako důvod', async () => {
    const wrapper = await mountWith([
      submission({
        deletable: false,
        delete_blocked_reason: 'receipt',
        receipt_document_id: 500,
        receipt_attached_at: '2026-08-02 09:00:00',
      }),
    ])

    expect(wrapper.find('[data-test="outbox-delete"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="outbox-delete-blocked"]').text())
      .toContain('databox.outbox.deleteBlocked.receipt')
  })

  it('u odeslané (nezrušené) zprávy se mazání nenabízí a nic se nevysvětluje', async () => {
    const wrapper = await mountWith([
      submission({
        dispatch_state: 'sent',
        external_message_id: '9900001',
        sent_at: '2026-08-01 09:00:00',
        deletable: undefined,
        delete_blocked_reason: undefined,
        source_obligation: undefined,
      }),
    ])

    expect(wrapper.find('[data-test="outbox-delete"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="outbox-delete-blocked"]').exists()).toBe(false)
  })

  it('u zrušené zprávy je vidět, že podání dál čeká na odeslání, i kam na ně', async () => {
    const wrapper = await mountWith([submission()])

    const note = wrapper.find('[data-test="outbox-source-still-pending"]')
    expect(note.exists()).toBe(true)
    expect(note.text()).toContain('databox.outbox.cancelledStillPending')
    expect(note.text()).toContain('JMHZ25')
    expect(note.text()).toContain('2026-08')
    expect(wrapper.find('[data-test="outbox-source-link"]').exists()).toBe(true)
  })

  it('když podání podané je, větu o čekání netvrdí', async () => {
    const wrapper = await mountWith([
      submission({
        source_obligation: {
          kind: 'payroll_submission',
          status: 'submitted',
          pending: false,
          agenda_code: 'JMHZ25',
          period: '2026-08',
        },
      }),
    ])

    expect(wrapper.find('[data-test="outbox-source-still-pending"]').exists()).toBe(false)
  })
})
