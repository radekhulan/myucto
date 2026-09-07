import { describe, it, expect, beforeEach, vi } from 'vitest'
import { reactive, nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'
import cs from '@/i18n/cs.json'
import { readStorageQuotaHeaders } from '@/api/storageQuota'
import { startPreview, stopPreview } from '@/api/instancePreview'

/**
 * Červená linka nad aplikací (H-31) — tvrzení nad KOMPONENTOU, ne nad snímkem.
 *
 * Hlídá se to, co se dá pokazit v provozu:
 *  - self-hosted instalace ji nesmí dostat (hlavní riziko),
 *  - vyčerpaná kvóta ji zobrazí, varování na 90 % ne,
 *  - neúspěšný dotaz ji nesmí schovat,
 *  - degradovaná licence ji zobrazí,
 *  - nemá zavírací prvek — nejde ji odklikat.
 *
 * `t()` sahá do SKUTEČNÝCH českých textů, takže test zároveň hlídá, že klíče
 * existují; chybějící klíč se propíše do porovnání jako holý klíč.
 */

const auth = reactive({
  isManagedInstallation: true,
  license: null as { state: string } | null,
})

vi.mock('@/stores/auth', () => ({ useAuthStore: () => auth }))

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ locale: { value: 'cs' },
    t: (key: string) => {
      const value = key.split('.').reduce<unknown>(
        (node, part) => (node && typeof node === 'object' ? (node as Record<string, unknown>)[part] : undefined),
        cs,
      )
      return typeof value === 'string' ? value : key
    },
  }),
}))

// Dynamický import až tady: mock výše se odkazuje na `auth`, které musí být
// inicializované dřív, než se komponenta (a s ní store) natáhne.
const InstanceCriticalBar = (await import('../InstanceCriticalBar.vue')).default

async function mountBar() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/hosting', component: { template: '<div />' } },
      { path: '/activation/purchase', component: { template: '<div />' } },
    ],
  })
  await router.push('/')
  await router.isReady()

  const wrapper = mount(InstanceCriticalBar, { global: { plugins: [router] } })
  await nextTick()
  return wrapper
}

const BAR = '[data-instance-critical-bar]'

beforeEach(() => {
  readStorageQuotaHeaders({})
  auth.isManagedInstallation = true
  auth.license = null
  stopPreview()
})

describe('InstanceCriticalBar', () => {
  /**
   * ⚠️ PROČ BY TENHLE TEST BEZ OPRAVY PADAL: linka navázaná jen na stav kvóty
   * a licence se objeví i na self-hosted instalaci — a to je nejhorší možná
   * regrese. Kvótu tam nikdo nenastavil a degradovaná licence tam znamená
   * legitimní provoz MIT jádra, ne dluh.
   */
  it('self-hosted instalace linku nedostane, ani když by oba důvody platily', async () => {
    auth.isManagedInstallation = false
    auth.license = { state: 'degraded' }
    readStorageQuotaHeaders({ 'x-storage-quota-state': 'exhausted' })

    const wrapper = await mountBar()

    expect(wrapper.find(BAR).exists()).toBe(false)
  })

  it('vyčerpaná kvóta linku zobrazí a řekne, co se děje i co s tím', async () => {
    readStorageQuotaHeaders({ 'x-storage-quota-state': 'exhausted' })

    const wrapper = await mountBar()

    expect(wrapper.find(BAR).exists()).toBe(true)
    expect(wrapper.find('[data-instance-critical-reason="storage_exhausted"]').exists()).toBe(true)
    const text = wrapper.text()
    expect(text).toContain(cs.instance_alert.storage_exhausted_title)
    expect(text).toContain(cs.instance_alert.storage_exhausted_desc)
    // Proklik na stránku s vysvětlením a cestou k nápravě.
    expect(wrapper.find('a[href="/hosting"]').exists()).toBe(true)
  })

  /** Varování na 90 % zůstává žlutým, neblokujícím pruhem — červená linka ne. */
  it('varování na 90 % linku nezobrazí', async () => {
    readStorageQuotaHeaders({ 'x-storage-quota-state': 'warning', 'x-storage-quota-percent': '90.0' })

    const wrapper = await mountBar()

    expect(wrapper.find(BAR).exists()).toBe(false)
  })

  /**
   * ⚠️ PROČ BY TENHLE TEST BEZ OPRAVY PADAL: stav kvóty se čte z hlaviček
   * odpovědí a původní implementace ho mazala při každé odpovědi bez hlaviček.
   * Jeden 401 nebo 500 by tak zhasl jedinou zprávu o tom, že se nic neukládá.
   */
  it('selhání dotazu linku neschová', async () => {
    readStorageQuotaHeaders({ 'x-storage-quota-state': 'exhausted' })
    const wrapper = await mountBar()
    expect(wrapper.find(BAR).exists()).toBe(true)

    // Chybová odpověď bez hlaviček (401 / 500 / chyba s odpovědí).
    readStorageQuotaHeaders({}, { trusted: false })
    await nextTick()

    expect(wrapper.find(BAR).exists()).toBe(true)
  })

  it('degradovaná licence linku zobrazí a vede na úhradu', async () => {
    auth.license = { state: 'degraded' }

    const wrapper = await mountBar()

    expect(wrapper.find('[data-instance-critical-reason="unpaid"]').exists()).toBe(true)
    expect(wrapper.text()).toContain(cs.instance_alert.unpaid_title)
    expect(wrapper.find('a[href="/activation/purchase"]').exists()).toBe(true)
  })

  it('prošlý trial linku zobrazí taky', async () => {
    auth.license = { state: 'trial_expired' }

    const wrapper = await mountBar()

    expect(wrapper.find('[data-instance-critical-reason="unpaid"]').exists()).toBe(true)
  })

  it('zdravá spravovaná instalace linku nemá', async () => {
    auth.license = { state: 'active' }

    const wrapper = await mountBar()

    expect(wrapper.find(BAR).exists()).toBe(false)
  })

  /**
   * ⚠️ Linka se nesmí dát odklikat. Kdo si ji zavře, dozví se o zastavených
   * zápisech až u ztraceného dokladu — proto žádný křížek, žádné „rozumím"
   * a žádné zapamatování v localStorage.
   */
  it('nemá žádný zavírací prvek', async () => {
    readStorageQuotaHeaders({ 'x-storage-quota-state': 'exhausted' })
    auth.license = { state: 'degraded' }

    const wrapper = await mountBar()
    const line = wrapper.find(BAR)

    expect(line.exists()).toBe(true)
    expect(line.findAll('button')).toHaveLength(0)
    expect(line.findAll('[data-close], [data-dismiss]')).toHaveLength(0)
    expect(line.html()).not.toMatch(/dismiss|zavřít|rozumím/i)
  })

  /**
   * ⚠️ PROČ BY TENHLE TEST BEZ OPRAVY PADAL: linka dřív vedla jen na vnitřní
   * obrazovku, ta na správu předplatného a teprve odtud se dalo doplatit —
   * tři skoky bez jediné částky. Kdo se v nich ztratil, nezaplatil. Když
   * licenční server pošle podepsaný odkaz, musí být hlavní tlačítko PŘÍMO on.
   */
  it('u neuhrazení vede hlavní tlačítko rovnou na platbu', async () => {
    startPreview('past_due')

    const wrapper = await mountBar()
    const pay = wrapper.find('[data-instance-critical-pay]')

    expect(pay.exists()).toBe(true)
    expect(pay.attributes('href')).toBe('https://myucto.cz/platba/nahled')
    // Částka na tlačítku — bez ní se člověk nedozví, o kolik jde. (Mock `t()`
    // interpolaci nedělá, takže se hlídá zvolená varianta klíče.)
    expect(pay.text()).toBe(cs.instance_alert.pay_cta_amount)
    // Proklik na rekapitulaci zůstává vedle jako sekundární.
    expect(wrapper.find('a[href="/activation/purchase"]').exists()).toBe(true)
  })

  /** Oba důvody najednou = dva řádky, ne jeden schovaný. */
  it('dva důvody ukáže oba', async () => {
    readStorageQuotaHeaders({ 'x-storage-quota-state': 'exhausted' })
    auth.license = { state: 'degraded' }

    const wrapper = await mountBar()

    expect(wrapper.findAll('[data-instance-critical-reason]')).toHaveLength(2)
  })
})
