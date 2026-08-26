import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { DataBoxCredential, InboxMessage, OutboxSubmission } from '@/api/dataBox'

/**
 * Ruční cesta: aplikace podání připraví, člověk ho odešle ze své datové
 * schránky a přinese zpátky doručenku.
 *
 * Co tenhle test hlídá:
 *   1. u připraveného podání je vidět KONKRÉTNÍ postup, ne obecná nápověda,
 *   2. spisová značka je v postupu vidět — bez ní se doručenka nespáruje sama,
 *   3. připojená doručenka se NIKDY netváří jako ověřená,
 *   4. nespárovaná doručenka je vidět a nabízí kandidáty místo prázdna.
 */

const m = vi.hoisted(() => ({
  credentials: vi.fn(),
  recipients: vi.fn(),
  outbox: vi.fn(),
  inbox: vi.fn(),
  mobileKeyProfile: vi.fn(),
  unmatchedReceipts: vi.fn(),
  receiptCandidates: vi.fn(),
  matchReceipt: vi.fn(),
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
    receiptCandidates: m.receiptCandidates,
    matchReceipt: m.matchReceipt,
  },
}))

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
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
  certificate_fingerprint: null,
  certificate_valid_to: null,
  last_verified_at: null,
  inbox_polling_enabled: false,
  inbox_polling_enabled_at: null,
  inbox_polling_enabled_by: null,
}

function submission(overrides: Partial<OutboxSubmission> = {}): OutboxSubmission {
  return {
    id: 10,
    environment: 'production',
    channel: 'isds',
    dispatch_mode: 'channel',
    agenda_code: 'DPHDP3',
    recipient_id: 1,
    recipient_box_id: 'zzzzzzz',
    subject: 'Přiznání k DPH',
    artifact_kind: 'tax_submission',
    artifact_id: 5,
    artifact_filename: 'dphdp3.xml',
    dispatch_state: 'ready',
    acceptance_state: 'unknown',
    acceptance_evidence_kind: null,
    acceptance_note: null,
    correlation_reference: 'DPHDP3-20260815-ABCDEF',
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
    row_version: 1,
    created_at: '2026-08-15 08:00:00',
    ...overrides,
  }
}

function receipt(overrides: Partial<InboxMessage> = {}): InboxMessage {
  return {
    id: 77,
    external_message_id: '9900001',
    sender_box_id: 'abcdefg',
    sender_name: 'Naše firma',
    subject: 'Přiznání k DPH',
    sender_ident: null,
    classification: 'delivery_receipt',
    matched_outbox_id: null,
    document_id: 500,
    signature_status: 'unverified',
    delivered_at: '2026-08-15 10:00:00',
    accepted_at: null,
    fetched_at: '2026-08-15 10:05:00',
    hidden_at: null,
    hidden_by: null,
    local_content_state: 'available',
    local_content_purged_at: null,
    local_content_purged_by: null,
    lifecycle_row_version: 1,
    ...overrides,
  }
}

async function mountWith(rows: OutboxSubmission[], unmatched: InboxMessage[] = []) {
  m.credentials.mockResolvedValue([credential])
  m.recipients.mockResolvedValue([])
  m.outbox.mockResolvedValue(rows)
  m.inbox.mockResolvedValue({ items: [], state: null })
  m.unmatchedReceipts.mockResolvedValue(unmatched)
  m.mobileKeyProfile.mockResolvedValue({ saved: false, username: null, environment: 'production' })

  const wrapper = mount(DataBox, { global: { stubs: { EmptyState: true } } })
  await flushPromises()
  await wrapper.findAll('nav button')[1].trigger('click')
  return wrapper
}

describe('DataBox — ruční odeslání a doručenka', () => {
  it('u připraveného podání ukáže konkrétní postup i se spisovou značkou', async () => {
    const wrapper = await mountWith([submission()])

    const text = wrapper.text()
    expect(text).toContain('databox.manual.title')
    expect(text).toContain('databox.manual.step1')
    expect(text).toContain('databox.manual.step4')
    // Bez značky se doručenka nespáruje sama — musí být vidět, ne schovaná.
    expect(text).toContain('DPHDP3-20260815-ABCDEF')
  })

  it('nabídne „označit jako odesláno“ i nahrání doručenky', async () => {
    const wrapper = await mountWith([submission()])

    const text = wrapper.text()
    expect(text).toContain('databox.outbox.markSent')
    expect(text).toContain('databox.outbox.uploadReceipt')
  })

  it('po klepnutí na „označit jako odesláno“ si vyžádá ID zprávy', async () => {
    const wrapper = await mountWith([submission()])
    const button = wrapper.findAll('button').find(b => b.text().includes('databox.outbox.markSent'))
    await button!.trigger('click')

    expect(wrapper.text()).toContain('databox.outbox.messageIdLabel')
    expect(wrapper.text()).toContain('databox.outbox.messageIdHint')
  })

  it('u připojené doručenky říká nahlas, že podpis neověřuje', async () => {
    const wrapper = await mountWith([
      submission({
        dispatch_state: 'delivered',
        dispatch_mode: 'manual',
        external_message_id: '9900001',
        sent_at: '2026-08-15 09:00:00',
        delivered_at: '2026-08-15 10:00:00',
        receipt_document_id: 500,
        receipt_matched_by: 'correlation_reference',
        receipt_attached_at: '2026-08-15 10:05:00',
        artifact_validation_status: 'skipped',
      }),
    ])

    const text = wrapper.text()
    expect(text).toContain('databox.outbox.receiptAttached')
    expect(text).toContain('databox.outbox.receiptUnverified')
    // A pořád platí: doručeno není vyřízeno.
    expect(text).toContain('databox.acceptance.unknown')
    expect(text).not.toContain('databox.acceptance.accepted')
  })

  it('nespárovanou doručenku ukáže a nabídne k ní kandidáty', async () => {
    m.receiptCandidates.mockResolvedValue([
      {
        id: 10,
        subject: 'Přiznání k DPH',
        agenda_code: 'DPHDP3',
        recipient_box_id: 'zzzzzzz',
        dispatch_state: 'sent',
        correlation_reference: 'DPHDP3-20260815-ABCDEF',
        created_at: '2026-08-15 08:00:00',
        reasons: ['recipient_box'],
      },
    ])
    const wrapper = await mountWith([submission()], [receipt()])

    expect(wrapper.text()).toContain('databox.receipts.title')
    expect(wrapper.text()).toContain('9900001')

    const button = wrapper.findAll('button').find(b => b.text().includes('databox.receipts.showCandidates'))
    await button!.trigger('click')
    await flushPromises()

    expect(m.receiptCandidates).toHaveBeenCalledWith(77)
    expect(wrapper.text()).toContain('databox.receipts.assign')
    expect(wrapper.text()).toContain('databox.receipts.reasons.recipient_box')
  })

  it('u ručně odeslaného podání bez doručenky řekne, že se na ni čeká', async () => {
    const wrapper = await mountWith([
      submission({
        dispatch_state: 'sent',
        dispatch_mode: 'manual',
        external_message_id: '9900001',
        sent_at: '2026-08-15 09:00:00',
        artifact_validation_status: 'skipped',
      }),
    ])

    expect(wrapper.text()).toContain('databox.outbox.manualDispatch')
    expect(wrapper.text()).toContain('databox.outbox.uploadReceipt')
  })
})
