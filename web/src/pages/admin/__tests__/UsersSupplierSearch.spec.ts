import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  listUsers: vi.fn(),
  listUserSuppliers: vi.fn(),
  searchSuppliers: vi.fn(),
  listRoles: vi.fn(),
}))

vi.mock('@/api/admin', () => ({
  adminApi: {
    listUsers: m.listUsers,
    listUserSuppliers: m.listUserSuppliers,
    searchSuppliers: m.searchSuppliers,
  },
}))

vi.mock('@/api/roles', () => ({ rolesApi: { list: m.listRoles } }))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }),
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ newUserBlocked: null }) }))
vi.mock('@/components/ui/buttonStyles', () => ({
  ICONS: { plus: 'M0 0' },
  btnOutline: () => 'btn-outline',
  btnFilled: () => 'btn-filled',
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

import Users from '../Users.vue'

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>(resolver => { resolve = resolver })
  return { promise, resolve }
}

const clientRole = {
  id: 2,
  system_key: null,
  name: 'Klient – Admin',
  role_type: 'client',
  is_active: true,
  created_at: '2026-01-01T00:00:00Z',
  updated_at: '2026-01-01T00:00:00Z',
  default_usage: 1,
  override_usage: 0,
}

const user = {
  id: 7,
  email: 'klient@example.test',
  name: 'Testovací klient',
  role_id: clientRole.id,
  role: { id: clientRole.id, name: clientRole.name, type: 'client', system_key: null },
  locale: 'cs',
  is_active: true,
  created_at: '2026-01-01T00:00:00Z',
  last_login_at: null,
}

describe('Uživatelé — vyhledání přiřazené firmy', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.clearAllMocks()
    m.listUsers.mockResolvedValue([user])
    m.listRoles.mockResolvedValue([clientRole])
    m.listUserSuppliers.mockResolvedValue([{
      supplier_id: 10,
      name: 'Přiřazená firma s.r.o.',
      ic: '12345678',
      role_id: null,
      effective_role: user.role,
    }])
    m.searchSuppliers.mockResolvedValue({
      data: [{ id: 20, name: 'Další firma s.r.o.', ic: '87654321' }],
      next_cursor: null,
    })
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  async function openEditForm() {
    const wrapper = mount(Users)
    await flushPromises()
    const edit = wrapper.findAll('button').find(button => button.text() === 'common.edit')
    expect(edit).toBeDefined()
    await edit!.trigger('click')
    await flushPromises()
    return {
      wrapper,
      input: wrapper.get('input[placeholder="users.supplier_search"]'),
    }
  }

  it('nabídku zobrazí až po focusu a po opuštění pole ji zase skryje', async () => {
    const { wrapper, input } = await openEditForm()
    const result = () => wrapper.findAll('button').find(button => button.text().includes('Další firma s.r.o.'))

    expect(result()).toBeUndefined()
    expect(m.searchSuppliers).not.toHaveBeenCalled()

    await input.trigger('focus')
    await flushPromises()
    expect(result()).toBeDefined()
    expect(m.searchSuppliers).toHaveBeenCalledTimes(1)

    await input.trigger('blur')
    expect(result()).toBeUndefined()
  })

  it('starší odpověď nepřepíše novější dotaz ani nevypne jeho loading', async () => {
    const older = deferred<{ data: Array<{ id: number; name: string; ic: string }>; next_cursor: null }>()
    const newer = deferred<{ data: Array<{ id: number; name: string; ic: string }>; next_cursor: null }>()
    m.searchSuppliers.mockReturnValueOnce(older.promise).mockReturnValueOnce(newer.promise)
    const { wrapper, input } = await openEditForm()

    await input.setValue('stará')
    await input.trigger('focus')
    expect(m.searchSuppliers).toHaveBeenCalledTimes(1)

    await input.setValue('nová')
    await vi.advanceTimersByTimeAsync(275)
    expect(m.searchSuppliers).toHaveBeenCalledTimes(2)
    expect(wrapper.text()).toContain('common.loading')

    older.resolve({ data: [{ id: 20, name: 'Starý výsledek', ic: '11111111' }], next_cursor: null })
    await flushPromises()
    expect(wrapper.text()).not.toContain('Starý výsledek')
    expect(wrapper.text()).toContain('common.loading')

    newer.resolve({ data: [{ id: 21, name: 'Nový výsledek', ic: '22222222' }], next_cursor: null })
    await flushPromises()
    expect(wrapper.text()).toContain('Nový výsledek')
    expect(wrapper.text()).not.toContain('common.loading')
  })

  it('odpověď zneplatněná blurem nepřepíše výsledky po znovuotevření', async () => {
    const beforeBlur = deferred<{ data: Array<{ id: number; name: string; ic: string }>; next_cursor: null }>()
    const afterFocus = deferred<{ data: Array<{ id: number; name: string; ic: string }>; next_cursor: null }>()
    m.searchSuppliers.mockReturnValueOnce(beforeBlur.promise).mockReturnValueOnce(afterFocus.promise)
    const { wrapper, input } = await openEditForm()

    await input.setValue('firma')
    await input.trigger('focus')
    await input.trigger('blur')
    await input.trigger('focus')
    expect(m.searchSuppliers).toHaveBeenCalledTimes(2)

    beforeBlur.resolve({ data: [{ id: 20, name: 'Výsledek před blurem', ic: '11111111' }], next_cursor: null })
    await flushPromises()
    expect(wrapper.text()).not.toContain('Výsledek před blurem')
    expect(wrapper.text()).toContain('common.loading')

    afterFocus.resolve({ data: [{ id: 21, name: 'Aktuální výsledek', ic: '22222222' }], next_cursor: null })
    await flushPromises()
    expect(wrapper.text()).toContain('Aktuální výsledek')
  })

  it('reset formuláře zneplatní rozpracované vyhledávání', async () => {
    const pending = deferred<{ data: Array<{ id: number; name: string; ic: string }>; next_cursor: null }>()
    m.searchSuppliers.mockReturnValueOnce(pending.promise)
    const { wrapper, input } = await openEditForm()

    await input.setValue('firma')
    await input.trigger('focus')
    const create = wrapper.findAll('button').find(button => button.text() === 'users.new')
    expect(create).toBeDefined()
    await create!.trigger('click')

    pending.resolve({ data: [{ id: 20, name: 'Výsledek před resetem', ic: '11111111' }], next_cursor: null })
    await flushPromises()
    expect(wrapper.get('input[placeholder="users.supplier_search"]').element).toHaveProperty('value', '')
    expect(wrapper.text()).not.toContain('Výsledek před resetem')
  })

  it('jedno písmeno neposílá při watcheru ani refocusu, jednočíselné ID ano', async () => {
    const { input } = await openEditForm()

    await input.trigger('focus')
    await flushPromises()
    expect(m.searchSuppliers).toHaveBeenCalledTimes(1)

    await input.setValue('a')
    await vi.advanceTimersByTimeAsync(275)
    await input.trigger('blur')
    await input.trigger('focus')
    expect(m.searchSuppliers).toHaveBeenCalledTimes(1)

    await input.setValue('7')
    await vi.advanceTimersByTimeAsync(275)
    expect(m.searchSuppliers).toHaveBeenCalledTimes(2)
    expect(m.searchSuppliers).toHaveBeenLastCalledWith({ q: '7', limit: 20 })
  })

  it('zachová načtení další stránky a výběr firmy kliknutím', async () => {
    m.searchSuppliers
      .mockResolvedValueOnce({ data: [{ id: 20, name: 'První firma', ic: '11111111' }], next_cursor: '20' })
      .mockResolvedValueOnce({ data: [{ id: 21, name: 'Druhá firma', ic: '22222222' }], next_cursor: null })
    const { wrapper, input } = await openEditForm()

    await input.trigger('focus')
    await flushPromises()
    const more = wrapper.findAll('button').find(button => button.text() === 'users.supplier_more')
    expect(more).toBeDefined()
    await more!.trigger('click')
    await flushPromises()
    expect(m.searchSuppliers).toHaveBeenLastCalledWith({ limit: 20, cursor: '20' })
    expect(wrapper.text()).toContain('První firma')
    expect(wrapper.text()).toContain('Druhá firma')

    const second = wrapper.findAll('button').find(button => button.text().includes('Druhá firma'))
    expect(second).toBeDefined()
    await second!.trigger('click')
    expect(wrapper.text()).toContain('Druhá firma')
    expect(wrapper.findAll('[role="option"]').some(option => option.text().includes('Druhá firma'))).toBe(false)
  })
})
