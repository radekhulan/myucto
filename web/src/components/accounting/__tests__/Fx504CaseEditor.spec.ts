import { expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import type { Fx504SourceCell } from '@/api/fx504'
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
import Fx504CaseEditor from '../Fx504CaseEditor.vue'
const cell: Fx504SourceCell = { cell_key: 'source', line_id: 1, entry_id: 2, line_no: 1, account_id: 3, account_code: '311', currency_code: 'EUR', dimensions: { '4': 41 }, czk_signed: '-2500.01', foreign_signed: '-100.00', candidates: [{ kind: 'document', doc_type: 'invoice', doc_id: 9 }, { kind: 'document', doc_type: 'invoice', doc_id: 10 }] }
it('requires signed exact totals in both currencies for a bulk payment', async () => {
  const w = mount(Fx504CaseEditor, { props: { cells: [cell], decisions: [], unresolved: [cell], locked: [], disabled: false } })
  const valid = () => w.emitted('change')!.at(-1)![1]
  expect(valid()).toBe(false)
  await w.get('select').setValue(JSON.stringify(cell.candidates[0]))
  await w.get('[data-testid="case-reason"]').setValue('Synthetic bulk payment allocation')
  expect(valid()).toBe(true)
  await w.findAll('button').at(-1)!.trigger('click')
  await w.findAll('select')[1]!.setValue(JSON.stringify(cell.candidates[1]))
  await w.findAll('[data-testid="case-reason"]')[1]!.setValue('Synthetic bulk payment allocation')
  const amounts = w.findAll('input[inputmode="decimal"]')
  await amounts[0]!.setValue('-1500.00')
  await amounts[1]!.setValue('-60.00')
  await amounts[2]!.setValue('-1000.00')
  await amounts[3]!.setValue('-40.00')
  expect(valid()).toBe(false)
  await amounts[2]!.setValue('-1000.01')
  expect(valid()).toBe(true)
  await amounts[3]!.setValue('40.00')
  expect(valid()).toBe(false)
  w.unmount()
})
it('retains historical decisions and disables every editing control', () => {
  const decisions = [{ origin: cell.candidates[0]!, reason: 'Synthetic historical evidence', contributions: [{ cell_key: cell.cell_key, czk_signed: cell.czk_signed, foreign_signed: cell.foreign_signed }] }]
  const w = mount(Fx504CaseEditor, { props: { cells: [cell], decisions, unresolved: [], locked: [cell.cell_key], disabled: false } })
  expect(w.get('fieldset').attributes('disabled')).toBeDefined()
  expect(w.emitted('change')!.at(-1)).toEqual([decisions, true])
  w.unmount()
})
