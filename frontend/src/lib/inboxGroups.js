import { chatDayKey } from './chatTime.js'

export function groupInboxThreads(threads, now = Date.now()) {
  const today = new Date(now)
  const yesterday = new Date(now)
  yesterday.setDate(yesterday.getDate() - 1)
  const weekStart = new Date(now)
  weekStart.setHours(0, 0, 0, 0)
  weekStart.setDate(weekStart.getDate() - (weekStart.getDay() + 6) % 7)
  const groups = new Map()
  for (const thread of threads) {
    const date = new Date(thread.lastMessageAt)
    const day = chatDayKey(thread.lastMessageAt)
    const key = day && day === chatDayKey(today) ? 'today'
      : day && day === chatDayKey(yesterday) ? 'yesterday'
        : Number.isFinite(date.getTime()) && date >= weekStart && date <= today ? 'thisWeek' : 'earlier'
    if (!groups.has(key)) groups.set(key, { key, threads: [] })
    groups.get(key).threads.push(thread)
  }
  return [...groups.values()]
}
