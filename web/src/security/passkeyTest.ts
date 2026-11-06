import { authApi } from '@/api/auth'
import { getCredential, webAuthnErrorKey } from '@/security/webauthn'

/** Jak dlouho po registraci server dovolí klíč zkušebně ověřit (zrcadlí API). */
export const PASSKEY_TEST_WINDOW_MS = 15 * 60 * 1000

/**
 * - `verified` — klíč funguje,
 * - `revoked` — správce klíčů vrátil ověření, které server odmítl, a klíč se odvolal,
 * - `cancelled` — systémový dialog uživatel zavřel; klíč zůstal, jen neověřený,
 * - `skipped` — test nešlo provést (klíč už použitý, okno vypršelo, výpadek sítě).
 */
export type PasskeyTestOutcome = 'verified' | 'revoked' | 'cancelled' | 'skipped'

/**
 * Hned po registraci klíč zkušebně ověří. Správce klíčů může registraci projít
 * a pak vracet ověření, které server odmítne; uživatel by se to jinak dozvěděl,
 * až by klíčem potřeboval něco potvrdit, a zůstal by zamčený.
 */
export async function testRegisteredPasskey(credentialId: number | undefined | null): Promise<PasskeyTestOutcome> {
  if (!credentialId) return 'skipped'
  let flow
  try {
    flow = await authApi.passkeyTestOptions(credentialId)
  } catch {
    return 'skipped'
  }
  let credential
  try {
    credential = await getCredential(flow.public_key)
  } catch (e) {
    return webAuthnErrorKey(e) !== null ? 'cancelled' : 'skipped'
  }
  try {
    await authApi.passkeyTestVerify(credentialId, flow.flow_token, credential)
    return 'verified'
  } catch (e: any) {
    const error = e?.response?.data?.error
    if (error?.code === 'passkey_test_failed') {
      return error.revoked === true ? 'revoked' : 'skipped'
    }
    return 'skipped'
  }
}

/** Klíč, který ještě nikdo nepoužil a je dost čerstvý na zkušební ověření. */
export function isPasskeyTestable(item: { created_at: string | null; last_used_at: string | null }, now = Date.now()): boolean {
  if (item.last_used_at || !item.created_at) return false
  const created = Date.parse(item.created_at.replace(' ', 'T') + 'Z')
  return Number.isFinite(created) && now - created < PASSKEY_TEST_WINDOW_MS
}
