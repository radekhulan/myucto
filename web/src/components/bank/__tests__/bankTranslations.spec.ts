import { describe, expect, it } from 'vitest'
import { createI18n } from 'vue-i18n'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'

describe('bank onboarding translations', () => {
  for (const [locale, messages] of Object.entries({ cs, en })) {
    it(`${locale} compiles every bank onboarding message`, () => {
      const i18n = createI18n({ legacy: false, locale, messages: { [locale]: messages } })
      for (const namespace of ['kb_plus', 'creditas_bank', 'bank_connection'] as const) {
        for (const [key, value] of Object.entries(messages[namespace])) {
          if (typeof value !== 'string') continue
          expect(() => i18n.global.t(`${namespace}.${key}`), `${namespace}.${key}`).not.toThrow()
        }
      }
    })
  }
})
