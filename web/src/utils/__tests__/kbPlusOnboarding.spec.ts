import { describe, expect, it } from 'vitest'
import { isKbPlusRegistrationRedirect, kbPlusErrorKey } from '../kbPlusOnboarding'

describe('KB+ redirect allowlist', () => {
  it.each([
    'https://api-gateway.kb.cz/client-registration-ui/v2/saml/register?request=synthetic',
    'https://login.kb.cz/autfe/ssologin?state=synthetic',
  ])('accepts only the documented banking destinations: %s', url => {
    expect(isKbPlusRegistrationRedirect(url)).toBe(true)
  })
  it.each([
    'http://login.kb.cz/autfe/ssologin?state=synthetic',
    'https://login.kb.cz.attacker.invalid/autfe/ssologin',
    'https://login.kb.cz@attacker.invalid/autfe/ssologin',
    'https://user:password@login.kb.cz/autfe/ssologin',
    'https://login.kb.cz:444/autfe/ssologin',
    'https://login.kb.cz/autfe/ssologin#synthetic',
    'https://login.kb.cz/unrelated',
    'https://api-gateway.kb.cz/client-registration-ui/v3/saml/register',
    '//login.kb.cz/autfe/ssologin',
    'javascript:alert(1)',
    'https://login.kb.cz\\@attacker.invalid/autfe/ssologin',
    '', null, undefined,
  ])('rejects unsafe redirect without opening it: %s', url => {
    expect(isKbPlusRegistrationRedirect(url)).toBe(false)
  })
  it('does not turn arbitrary server text or callback codes into UI content', () => {
    expect(kbPlusErrorKey('https://attacker.invalid/?token=synthetic')).toBe('kb_plus.error_generic')
    expect(kbPlusErrorKey('callback_query_redaction_required')).toBe('kb_plus.error_callback_redaction')
    expect(kbPlusErrorKey('kb_plus_account_not_found')).toBe('kb_plus.error_account_not_found')
  })
})
