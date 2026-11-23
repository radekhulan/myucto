import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import cs from '@/i18n/cs.json'
import ImapOAuthFields from '../ImapOAuthFields.vue'

describe('ImapOAuthFields', () => {
  it('vysvětlí vlastní registraci aplikace v Microsoft Entra a odkáže na návod', () => {
    const i18n = createI18n({ legacy: false, locale: 'cs', messages: { cs } })
    const wrapper = mount(ImapOAuthFields, { global: { plugins: [i18n] } })
    const help = wrapper.get('[data-test="imap-oauth-help"]')
    expect(help.text()).toContain('Microsoft Entra')
    expect(help.get('a').attributes('href')).toBe('/manual?ch=96_Nastaveni#9671-pripojeni-schranky-microsoft-365-pres-imap')
  })
})
