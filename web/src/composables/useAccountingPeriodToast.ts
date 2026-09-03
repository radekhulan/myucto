import { useI18n } from 'vue-i18n'
import * as vueRouter from 'vue-router'
import { accountingPeriodTarget, apiErrorMessage } from '@/api/errors'
import { postingErrorI18nKey } from '@/api/accounting'
import { useToast } from '@/composables/useToast'

/**
 * Chyba „pro tohle datum neexistuje účetní období" s proklikem tam, kde se
 * období zakládá.
 *
 * Chybějící období blokuje KAŽDÉ zaúčtování — detail faktury, hromadné
 * zaúčtování ze seznamu, náhled kontace, majetek. Hláška o tom uměla říct
 * jen datum a odkázat slovy na sekci menu, která se tak nejmenuje: reálná
 * cesta je položka **Uzávěrka** (`/accounting/periods`), což zní jako konec
 * roku, ne jako jeho otevření — takže tam nikdo nehledá. Typický spouštěč je
 * přitom import historie (doklad z 2022, účetnictví od 2026), tedy okamžik,
 * kdy uživatel aplikaci teprve poznává.
 *
 * Composable vrací jednu funkci: zavolá se místo `toast.error(...)` a sama
 * pozná, jestli o chybějící období jde. Když ano, přidá k hlášce tlačítko,
 * které otevře Uzávěrku s TÍM rokem, o který šlo. Když ne, chová se jako
 * obyčejný chybový toast — dá se tedy nasadit i tam, kde tenhle stav nehrozí.
 * Stejný vzor jako {@see usePayrollYearClosedToast}.
 */
export function useAccountingPeriodToast() {
  const toast = useToast()
  /*
   * Obrazovky se montují i mimo router (testy, náhledy). Proklik je pohodlí
   * navíc, ne podmínka funkčnosti — bez routeru se hláška ukáže bez tlačítka,
   * místo aby celá obrazovka spadla na chybějící navigaci.
   */
  let router: ReturnType<typeof vueRouter.useRouter> | undefined
  try {
    router = typeof vueRouter.useRouter === 'function'
      ? vueRouter.useRouter()
      : undefined
  } catch {
    router = undefined
  }
  const { t } = useI18n()

  return function showPostingError(error: unknown, fallback?: string): void {
    const code = (error as any)?.response?.data?.error?.code
    /*
     * Přednost má překlad podle strojového kódu (accounting.posting_errors.*),
     * teprve pak česká věta ze serveru. Bez toho by anglické rozhraní dostalo
     * u téhle jediné chyby českou hlášku.
     */
    const key = typeof code === 'string' && code !== '' ? postingErrorI18nKey(code) : ''
    const translated = key !== '' ? t(key) : ''
    const message = (translated !== '' && translated !== key)
      ? translated
      : apiErrorMessage(error, fallback ?? t('common.error'))

    const target = accountingPeriodTarget(error)
    if (target === null || router === undefined) {
      toast.error(message)
      return
    }
    toast.error(message, {
      label: t('accounting.posting_errors.open_periods_action'),
      handler: () => void router.push(target),
    })
  }
}
