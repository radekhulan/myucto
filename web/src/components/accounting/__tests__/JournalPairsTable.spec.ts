import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import JournalPairsTable, { type PairRow } from '../JournalPairsTable.vue'

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => params ? `${key}:${JSON.stringify(params)}` : key }),
}))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (value: number) => String(value) }))
vi.mock('@/components/ui/ActionBar.vue', () => ({
  default: { props: ['actions'], template: '<div data-test="bar"><button v-for="a in actions" :key="a.key" :data-key="a.key" :disabled="a.disabled" @click="a.run && a.run()">{{ a.label }}</button></div>' },
}))

const pairs: PairRow[] = [
  { pair_no: 1, debit_account_code: '311', credit_account_code: '602', amount: 1000, line_text: 'služba' },
  { pair_no: 2, debit_account_code: '311', credit_account_code: '343', amount: 210 },
  { pair_no: 3, debit_account_code: '311', credit_account_code: '602', amount: 50, reversed_by_entry_id: 77 },
]

describe('JournalPairsTable', () => {
  it('ukáže všechny dvojice a součet', () => {
    const w = mount(JournalPairsTable, { props: { pairs } })
    expect(w.findAll('tbody tr')).toHaveLength(3)
    expect(w.get('tfoot').text()).toContain('1260')
    w.unmount()
  })

  it('vybrat jde jen dvojice, které nejsou stornované', async () => {
    const w = mount(JournalPairsTable, { props: { pairs, selectable: true, modelValue: [] } })
    const boxes = w.findAll('tbody input[type="checkbox"]')
    expect(boxes.map(b => (b.element as HTMLInputElement).disabled)).toEqual([false, false, true])
    await boxes[1].setValue(true)
    expect(w.emitted('update:modelValue')![0]![0]).toEqual([2])
    w.unmount()
  })

  it('výběr všech vybere jen vybratelné dvojice', async () => {
    const w = mount(JournalPairsTable, { props: { pairs, selectable: true, modelValue: [] } })
    await w.get('thead input[type="checkbox"]').setValue(true)
    expect(w.emitted('update:modelValue')![0]![0]).toEqual([1, 2])
    w.unmount()
  })

  it('akce se vykreslí přes ActionBar a stornovaná dvojice odkazuje na opravný zápis', async () => {
    const run = vi.fn()
    const w = mount(JournalPairsTable, {
      props: { pairs, selectable: true, modelValue: [1], actions: [{ key: 'pair-reverse', label: 'Storno dvojice', run, variant: 'danger' }] },
    })
    await w.get('[data-key="pair-reverse"]').trigger('click')
    expect(run).toHaveBeenCalled()
    const link = w.findAll('button').find(b => b.text().includes('accounting.pairs.reversed_by'))!
    await link.trigger('click')
    expect(w.emitted('open-entry')![0]).toEqual([77])
    w.unmount()
  })

  it('bez akcí a bez výběru lišta nad tabulkou chybí', () => {
    const w = mount(JournalPairsTable, { props: { pairs } })
    expect(w.find('[data-test="bar"]').exists()).toBe(false)
    w.unmount()
  })
})
