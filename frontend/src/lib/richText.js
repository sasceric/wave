const allowedElements = new Set([
  'a',
  'b',
  'blockquote',
  'br',
  'div',
  'em',
  'h1',
  'h2',
  'h3',
  'i',
  'li',
  'ol',
  'p',
  'pre',
  's',
  'span',
  'strong',
  'sub',
  'sup',
  'u',
  'ul',
])

const removedElements = new Set([
  'iframe',
  'math',
  'object',
  'script',
  'style',
  'svg',
  'template',
])

const allowedClass = /^ql-(?:align-(?:center|right|justify)|direction-rtl|font-(?:serif|monospace)|indent-[1-8]|size-(?:small|large|huge)|syntax|ui)$/
const allowedColor = /^(?:#[\da-f]{3,8}|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}(?:\s*,\s*(?:0|1|0?\.\d+))?\s*\))$/i

function safeHref(value) {
  const href = value.trim()
  // eslint-disable-next-line no-control-regex -- Strip URL control characters before checking the scheme.
  const normalizedHref = href.replace(/[\u0000-\u0020\u007f]+/g, '')
  const scheme = normalizedHref.match(/^([a-z][a-z\d+.-]*):/i)?.[1]?.toLowerCase()

  if (!href || normalizedHref.startsWith('//') || (scheme && !['http', 'https', 'mailto'].includes(scheme))) {
    return null
  }

  return normalizedHref
}

function safeStyle(value) {
  return value.split(';')
    .map((declaration) => declaration.split(':', 2).map((part) => part.trim()))
    .filter(([property, color]) => (
      ['color', 'background-color'].includes(property?.toLowerCase())
      && allowedColor.test(color || '')
    ))
    .map(([property, color]) => `${property.toLowerCase()}: ${color}`)
    .join('; ')
}

function safeClasses(value) {
  return value.split(/\s+/).filter((className) => allowedClass.test(className)).join(' ')
}

function sanitizeNode(node) {
  if (node.nodeType === Node.TEXT_NODE) {
    return node.cloneNode()
  }

  if (node.nodeType !== Node.ELEMENT_NODE) {
    return null
  }

  const tagName = node.tagName.toLowerCase()
  if (removedElements.has(tagName)) {
    return null
  }

  const children = Array.from(node.childNodes, sanitizeNode).filter(Boolean)
  if (!allowedElements.has(tagName)) {
    const fragment = document.createDocumentFragment()
    children.forEach((child) => fragment.append(child))
    return fragment
  }

  const element = document.createElement(tagName)
  Array.from(node.attributes).forEach(({ name, value }) => {
    if (tagName === 'a' && name === 'href') {
      const href = safeHref(value)
      if (href) {
        element.setAttribute('href', href)
      }
    } else if (name === 'class') {
      const classNames = safeClasses(value)
      if (classNames) {
        element.setAttribute('class', classNames)
      }
    } else if (tagName === 'li' && name === 'data-list' && ['bullet', 'ordered'].includes(value)) {
      element.setAttribute('data-list', value)
    } else if (tagName === 'span' && name === 'style') {
      const style = safeStyle(value)
      if (style) {
        element.setAttribute('style', style)
      }
    }
  })
  children.forEach((child) => element.append(child))

  return element
}

export function sanitizeRichText(value) {
  const template = document.createElement('template')
  template.innerHTML = String(value || '')
  const container = document.createElement('div')

  Array.from(template.content.childNodes, sanitizeNode)
    .filter(Boolean)
    .forEach((child) => container.append(child))

  return container.innerHTML
}
