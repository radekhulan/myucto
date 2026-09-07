export function isKbPlusRegistrationRedirect(value: unknown): value is string {
  if (typeof value !== 'string' || value.length > 16384 || /[\u0000-\u0020\u007f\\]/.test(value)) return false
  try {
    const url = new URL(value)
    return url.protocol === 'https:' && !url.username && !url.password && !url.hash && (!url.port || url.port === '443')
      && ((url.hostname === 'api-gateway.kb.cz' && url.pathname === '/client-registration-ui/v2/saml/register')
        || (url.hostname === 'login.kb.cz' && url.pathname === '/autfe/ssologin'))
  } catch {
    return false
  }
}

export function navigateToKbPlus(value: unknown): boolean {
  if (!isKbPlusRegistrationRedirect(value)) return false
  window.location.assign(value)
  return true
}

export function kbPlusErrorKey(code: string): string {
  const keys: Record<string, string> = {
    kb_plus_registration_invalid: 'registration_invalid',
    kb_plus_onboarding_expired: 'expired', kb_plus_onboarding_used: 'used',
    kb_plus_onboarding_used_or_expired: 'expired', kb_plus_contact_missing: 'contact',
    kb_plus_account_not_found: 'account_not_found', kb_plus_account_ambiguous: 'account_ambiguous',
    encryption_key_unavailable: 'encryption', credential_unavailable: 'encryption',
    app_url_missing: 'app_url', app_url_invalid: 'app_url',
    callback_query_redaction_required: 'callback_redaction',
    kb_plus_callback_query_redaction_required: 'callback_redaction',
    bank_remote_unavailable: 'remote', remote_unavailable: 'remote',
    certificate_invalid: 'certificate', certificate_runtime_unavailable: 'certificate_runtime',
    bank_rate_limited: 'cooldown', rate_limited: 'cooldown', bank_connection_busy: 'busy',
  }
  return `kb_plus.error_${Object.hasOwn(keys, code) ? keys[code] : 'generic'}`
}
