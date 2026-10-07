// Startup only: route changes and content requests keep their own skeletons.
export function showStartupLoader({ ready, label, document = globalThis.document, timers = globalThis }) {
  const loader = document.getElementById('wave-startup')
  if (!loader) return
  const root = document.getElementById('app')
  const labelElement = document.getElementById('wave-startup-label')
  if (labelElement) labelElement.textContent = label
  loader.setAttribute('aria-label', label)
  const wasInert = root?.inert ?? false
  if (root) root.inert = true
  document.documentElement.classList.add('app-starting')
  let finished = false
  let timer
  const finish = () => {
    if (finished) return
    finished = true
    timers.clearTimeout(timer)
    loader.remove()
    if (root) root.inert = wasInert
    document.documentElement.classList.remove('app-starting')
  }
  // Release the UI even if a session or route request never settles. A late
  // session response still updates the account through the existing watcher.
  timer = timers.setTimeout(finish, 10_000)
  Promise.resolve(ready).then(finish, finish)
  return finish
}
