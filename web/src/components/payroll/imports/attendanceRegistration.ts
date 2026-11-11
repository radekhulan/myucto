import type { AttendancePerson } from '@/api/payrollImports'

/**
 * Údaje pro registraci ČSSZ, které import osobě zapíše, jako jeden řádek textu.
 * Samostatný modul: importHelpers načítá i stránka Zaměstnanci a klíč
 * z jmenného prostoru importů by jí přibral jeho překlady.
 */
export function registrationSummary(person: AttendancePerson, t: (key: string) => string): string {
  return (person.registration ?? [])
    .map(item => `${t(`payroll_imports.attendance.registration.${item.key}`)} ${item.value}`)
    .join(' · ')
}
