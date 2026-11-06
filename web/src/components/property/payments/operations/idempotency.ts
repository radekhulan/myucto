export function createRetryToken() {
  let last = ''
  let token = ''
  return {
    for(payload: unknown): string {
      const serialized = JSON.stringify(payload)
      if (serialized !== last) { last = serialized; token = crypto.randomUUID() }
      return token
    },
    reset() { last = ''; token = '' },
  }
}
