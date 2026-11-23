import { readonly, ref, type Ref } from 'vue'

/**
 * Telefon = šířka OKNA PROHLÍŽEČE pod 768 px, ne šířka pracovního panelu.
 *
 * Stránky běží v pracovních panelech (iframe), takže `matchMedia` i CSS media
 * dotazy uvnitř panelu měří šířku panelu. Na desktopu se třemi panely má každý
 * třeba 500 px a telefonní úpravy by se zapnuly i tam. Proto se šířka čte
 * z nejvyššího okna aplikace (stejný původ) a výsledek nese atribut
 * `data-phone` na `<html>` každého dokumentu. Na něj jsou navázané varianty
 * `max-md:` a `max-sm:` v `styles/main.css`.
 */
const PHONE_QUERY = '(max-width: 767.98px)'
const SMALL_PHONE_QUERY = '(max-width: 639.98px)'

function hostWindow(): Window {
  try {
    const top = window.top
    if (top && top !== window && top.document) return top
  } catch {
    // Cizí původ nad aplikací: měří se vlastní okno.
  }
  return window
}

const isPhone = ref(false)
let installed = false

export function installPhoneViewport(): void {
  if (installed || typeof window === 'undefined' || typeof window.matchMedia !== 'function') return
  installed = true
  const host = hostWindow()
  const phone = host.matchMedia(PHONE_QUERY)
  const small = host.matchMedia(SMALL_PHONE_QUERY)
  const apply = () => {
    isPhone.value = phone.matches
    document.documentElement.toggleAttribute('data-phone', phone.matches)
    document.documentElement.toggleAttribute('data-phone-sm', small.matches)
  }
  apply()
  phone.addEventListener?.('change', apply)
  small.addEventListener?.('change', apply)
  // Panel se zavírá dřív než hlavní okno: posluchače na cizím okně neodchází samy.
  window.addEventListener('pagehide', () => {
    phone.removeEventListener?.('change', apply)
    small.removeEventListener?.('change', apply)
  }, { once: true })
}

/** Reaktivní „jsem na telefonu" (šířka okna prohlížeče pod 768 px). */
export function useIsPhone(): Readonly<Ref<boolean>> {
  installPhoneViewport()
  return readonly(isPhone)
}
