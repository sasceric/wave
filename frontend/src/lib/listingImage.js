// Match directory page widths, gaps and the four-column desktop / two-column mobile grid.
export const DIRECTORY_IMAGE_SIZES = '(max-width: 420px) calc((100vw - 44px) / 2), (max-width: 600px) calc((100vw - 52px) / 2), (max-width: 760px) 274px, (max-width: 1224px) calc((100vw - 106px) / 4), 280px'

export function listingImage(image, src) {
  if (image?.src) return image
  if (!src) return null

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
