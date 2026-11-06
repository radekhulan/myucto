import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h, KeepAlive, nextTick, ref } from 'vue'

const m = vi.hoisted(() => ({
  canRead: true, locked: true, client: true, tokenAccess: false, copy: vi.fn(), get: vi.fn(), remove: vi.fn(), success: vi.fn(), error: vi.fn(),
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({
  canRead: (key: string) => key === 'profile.tokens' ? m.tokenAccess : m.canRead,
  get isClientRole() { return m.client }, get domainContext() { return { locked: m.locked } },
}) }))
vi.mock('@/composables/useFormat', () => ({ formatDateTime: (value: string) => value }))
vi.mock('@/api/client', () => ({ api: { get: m.get, delete: m.remove } }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: m.success, error: m.error }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
import ClientMcp from './ClientMcp.vue'

describe('client domain MCP connections', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: m.copy } })
    m.copy.mockResolvedValue(undefined)
    m.canRead = true
    m.client = true
    m.tokenAccess = false
    m.locked = true
    m.remove.mockResolvedValue({ data: { revoked: true } })
    m.get.mockImplementation(async (path: string) => ({ data: path === '/mcp/settings'
      ? { enabled: true, node_available: true, endpoint: 'https://portal.example.test/mcp' }
      : { grants: [{ id: 7, client_name: 'Synthetic assistant', resource: 'https://portal.example.test/mcp', scope: 'read', is_active: true, last_used_at: null }] },
    }))
    vi.spyOn(window, 'confirm').mockReturnValue(true)
  })

  it('shows a separate server card and copies the exact domain endpoint', async () => {
    const wrapper = mount(ClientMcp)
    await flushPromises()
    expect(wrapper.text()).toContain('mcp_server_page.connection.remote_title')
    expect(wrapper.get('code').text()).toBe('https://portal.example.test/mcp')
    await wrapper.findAll('button').find(button => button.text() === 'mcp_server_page.connection.copy_url')!.trigger('click')
    await flushPromises()
    expect(m.copy).toHaveBeenCalledWith('https://portal.example.test/mcp')
    expect(m.success).toHaveBeenCalledWith('client_mcp.copied')
    wrapper.unmount()
  })

  it('allows copying the published address while the remote server is disabled', async () => {
    m.get.mockImplementation(async (path: string) => ({ data: path === '/mcp/settings'
      ? { enabled: false, node_available: true, endpoint: 'https://portal.example.test/mcp' }
      : { grants: [] },
    }))
    const wrapper = mount(ClientMcp)
    await flushPromises()
    expect(wrapper.text()).toContain('client_mcp.disabled')
    expect(wrapper.get('code').text()).toBe('https://portal.example.test/mcp')
    await wrapper.findAll('button').find(button => button.text() === 'mcp_server_page.connection.copy_url')!.trigger('click')
    await flushPromises()
    expect(m.copy).toHaveBeenCalledWith('https://portal.example.test/mcp')
    wrapper.unmount()
  })

  it('does not invent a localhost endpoint when settings cannot be loaded', async () => {
    m.get.mockRejectedValue(new Error('Synthetic settings failure'))
    const wrapper = mount(ClientMcp)
    await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toContain('client_mcp.load_error')
    expect(wrapper.get('[data-empty-state]').attributes('data-empty-state')).toBe('failed')
    expect(wrapper.text()).not.toContain('client_mcp.disabled')
    expect(wrapper.text()).not.toContain('client_mcp.empty')
    expect(wrapper.find('code').exists()).toBe(false)
    expect(wrapper.findAll('button').some(button => button.text() === 'mcp_server_page.connection.copy_url')).toBe(false)
    wrapper.unmount()
  })

  it('reports a failed clipboard write without claiming the address was copied', async () => {
    m.copy.mockRejectedValue(new Error('Synthetic clipboard denial'))
    const wrapper = mount(ClientMcp)
    await flushPromises()
    await wrapper.findAll('button').find(button => button.text() === 'mcp_server_page.connection.copy_url')!.trigger('click')
    await flushPromises()
    expect(m.error).toHaveBeenCalledWith('client_mcp.copy_error')
    expect(m.success).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('keeps revocation available after the connect permission is removed', async () => {
    m.canRead = false
    const wrapper = mount(ClientMcp)
    await flushPromises()
    expect(wrapper.text()).toContain('client_mcp.permission_required')
    expect(wrapper.text()).not.toContain('client_mcp.instructions')
    await wrapper.findAll('button').find(b => b.text() === 'client_mcp.revoke')!.trigger('click')
    await flushPromises()
    expect(m.remove).toHaveBeenCalledWith('/mcp/grants/7')
    expect(m.success).toHaveBeenCalledWith('client_mcp.revoked')
    wrapper.unmount()
  })

  it('labels the canonical endpoint separately and keeps the client-domain requirement', async () => {
    m.locked = false
    m.get.mockImplementation(async (path: string) => ({ data: path === '/mcp/settings'
      ? { enabled: true, node_available: true, endpoint: 'https://app.example.test/mcp' }
      : { grants: [] },
    }))
    const wrapper = mount(ClientMcp)
    await flushPromises()
    expect(wrapper.text()).toContain('client_mcp.permission_required')
    expect(wrapper.text()).not.toContain('client_mcp.instructions')
    expect(wrapper.get('code').text()).toBe('https://app.example.test/mcp')
    expect(wrapper.text()).toContain('client_mcp.canonical_endpoint_hint')
    expect(wrapper.text()).toContain('client_mcp.description_main_domain')
    expect(wrapper.text()).toContain('client_mcp.step_copy_main_domain')
    expect(wrapper.text()).toContain('client_mcp.step_authorize_main_domain')
    expect(wrapper.text()).toContain('client_mcp.empty_main_domain')
    expect(wrapper.text()).not.toMatch(/client_mcp\.(description|step_copy|step_authorize|empty)(?!_main_domain)/)
    wrapper.unmount()
  })

  it('shows the list as the only connection data, without tokens or logs', async () => {
    m.get.mockImplementation(async (path: string) => ({ data: path === '/mcp/settings'
      ? { enabled: true, node_available: true, endpoint: 'https://portal.example.test/mcp' }
      : { grants: [
        { id: 7, client_name: 'Synthetic assistant', resource: 'https://portal.example.test/mcp', scope: 'read_write', is_active: true, last_used_at: null },
        { id: 8, client_name: 'Synthetic revoked', resource: 'https://portal.example.test/mcp', scope: 'read', is_active: false, last_used_at: null },
      ] },
    }))
    const wrapper = mount(ClientMcp)
    await flushPromises()
    expect(m.get.mock.calls.map(call => call[0]).sort()).toEqual(['/mcp/grants', '/mcp/settings'])
    expect(m.get).toHaveBeenCalledWith('/mcp/grants')
    expect(wrapper.text()).toContain('Synthetic revoked')
    expect(wrapper.text()).toContain('client_mcp.inactive')
    expect(wrapper.findAll('button').filter(button => button.text() === 'client_mcp.revoke')).toHaveLength(1)
    wrapper.unmount()
  })

  it('uses the shared card and button styles without theme-specific classes', async () => {
    const wrapper = mount(ClientMcp)
    await flushPromises()
    expect(wrapper.html()).not.toContain('dark:')
    expect(wrapper.classes()).toContain('max-w-5xl')
    expect(wrapper.get('h1').classes()).toEqual(expect.arrayContaining(['text-2xl', 'font-semibold']))
    for (const button of wrapper.findAll('button')) expect(button.classes()).toContain('whitespace-nowrap')
    wrapper.unmount()
  })

  it('preserves the existing staff token permission on a client domain', async () => {
    m.client = false
    m.canRead = false
    m.tokenAccess = true
    const wrapper = mount(ClientMcp)
    await flushPromises()
    expect(wrapper.find('code').exists()).toBe(true)
    wrapper.unmount()
  })

  it('does not use the staff fallback for a client role', async () => {
    m.canRead = false
    m.tokenAccess = true
    const wrapper = mount(ClientMcp)
    await flushPromises()
    expect(wrapper.text()).toContain('client_mcp.permission_required')
    expect(wrapper.text()).not.toContain('client_mcp.instructions')
    wrapper.unmount()
  })

  it('keeps the company-scoped guide visible when new connections are not permitted', async () => {
    m.canRead = false
    const wrapper = mount(ClientMcp)
    await flushPromises()
    expect(wrapper.text()).toContain('client_mcp.permission_required')
    expect(wrapper.text()).not.toContain('client_mcp.instructions')
    expect(wrapper.text()).toContain('client_mcp.step_authorize')
    expect(wrapper.text()).toContain('client_mcp.endpoint_hint')
    expect(wrapper.text()).not.toContain('mcp_server_page.connection.step_authorize')
    wrapper.unmount()
  })

  it('reloads connections after returning to a kept-alive panel', async () => {
    const shown = ref(true)
    const root = defineComponent({ setup: () => () => h(KeepAlive, null, {
      default: () => shown.value ? h(ClientMcp) : h('div'),
    }) })
    const wrapper = mount(root)
    await flushPromises()
    const initial = m.get.mock.calls.length
    shown.value = false
    await nextTick()
    shown.value = true
    await flushPromises()
    expect(m.get.mock.calls.length).toBe(initial + 2)
    wrapper.unmount()
  })
})
