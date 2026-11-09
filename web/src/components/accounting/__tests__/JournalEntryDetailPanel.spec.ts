import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import JournalEntryDetailPanel from '../JournalEntryDetailPanel.vue'
import type { JournalEntryDetail } from '@/api/accounting'

vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number) => String(v) }))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key }),
}))

const stub = (name: string) => ({ name, template: `<div data-test="${name}" />` })
const stubs = {
  JournalLinesTable: stub('lines-table'),
  JournalLineDimensionsEditor: true,
  JournalRelatedPanel: true,
  OtherItemSourceContext: true,
  InternalDocumentJournalContext: true,
  WhyPanel: true,
  CollapsibleSection: true,
  RepostModal: true,
  WorkspaceTeleport: true,
  RouterLink: true,
}

function detail(extra: Partial<JournalEntryDetail> = {}): JournalEntryDetail {
  return {
    id: 5, supplier_id: 1, period_id: 1, entry_date: '2026-01-02', document_date: null, document_no: null, description: null,
    source_type: 'manual', source_id: null, posted_at: '2026-01-02', posted_by: 1, reversed_by: null, row_version: 1,
    created_at: '2026-01-02', updated_at: '2026-01-02', lines: [],
    ...extra,
  } as JournalEntryDetail
}

function mountPanel(d: JournalEntryDetail) {
  return mount(JournalEntryDetailPanel, {
    props: { detail: d, relatedKey: 0, canWrite: false, canDelete: false, canDeletePair: false, dateFrom: '', dateTo: '' },
    global: { stubs },
  })
}

describe('JournalEntryDetailPanel', () => {
  it('složený zápis ukáže tabulku řádků', () => {
    const w = mountPanel(detail())
    expect(w.find('[data-test="lines-table"]').exists()).toBe(true)
    w.unmount()
  })

  it('zápis po dvojicích tabulku řádků neduplikuje', () => {
    const w = mountPanel(detail({
      pairs: [{ pair_no: 1, debit_line_id: 1, credit_line_id: 2, debit_account_code: '311', credit_account_code: '602', amount: 100 }],
    }))
    expect(w.find('[data-test="lines-table"]').exists()).toBe(false)
    w.unmount()
  })
})
