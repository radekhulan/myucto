import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { authApi } from '@/api/auth'
import { ssoEnforcementPolicy } from '@/security/oidc'

export function ssoEnforcedProvider(value: string | null | undefined): string | null {
  return ssoEnforcementPolicy({ sso_required_provider: value }).provider
}

/** Pole ověření citlivé operace, jak je dialog sbírá. */
export interface ReauthInput {
  password?: string
  totp_code?: string
  step_up_token?: string
}

/**
 * Vynucené přihlášení přes účet organizace pro celou instalaci (konfigurace
 * serveru). Jediný zdroj pravidel pro celé UI:
 *
 * - `enforced`: heslo účtu se nepoužívá pro nikoho, ani pro správce instance;
 * - `strong`: poskytovatel řídí i druhý faktor, citlivé operace potvrzuje jen
 *   ověření účtem organizace (`SsoStepUpOption`) a místní faktory nemají smysl;
 * - jinak (poskytovatel je jen první faktor) zůstávají passkey a TOTP jako
 *   druhý faktor a potvrzení citlivé operace, jen bez hesla.
 */
export function useSsoEnforcement() {
  const auth = useAuthStore()
  const policy = computed(() => ssoEnforcementPolicy(auth.setupStatus))
  const provider = computed(() => policy.value.provider)
  const enforced = computed(() => policy.value.provider !== null)
  const strong = computed(() => policy.value.strong)
  const passwordAllowed = computed(() => !enforced.value)
  const localFactorsAllowed = computed(() => !strong.value)

  /**
   * Při vynucení server přijme jen `step_up_token`: TOTP kód se proto nejdřív
   * vymění za proof dané operace. Bez vynucení odejdou pole beze změny.
   */
  async function reauthProof(operation: string, input: ReauthInput): Promise<ReauthInput> {
    if (!enforced.value) return input
    if (input.step_up_token) return { step_up_token: input.step_up_token }
    const code = input.totp_code?.trim() ?? ''
    if (!strong.value && code !== '') return { step_up_token: await authApi.totpStepUp(operation, code) }
    return {}
  }

  /**
   * Co při vynucení chybí k potvrzení citlivé operace: `''` = hotovo, jinak
   * klíč hlášky. `null` = bez vynucení, rozhoduje původní logika dialogu.
   * `token` je proof, který dialog drží, `fromSso` říká, zda ho vydalo
   * ověření účtem organizace.
   */
  function enforcedStepUpMissing(token: string, fromSso: boolean, totpCode: string, hasTotp: boolean): string | null {
    if (!enforced.value) return null
    if (strong.value) return token !== '' && fromSso ? '' : 'sso.step_up_enforced_required'
    if (token !== '') return ''
    return hasTotp && /^\d{6}$/.test(totpCode.trim()) ? '' : 'sso.step_up_local_factor_required'
  }

  return { provider, enforced, strong, passwordAllowed, localFactorsAllowed, reauthProof, enforcedStepUpMissing }
}
