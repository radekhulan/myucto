import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({ status: vi.fn(), check: vi.fn(), trigger: vi.fn() }))
vi.mock('@/api/instanceUpdates', () => ({ instanceUpdatesApi: m }))
vi.mock('@/composables/useSessionAwarePolling', async () => {
  const { onMounted } = await import('vue')
  return { useSessionAwarePolling: (task: (signal: AbortSignal) => Promise<void>) => onMounted(() => task(new AbortController().signal)) }
})
vi.mock('vue-i18n', () => ({ useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }) }))
import InstanceUpdates from '../InstanceUpdates.vue'

const status = { current: '6.0.0', latest: '6.0.0', has_update: false, cache_stale: false, check_failed: false, last_check_at: '2026-10-27T10:00:00Z', upgrade_in_progress: false, managed: false, other_recent_sessions: 0 }

describe('Technické informace o aktualizacích', () => {
  beforeEach(() => { vi.resetAllMocks(); vi.stubGlobal('confirm', vi.fn(() => true)); m.status.mockResolvedValue(status); m.check.mockResolvedValue(status); m.trigger.mockResolvedValue({ status: 'queued' }) })

  it.each([
    [{ latest: null, cache_stale: true }, 'unknown'],
    [{ check_failed: true }, 'unknown'],
    [{ cache_stale: true }, 'unknown'],
    [{ has_update: true, latest: '6.0.1' }, 'available'],
    [{ upgrade_in_progress: true, latest: null }, 'running'],
    [{}, 'current'],
  ])('zobrazuje stav %s jako %s s odpovídající dostupností spuštění', async (patch, expected) => {
    m.status.mockResolvedValue({ ...status, ...patch })
    const wrapper = mount(InstanceUpdates)
    await flushPromises()
    expect(wrapper.get('[data-update-state]').attributes('data-update-state')).toBe(expected)
    expect(wrapper.findAll('button')).toHaveLength(3)
    expect(wrapper.get('[data-trigger-update]').attributes('disabled') === undefined).toBe(expected === 'available')
    await wrapper.get('button').trigger('click')
    await flushPromises()
    expect(m.status).toHaveBeenCalledTimes(2)
    wrapper.unmount()
  })

  it('zkontroluje verzi a po potvrzení spustí pouze nabízenou aktualizaci', async () => {
    m.check.mockResolvedValue({ ...status, latest: '6.0.1', has_update: true })
    const wrapper = mount(InstanceUpdates)
    await flushPromises()
    await wrapper.get('[data-check-updates]').trigger('click')
    await flushPromises()
    expect(m.check).toHaveBeenCalledTimes(1)
    await wrapper.get('[data-trigger-update]').trigger('click')
    await flushPromises()
    expect(window.confirm).toHaveBeenCalledWith('instance_updates.confirm_trigger')
    expect(m.trigger).toHaveBeenCalledExactlyOnceWith('6.0.1', false)
    expect(wrapper.text()).toContain('instance_updates.queued')
    wrapper.unmount()
  })

  it('zrušené potvrzení nic nespustí a spravovaná instalace nemá mutační tlačítka', async () => {
    vi.mocked(window.confirm).mockReturnValue(false)
    m.status.mockResolvedValue({ ...status, latest: '6.0.1', has_update: true })
    const wrapper = mount(InstanceUpdates)
    await flushPromises()
    await wrapper.get('[data-trigger-update]').trigger('click')
    expect(m.trigger).not.toHaveBeenCalled()
    wrapper.unmount()
    m.status.mockResolvedValue({ ...status, managed: true })
    const managed = mount(InstanceUpdates)
    await flushPromises()
    expect(managed.find('[data-check-updates]').exists()).toBe(false)
    expect(managed.find('[data-trigger-update]').exists()).toBe(false)
    managed.unmount()
  })

  it('upozorní na další připojené relace a vyžádá potvrzení přerušení práce', async () => {
    m.status.mockResolvedValue({ ...status, latest: '6.0.1', has_update: true, other_recent_sessions: 2 })
    const wrapper = mount(InstanceUpdates)
    await flushPromises()
    expect(wrapper.text()).toContain('instance_updates.active_warning')
    await wrapper.get('[data-trigger-update]').trigger('click')
    await flushPromises()
    expect(window.confirm).toHaveBeenCalledWith('instance_updates.confirm_trigger_active')
    expect(m.trigger).toHaveBeenCalledExactlyOnceWith('6.0.1', true)
    wrapper.unmount()
  })

  it('nově připojené relace z backendové pojistky vyžádají nové potvrzení', async () => {
    m.status.mockResolvedValue({ ...status, latest: '6.0.1', has_update: true })
    m.trigger.mockRejectedValue({ response: { data: { error: { code: 'active_sessions', other_recent_sessions: 3 } } } })
    const wrapper = mount(InstanceUpdates)
    await flushPromises()
    await wrapper.get('[data-trigger-update]').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('instance_updates.active_changed')
    expect(wrapper.text()).toContain('instance_updates.active_warning')
    expect(m.trigger).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('zobrazí chybu čtení bez interní chybové zprávy', async () => {
    m.status.mockRejectedValue(new Error('synthetic internal path /private/example'))
    const wrapper = mount(InstanceUpdates)
    await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toBe('instance_updates.load_failed')
    expect(wrapper.text()).not.toContain('/private/example')
    wrapper.unmount()
  })
})
