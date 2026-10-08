import QRCode from 'qrcode'

// Encode only the permanent Wave URL. No third-party image/QR endpoint is used.
export async function qrImages(url) {
  const options = { errorCorrectionLevel: 'M', margin: 4, width: 768, color: { dark: '#000000', light: '#ffffff' } }
  const [png, svg] = await Promise.all([
    QRCode.toDataURL(url, options),
    QRCode.toString(url, { ...options, type: 'svg' }),
  ])
  return { png, svg }
}
