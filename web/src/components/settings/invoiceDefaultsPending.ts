// Zápis může doběhnout po odmountování záložky. Nový formulář stejné firmy
// musí počkat na potvrzení, než načte výchozí hodnoty.
const pending = new Map<number, Promise<void>>()
export function pendingInvoiceDefaultsSave(id: number): Promise<void> | undefined { return pending.get(id) }
export function trackInvoiceDefaultsSave(id: number, promise: Promise<void>): void {
  pending.set(id, promise)
  void promise.finally(() => { if (pending.get(id) === promise) pending.delete(id) })
}
