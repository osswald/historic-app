import { afterEach, describe, expect, it, vi } from 'vitest'
import { endSession, sessionToken, startDevSession } from './session'

afterEach(() => {
  sessionStorage.clear()
})

describe('session', () => {
  it('stores the dev token', () => {
    expect(sessionToken()).toBeNull()
    startDevSession()
    expect(sessionToken()).toBe('dev')
  })

  it('clears the token and announces logout', () => {
    startDevSession()
    const listener = vi.fn()
    window.addEventListener('historic-logout', listener)

    endSession()

    expect(sessionToken()).toBeNull()
    expect(listener).toHaveBeenCalledOnce()
    window.removeEventListener('historic-logout', listener)
  })
})
