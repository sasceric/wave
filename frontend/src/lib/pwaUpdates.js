// Keep update discovery separate from activation: a resumed PWA may already be
// controlled by a newer worker while its document still runs the old JavaScript.
export function startPwaUpdates({
  registerSW,
  buildId,
  onAvailable,
  onUpdating,
  onError,
  onCheckError = (cause) => console.error('Unable to check for a Wave update.', cause),
  windowTarget = window,
  documentTarget = document,
  activationTimeout = 30000,
  probeTimeout = 1000,
}) {
  const serviceWorker = windowTarget.navigator.serviceWorker
  let registration = null
  let lastController = serviceWorker.controller
  let stopped = false
  let applying = false
  let reloaded = false
  let checking = null
  let moduleLoadFailed = false
  const pending = new Set()

  function available(reason = 'update') {
    if (!stopped) onAvailable(true, reason)
  }

  function moduleFailed() {
    // An open tab may request a lazy chunk removed by a deployment. Keep its
    // current form intact and offer an explicit refresh, including when worker
    // registration has not completed. Do not swallow the router's import error.
    moduleLoadFailed = true
    available('load-failed')
    void check()
  }

  function reload() {
    if (stopped || reloaded) return
    reloaded = true
    windowTarget.location.reload()
  }

  function workerBuild(worker) {
    if (!worker || !buildId || !windowTarget.MessageChannel) return Promise.resolve(null)
    return new Promise((resolve) => {
      const channel = new windowTarget.MessageChannel()
      const finish = (value = null) => {
        windowTarget.clearTimeout(timer)
        channel.port1.close()
        channel.port2.close()
        pending.delete(finish)
        resolve(value)
      }
      const timer = windowTarget.setTimeout(finish, probeTimeout)
      pending.add(finish)
      channel.port1.onmessage = ({ data }) => finish(data?.type === 'WAVE_BUILD_ID' ? data.buildId : null)
      try {
        worker.postMessage({ type: 'WAVE_GET_BUILD' }, [channel.port2])
      } catch {
        finish()
      }
    })
  }

  async function inspectController(changed = false) {
    const controller = serviceWorker.controller
    const controllerBuild = await workerBuild(controller)
    if (stopped || controller !== serviceWorker.controller) return
    if (controllerBuild ? controllerBuild !== buildId : changed) available()
  }

  function controllerChanged() {
    const hadController = Boolean(lastController)
    lastController = serviceWorker.controller
    void inspectController(hadController)
  }

  function check() {
    if (stopped || !registration || documentTarget.visibilityState !== 'visible'
      || windowTarget.navigator.onLine === false) return Promise.resolve()
    if (checking) return checking
    checking = (async () => {
      if (registration.waiting) available()
      await Promise.all([inspectController(), registration.update()])
      if (registration.waiting) available()
    })().catch((cause) => {
      if (!stopped) onCheckError(cause)
    }).finally(() => { checking = null })
    return checking
  }

  // Bound both installation and activation. A failed install must leave the
  // existing app usable, with a retry button rather than a permanent spinner.
  function waitForWorker(worker, activated = false, activate = null) {
    return new Promise((resolve, reject) => {
      let settled = false
      const finish = (cause) => {
        if (settled) return
        settled = true
        windowTarget.clearTimeout(timer)
        worker.removeEventListener('statechange', inspect)
        serviceWorker.removeEventListener('controllerchange', inspect)
        pending.delete(cancel)
        if (cause) reject(cause)
        else resolve()
      }
      const cancel = () => finish(new Error('Wave update stopped.'))
      const inspect = () => {
        if (worker.state === 'redundant') finish(new Error('Wave update installation failed.'))
        else if (activated ? serviceWorker.controller === worker : ['installed', 'activated'].includes(worker.state)) finish()
      }
      const timer = windowTarget.setTimeout(() => finish(new Error('Wave update activation timed out.')), activationTimeout)
      pending.add(cancel)
      worker.addEventListener('statechange', inspect)
      serviceWorker.addEventListener('controllerchange', inspect)
      inspect()
      if (!settled && activate) {
        try {
          activate()
        } catch (cause) {
          finish(cause)
        }
      }
    })
  }

  async function apply() {
    if (stopped || applying || reloaded) return
    if (!registration) {
      if (moduleLoadFailed) reload()
      return
    }
    applying = true
    onUpdating(true)
    try {
      // update() may start an installation; wait for it before choosing whether
      // to activate a waiting worker or just reload an already updated document.
      if (!registration.waiting && !registration.installing) {
        await new Promise((resolve, reject) => {
          const cancel = () => finish(new Error('Wave update stopped.'))
          const finish = (cause) => {
            windowTarget.clearTimeout(timer)
            pending.delete(cancel)
            if (cause) reject(cause)
            else resolve()
          }
          const timer = windowTarget.setTimeout(() => finish(new Error('Wave update check timed out.')), activationTimeout)
          pending.add(cancel)
          Promise.resolve().then(() => registration.update()).then(() => finish(), finish)
        })
      }
      if (stopped) return
      if (registration.installing) await waitForWorker(registration.installing)
      if (stopped) return
      const waiting = registration.waiting
      if (waiting) {
        await waitForWorker(waiting, true, () => waiting.postMessage({ type: 'SKIP_WAITING' }))
      }
      reload()
    } catch (cause) {
      if (!stopped) onError(cause)
    } finally {
      applying = false
      if (!stopped) onUpdating(false)
    }
  }

  serviceWorker.addEventListener('controllerchange', controllerChanged)
  windowTarget.addEventListener('vite:preloadError', moduleFailed)
  documentTarget.addEventListener('visibilitychange', check)
  for (const event of ['focus', 'pageshow', 'online']) windowTarget.addEventListener(event, check)
  const interval = windowTarget.setInterval(check, 30 * 60 * 1000)
  registerSW({
    // Let the page's fonts and hero image finish before precaching the app.
    immediate: false,
    onNeedRefresh: available,
    // Workbox can observe an update activated by another window. Only the
    // explicit Update action may reload this document and discard open drafts.
    onNeedReload: () => { void inspectController(true) },
    onRegisteredSW(_scriptUrl, registered) {
      if (stopped) return
      registration = registered ?? null
      void check()
    },
    onRegisterError: onCheckError,
  })

  return {
    apply,
    check,
    stop() {
      stopped = true
      serviceWorker.removeEventListener('controllerchange', controllerChanged)
      windowTarget.removeEventListener('vite:preloadError', moduleFailed)
      documentTarget.removeEventListener('visibilitychange', check)
      for (const event of ['focus', 'pageshow', 'online']) windowTarget.removeEventListener(event, check)
      windowTarget.clearInterval(interval)
      for (const cancel of [...pending]) cancel()
    },
  }
}
