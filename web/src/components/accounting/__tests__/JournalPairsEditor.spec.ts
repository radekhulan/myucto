import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import JournalPairsEditor, { type PairEditorRow } from '../JournalPairsEditor.vue'

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => params ? `${key}:${JSON.stringify(params)}` : key }),
}))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (value: number) => String(value) }))

const accounts = [
  { id: 1, account_code: '221', name: 'Banka', is_active: true },
  { id: 2, account_code: '311', name: 'Odběratelé', is_active: true },
] as never[]

function mountEditor(rows: PairEditorRow[], errors: Record<number, string> = {}) {
  return mount(JournalPairsEditor, { props: { modelValue: rows, accounts, errors } })
}
const row = (d: string, c: string, amount: number | null): PairEditorRow =>
  ({ debit_account_code: d, credit_account_code: c, amount, line_text: '' })

describe('JournalPairsEditor', () => {
  it('úplná dvojice je platná, součet je jedna částka', () => {
    const w = mountEditor([row('221', '311', 100)])
    expect((w.vm as unknown as { valid: boolean }).valid).toBe(true)
    expect(w.text()).toContain('accounting.pairs.complete')
    w.unmount()
  })

  it.each([
    ['chybí MD', row('', '311', 10), 'accounting.error.pair_missing_debit_account'],
    ['chybí Dal', row('221', '', 10), 'accounting.error.pair_missing_credit_account'],
    ['stejné účty', row('221', '221', 10), 'accounting.error.pair_same_account'],
    ['nulová částka', row('221', '311', 0), 'accounting.error.pair_amount_not_positive'],
    ['záporná částka', row('221', '311', -5), 'accounting.error.pair_amount_not_positive'],
  ])('%s: dvojice není platná a ukáže srozumitelnou chybu', (_name, r, key) => {
    const w = mountEditor([r])
    expect((w.vm as unknown as { valid: boolean }).valid).toBe(false)
    expect(w.get('[data-test="pair-error"]').text()).toContain(key)
    w.unmount()
  })

  it('chyba ze serveru má přednost před předběžnou kontrolou a míří na svou dvojici', () => {
    const w = mountEditor([row('221', '311', 10), row('221', '311', 20)], { 1: 'Server: dvojice nesedí' })
    const errors = w.findAll('[data-test="pair-error"]')
    expect(errors).toHaveLength(1)
    expect(errors[0].text()).toContain('Server: dvojice nesedí')
    expect(errors[0].text()).toContain('"n":2')
    w.unmount()
  })

  it('přidání a odebrání dvojice; poslední dvojici odebrat nejde', async () => {
    const w = mountEditor([row('221', '311', 10)])
    await w.get('button.cursor-pointer.text-danger-500').trigger('click')
    expect(w.emitted('update:modelValue')).toBeUndefined()
    const add = w.findAll('button').find(b => b.text().includes('accounting.pairs.add'))!
    await add.trigger('click')
    const next = w.emitted('update:modelValue')![0]![0] as PairEditorRow[]
    expect(next).toHaveLength(2)
    expect(next[1]).toMatchObject({ debit_account_code: '', credit_account_code: '', amount: null })
    w.unmount()
  })

  it('prázdný nový řádek nehlásí chyby, dokud do něj uživatel nic nenapíše', () => {
    const w = mountEditor([row('', '', null)])
    expect(w.find('[data-test="pair-error"]').exists()).toBe(false)
    expect((w.vm as unknown as { valid: boolean }).valid).toBe(false)
    w.unmount()
  })

  it('červené storno se v součtu odečítá', () => {
    const w = mountEditor([row('221', '311', 100), { ...row('221', '311', 30), is_red_storno: true }])
    expect(w.text()).toContain('70')
    w.unmount()
  })
})
