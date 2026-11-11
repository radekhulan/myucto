/**
 * Kam vede náprava blokátoru registrace zaměstnance (REGZEC/PREZEC).
 *
 * Server píše v hlášce, co je špatně a kde to opravit; tahle mapa k tomu
 * přidává proklik. `employer_settings` = Nastavení mezd → Zaměstnavatel
 * a účtárny (variabilní symbol, kód správy), `a1_profile` = profil
 * registrace A1 na téže kartě, `person` = sekce karty osoby.
 */
export type RegistrationRemediation =
  | { kind: 'employer_settings' }
  | { kind: 'a1_profile' }
  | { kind: 'person', panel: 'identifiers' | 'addresses', field: string }

export const registrationRemediationCodes: Record<string, RegistrationRemediation> = {
  registration_employer_variable_symbol_missing: { kind: 'employer_settings' },
  registration_employer_variable_symbol_invalid: { kind: 'employer_settings' },
  registration_cssz_workplace_code_missing: { kind: 'employer_settings' },
  registration_cssz_workplace_code_invalid: { kind: 'employer_settings' },
  registration_regzec_a1_profile_missing: { kind: 'a1_profile' },
  // Na přihlášku je brzy: profil A1 jde vyplnit a uložit už teď.
  registration_prezec_start_window_invalid: { kind: 'a1_profile' },
  registration_regzec_a1_start_window_invalid: { kind: 'a1_profile' },
  registration_regzec_a1_foreign_insurance_missing: { kind: 'a1_profile' },
  registration_event_foreign_insurance_missing: { kind: 'a1_profile' },
  registration_event_birth_number_missing: { kind: 'person', panel: 'identifiers', field: 'birth_number' },
  registration_event_czech_residence_unverifiable: { kind: 'person', panel: 'addresses', field: 'permanent_address' },
}

export function registrationRemediation(code: string | null | undefined): RegistrationRemediation | null {
  if (!code || !Object.hasOwn(registrationRemediationCodes, code)) return null
  return registrationRemediationCodes[code]!
}

/**
 * Chyby náhledu, které se týkají přihlášky PŘED nástupem. U nich má smysl
 * nabídnout volbu částečná / plná registrace, i když náhled neprošel: jinak
 * by rada „podejte plnou registraci" neměla v obrazovce kam vést.
 */
export function registrationErrorBeforeStart(code: string | null | undefined): boolean {
  return typeof code === 'string'
    && (code.startsWith('registration_prezec_')
      || code === 'registration_regzec_a1_start_window_invalid')
}

/** Na přihlášku je zatím brzy: stav k vyčkání, ne chyba údajů. */
export function registrationTooEarly(code: string | null | undefined): boolean {
  return code === 'registration_prezec_start_window_invalid'
    || code === 'registration_regzec_a1_start_window_invalid'
}
