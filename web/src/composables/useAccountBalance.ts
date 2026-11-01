import { useI18n } from 'vue-i18n'
import { formatMoney } from '@/composables/useFormat'

/**
 * Zůstatek účtu se stranou. Server ho vrací se znaménkem (kladné = MD, záporné = Dal),
 * uživatel ho vidí jako v hlavní knize: kladná částka a strana slovem. Jinak by u pasivního
 * účtu svítilo mínus i u zcela běžného zůstatku.
 */
export function useAccountBalance() {
  const { t } = useI18n()

  function side(balance: number): string {
    return balance > 0 ? t('accounting.journal.side.debit') : t('accounting.journal.side.credit')
  }

  return {
    balanceLabel: (label: string, balance: number): string =>
      balance === 0 ? label : `${label} · ${side(balance)}`,
    formatBalanceAmount: (balance: number): string => formatMoney(Math.abs(balance)),
    formatBalance: (balance: number): string =>
      balance === 0 ? formatMoney(0) : `${formatMoney(Math.abs(balance))} ${side(balance)}`,
  }
}
