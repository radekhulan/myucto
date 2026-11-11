import { describe, expect, it } from 'vitest'
import type { PayrollInputImportPreview } from '@/api/payroll'
import type { PayrollAbsenceEmployment } from '@/api/payrollAbsences'
import {
  canApplyPayrollImport,
  localPayrollPeriod,
  monthStart,
  parsePayrollAmountToMinor,
  parsePayrollHoursToMilli,
  payrollEmploymentOptionsFromContext,
  payrollEmploymentPeriodFit,
  payrollImportFingerprint,
  payrollImportIssues,
  payrollMinorToInput,
  payrollQueryPeriod,
} from '@/pages/payroll/payrollComponentsUi'

function preview(overrides: Partial<PayrollInputImportPreview> = {}): PayrollInputImportPreview {
  return {
    format: 'csv',
    source_name: 'synthetic.csv',
    period: '2026-06',
    content_hash: 'synthetic-hash',
    row_count: 3,
    accepted_count: 1,
    rejected_count: 1,
    duplicate_count: 1,
    rows: [],
    errors: [{
      row_number: 4,
      error_code: 'row_validation_failed',
      field_name: 'amount_minor',
      error_message: 'Synthetic invalid amount.',
    }],
    duplicates: [{
      row_number: 3,
      error_code: 'duplicate_in_file',
      field_name: 'external_id',
      error_message: 'Synthetic duplicate.',
    }],
    ...overrides,
  }
}

describe('payrollComponentsUi', () => {
  it('requires a matching dry-run fingerprint before apply', () => {
    const source = {
      period: '2026-06',
      format: 'csv' as const,
      source_name: 'synthetic.csv',
      content_base64: 'c3ludGhldGlj',
    }
    const fingerprint = payrollImportFingerprint(source)

    expect(canApplyPayrollImport(null, null, fingerprint)).toBe(false)
    expect(canApplyPayrollImport(preview(), fingerprint, fingerprint)).toBe(true)
    expect(canApplyPayrollImport(preview(), fingerprint, payrollImportFingerprint({
      ...source,
      period: '2026-07',
    }))).toBe(false)
    expect(canApplyPayrollImport(preview({ accepted_count: 0 }), fingerprint, fingerprint)).toBe(false)
  })

  it('merges row errors and duplicates in source-row order', () => {
    expect(payrollImportIssues(preview())).toEqual([
      expect.objectContaining({ row_number: 3, kind: 'duplicate', error_code: 'duplicate_in_file' }),
      expect.objectContaining({ row_number: 4, kind: 'error', error_code: 'row_validation_failed' }),
    ])
  })

  it('keeps the employee-employment contract used by mobile cards and forms', () => {
    const employments = [{
      id: 12,
      employee_id: 8,
      code: 'SYN-HPP',
      relation_type: 'employment',
      status: 'active',
      full_name: 'Syntetická osoba',
    }] as PayrollAbsenceEmployment[]

    expect(payrollEmploymentOptionsFromContext(employments)).toEqual([{
      employee_id: 8,
      employment_id: 12,
      full_name: 'Syntetická osoba',
      code: 'SYN-HPP',
      relation_type: 'employment',
      status: 'active',
      start_date: null,
      end_date: null,
    }])
  })

  // Případ z podpory: květnový vstup na dubnovou skončenou dohodu se ve výběru
  // nedal rozlišit od nové dohody a náhled hlásil „lze bezpečně zpracovat".
  it('pozná vztah, který v měsíci vstupu neplatí', () => {
    const old = { start_date: '2026-04-01', end_date: '2026-04-30' }
    expect(payrollEmploymentPeriodFit(old, '2026-05')).toBe('ended')
    expect(payrollEmploymentPeriodFit(old, '2026-04')).toBe('ok')
    expect(payrollEmploymentPeriodFit({ start_date: '2026-06-15', end_date: null }, '2026-05')).toBe('not_started')
    expect(payrollEmploymentPeriodFit({ start_date: '2026-05-31', end_date: null }, '2026-05')).toBe('ok')
    expect(payrollEmploymentPeriodFit({ start_date: null, end_date: null }, '2026-05')).toBe('ok')
    const [option] = payrollEmploymentOptionsFromContext([
      { id: 5, employee_id: 1, code: 'B021', relation_type: 'dpp', status: 'ended', full_name: 'Syntetický Brigádník', start_date: '2026-04-01', end_date: '2026-04-30' },
    ] as PayrollAbsenceEmployment[])
    expect(option).toMatchObject({ start_date: '2026-04-01', end_date: '2026-04-30' })
  })

  it('never offers an archived or never-started relation for a payroll input', () => {
    const employments = [
      { id: 12, employee_id: 8, code: 'SYN-HPP', relation_type: 'employment', status: 'active', full_name: 'Syntetická osoba' },
      { id: 13, employee_id: 8, code: 'SYN-ARCH', relation_type: 'employment', status: 'archived', full_name: 'Syntetická osoba' },
      { id: 14, employee_id: 8, code: 'SYN-NOSHOW', relation_type: 'employment', status: 'no_show', full_name: 'Syntetická osoba' },
    ] as PayrollAbsenceEmployment[]

    expect(payrollEmploymentOptionsFromContext(employments).map(option => option.employment_id))
      .toEqual([12])
  })

  // Server řadí podle collation databáze, klient podle českých pravidel. Bez
  // vlastního řazení by nabídka skákala podle toho, odkud přišla.
  it('orders the offer by Czech collation, then by relation code', () => {
    const employments = [
      { id: 21, employee_id: 2, code: 'B', relation_type: 'employment', status: 'active', full_name: 'Žák' },
      { id: 22, employee_id: 1, code: 'B', relation_type: 'employment', status: 'active', full_name: 'Čáp' },
      { id: 23, employee_id: 1, code: 'A', relation_type: 'employment', status: 'active', full_name: 'Čáp' },
    ] as PayrollAbsenceEmployment[]

    expect(payrollEmploymentOptionsFromContext(employments).map(option => option.employment_id))
      .toEqual([23, 22, 21])
  })

  it('converts user amounts without floating-point rounding', () => {
    expect(parsePayrollAmountToMinor('1 234,56')).toBe(123456)
    expect(parsePayrollAmountToMinor('-0,05')).toBe(-5)
    expect(parsePayrollAmountToMinor('12,345')).toBeNull()
    expect(payrollMinorToInput(123456)).toBe('1234,56')
    expect(monthStart('2026-06')).toBe('2026-06-01')
  })

  it('accepts at most thousandths of an overtime hour without rounding user input', () => {
    expect(parsePayrollHoursToMilli('1,25')).toBe(1250)
    expect(parsePayrollHoursToMilli('0.001')).toBe(1)
    expect(parsePayrollHoursToMilli('1.2345')).toBeNull()
    expect(parsePayrollHoursToMilli('-1')).toBeNull()
  })

  it('selects the payroll period from local time around midnight', () => {
    expect(localPayrollPeriod(new Date(2026, 7, 1, 0, 30))).toBe('2026-08')
  })

  it('keeps the period the user came with instead of jumping to this month', () => {
    // V září se zpracovává srpen; přeskočení na dnešní měsíc by zadané částky
    // uložilo do jiného období, než ve kterém účetní pracuje.
    expect(payrollQueryPeriod({ period: '2026-08' }, '2026-09')).toBe('2026-08')
    expect(payrollQueryPeriod({ period: ['2026-08'] }, '2026-09')).toBe('2026-08')
  })

  it('falls back to the current month on a missing or malformed period', () => {
    expect(payrollQueryPeriod({}, '2026-09')).toBe('2026-09')
    expect(payrollQueryPeriod({ period: '2026-13' }, '2026-09')).toBe('2026-09')
    expect(payrollQueryPeriod({ period: '2026-8' }, '2026-09')).toBe('2026-09')
    expect(payrollQueryPeriod({ period: 8 }, '2026-09')).toBe('2026-09')
  })
})
