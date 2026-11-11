import { describe, expect, it } from 'vitest'
import { createI18n } from 'vue-i18n'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'
import { payrollQuickInputBlockerMessage } from '../payrollQuickInputBlockerMessage'

describe('payrollQuickInputBlockerMessage', () => {
  it.each(['cs', 'en'])('přeloží blokace obou číselníků a neznámé popíše neutrálně (%s)', (locale) => {
    const { t, te } = createI18n({ legacy: false, locale, messages: { cs, en } }).global
    for (const code of ['hourly_time_missing', 'hourly_minimum_time_missing']) {
      const message = payrollQuickInputBlockerMessage(t, te, code, 'server fallback')
      expect(message).toBe(t(`payroll.hourly_preview.blockers.${code}`))
      expect(message).not.toContain('payroll.')
    }
    expect(payrollQuickInputBlockerMessage(t, te, 'base_conflict', 'server fallback'))
      .toBe(t('payroll.quick_inputs.blockers.base_conflict'))
    expect(payrollQuickInputBlockerMessage(t, te, 'unknown_code', 'server fallback'))
      .toBe('server fallback')
    const unknown = payrollQuickInputBlockerMessage(t, te, 'unknown_code')
    expect(unknown).toBe(t('payroll.quick_inputs.blocker_unknown'))
    expect(unknown).not.toContain('payroll.')
  })

  it('upřednostní blokaci rychlých vstupů před hodinovým číselníkem', () => {
    expect(payrollQuickInputBlockerMessage(key => key, () => true, 'shared_code'))
      .toBe('payroll.quick_inputs.blockers.shared_code')
  })
})
