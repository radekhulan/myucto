import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { DataBoxCredential, DefectNotice, InboxMessage } from '@/api/dataBox'

/**
 * Co uživatel uvidí o DORUČENÍ a o jeho následcích.
 *
 * Dvě věci, které se tu hlídají a které by jinak tiše zmizely:
 *
 * 1. **Běžící lhůta fikce není doručení.** Zpráva dodaná před třemi dny je
 *    dodaná, ne doručená — a to je jiný stav než „nevíme". Kdyby UI ukázalo
 *    obojí stejně, uživatel by od dodání počítal lhůty, které ještě neběží.
 *
 * 2. **Prázdný seznam výzev neznamená, že žádná nepřišla.** Aplikace výzvy
 *    podle § 74 DŘ z datové schránky sama nerozpoznává, takže „nic tu není"
 *    smí znamenat jen „nic jsme nezaevidovali". Tuhle větu musí obrazovka
 *    říct nahlas, jinak z ticha vznikne falešná jistota.
 *
 * Na backendu totéž hlídají `DeliveryFictionCalculatorTest`,
 * `DefectNoticeAssessorTest` a `DeliveryFictionAndDefectNoticeTest`.
 */

const m = vi.hoisted(() => ({
  credentials: vi.fn(),
  recipients: vi.fn(),
  outbox: vi.fn(),
  inbox: vi.fn(),
  mobileKeyProfile: vi.fn(),
  unmatchedReceipts: vi.fn(),
  defectNotices: vi.fn(),
  refreshDelivery: vi.fn(),
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
    defectNotices: m.defectNotices,
    refreshDelivery: m.refreshDelivery,
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

function message(overrides: Partial<InboxMessage> = {}): InboxMessage {
  return {
    id: 7,
    external_message_id: 'DM-77',
    sender_box_id: 'zzzzzzz',
    sender_name: 'Finanční úřad',
    subject: 'Výzva k odstranění vad podání',
    sender_ident: null,
    classification: 'tax_office_response',
    matched_outbox_id: null,
    document_id: null,
    signature_status: 'unverified',
    delivered_at: '2026-03-02 09:15:00',
    accepted_at: null,
    fetched_at: '2026-03-02 10:00:00',
    hidden_at: null,
    hidden_by: null,
    local_content_state: 'available',
    local_content_purged_at: null,
    local_content_purged_by: null,
    lifecycle_row_version: 1,
    delivery_basis: 'pending',
    delivered_on: null,
    fiction_statutory_on: '2026-03-12',
    fiction_due_on: '2026-03-12',
    fiction_days: 10,
    fiction_days_source: 'statute',
    sender_is_public_authority: true,
    delivery_resolved_at: '2026-03-02 10:00:01',
    delivery_note: 'Zpráva je dodaná, doručená zatím není.',
    ...overrides,
  }
}

function notice(overrides: Partial<DefectNotice> = {}): DefectNotice {
  return {
    id: 3,
    environment: 'production',
    outbox_id: null,
    inbox_message_id: 7,
    notice_reference: '1234567/26/2001',
    authority_kind: 'tax_office',
    defect_ground: 'a_not_processable',
    consequence: 'ineffective',
    delivered_on: '2026-03-12',
    respond_by_on: '2026-03-27',
    respond_by_source: 'derived_from_days',
    stated_period_days: 15,
    respond_by_shifted: false,
    status: 'open',
    responded_on: null,
    response_outbox_id: null,
    outcome: 'unknown',
    note: null,
    row_version: 1,
    created_at: '2026-03-12 10:00:00',
    assessment: {
      status: 'open',
      consequence: 'ineffective',
      outcome: 'unknown',
      respond_by_on: '2026-03-27',
      respond_by_source: 'derived_from_days',
      respond_by_shifted: false,
      days_left: 5,
      sentence: 'Vadu je potřeba odstranit do 27. 3. 2026.',
      suspiciously_short_period: false,
      needs_attention: true,
    },
    ...overrides,
  }
}

async function mountWith(options: {
  messages?: InboxMessage[]
  notices?: DefectNotice[]
  noticesResult?: { supported: boolean; items: DefectNotice[]; notice: string }
} = {}) {
  m.credentials.mockResolvedValue([credential])
  m.recipients.mockResolvedValue([])
  m.outbox.mockResolvedValue([])
  m.inbox.mockResolvedValue({ items: options.messages ?? [], state: null })
  m.unmatchedReceipts.mockResolvedValue([])
  m.mobileKeyProfile.mockResolvedValue({ saved: false, username: null, environment: 'production' })
  m.defectNotices.mockResolvedValue(options.noticesResult ?? {
    supported: true,
    items: options.notices ?? [],
    notice: 'Výzvy sem zapisuje člověk.',
  })

  const wrapper = mount(DataBox, { global: { stubs: { EmptyState: true } } })
  await flushPromises()
  return wrapper
}

/** Karty jsou: Přístup, Odchozí, Příchozí, Výzvy, Příjemci. */
async function openTab(wrapper: Awaited<ReturnType<typeof mountWith>>, index: number) {
  await wrapper.findAll('nav button')[index].trigger('click')
}

describe('DataBox — rozhodný den doručení', () => {
  it('zpřístupní stažený ZFO a jeho přílohy přes uložený dokument', async () => {
    const wrapper = await mountWith({ messages: [message({ document_id: 500 })] })
    await openTab(wrapper, 2)

    expect(wrapper.text()).toContain('databox.inbox.openMessage')
  })

  it('běžící lhůtu fikce ukáže jako „doručeno není“, ne jako doručení', async () => {
    const wrapper = await mountWith({ messages: [message()] })
    await openTab(wrapper, 2)

    const text = wrapper.text()
    expect(text).toContain('databox.delivery.basis.pending')
    expect(text).toContain('databox.delivery.fictionDueOn')
    expect(text).not.toContain('databox.delivery.deliveredOn')
  })

  it('doručení fikcí pojmenuje i s dnem, od kterého běží lhůty', async () => {
    const wrapper = await mountWith({
      messages: [message({ delivery_basis: 'fiction', delivered_on: '2026-03-12' })],
    })
    await openTab(wrapper, 2)

    expect(wrapper.text()).toContain('databox.delivery.basis.fiction')
    expect(wrapper.text()).toContain('databox.delivery.deliveredOn')
  })

  it('neurčené doručení se nepřevleče za „v pořádku“', async () => {
    const wrapper = await mountWith({
      messages: [message({
        delivery_basis: 'unknown',
        delivered_on: null,
        fiction_due_on: null,
        fiction_statutory_on: null,
        sender_is_public_authority: null,
        delivery_note: 'Odesílatel není doložený jako orgán veřejné moci.',
      })],
    })
    await openTab(wrapper, 2)

    const text = wrapper.text()
    expect(text).toContain('databox.delivery.basis.unknown')
    expect(text).toContain('Odesílatel není doložený jako orgán veřejné moci.')
    expect(text).not.toContain('databox.delivery.deliveredOn')
  })
})

describe('DataBox — výzvy k odstranění vad', () => {
  it('prázdný seznam doprovodí větou, že to neznamená „nic nepřišlo“', async () => {
    const wrapper = await mountWith()
    await openTab(wrapper, 3)

    expect(wrapper.text()).toContain('Výzvy sem zapisuje člověk.')
  })

  it('řekne, že lhůtu si aplikace nedomýšlí', async () => {
    const wrapper = await mountWith()
    await openTab(wrapper, 3)

    expect(wrapper.text()).toContain('databox.notices.deadlineHint')
  })

  it('u běžící lhůty ukáže větu, podle které se dá jednat', async () => {
    const wrapper = await mountWith({ notices: [notice()] })
    await openTab(wrapper, 3)

    const text = wrapper.text()
    expect(text).toContain('databox.notices.statuses.open')
    expect(text).toContain('Vadu je potřeba odstranit do 27. 3. 2026.')
    expect(text).toContain('databox.notices.grounds.a_not_processable')
  })

  it('nevyřízené výzvy hlásí odznakem u karty', async () => {
    const wrapper = await mountWith({ notices: [notice()] })

    expect(wrapper.findAll('nav button')[3].text()).toContain('1')
  })

  it('selhání načtení výzev se netváří jako prázdný seznam', async () => {
    m.credentials.mockResolvedValue([credential])
    m.recipients.mockResolvedValue([])
    m.outbox.mockResolvedValue([])
    m.inbox.mockResolvedValue({ items: [], state: null })
    m.unmatchedReceipts.mockResolvedValue([])
    m.mobileKeyProfile.mockResolvedValue({ saved: false, username: null, environment: 'production' })
    m.defectNotices.mockRejectedValue(new Error('spojení selhalo'))

    const wrapper = mount(DataBox, { global: { stubs: { EmptyState: true } } })
    await flushPromises()
    await openTab(wrapper, 3)

    expect(wrapper.text()).toContain('spojení selhalo')
  })

  it('přepočet doručení řekne, kolika zpráv se týkal', async () => {
    m.refreshDelivery.mockResolvedValue({ checked: 3, changed: 1, delivered_by_fiction: 1 })
    const wrapper = await mountWith({ messages: [message()] })
    await openTab(wrapper, 2)

    const buttons = wrapper.findAll('button').filter(b => b.text().includes('databox.delivery.refresh'))
    expect(buttons.length).toBe(1)
    await buttons[0].trigger('click')
    await flushPromises()

    expect(m.refreshDelivery).toHaveBeenCalledWith('production')
    expect(m.toastSuccess).toHaveBeenCalledWith('databox.delivery.refreshed')
  })
})
