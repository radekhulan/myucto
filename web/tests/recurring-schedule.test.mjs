import { strict as assert } from 'node:assert'
import { test } from 'node:test'
import { readFileSync } from 'node:fs'
import ts from 'typescript'

const source = readFileSync(new URL('../src/utils/recurringSchedule.ts', import.meta.url), 'utf8')
const { outputText } = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ESNext } })
const { validateRescheduleDate: validate } = await import(`data:text/javascript;base64,${Buffer.from(outputText).toString('base64')}`)
const check = date => validate(date, '2027-09-01', '2027-02-01', '2028-12-31')

test('unchanged date explains why confirming alone cannot reset the schedule', () => {
  assert.equal(check('2027-09-01'), 'recurring.reschedule_unchanged')
})
test('restoring the February annual schedule is valid', () => {
  assert.equal(check('2027-02-01'), null)
})
test('invalid, empty and out-of-range dates have explicit errors', () => {
  for (const date of ['', 'invalid', '2027-02-30', '2027-2-1']) {
    assert.equal(check(date), 'recurring.reschedule_invalid_date')
  }
  assert.equal(check('2027-01-31'), 'recurring.reschedule_too_early')
  assert.equal(check('2029-01-01'), 'recurring.reschedule_too_late')
  assert.equal(check('2028-12-31'), null)
})
