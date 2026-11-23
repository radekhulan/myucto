import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent } from 'vue'
import type { MigrationCheckDetail, MigrationCheckItem, MigrationCheckPreview, MigrationCheckSummary } from '@/api/migrationChecks'

const m = vi.hoisted(() => ({
  overview: vi.fn(),
  detail: vi.fn(),
  preview: vi.fn(),
  fix: vi.fn(),
  toastError: vi.fn(),
  toastSuccess: vi.fn(),
  params: { key: 'tax_document_advance_link' } as Record<string, string>,
  canRead: true,
}))

vi.mock('@/api/migrationChecks', async (importOriginal) => {
  const real = await importOriginal<typeof import('@/api/migrationChecks')>()
  return { ...real, migrationChecksApi: { overview: m.overview, detail: m.detail, preview: m.preview, fix: m.fix } }
})
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: m.toastError, success: m.toastSuccess }) }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (v: number) => `${v} Kč`, formatDate: (v: string) => v }))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, p?: Record<string, unknown>) => (p ? `${key}:${JSON.stringify(p)}` : key),
    te: () => false,
  }),
}))
vi.mock('vue-router', () => ({ useRoute: () => ({ params: m.params }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: () => m.canRead }) }))

import MigrationDataChecks from '@/pages/imports/MigrationDataChecks.vue'
import MigrationDataCheckDetail from '@/pages/imports/MigrationDataCheckDetail.vue'
import MigrationOverview from '@/pages/imports/MigrationOverview.vue'

const ActionBarStub = defineComponent({
  props: ['actions'],
  template: `<div><button v-for="a in actions.filter(x => x.show === undefined || x.show)" :key="a.key" :data-testid="'action-' + a.key"
    :disabled="a.disabled" @click="a.run && a.run()">{{ a.label }}</button></div>`,
})
const ModalStub = defineComponent({ template: '<div data-testid="modal"><slot /><slot name="footer" /></div>' })
const stubs = { ActionBar: ActionBarStub, Modal: ModalStub, EmptyState: true, PaginationBar: true }

function summary(over: Partial<MigrationCheckSummary> = {}): MigrationCheckSummary {
  return {
    key: 'tax_document_advance_link', title: 'DDKP bez vazby', fixable: true, manual: '126_Kontrola_dat_po_prevodu#1269-ddkp-bez-vazby-na-zalohu',
    status: 'fixable', count: 3, fixable_count: 2, locked_count: 1, review_count: 0, amount_czk: 3630, fixable_amount_czk: 2420, notes: [],
    ...over,
  }
}

function item(over: Partial<MigrationCheckItem> = {}): MigrationCheckItem {
  return {
    entity_type: 'invoice', entity_id: 41, label: 'DD0001', date: '2099-03-01', amount_czk: 1210, amount: null, currency: null,
    state: 'fixable', reason: 'DDKP se naváže na zálohu ZF1.', details: {}, ...over,
  }
}

function detail(over: Partial<MigrationCheckDetail> = {}): MigrationCheckDetail {
  return {
    ...summary(), options: [], can_fix: true,
    items: [item(), item({ entity_id: 42, label: 'DD0002', state: 'locked', reason: 'Leží v podaném období DPH.' }),
      item({ entity_type: 'account', entity_id: null, label: '311 Odběratelé, rok 2099', details: { period_id: 5, account: '311', as_of: '2099-12-31' }, state: 'review' })],
    pagination: { page: 1, per_page: 50, total: 3 },
    ...over,
  }
}

function preview(over: Partial<MigrationCheckPreview> = {}): MigrationCheckPreview {
  return {
    key: 'tax_document_advance_link', title: 'DDKP bez vazby', can_fix: true, blocked: null, fingerprint: 'abcdef0123456789',
    count: 1, amount_czk: 1210, changes: [item()], skipped: [item({ entity_id: 42, state: 'locked' })], notes: [], ...over,
  }
}

describe('Přechod z jiných účetních systémů: dlaždice kontroly', () => {
  it('kontrola dat je poslední dlaždice a vede na přehled kontrol', () => {
    m.canRead = true
    const w = mount(MigrationOverview, { global: { stubs: { 'i18n-t': true } } })
    const tiles = w.findAll('.grid > a')
    const last = tiles[tiles.length - 1]
    expect(last.attributes('data-testid')).toBe('migration-overview-checks')
    expect(JSON.parse(last.attributes('data-to')!)).toEqual({ name: 'imports-checks' })
  })

  it('bez přístupu do účetnictví se dlaždice nezobrazí', () => {
    m.canRead = false
    const w = mount(MigrationOverview, { global: { stubs: { 'i18n-t': true } } })
    expect(w.find('[data-testid="migration-overview-checks"]').exists()).toBe(false)
  })
})

describe('Kontrola dat po převodu: přehled', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('ukáže zdroje převodu a kontroly se stavem, počtem a odkazem na detail', async () => {
    m.overview.mockResolvedValue({
      sources: ['pohoda'], can_fix: true,
      checks: [summary(), summary({ key: 'subledger_vs_ledger', fixable: false, status: 'review', fixable_count: 0, notes: ['Rok 2099 nešel sestavit.'] }),
        summary({ key: 'foreign_bank_in_czk', status: 'ok', count: 0, fixable_count: 0, locked_count: 0, amount_czk: 0 })],
    })
    const w = mount(MigrationDataChecks, { global: { stubs } })
    await flushPromises()
    expect(w.find('[data-testid="source-pohoda"]').text()).toBe('pohoda')
    expect(w.find('[data-testid="check-status-tax_document_advance_link"]').text()).toBe('migrationChecks.status.fixable')
    expect(w.find('[data-testid="check-status-subledger_vs_ledger"]').text()).toBe('migrationChecks.status.review')
    expect(w.find('[data-testid="check-row-subledger_vs_ledger"]').text()).toContain('migrationChecks.report_only')
    expect(w.find('[data-testid="check-row-subledger_vs_ledger"]').text()).toContain('Rok 2099 nešel sestavit.')
    expect(w.find('[data-testid="total-fixable"]').text()).toContain('"count":1')
    const link = w.find('[data-testid="check-open-tax_document_advance_link"]')
    expect(JSON.parse(link.attributes('data-to')!)).toEqual({ name: 'imports-check-detail', params: { key: 'tax_document_advance_link' } })
  })

  it('firmě bez převodu kontroly nenabízí', async () => {
    m.overview.mockResolvedValue({ sources: [], checks: [], can_fix: false })
    const w = mount(MigrationDataChecks, { global: { stubs } })
    await flushPromises()
    expect(w.find('[data-testid="migration-checks-empty"]').exists()).toBe(true)
    expect(w.find('table').exists()).toBe(false)
  })

  it('chybu načtení ukáže místo tabulky', async () => {
    m.overview.mockRejectedValue({ response: { data: { error: { message: 'Přístup odepřen.' } } } })
    const w = mount(MigrationDataChecks, { global: { stubs } })
    await flushPromises()
    expect(w.find('[data-testid="migration-checks-error"]').exists()).toBe(true)
  })
})

describe('Kontrola dat po převodu: detail a oprava', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.params.key = 'tax_document_advance_link'
  })

  it('nálezy mají proklik na doklad i saldokonto a stav', async () => {
    m.detail.mockResolvedValue(detail())
    const w = mount(MigrationDataCheckDetail, { global: { stubs } })
    await flushPromises()
    expect(m.detail).toHaveBeenCalledWith('tax_document_advance_link', { page: 1, per_page: 50 })
    const rows = w.findAll('[data-testid="finding-row"]')
    expect(rows).toHaveLength(3)
    expect(rows[0].find('a').attributes('href')).toBe('/invoices/41')
    expect(JSON.parse(rows[2].find('a').attributes('data-to')!)).toEqual({ path: '/accounting/saldo', query: { period_id: '5', account: '311', as_of: '2099-12-31' } })
    expect(rows[1].text()).toContain('migrationChecks.state.locked')
  })

  it('filtr stavu načte jen nálezy v daném stavu', async () => {
    m.detail.mockResolvedValue(detail())
    const w = mount(MigrationDataCheckDetail, { global: { stubs } })
    await flushPromises()
    await w.find('[data-testid="filter-locked"]').trigger('click')
    await flushPromises()
    expect(m.detail).toHaveBeenLastCalledWith('tax_document_advance_link', { page: 1, per_page: 50, state: 'locked' })
  })

  it('oprava jde přes náhled a posílá jeho otisk', async () => {
    m.detail.mockResolvedValue(detail())
    m.preview.mockResolvedValue(preview())
    m.fix.mockResolvedValue({ key: 'tax_document_advance_link', fixed_count: 1, amount_czk: 1210, skipped_count: 1, notes: [], after: summary({ status: 'review', fixable_count: 0 }) })
    const w = mount(MigrationDataCheckDetail, { global: { stubs } })
    await flushPromises()
    await w.find('[data-testid="action-fix"]').trigger('click')
    await flushPromises()
    expect(m.preview).toHaveBeenCalledWith('tax_document_advance_link', {})
    expect(m.fix).not.toHaveBeenCalled()
    expect(w.find('[data-testid="preview-summary"]').text()).toContain('"count":1')
    await w.find('[data-testid="preview-confirm"]').trigger('click')
    await flushPromises()
    expect(m.fix).toHaveBeenCalledWith('tax_document_advance_link', 'abcdef0123456789', {})
    expect(m.toastSuccess).toHaveBeenCalled()
    expect(m.detail).toHaveBeenCalledTimes(2)
  })

  it('volba opravy se promítne do náhledu i opravy', async () => {
    m.params.key = 'foreign_bank_in_czk'
    m.detail.mockResolvedValue(detail({ key: 'foreign_bank_in_czk', options: [{ key: 'include_uploaded', label: 'Přepnout i nahrané výpisy' }] }))
    m.preview.mockResolvedValue(preview({ key: 'foreign_bank_in_czk' }))
    m.fix.mockResolvedValue({ key: 'foreign_bank_in_czk', fixed_count: 2, amount_czk: 0, skipped_count: 0, notes: [], after: summary({ status: 'ok' }) })
    const w = mount(MigrationDataCheckDetail, { global: { stubs } })
    await flushPromises()
    await w.find('[data-testid="action-fix"]').trigger('click')
    await flushPromises()
    await w.find('[data-testid="option-include_uploaded"]').setValue(true)
    await flushPromises()
    expect(m.preview).toHaveBeenLastCalledWith('foreign_bank_in_czk', { include_uploaded: true })
    await w.find('[data-testid="preview-confirm"]').trigger('click')
    await flushPromises()
    expect(m.fix).toHaveBeenCalledWith('foreign_bank_in_czk', 'abcdef0123456789', { include_uploaded: true })
  })

  it('zablokovaný náhled opravu nespustí', async () => {
    m.detail.mockResolvedValue(detail())
    m.preview.mockResolvedValue(preview({ can_fix: false, blocked: 'Oprava převodu opravuje firmu jen celou.', changes: [], count: 0 }))
    const w = mount(MigrationDataCheckDetail, { global: { stubs } })
    await flushPromises()
    await w.find('[data-testid="action-fix"]').trigger('click')
    await flushPromises()
    expect(w.text()).toContain('Oprava převodu opravuje firmu jen celou.')
    expect(w.find('[data-testid="preview-confirm"]').attributes('disabled')).toBeDefined()
  })

  it('kontrola bez opravy ani uživatel bez práva tlačítko Opravit nemají', async () => {
    m.detail.mockResolvedValue(detail({ fixable: false }))
    let w = mount(MigrationDataCheckDetail, { global: { stubs } })
    await flushPromises()
    expect(w.find('[data-testid="action-fix"]').exists()).toBe(false)
    expect(w.find('[data-testid="report-only"]').exists()).toBe(true)
    w.unmount()
    m.detail.mockResolvedValue(detail({ can_fix: false }))
    w = mount(MigrationDataCheckDetail, { global: { stubs } })
    await flushPromises()
    expect(w.find('[data-testid="action-fix"]').exists()).toBe(false)
    expect(w.find('[data-testid="no-fix-right"]').exists()).toBe(true)
  })
})
