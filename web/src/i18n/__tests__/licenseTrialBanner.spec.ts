import { describe, it, expect } from 'vitest'
import { i18n } from '@/i18n'
import cs from '@/i18n/cs.json'

describe('license.banner_trial', () => {
  it('declines the remaining days in Czech', () => {
    i18n.global.mergeLocaleMessage('cs', { license: { banner_trial: cs.license.banner_trial } })
    const text = (days: number) => i18n.global.t('license.banner_trial', { days }, days)
    expect(text(0)).toMatch(/^Zkušební období končí dnes - /)
    expect(text(1)).toMatch(/^Zkušební období končí zítra - /)
    expect(text(3)).toMatch(/^Zkušební období končí za 3 dny - /)
    expect(text(60)).toMatch(/^Zkušební období končí za 60 dní - /)
  })
})
