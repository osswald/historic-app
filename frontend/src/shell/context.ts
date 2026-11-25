import { createContext, useContext } from 'react'
import type { Member } from '../api/types'

export const MemberContext = createContext<Member | null>(null)
export const ToastContext = createContext<(message: string) => void>(() => {})

export function useMember(): Member | null {
  return useContext(MemberContext)
}

export function useToast(): (message: string) => void {
  return useContext(ToastContext)
}
