import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({ demo: false, query: {} as Record<string, string> }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('vue-router', () => ({
  useRoute: () => ({ query: m.query }),
  useRouter: () => ({ replace: vi.fn() }),
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ isDemo: m.demo }) }))
vi.mock('../SentEmails.vue', () => ({ default: { template: '<div data-tab-sent />' } }))
vi.mock('../EmailTemplates.vue', () => ({ default: { template: '<div data-tab-templates />' } }))
vi.mock('../EmailProfiles.vue', () => ({ default: { template: '<div data-tab-profiles />' } }))
vi.mock('../ElectronicSignatures.vue', () => ({ default: { template: '<div data-tab-signatures />' } }))
vi.mock('../SmtpLogAnalysis.vue', () => ({ default: { template: '<div data-tab-logs />' } }))

import Emails from '../Emails.vue'

function mountEmails(demo: boolean, tab?: string) {
  m.demo = demo
  m.query = tab ? { tab } : {}
  return mount(Emails)
}

describe('E-maily na ukázkové instanci', () => {
  it('mimo demo nabízí všech pět záložek a začíná odeslanou poštou', () => {
    const wrapper = mountEmails(false)
    expect(wrapper.findAll('button').map(button => button.text())).toEqual([
      'nav.sent_emails', 'nav.email_templates', 'nav.email_profiles', 'nav.electronic_signatures', 'nav.smtp_logs',
    ])
    expect(wrapper.find('[data-tab-sent]').exists()).toBe(true)
  })

  it('v demu skryje odeslanou poštu, šablony a SMTP logy i při přímém odkazu ?tab=logs', () => {
    const wrapper = mountEmails(true, 'logs')
    expect(wrapper.findAll('button').map(button => button.text())).toEqual(['nav.email_profiles', 'nav.electronic_signatures'])
    expect(wrapper.find('[data-tab-logs]').exists()).toBe(false)
    expect(wrapper.find('[data-tab-sent]').exists()).toBe(false)
    expect(wrapper.find('[data-tab-profiles]').exists()).toBe(true)
  })
})
