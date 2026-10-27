import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({ list: vi.fn(), terminate: vi.fn(), terminateSelected: vi.fn(), success: vi.fn(), error: vi.fn(), warning: vi.fn(), navigate: vi.fn() }))
vi.mock('@/api/userSessions', () => ({ userSessionsApi: m }))
vi.mock('@/composables/useToast', () => ({ useToast: () => m }))
vi.mock('@/workspace/fullPageNavigation', () => ({ navigateFullPage: m.navigate }))
vi.mock('@/composables/useSessionAwarePolling', async () => {
  const { onMounted } = await import('vue')
  return { useSessionAwarePolling: (task: (signal: AbortSignal) => Promise<void>) => onMounted(() => task(new AbortController().signal)) }
})
vi.mock('vue-i18n', () => ({ useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }) }))
import ActiveSessions from '../ActiveSessions.vue'

const row = {
  id: 'a'.repeat(64), user_id: 42, name: 'Synthetic user', email: 'synthetic@example.test',
  ip: '127.0.0.1', user_agent: 'Synthetic Browser', created_at: '2026-10-27T09:00:00Z',
  last_seen: '2026-10-27T10:00:00Z', last_user_activity_at: '2026-10-27T09:59:00Z',
  expires_at: '2026-11-27T09:00:00Z', locked_at: null, recent_contact: true, is_current: false,
}

describe('Aktivní relace', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    vi.stubGlobal('confirm', vi.fn(() => true))
    m.list.mockResolvedValue({ data: [row], next_cursor: null, can_terminate: true })
    m.terminateSelected.mockResolvedValue({ terminated: 2, current_session_revoked: false })
    m.terminate.mockResolvedValue({ current_session_revoked: false })
  })

  it('technický přehled zůstane jen ke čtení a nezobrazí IP ani zařízení', async () => {
    m.list.mockResolvedValue({ data: [{ ...row, ip: null, user_agent: null, email: null }], next_cursor: null, can_terminate: false })
    const wrapper = mount(ActiveSessions)
    await flushPromises()
    expect(wrapper.text()).toContain('Synthetic user')
    expect(wrapper.text()).toContain('user_sessions.read_only')
    expect(wrapper.find('[data-terminate]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('user_sessions.ip')
    expect(wrapper.text()).not.toContain('user_sessions.device')
    wrapper.unmount()
  })

  it('ukončí potvrzenou relaci a obnoví seznam', async () => {
    const wrapper = mount(ActiveSessions)
    await flushPromises()
    expect(wrapper.get('[data-terminate] svg path').attributes('d')).toBeTruthy()
    await wrapper.get('[data-terminate]').trigger('click')
    await flushPromises()
    expect(m.terminate).toHaveBeenCalledExactlyOnceWith(row.id)
    expect(m.list).toHaveBeenCalledTimes(2)
    expect(m.success).toHaveBeenCalledWith('user_sessions.terminated')
    wrapper.unmount()
  })

  it('zrušení potvrzení neukončí relaci', async () => {
    vi.mocked(window.confirm).mockReturnValue(false)
    const wrapper = mount(ActiveSessions)
    await flushPromises()
    await wrapper.get('[data-terminate]').trigger('click')
    expect(m.terminate).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('vlastní relaci výslovně potvrdí a přejde k přihlášení v celém okně', async () => {
    m.list.mockResolvedValue({ data: [{ ...row, is_current: true }], can_terminate: true, next_cursor: null })
    m.terminate.mockResolvedValue({ current_session_revoked: true })
    const wrapper = mount(ActiveSessions)
    await flushPromises()
    await wrapper.get('[data-terminate]').trigger('click')
    await flushPromises()
    expect(window.confirm).toHaveBeenCalledWith('user_sessions.confirm_current')
    expect(m.navigate).toHaveBeenCalledWith('/login')
    wrapper.unmount()
  })

  it('souběžně ukončenou relaci odstraní obnovením a nezamění ji za úspěšnou revokaci', async () => {
    m.terminate.mockRejectedValue({ response: { status: 404 } })
    const wrapper = mount(ActiveSessions)
    await flushPromises()
    await wrapper.get('[data-terminate]').trigger('click')
    await flushPromises()
    expect(m.warning).toHaveBeenCalledWith('user_sessions.already_ended')
    expect(m.success).not.toHaveBeenCalled()
    expect(m.list).toHaveBeenCalledTimes(2)
    wrapper.unmount()
  })

  it('stránkuje kurzorem a umožní návrat na předchozí stránku', async () => {
    m.list.mockResolvedValueOnce({ data: [row], can_terminate: true, next_cursor: 'next-cursor' })
    const wrapper = mount(ActiveSessions)
    await flushPromises()
    const button = (label: string) => wrapper.findAll('button').find(item => item.text() === label)!
    await button('user_sessions.next').trigger('click')
    await flushPromises()
    expect(m.list).toHaveBeenLastCalledWith('next-cursor', undefined)
    await button('user_sessions.previous').trigger('click')
    await flushPromises()
    expect(m.list).toHaveBeenLastCalledWith(null, undefined)
    wrapper.unmount()
  })

  it('vybere stránku a hromadně ukončí jen vybrané relace', async () => {
    const other = { ...row, id: 'b'.repeat(64), name: 'Other synthetic user' }
    m.list.mockResolvedValue({ data: [row, other], can_terminate: true, next_cursor: null })
    const wrapper = mount(ActiveSessions)
    await flushPromises()
    expect(wrapper.get('[data-terminate-selected]').attributes('disabled')).toBeDefined()
    await wrapper.get('[data-select-all]').setValue(true)
    await wrapper.get('[data-terminate-selected]').trigger('click')
    await flushPromises()
    expect(m.terminateSelected).toHaveBeenCalledExactlyOnceWith([row.id, other.id])
    expect(m.terminate).not.toHaveBeenCalled()
    expect(m.list).toHaveBeenCalledTimes(2)
    wrapper.unmount()
  })

  it('hromadné ukončení vlastní relace výslovně potvrdí a odhlásí', async () => {
    m.list.mockResolvedValue({ data: [{ ...row, is_current: true }], can_terminate: true, next_cursor: null })
    m.terminateSelected.mockResolvedValue({ terminated: 1, current_session_revoked: true })
    const wrapper = mount(ActiveSessions)
    await flushPromises()
    await wrapper.get('[data-select-all]').setValue(true)
    await wrapper.get('[data-terminate-selected]').trigger('click')
    await flushPromises()
    expect(window.confirm).toHaveBeenCalledWith('user_sessions.confirm_selected_current')
    expect(m.navigate).toHaveBeenCalledWith('/login')
    wrapper.unmount()
  })

  it('zastaralý hromadný výběr obnoví bez hlášení úspěchu', async () => {
    m.terminateSelected.mockRejectedValue({ response: { status: 409 } })
    const wrapper = mount(ActiveSessions)
    await flushPromises()
    await wrapper.get('[data-select-all]').setValue(true)
    await wrapper.get('[data-terminate-selected]').trigger('click')
    await flushPromises()
    expect(m.warning).toHaveBeenCalledWith('user_sessions.selection_stale')
    expect(m.success).not.toHaveBeenCalled()
    expect(wrapper.get('[data-terminate-selected]').attributes('disabled')).toBeDefined()
    wrapper.unmount()
  })

  it('připne vlastní relaci před novější kontakt a zachová ji na další stránce', async () => {
    const own = { ...row, id: 'c'.repeat(64), name: 'Current synthetic session', last_seen: '2026-10-26T09:00:00Z', is_current: true }
    m.list.mockResolvedValueOnce({ data: [row], current_session: own, can_terminate: true, next_cursor: 'page-2' })
      .mockResolvedValue({ data: [{ ...row, id: 'd'.repeat(64) }], current_session: own, can_terminate: true, next_cursor: null })
    const wrapper = mount(ActiveSessions)
    await flushPromises()
    expect(wrapper.findAll('article')[0]!.attributes('data-session')).toBe(own.id)
    await wrapper.findAll('button').find(item => item.text() === 'user_sessions.next')!.trigger('click')
    await flushPromises()
    expect(wrapper.findAll('article')[0]!.attributes('data-session')).toBe(own.id)
    expect(wrapper.findAll('article')).toHaveLength(2)
    wrapper.unmount()
  })

  it('zobrazí chybu načtení a nabídne opakování', async () => {
    m.list.mockRejectedValueOnce(new Error('synthetic failure'))
    const wrapper = mount(ActiveSessions)
    await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toBe('user_sessions.load_failed')
    await wrapper.findAll('button').find(item => item.text() === 'user_sessions.refresh')!.trigger('click')
    await flushPromises()
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Synthetic user')
    wrapper.unmount()
  })
})
