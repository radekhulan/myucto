export type PayrollManualChapterRule = [RegExp, string]

export const PAYROLL_MANUAL_CHAPTERS: PayrollManualChapterRule[] = [
  [/^\/payroll\/absences(?:\/|$)/, '76_Absence_a_dovolena'],
  [/^\/payroll\/time(?:\/|$)/, '77_Dochazka_a_smeny'],
  [/^\/payroll\/travel(?:\/|$)/, '78_Cestovni_nahrady'],
  [/^\/payroll\/quick-inputs(?:\/|$)/, '79_Rychly_mesicni_vstup'],
  [/^\/payroll\/runs(?:\/|$)/, '80_Mzdove_behy'],
  [/^\/payroll\/month-guide(?:\/|$)/, '80_Mzdove_behy'],
  [/^\/payroll\/posting-reconciliation(?:\/|$)/, '81_Shoda_uctovani_mezd'],
  [/^\/payroll\/posting-detail(?:\/|$)/, '81_Shoda_uctovani_mezd'],
  [/^\/payroll\/payments(?:\/|$)/, '82_Platby_a_uhrady'],
  [/^\/payroll\/documents(?:\/|$)/, '83_Dokumenty_a_vystupy'],
  [/^\/payroll\/annual-settlement(?:\/|$)/, '84_Rocni_zuctovani'],
  [/^\/payroll\/submissions(?:\/|$)/, '85_Podani_a_hlaseni'],
  [/^\/payroll\/people(?:\/|$)/, '86_Zamestnanci'],
  [/^\/payroll\/personnel-file(?:\/|$)/, '86_Zamestnanci'],
  [/^\/payroll\/deduction-agreements(?:\/|$)/, '87_Dohody_o_srazkach'],
  [/^\/payroll\/enforcement\/cooperation(?:\/|$)/, '88_Srazky_a_exekuce'],
  [/^\/payroll\/enforcement(?:\/|$)/, '88_Srazky_a_exekuce'],
  [/^\/payroll\/insolvency(?:\/|$)/, '88_Srazky_a_exekuce'],
  [/^\/payroll\/benefit-baskets(?:\/|$)/, '89_Kose_benefitu'],
  [/^\/payroll\/settings(?:\/|$)/, '90_Nastaveni_mezd'],
  // Agenda přechodu žije jako záložky Importů; `manualChapterPath()` je do cesty
  // dopíše, aby Nápověda nevedla na Nastavení mezd. Musí stát PŘED obecným
  // pravidlem Importů, které by jinak sebralo i tyhle cesty.
  [/^\/payroll\/imports\/(takeover|reconciliation|posting_map)$/, '108_Prechod_z_PAMICA'],
  // Importy registrací, hlášení a docházky popisuje 90.9 v Nastavení mezd.
  [/^\/payroll\/imports(?:\/|$)/, '90_Nastaveni_mezd'],
  // Staré adresy agendy přechodu dnes jen přesměrovávají, kapitolu ale drží dál:
  // odkazuje na ně manuál i uložené záložky prohlížeče.
  [/^\/payroll\/migration-reconciliation(?:\/|$)/, '108_Prechod_z_PAMICA'],
  [/^\/payroll\/posting-map(?:\/|$)/, '108_Prechod_z_PAMICA'],
  [/^\/payroll\/components(?:\/|$)/, '91_Mzdove_slozky_a_vstupy'],
  [/^\/payroll\/rulesets(?:\/|$)/, '92_Legislativni_pravidla_mezd'],
  [/^\/payroll\/retention(?:\/|$)/, '93_Retencni_lhuty'],
  [/^\/payroll\/erasure(?:\/|$)/, '94_Vymaz_osobnich_udaju'],
  [/^\/payroll(?:\/|$)/, '75_Uplne_mzdy'],
]

export function payrollManualChapter(path: string): string | undefined {
  return PAYROLL_MANUAL_CHAPTERS.find(([pattern]) => pattern.test(path))?.[1]
}
