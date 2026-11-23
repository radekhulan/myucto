import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import DimensionProfitMatrix from '../DimensionProfitMatrix.vue'
import type { DimensionProfitMatrix as Matrix } from '@/api/dimensions'

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key, te: (key: string) => key === 'dimensions.system_values.activity.economic' }) }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (v: number) => v.toFixed(2) }))

const matrix: Matrix = {
  columns: [
    { key: '7', value_id: 7, code: 'P', name: 'Výroba' },
    { key: '', value_id: null, code: '', name: null },
  ],
  rows: [
    { code: '602', name: 'Tržby z prodeje služeb', account_type: 'revenue', cells: [300, 0], total: 300 },
    { code: '518', name: 'Ostatní služby', account_type: 'expense', cells: [100, 7], total: 107 },
  ],
  results: [200, -7],
  total_result: 193,
}

describe('DimensionProfitMatrix', () => {
  it('sloupce jsou kořeny a bez hodnoty, řádky účty ve skupinách výnosy a náklady', () => {
    const w = mount(DimensionProfitMatrix, { props: { matrix } })
    const heads = w.findAll('thead th').map(th => th.text())
    expect(heads).toEqual(['dimensions.matrix_account', 'P Výroba', 'dimensions.profit_unassigned', 'dimensions.profit_total'])

    const bodyRows = w.findAll('tbody tr').map(tr => tr.text())
    expect(bodyRows[0]).toBe('dimensions.profit_revenue')
    expect(bodyRows[1]).toContain('602')
    expect(bodyRows[2]).toBe('dimensions.profit_cost')
    expect(bodyRows[3]).toContain('518')

    const cells = w.findAll('[data-test="matrix-row"]')[1].findAll('td').map(td => td.text())
    expect(cells.slice(1)).toEqual(['100.00', '7.00', '107.00'])
  })

  it('výsledek sloupců a celkem v patičce, záporný červeně', () => {
    const w = mount(DimensionProfitMatrix, { props: { matrix } })
    const foot = w.find('[data-test="matrix-results"]').findAll('td')
    expect(foot.map(td => td.text())).toEqual(['dimensions.profit_result', '200.00', '-7.00', '193.00'])
    expect(foot[2].classes()).toContain('text-danger-600')
    expect(foot[1].classes()).not.toContain('text-danger-600')
  })

  it('systémová hodnota má ve sloupci jen přeložený název bez interního kódu', () => {
    const w = mount(DimensionProfitMatrix, {
      props: { systemKey: 'activity', matrix: { ...matrix, columns: [{ key: '8', value_id: 8, code: 'economic', name: 'Hospodářská činnost', semantic_key: 'economic' }, matrix.columns[1]] } },
    })
    expect(w.findAll('thead th').map(th => th.text())[1]).toBe('dimensions.system_values.activity.economic')
  })

  it('bez výnosů skupinu výnosů nevykreslí', () => {
    const w = mount(DimensionProfitMatrix, {
      props: { matrix: { ...matrix, rows: [matrix.rows[1]], results: [-100, -7], total_result: -107 } },
    })
    expect(w.text()).not.toContain('dimensions.profit_revenue')
    expect(w.findAll('[data-test="matrix-row"]')).toHaveLength(1)
  })
})
