import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

const m = vi.hoisted(() => ({ totpStepUp: vi.fn() }))
vi.mock('@/api/auth', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/auth')>()
  return { ...actual, authApi: { ...actual.authApi, totpStepUp: m.totpStepUp } }
})

import { useSsoEnforcement } from '../useSsoEnforcement'
import { useAuthStore } from '@/stores/auth'

function configure(required: string | null, strong: boolean) {
  useAuthStore().setupStatus = {
    sso_required_provider: required,
    sso_providers: [{ id: 'entra', label: 'Microsoft', strong }],
  } as never
}

describe('useSsoEnforcement', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    m.totpStepUp.mockReset()
  })

  it('bez vynucení povolí heslo i místní faktory a pole ověření nechá beze změny', async () => {
    configure(null, true)
    const sso = useSsoEnforcement()
    expect(sso.enforced.value).toBe(false)
    expect(sso.passwordAllowed.value).toBe(true)
    expect(sso.localFactorsAllowed.value).toBe(true)
    const input = { password: 'synthetic-pass', totp_code: '123456', step_up_token: undefined }
    expect(await sso.reauthProof('epo.certificate', input)).toBe(input)
    expect(sso.enforcedStepUpMissing('', false, '', false)).toBeNull()
    expect(m.totpStepUp).not.toHaveBeenCalled()
  })

  it('prázdný poskytovatel vynucení neznamená', () => {
    configure('', true)
    expect(useSsoEnforcement().enforced.value).toBe(false)
  })

  it('silný poskytovatel přijme jen proof z ověření účtem organizace', async () => {
    configure('entra', true)
    const sso = useSsoEnforcement()
    expect(sso.passwordAllowed.value).toBe(false)
    expect(sso.localFactorsAllowed.value).toBe(false)
    expect(sso.enforcedStepUpMissing('passkey-proof', false, '', true)).toBe('sso.step_up_enforced_required')
    expect(sso.enforcedStepUpMissing('', false, '123456', true)).toBe('sso.step_up_enforced_required')
    expect(sso.enforcedStepUpMissing('sso-proof', true, '', false)).toBe('')
    expect(await sso.reauthProof('epo.certificate', { password: 'x', step_up_token: 'sso-proof' }))
      .toEqual({ step_up_token: 'sso-proof' })
    expect(await sso.reauthProof('epo.certificate', { password: 'x', totp_code: '123456' })).toEqual({})
    expect(m.totpStepUp).not.toHaveBeenCalled()
  })

  it('poskytovatel jako první faktor nechá passkey a TOTP, kód vymění za proof a heslo neodešle', async () => {
    configure('entra', false)
    const sso = useSsoEnforcement()
    expect(sso.passwordAllowed.value).toBe(false)
    expect(sso.localFactorsAllowed.value).toBe(true)
    expect(sso.enforcedStepUpMissing('', false, '', true)).toBe('sso.step_up_local_factor_required')
    expect(sso.enforcedStepUpMissing('', false, '123456', true)).toBe('')
    expect(sso.enforcedStepUpMissing('passkey-proof', false, '', false)).toBe('')
    m.totpStepUp.mockResolvedValue('totp-proof')
    expect(await sso.reauthProof('backup.password', { password: 'synthetic-pass', totp_code: ' 123456 ' }))
      .toEqual({ step_up_token: 'totp-proof' })
    expect(m.totpStepUp).toHaveBeenCalledWith('backup.password', '123456')
  })

  it('vynucený silný poskytovatel nenutí ani nenabízí zřízení místního MFA', () => {
    const auth = useAuthStore()
    auth.user = { id: 1, must_setup_mfa: true, must_setup_totp: true, should_offer_mfa: true } as never
    configure(null, true)
    expect(auth.mustSetupMfa).toBe(true)
    expect(auth.shouldOfferMfa).toBe(true)
    configure('entra', false)
    expect(auth.mustSetupMfa).toBe(true)
    configure('entra', true)
    expect(auth.mustSetupMfa).toBe(false)
    expect(auth.mustSetupTotp).toBe(false)
    expect(auth.shouldOfferMfa).toBe(false)
  })
})
