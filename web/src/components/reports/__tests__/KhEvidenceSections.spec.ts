import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { i18nMessages } from '../../../../tests/locales'
import type { KhEvidenceReport, KhEvidenceRow, KhEvidenceTotals } from '@/api/reports'
import KhEvidenceSections from '../KhEvidenceSections.vue'

function row(over: Partial<KhEvidenceRow> = {}): KhEvidenceRow {
  return {
    section: 'A.4', source: 'sale', invoice_id: 1, doc_number: '2026001', internal_number: '2026001',
    counterparty_name: 'Testovací odběratel', counterparty_dic: '11111118', tax_date: '2026-05-10',
    base21: 20000.37, vat21: 4200.08, base12: 0, vat12: 0, base_total: 20000.37, vat_total: 4200.08,
    is_correction: false, ...over,
  }
}

function totals(over: Partial<KhEvidenceTotals> = {}): KhEvidenceTotals {
  return {
    count: 1, base21: 20000.37, vat21: 4200.08, base12: 0, vat12: 0, base_total: 20000.37, vat_total: 4200.08,
    base_total_whole: 20000, vat_total_whole: 4200, rounding_difference: 0, ...over,
  }
}

function report(over: Partial<KhEvidenceReport> = {}): KhEvidenceReport {
  return {
    source: 'current', submission: null,
    supplier: { company_name: 'Test s.r.o.', ic: '', dic: '' },
    period: { year: 2026, month: 5, period_type: 'monthly', quarter: null, start: '2026-05-01', end: '2026-05-31', label: '05/2026' },
    section: 'A.4', generated_at: '2026-06-01 10:00:00',
    sections: { 'A.4': { rows: [row()], totals: totals() } },
    excluded: [], warnings: [], ...over,
  }
}

function mountIt(r: KhEvidenceReport) {
  const i18n = createI18n({ legacy: false, locale: 'cs', messages: { cs: i18nMessages.cs } })
  return mount(KhEvidenceSections, { props: { report: r }, global: { plugins: [i18n] } })
}

describe('KhEvidenceSections', () => {
  it('ukáže doklady oddílu se součtem a celými korunami', () => {
    const text = mountIt(report()).text()
    expect(text).toContain('2026001')
    expect(text).toContain('Uskutečněná plnění nad 10 000 Kč')
    expect(text).toMatch(/20\s000,37/)
    expect(text).toContain('Zaokrouhleno na celé Kč')
  })

  it('u porovnání s podaným KH zobrazí stav dokladu', () => {
    const r = report({
      comparison: true,
      sections: { 'A.4': { rows: [row({ status: 'amount_diff' }), row({ doc_number: 'X1', status: 'only_submitted' })], totals: totals({ count: 2 }) } },
    })
    const text = mountIt(r).text()
    expect(text).toContain('Rozdíl částky')
    expect(text).toContain('Jen v podaném KH')
  })
})
