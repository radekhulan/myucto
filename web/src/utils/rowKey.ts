/**
 * Stabilní `:key` pro řádky editovatelných tabulek, které se dají mazat.
 *
 * Why: `:key="i"` sváže Vue instanci s POZICÍ, ne s řádkem. Po smazání
 * prostředního řádku zůstane instance na místě a převezme data souseda — a s
 * nimi i svůj vnitřní stav. U {@link ../components/ui/DateInput.vue DateInput}
 * to znamená, že rozepsaný neplatný text z jednoho řádku skočí do druhého a
 * přes `setCustomValidity` zablokuje uložení formuláře.
 *
 * Klíč se drží ve `WeakMap` podle identity objektu řádku, takže se nezapisuje
 * do dat: payload odcházející do API zůstává beze změny a nic se nemusí
 * odstraňovat před odesláním. Uvolní se s řádkem samotným.
 *
 * Použití:
 *   <tr v-for="(row, i) in rows" :key="rowKey(row)">
 * Pro dvě vykreslení téhož seznamu (tabulka + karty) je potřeba rozlišit
 * prefixem, jinak by si obě instance sáhly na týž klíč:
 *   :key="`m-${rowKey(row)}`"
 */
const keys = new WeakMap<object, number>()
let nextKey = 0

export function rowKey(row: object): number {
  let key = keys.get(row)
  if (key === undefined) {
    key = ++nextKey
    keys.set(row, key)
  }
  return key
}
