const KEY = 'historic.session'

export function sessionToken(): string | null {
  return sessionStorage.getItem(KEY)
}

export function startDevSession(): void {
  sessionStorage.setItem(KEY, 'dev')
}

export function endSession(): void {
  if (!sessionStorage.getItem(KEY)) {
    window.dispatchEvent(new Event('historic-logout'))
    return
  }
  sessionStorage.removeItem(KEY)
  window.dispatchEvent(new Event('historic-logout'))
}
