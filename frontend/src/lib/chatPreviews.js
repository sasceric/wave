import { apiGet } from './api'

const requests = new Map()
const queue = []
let running = 0

function runNext() {
  if (running >= 2 || !queue.length) return
  const job = queue.shift()
  running += 1
  void apiGet(job.path).then(response => job.resolve(response.data), () => job.resolve(null)).finally(() => {
    running -= 1
    runNext()
  })
  runNext()
}

export function loadChatPreview(path, url) {
  const key = `${path} ${url}`
  if (!requests.has(key)) {
    requests.set(key, new Promise(resolve => { queue.push({ path, resolve }); runNext() }))
    if (requests.size > 100) requests.delete(requests.keys().next().value)
  }
  return requests.get(key)
}
