/**
 * Strojové kódy odesílací cesty mzdových podání, podle kterých obrazovky
 * nabízejí další krok. Samostatný modul, aby na ně nesahaly testy, které
 * mockují celé `@/api/payroll`.
 */

/** Kód pokusu, na který ČSSZ odpověděla „shodné podání už existuje" (20022). */
export const PAYROLL_TRANSPORT_ORIGINAL_AT_CSSZ = 'jmhz_original_at_cssz'

/** Kód chyby zmrazení, které čeká na potvrzení slevy po lhůtě (kontrola 290). */
export const PAYROLL_JMHZ_LATE_DISCOUNT_CONFIRMATION = 'jmhz_submission_late_discount_confirmation_required'

/** Kód chyby zmrazení do 48 h po přijaté změně údajů (REGZEC A3): odeslat i tak, nebo odložit dotčené. */
export const PAYROLL_JMHZ_REGISTRATION_SETTLING = 'jmhz_registration_change_settling'
