// Match directory page widths, gaps and the four-column desktop / two-column mobile grid.
export const DIRECTORY_IMAGE_SIZES = '(max-width: 420px) calc((100vw - 44px) / 2), (max-width: 600px) calc((100vw - 52px) / 2), (max-width: 760px) 274px, (max-width: 1224px) calc((100vw - 106px) / 4), 280px'

// Home cards scroll horizontally on phones and use four columns on desktop.
export const HOME_IMAGE_SIZES = '(max-width: 420px) calc((100vw - 32px) * .88), (max-width: 760px) min(calc((100vw - 40px) * .84), 471px), (max-width: 1464px) calc((100vw - 106px) / 4), 340px'

export function listingImage(image, src) {
  if (image?.src) return image
  if (!src) return null

  if (src === '/images/creator-placeholder.webp') {
    return {
      src: '/images/creator-placeholder-480.webp',
      srcset: [320, 480, 640].map((width) => `/images/creator-placeholder-${width}.webp ${width}w`).join(', ') + ', /images/creator-placeholder.webp 1373w',
      width: 1373,
      height: 1145,
    }
  }

  if (src === '/images/share.webp') {
    return {
      src: '/images/share-320.webp',
      srcset: '/images/share-320.webp 320w, /images/share-640.webp 640w, /images/share.webp 1731w',
      width: 1731,
      height: 909,
    }
  }

  // Seeded Unsplash photos already support image resizing. Keep arbitrary
  // external URLs untouched; never proxy or fetch them on the server.
  try {
    const url = new URL(src)
    if (url.origin === 'https://images.unsplash.com') {
      const variant = (width) => {
        const resized = new URL(url)
        resized.searchParams.set('w', String(width))
        return resized.href
      }
      return {
        src: variant(320),
        srcset: [96, 320, 480].map((width) => `${variant(width)} ${width}w`).join(', '),
      }
    }
  } catch {
    // Local URLs and providers without known resize support use their own file.
  }

  return { src }
}
