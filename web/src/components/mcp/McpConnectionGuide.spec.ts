import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'
import McpConnectionGuide from './McpConnectionGuide.vue'

const messages = { cs, en }

function render(locale: 'cs' | 'en', clientDomain = false, mainDomain = false) {
  return mount(McpConnectionGuide, {
    props: { clientDomain, mainDomain },
    global: { plugins: [createI18n({ legacy: false, locale, messages })] },
  })
}

describe.each(['cs', 'en'] as const)('MCP connection guide (%s)', locale => {
  it('explains consent limited to the client company and distinguishes the MCP address from the portal', () => {
    const wrapper = render(locale, true)
    expect(wrapper.text()).toContain(messages[locale].client_mcp.step_authorize)
    expect(wrapper.text()).toContain(messages[locale].client_mcp.endpoint_hint)
    expect(wrapper.text()).toContain(messages[locale].client_mcp.url_requirement)
    expect(wrapper.text()).not.toContain(messages[locale].mcp_server_page.connection.step_authorize)
    expect(wrapper.get('ol').findAll('li')).toHaveLength(4)
    expect(wrapper.findAll('h3').map(heading => heading.text())).toContain('ChatGPT')
    expect(wrapper.findAll('h3').some(heading => heading.text().includes('Claude'))).toBe(true)
    wrapper.unmount()
  })

  it('sends the user to the client domain instead of promising a company scope for the main address', () => {
    const wrapper = render(locale, true, true)
    const copy = messages[locale].client_mcp
    expect(wrapper.text()).toContain(copy.step_copy_main_domain)
    expect(wrapper.text()).toContain(copy.step_authorize_main_domain)
    expect(wrapper.text()).not.toContain(copy.step_copy)
    expect(wrapper.text()).not.toContain(copy.step_authorize)
    wrapper.unmount()
  })

  it('preserves the existing consent instructions on the internal profile page', () => {
    const wrapper = render(locale)
    expect(wrapper.text()).toContain(messages[locale].mcp_server_page.connection.step_authorize)
    expect(wrapper.text()).toContain(messages[locale].mcp_server_page.connection.step_verify)
    expect(wrapper.text()).not.toContain(messages[locale].client_mcp.endpoint_hint)
    wrapper.unmount()
  })
})
