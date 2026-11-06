import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { describe, it, expect } from 'vitest'
import TotpAppRecommendation from '../TotpAppRecommendation.vue'
import cs from '@/i18n/cs.json'

describe('TotpAppRecommendation', () => {
  it('links the recommended authenticator apps inside the setup sentence', () => {
    const wrapper = mount(TotpAppRecommendation, { global: { plugins: [createI18n({ legacy: false, locale: 'cs', messages: { cs } })] } })
    const links = wrapper.findAll('a')
    expect(links.map(a => a.attributes('href'))).toEqual([
      'https://proton.me/authenticator',
      'https://ente.com/auth/',
      'https://support.google.com/accounts/answer/1066447',
      'https://support.microsoft.com/cs-cz/authenticator/download-microsoft-authenticator',
    ])
    expect(wrapper.text()).toContain('Naskenujte QR kód')
    expect(wrapper.text()).not.toContain('Authy')
    expect(wrapper.text()).not.toContain('{apps}')
  })
})
