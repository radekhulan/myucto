import { afterEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { isInvoiceDateOverdue, setOverdueIncludesToday } from '@/utils/invoiceOverdue'

const mocks = vi.hoisted(() => ({ setupStatus: vi.fn() }))
vi.mock('@/api/auth', () => ({ authApi: { setupStatus: mocks.setupStatus } }))

afterEach(() => {
  vi.useRealTimers()
  setOverdueIncludesToday(false)
  mocks.setupStatus.mockReset()
})

describe('načtení konfigurace splatnosti', () => {
  it('přenese nastavení serveru do reaktivního zobrazení a u staršího serveru použije false', async () => {
    setActivePinia(createPinia())
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-09-07T12:00:00Z'))
    const auth = useAuthStore()
    const overdue = computed(() => isInvoiceDateOverdue('2026-09-07'))
    expect(overdue.value).toBe(false)

    mocks.setupStatus.mockResolvedValue({ needs_setup: true, overdue_includes_today: true })
    await auth.fetchSetupStatus()
    expect(overdue.value).toBe(true)

    mocks.setupStatus.mockResolvedValue({ needs_setup: true })
    await auth.fetchSetupStatus()
    expect(overdue.value).toBe(false)
  })
})
