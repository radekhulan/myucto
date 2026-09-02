import { useI18n } from 'vue-i18n'
import * as vueRouter from 'vue-router'
import { apiErrorMessage, yearClosedTarget } from '@/api/errors'
import { useToast } from '@/composables/useToast'

/**
 * Chyba „mzdový rok je uzavřený" s proklikem na Roční uzávěrku mezd.
 *
 * Uzávěrka je jediné místo v modulu, které blokuje zápis napříč agendami:
 * uzavřením roku přestanou jít uložit nepřítomnosti, exekuce, závazky, podání
 * i mzdové běhy — tedy pět obrazovek, které o uzávěrce nic nevědí. Hláška ze
 * serveru uměla říct rok a poradit „rok nejprve znovu otevřete", ale nikam
 * nevedla; účetní musela uhodnout, že se to dělá na mzdovém rozcestníku, a
 * doklikat se tam. Uzávěrka se přitom dělá jednou za rok, takže tu cestu
 * nikdo nemá v ruce.
 *
 * Composable proto vrací jednu funkci: zavolá se místo `toast.error(...)` a
 * sama pozná, jestli jde o uzavřený rok. Když ano, přidá k hlášce tlačítko,
 * které rovnou otevře uzávěrku s TÍM rokem, o který šlo. Když ne, chová se
 * jako obyčejný chybový toast, takže se dá nasadit i tam, kde uzavřený rok
 * nehrozí.
 */
export function usePayrollYearClosedToast() {
  const toast = useToast()
  /*
   * Obrazovky se montují i mimo router (testy, náhledy). Proklik je pohodlí
   * navíc, ne podmínka funkčnosti — bez routeru se hláška prostě ukáže bez
   * tlačítka, místo aby celá obrazovka spadla na chybějící navigaci.
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

  return function showPayrollError(error: unknown, fallback: string): void {
    const target = yearClosedTarget(error)
    const message = apiErrorMessage(error, fallback)
    if (target === null || router === undefined) {
      toast.error(message)
      return
    }
    toast.error(message, {
      label: t('payroll.year_close.open_action'),
      handler: () => void router.push(target),
    })
  }
}
