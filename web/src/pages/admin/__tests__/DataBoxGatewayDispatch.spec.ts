import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { DataBoxCredential, OutboxSubmission } from '@/api/dataBox'

/**
 * Odesílací brána ISDS na obrazovce Firma → Datová schránka.
 *
 * Co tenhle test hlídá — samé věci, které je u podání drahé zkazit:
 *   1. tlačítko se nabízí JEN když je brána zaregistrovaná a zapnutá; když
 *      o registracích nevíme (403 bez práva), ruční cesta zůstává beze změny,
 *   2. tlačítko NEODESÍLÁ — jen pošle prohlížeč do datové schránky,
 *   3. návrat z ISDS se zpracuje a jednorázové `sessionId` zmizí z adresy,
 *   4. nejistý konec zůstane na obrazovce s pokynem „neodesílejte znovu",
 *      ne jako toast, který za pět vteřin zmizí.
 */

const m = vi.hoisted(() => ({
  credentials: vi.fn(),
  recipients: vi.fn(),
  outbox: vi.fn(),
  inbox: vi.fn(),
  mobileKeyProfile: vi.fn(),
  unmatchedReceipts: vi.fn(),
  defectNotices: vi.fn(),
  gatewayCapabilities: vi.fn(),
  gatewayStart: vi.fn(),
  gatewayComplete: vi.fn(),
  gatewayCompletePayroll: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
  toastInfo: vi.fn(),
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
    gatewayCapabilities: m.gatewayCapabilities,
    gatewayStart: m.gatewayStart,
    gatewayComplete: m.gatewayComplete,
    gatewayCompletePayroll: m.gatewayCompletePayroll,
  },
}))

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/composables/useFormat', () => ({ formatUtcDateTime: (value: string) => value }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (e: unknown) => String(e) }))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError, info: m.toastInfo }),
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
    subject: 'Přiznání k DPH',
    period_code: '2026-07',
    recipient_box_id: 'abcdefg',
    recipient_name: 'Finanční úřad',
    artifact_kind: 'vat_return',
    artifact_id: 1,
    artifact_filename: 'dph.xml',
    artifact_sha256: 'x',
    artifact_validation_status: 'passed',
    correlation_reference: 'MU-2026-07-DPH',
    dispatch_state: 'ready',
    acceptance_state: 'unknown',
    acceptance_evidence: null,
    external_message_id: null,
    delivered_at: null,
    accepted_at: null,
    receipt_document_id: null,
    receipt_attached_at: null,
    receipt_matched_by: null,
    last_error_code: null,
    last_error_message: null,
    row_version: 1,
    ...overrides,
  } as OutboxSubmission
}

let assign: ReturnType<typeof vi.fn>
let replaceState: ReturnType<typeof vi.fn>

beforeEach(() => {
  vi.clearAllMocks()
  m.credentials.mockResolvedValue([credential])
  m.recipients.mockResolvedValue([])
  m.outbox.mockResolvedValue([submission()])
  m.inbox.mockResolvedValue({ items: [], state: null })
  m.unmatchedReceipts.mockResolvedValue([])
  m.mobileKeyProfile.mockResolvedValue({ saved: false, username: null, environment: 'production' })
  m.defectNotices.mockResolvedValue({ items: [], supported: true, notice: null })
  m.gatewayCapabilities.mockResolvedValue([{ environment: 'production', available: true }])

  assign = vi.fn()
  replaceState = vi.fn()
  Object.defineProperty(window, 'location', {
    configurable: true,
    value: { assign, search: '', pathname: '/admin/databox' },
  })
  Object.defineProperty(window, 'history', {
    configurable: true,
    value: { replaceState },
  })
})

afterEach(() => {
  vi.clearAllMocks()
})

/** Stránka startuje na záložce Přístup; fronta podání je až na „Odchozí". */
async function mountPage(openOutbox = true) {
  const wrapper = mount(DataBox)
  await flushPromises()
  if (openOutbox) {
    const tab = wrapper.findAll('button').find(b => b.text().includes('databox.tabs.outbox'))
    await tab!.trigger('click')
    await flushPromises()
  }
  await wrapper.vm.$nextTick()

  return wrapper
}

describe('odesílací brána na obrazovce datové schránky', () => {
  it('nabídne přípravu v datové schránce, když je brána zapnutá', async () => {
    const wrapper = await mountPage()

    expect(wrapper.text()).toContain('databox.gateway.prepare')
  })

  it('nenabídne ji, když je registrace vypnutá', async () => {
    m.gatewayCapabilities.mockResolvedValue([{ environment: 'production', available: false }])

    const wrapper = await mountPage()

    expect(wrapper.text()).not.toContain('databox.gateway.prepare')
    // Ruční cesta zůstává — uživatel nesmí zůstat bez možnosti podat.
    expect(wrapper.text()).toContain('databox.outbox.markSent')
    expect(wrapper.text()).not.toContain('databox.outbox.confirmSend')
  })

  /**
   * 403 bez práva `settings.signing` znamená „nevíme", ne „brána není".
   * Nabízet tlačítko, které skončí překážkou, by uživatele poslalo do zdi.
   */
  it('nenabídne ji, když o registracích nic nevíme', async () => {
    m.gatewayCapabilities.mockRejectedValue(new Error('403'))

    const wrapper = await mountPage()

    expect(wrapper.text()).not.toContain('databox.gateway.prepare')
    expect(m.toastError).not.toHaveBeenCalled()
  })

  it('registrace z jiného prostředí se nepočítá', async () => {
    m.gatewayCapabilities.mockResolvedValue([{ environment: 'test', available: true }])

    const wrapper = await mountPage()

    expect(wrapper.text()).not.toContain('databox.gateway.prepare')
  })

  /** Instrukce a hranice přihlašovacích údajů musí být vidět před odchodem. */
  it('ukáže přihlašovací instrukci a přesměruje až po potvrzení', async () => {
    m.gatewayStart.mockResolvedValue({
      session_id: 1,
      app_token: '123456789012345678',
      redirect_url: 'https://www.datovka.gov.cz/as/login?atsId=ATS-1&appToken=123456789012345678',
      login_guidance: 'Přihlásíte se přímo v ISDS.',
      login_policy_documented: false,
      expires_at: '2026-08-20 12:00:00',
      resumed: false,
    })
    const wrapper = await mountPage()

    const button = wrapper.findAll('button').find(b => b.text().includes('databox.gateway.prepare'))
    await button!.trigger('click')
    await flushPromises()

    expect(m.gatewayStart).toHaveBeenCalledWith(10)
    expect(assign).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="gateway-preflight"]').text()).toContain(
      'Přihlásíte se přímo v ISDS.',
    )
    expect(wrapper.text()).toContain('databox.gateway.credentialsStayInIsds')
    expect(wrapper.text()).toContain('databox.gateway.methodsByIsds')

    const continueButton = wrapper.findAll('button').find(b =>
      b.text().includes('databox.gateway.continueToIsds'),
    )
    await continueButton!.trigger('click')
    expect(assign).toHaveBeenCalledWith(expect.stringContaining('/as/login'))
  })
})

describe('návrat z datové schránky', () => {
  beforeEach(() => {
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { assign, search: '?appToken=123456789012345678&sessionId=S-1', pathname: '/admin/databox' },
    })
  })

  it('zpracuje návrat a odstraní jednorázové sessionId z adresy', async () => {
    m.gatewayComplete.mockResolvedValue({
      state: 'approved',
      outbox_id: 10,
      redirect_url: null,
      external_message_id: 'DM-9000',
      message: 'Zpráva odešla z vaší datové schránky.',
    })

    const wrapper = await mountPage()

    expect(m.gatewayComplete).toHaveBeenCalledWith('123456789012345678', 'S-1')
    // Obnovení stránky se spotřebovaným sessionId by skončilo SESSION_NOT_FOUND.
    expect(replaceState).toHaveBeenCalledWith({}, '', '/admin/databox')
    expect(wrapper.text()).toContain('databox.gateway.state.approved')
    expect(wrapper.text()).toContain('DM-9000')
    // Doručenku brána stáhnout neumí — musí to být vidět hned.
    expect(wrapper.text()).toContain('databox.gateway.receiptManual')
  })

  it('po vložení konceptu pošle uživatele na schválení', async () => {
    m.gatewayComplete.mockResolvedValue({
      state: 'awaiting_approval',
      outbox_id: 10,
      redirect_url: 'https://www.datovka.gov.cz/as/koncept/view?konceptId=K-1',
      external_message_id: null,
      message: 'Zpráva je připravená v datové schránce.',
    })

    await mountPage()

    expect(assign).toHaveBeenCalledWith(expect.stringContaining('/as/koncept/view'))
  })

  /**
   * ⚠️ Nejdůležitější případ. Zpráva MOHLA odejít; pokyn „neodesílejte znovu"
   * nesmí zmizet s toastem.
   */
  it('nejistý konec zůstane na obrazovce', async () => {
    m.gatewayComplete.mockRejectedValue(new Error('Datová schránka neodpověděla a není jisté, jestli zpráva odešla.'))

    const wrapper = await mountPage()

    expect(wrapper.text()).toContain('databox.gateway.state.uncertain')
    expect(wrapper.text()).toContain('není jisté')
  })

  it('zamítnutí uživatelem se nehlásí jako chyba', async () => {
    m.gatewayComplete.mockResolvedValue({
      state: 'rejected',
      outbox_id: 10,
      redirect_url: null,
      external_message_id: null,
      message: 'Odeslání jste v datové schránce zamítli.',
    })

    const wrapper = await mountPage()

    expect(wrapper.text()).toContain('databox.gateway.state.rejected')
    expect(m.toastError).not.toHaveBeenCalled()
  })

  it('mzdovou relaci dokončí přes payroll oprávnění, když obecná cesta vrátí 403', async () => {
    const forbidden = Object.assign(new Error('Forbidden'), {
      isAxiosError: true,
      response: { status: 403 },
    })
    m.gatewayComplete.mockRejectedValue(forbidden)
    m.gatewayCompletePayroll.mockResolvedValue({
      state: 'approved',
      outbox_id: 10,
      redirect_url: null,
      external_message_id: 'DM-JMHZ-1',
      message: 'Mzdové podání odešlo.',
    })

    const wrapper = await mountPage()

    expect(m.gatewayCompletePayroll).toHaveBeenCalledWith('123456789012345678', 'S-1')
    expect(wrapper.text()).toContain('DM-JMHZ-1')
  })
})
