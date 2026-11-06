import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { useI18n } from 'vue-i18n'
import { i18n, ensureInitialLocaleReady, ensureLocaleLoaded, ensureNamespaces } from '@/i18n'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'
import McpServerAddress from './McpServerAddress.vue'

const messages = { cs, en }

async function loadLocale(locale: 'cs' | 'en') {
  await ensureInitialLocaleReady()
  await ensureLocaleLoaded(locale)
  i18n.global.locale.value = locale
  await ensureNamespaces(['client_mcp', 'mcp_server_page'])
}

describe.each(['cs', 'en'] as const)('shared MCP server address (%s)', locale => {
  it('renders and emits the exact server endpoint for copying', async () => {
    await loadLocale(locale)
    const endpoint = 'https://portal.example.test/mcp'
    const wrapper = mount(McpServerAddress, { props: { endpoint }, global: { plugins: [i18n] } })
    expect(wrapper.get('h2').text()).toBe(messages[locale].mcp_server_page.connection.remote_title)
    expect(wrapper.get('code').text()).toBe(endpoint)
    await wrapper.get('button').trigger('click')
    expect(wrapper.emitted('copy')).toEqual([[endpoint]])
    wrapper.unmount()
  })

  it('resolves the canonical endpoint hint through the production lazy translation loader', async () => {
    await loadLocale(locale)
    const Host = defineComponent({ setup() {
      const { t } = useI18n()
      return () => h(McpServerAddress, { endpoint: 'https://app.example.test/mcp',
        description: t('mcp_server_page.connection.remote_intro'), hint: t('client_mcp.canonical_endpoint_hint') })
    } })
    const wrapper = mount(Host, { global: { plugins: [i18n] } })
    expect(wrapper.text()).toContain(messages[locale].client_mcp.canonical_endpoint_hint)
    expect(wrapper.text()).toContain(messages[locale].mcp_server_page.connection.remote_intro)
    expect(wrapper.text()).not.toContain('client_mcp.canonical_endpoint_hint')
    wrapper.unmount()
  })
})
