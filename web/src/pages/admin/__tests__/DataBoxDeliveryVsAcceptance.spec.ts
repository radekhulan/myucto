import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { DataBoxCredential, OutboxSubmission } from '@/api/dataBox'

/**
 * UI nesmí slít „doručeno" a „zpracováno" do jednoho stavu.
 *
 * Doručenka z datové schránky dokládá, že zpráva DORAZILA do schránky úřadu.
 * O tom, jestli ji úřad zpracoval a přijal, neříká nic — chyby přijdou až po
 * dnech jako výzva k odstranění vad. Kdyby UI ukázalo jediný zelený štítek
 * „Hotovo", uživatel by v dobré víře přestal podání sledovat.
 *
 * Ten samý požadavek hlídá na backendu `DeliveryIsNotAcceptanceTest` (slovník
 * a tvar) a `SubmissionOutboxInvariantsTest` (databáze). Tenhle test uzavírá
 * poslední vrstvu: co uživatel doopravdy uvidí.
 */

const m = vi.hoisted(() => ({
  credentials: vi.fn(),
  recipients: vi.fn(),
  outbox: vi.fn(),
  inbox: vi.fn(),
  pollInbox: vi.fn(),
  pollInboxWithPassword: vi.fn(),
  startMobileKeyInbox: vi.fn(),
  mobileKeyInboxStatus: vi.fn(),
  mobileKeyProfile: vi.fn(),
  saveMobileKeyProfile: vi.fn(),
  deleteMobileKeyProfile: vi.fn(),
  startSmsInbox: vi.fn(),
  completeSmsInbox: vi.fn(),
  unmatchedReceipts: vi.fn(),
  toastSuccess: vi.fn(),
  toastWarning: vi.fn(),
  toastError: vi.fn(),
}))

vi.mock('@/api/dataBox', () => ({
  dataBoxApi: {
    credentials: m.credentials,
    recipients: m.recipients,
    outbox: m.outbox,
    inbox: m.inbox,
    pollInbox: m.pollInbox,
    pollInboxWithPassword: m.pollInboxWithPassword,
    startMobileKeyInbox: m.startMobileKeyInbox,
    mobileKeyInboxStatus: m.mobileKeyInboxStatus,
    mobileKeyProfile: m.mobileKeyProfile,
    saveMobileKeyProfile: m.saveMobileKeyProfile,
    deleteMobileKeyProfile: m.deleteMobileKeyProfile,
    startSmsInbox: m.startSmsInbox,
    completeSmsInbox: m.completeSmsInbox,
    unmatchedReceipts: m.unmatchedReceipts,
  },
}))

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (e: unknown) => String(e) }))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, warning: m.toastWarning, error: m.toastError }),
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
    dispatch_state: 'delivered',
    acceptance_state: 'unknown',
    acceptance_evidence_kind: null,
    acceptance_note: null,
    correlation_reference: 'DPHDP3-20260815-ABCDEF',
    external_message_id: 'DM-1',
    artifact_validation_status: 'passed',
    recipient_box_verified_at: '2026-08-15 09:00:00',
    receipt_document_id: null,
    receipt_signature_status: 'unverified',
    receipt_matched_by: null,
    receipt_inbox_message_id: null,
    receipt_attached_at: null,
    confirmed_by: 1,
    confirmed_at: '2026-08-15 09:00:00',
    sent_at: '2026-08-15 09:00:00',
    delivered_at: '2026-08-15 10:00:00',
    accepted_at: null,
    rejected_at: null,
    last_error_code: null,
    last_error_message: null,
    row_version: 4,
    created_at: '2026-08-15 08:00:00',
    ...overrides,
  }
}

async function mountWith(
  rows: OutboxSubmission[],
  credentials: DataBoxCredential[] = [credential],
  mobileProfile = { saved: false, username: null as string | null, environment: 'production' as const },
) {
  m.credentials.mockResolvedValue(credentials)
  m.recipients.mockResolvedValue([])
  m.outbox.mockResolvedValue(rows)
  m.inbox.mockResolvedValue({ items: [], state: null })
  m.pollInbox.mockResolvedValue({ fetched: 0, stored: 0, skipped: 0, failed: 0, unclassified: 0 })
  m.pollInboxWithPassword.mockResolvedValue({ fetched: 0, stored: 0, skipped: 0, failed: 0, unclassified: 0 })
  m.unmatchedReceipts.mockResolvedValue([])
  m.mobileKeyProfile.mockResolvedValue(mobileProfile)
  m.startMobileKeyInbox.mockResolvedValue({
    flow_token: 'encrypted-mobile-flow',
    state: 1,
    description: 'Čeká na potvrzení.',
    expires_at: '2026-08-25T20:00:00+02:00',
  })
  m.saveMobileKeyProfile.mockResolvedValue({
    id: 1,
    saved: true,
    username: 'mobile-user',
    environment: 'production',
  })
  m.startSmsInbox.mockResolvedValue({
    flow_token: 'encrypted-sms-flow',
    description: 'SMS byla odeslána.',
    expires_at: '2026-08-25T20:00:00+02:00',
  })
  m.completeSmsInbox.mockResolvedValue({ fetched: 0, stored: 0, skipped: 0, failed: 0, unclassified: 0 })

  const wrapper = mount(DataBox, {
    global: {
      stubs: { EmptyState: true },
    },
  })
  await flushPromises()
  return wrapper
}

describe('DataBox — doručeno vs. zpracováno', () => {
  it('u doručeného podání ukáže obě osy zvlášť a vyřízení nechá na „nevíme“', async () => {
    const wrapper = await mountWith([submission()])
    await wrapper.findAll('nav button')[1].trigger('click')

    const text = wrapper.text()
    // Doprava: doručeno.
    expect(text).toContain('databox.dispatch.delivered')
    // Vyřízení: pořád neznámé — NIKDY ne „accepted“.
    expect(text).toContain('databox.acceptance.unknown')
    expect(text).not.toContain('databox.acceptance.accepted')
  })

  it('u doručeného podání vysvětlí větou, že úřad ještě nerozhodl', async () => {
    const wrapper = await mountWith([submission()])
    await wrapper.findAll('nav button')[1].trigger('click')

    expect(wrapper.text()).toContain('databox.outbox.deliveredNotProcessed')
  })

  it('teprve doložené přijetí ukáže „zpracováno“', async () => {
    const wrapper = await mountWith([
      submission({
        acceptance_state: 'accepted',
        acceptance_evidence_kind: 'agency_protocol_message',
        accepted_at: '2026-08-20 12:00:00',
      }),
    ])
    await wrapper.findAll('nav button')[1].trigger('click')

    expect(wrapper.text()).toContain('databox.acceptance.accepted')
    // Vysvětlující věta o nerozhodnutém podání už tam nemá co dělat.
    expect(wrapper.text()).not.toContain('databox.outbox.deliveredNotProcessed')
  })

  it('u nejistého odeslání nenabídne odeslat znovu, ale dohledat', async () => {
    const wrapper = await mountWith([
      submission({
        dispatch_state: 'send_uncertain',
        external_message_id: null,
        sent_at: null,
        delivered_at: null,
      }),
    ])
    await wrapper.findAll('nav button')[1].trigger('click')

    const text = wrapper.text()
    expect(text).toContain('databox.outbox.resolve')
    expect(text).toContain('databox.outbox.uncertainHint')
    // Opakované odeslání by u úřadu vyrobilo duplicitu.
    expect(text).not.toContain('databox.outbox.confirmSend')
  })

  it('schránku vyzvedne jen po výslovné akci a potvrzení uživatele', async () => {
    const wrapper = await mountWith([])
    await wrapper.findAll('nav button')[2].trigger('click')

    expect(wrapper.text()).toContain('databox.inbox.manualOnly')
    expect(wrapper.find('input[type="radio"][value="mobile_key"]').exists()).toBe(true)
    const certificate = wrapper.find('input[type="radio"][value="certificate"]')
    await certificate.setValue()
    const fetchButton = wrapper.findAll('button').find(b => b.text().includes('databox.inbox.fetchOnce'))
    await fetchButton?.trigger('click')
    expect(m.pollInbox).not.toHaveBeenCalled()

    await wrapper.find('input[type="checkbox"]').setValue(true)
    await fetchButton?.trigger('click')
    await flushPromises()
    expect(m.pollInbox).toHaveBeenCalledWith('production')
  })

  it('částečné selhání stažení ukáže jako varování, ne jako úspěch', async () => {
    const wrapper = await mountWith([])
    m.toastSuccess.mockClear()
    m.toastWarning.mockClear()
    m.pollInbox.mockResolvedValue({
      fetched: 2,
      stored: 1,
      skipped: 0,
      failed: 1,
      unclassified: 0,
      error: 'isds_inbox_message_ingest_failed',
    })
    await wrapper.findAll('nav button')[2].trigger('click')
    await wrapper.find('input[type="radio"][value="certificate"]').setValue()
    await wrapper.find('input[type="checkbox"]').setValue(true)
    const fetchButton = wrapper.findAll('button').find(b => b.text().includes('databox.inbox.fetchOnce'))
    await fetchButton?.trigger('click')
    await flushPromises()

    expect(m.toastWarning).toHaveBeenCalledWith('databox.inbox.polledWithErrors')
    expect(m.toastSuccess).not.toHaveBeenCalledWith('databox.inbox.polled')
  })

  it('nabídne Mobilní klíč a jednorázové heslo i bez uloženého certifikátu', async () => {
    const wrapper = await mountWith([], [])
    await wrapper.findAll('nav button')[2].trigger('click')

    expect(wrapper.find('input[type="radio"][value="mobile_key"]').exists()).toBe(true)
    expect(wrapper.find('input[type="radio"][value="password"]').exists()).toBe(true)
    expect(wrapper.find('input[autocomplete="username"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('databox.inbox.communicationCode')
  })

  it('použije osobně uložený Mobilní klíč bez opětovného zobrazení kódu', async () => {
    const wrapper = await mountWith([], [], { saved: true, username: 'saved-user', environment: 'production' })
    await wrapper.findAll('nav button')[2].trigger('click')

    expect((wrapper.find('input[autocomplete="username"]').element as HTMLInputElement).value).toBe('saved-user')
    await wrapper.find('input[type="checkbox"]').setValue(true)
    const button = wrapper.findAll('button').find(b => b.text().includes('databox.inbox.startMobileKey'))
    await button?.trigger('click')
    await flushPromises()

    expect(m.startMobileKeyInbox).toHaveBeenCalledWith('production', 'saved-user', '', true)
    wrapper.unmount()
  })

  it('uloží osobní Mobilní klíč až po úspěšném zahájení přihlášení', async () => {
    const wrapper = await mountWith([], [])
    await wrapper.findAll('nav button')[2].trigger('click')
    await wrapper.find('input[autocomplete="username"]').setValue('mobile-user')
    await wrapper.find('input[autocomplete="off"]').setValue('communication-code')
    const checkboxes = wrapper.findAll('input[type="checkbox"]')
    await checkboxes[0].setValue(true)
    await checkboxes[1].setValue(true)

    const button = wrapper.findAll('button').find(b => b.text().includes('databox.inbox.startMobileKey'))
    await button?.trigger('click')
    await flushPromises()

    expect(m.startMobileKeyInbox).toHaveBeenCalledWith(
      'production',
      'mobile-user',
      'communication-code',
      false,
    )
    expect(m.startMobileKeyInbox.mock.invocationCallOrder.at(-1)).toBeLessThan(
      m.saveMobileKeyProfile.mock.invocationCallOrder.at(-1) ?? 0,
    )
    expect(m.saveMobileKeyProfile).toHaveBeenCalledWith(
      'production',
      'mobile-user',
      'communication-code',
    )
  })

  it('zadá SMS kód v druhém kroku a teprve potom načte schránku', async () => {
    const wrapper = await mountWith([], [])
    await wrapper.findAll('nav button')[2].trigger('click')
    await wrapper.find('input[type="radio"][value="sms"]').setValue()
    await wrapper.find('input[autocomplete="username"]').setValue('sms-user')
    await wrapper.find('input[autocomplete="current-password"]').setValue('one-time-password')
    await wrapper.find('input[type="checkbox"]').setValue(true)

    const send = wrapper.findAll('button').find(b => b.text().includes('databox.inbox.sendSms'))
    await send?.trigger('click')
    await flushPromises()
    expect(m.startSmsInbox).toHaveBeenCalledWith('production', 'sms-user', 'one-time-password')

    await wrapper.find('input[autocomplete="one-time-code"]').setValue('123456')
    const complete = wrapper.findAll('button').find(b => b.text().includes('databox.inbox.confirmSmsAndFetch'))
    await complete?.trigger('click')
    await flushPromises()
    expect(m.completeSmsInbox).toHaveBeenCalledWith('encrypted-sms-flow', '123456', 'production')
  })
})
