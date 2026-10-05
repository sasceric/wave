function parseDate(value) {
  if (value === null || value === undefined || value === '') return null
  const date = new Date(value)
  return Number.isFinite(date.getTime()) ? date : null
}

export function chatDayKey(value) {
  const date = parseDate(value)
  if (!date) return ''
  return [date.getFullYear(), date.getMonth() + 1, date.getDate()].join('-')
}

export function groupChatMessages(messages) {
  const days = []
  for (const message of messages) {
    const dateKey = chatDayKey(message.createdAt)
    let day = days.at(-1)
    if (!day || day.dateKey !== dateKey) {
      day = { key: `${dateKey || 'unknown'}-${message.id}`, dateKey, createdAt: message.createdAt, messages: [] }
      days.push(day)
    }
    day.messages.push(message)
  }
  return days
}

export function areChatMessagesGrouped(previous, message) {
  if (!previous || !message) return false
  const previousDate = parseDate(previous.createdAt)
  const date = parseDate(message.createdAt)
  if (!previousDate || !date || chatDayKey(previousDate) !== chatDayKey(date)) return false
  const sameSender = previous.senderId != null && message.senderId != null
    ? previous.senderId === message.senderId
    : Boolean(previous.senderRole) && previous.senderRole === message.senderRole
  const elapsed = date.getTime() - previousDate.getTime()
  return sameSender && elapsed >= 0 && elapsed <= 5 * 60_000
}

export function createChatTimeFormatter(locale, t) {
  // Intl does not support bs/cnr consistently; Croatian shares their relative-time forms.
  const intlLocale = { bs: 'hr', cnr: 'hr', sr: 'sr-Latn' }[locale] || locale
  const clock = new Intl.DateTimeFormat(intlLocale, { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
  const weekday = new Intl.DateTimeFormat(intlLocale, { weekday: 'long' })
  const relative = new Intl.RelativeTimeFormat(intlLocale, { numeric: 'always', style: 'short' })
  const formatClock = (value) => {
    const date = parseDate(value)
    return date ? clock.format(date) : ''
  }
  const formatDate = (value) => {
    const date = parseDate(value)
    return date ? `${String(date.getDate()).padStart(2, '0')}.${String(date.getMonth() + 1).padStart(2, '0')}.${date.getFullYear()}` : ''
  }
  return {
    clock: formatClock,
    full: (value) => [formatDate(value), formatClock(value)].filter(Boolean).join(' '),
    dayLabel(value, now) {
      const date = parseDate(value)
      if (!date) return ''
      const today = new Date(now)
      const yesterday = new Date(now)
      yesterday.setDate(yesterday.getDate() - 1)
      if (chatDayKey(date) === chatDayKey(today)) return t('campaignChat.today')
      if (chatDayKey(date) === chatDayKey(yesterday)) return t('campaignChat.yesterday')
      return `${weekday.format(date)}, ${formatDate(date)}`
    },
    ago(value, now) {
      const date = parseDate(value)
      if (!date) return ''
      const seconds = Math.max(0, (now - date.getTime()) / 1000)
      if (seconds < 60) return t('campaignChat.justNow')
      const units = [
        ['year', 365 * 86_400], ['month', 30 * 86_400], ['week', 7 * 86_400],
        ['day', 86_400], ['hour', 3600], ['minute', 60],
      ]
      const [unit, duration] = units.find(([, duration]) => seconds >= duration)
      return relative.format(-Math.floor(seconds / duration), unit)
    },
  }
}
