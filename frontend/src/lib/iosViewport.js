export function configureIOSViewport(doc = document, device = navigator) {
  const ios = /iPad|iPhone|iPod/.test(device.userAgent)
    || (device.platform === 'MacIntel' && device.maxTouchPoints > 1)
  if (!ios) return

  const viewport = doc.querySelector('meta[name="viewport"]')
  if (!viewport) return

  // WebKit uses the author scale limit for input focus, while modern Safari
  // still allows manual pinch zoom. Keep this off Android and desktop browsers.
  const directives = viewport.content.split(',')
    .map(value => value.trim())
    .filter(value => value && !/^maximum-scale\s*=/i.test(value))
  viewport.content = [...directives, 'maximum-scale=1.0'].join(', ')
}
