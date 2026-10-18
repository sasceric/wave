export function imageRecommendationKey(folder) {
  return {
    'creator-avatar': 'imageUpload.creator',
    'company-logo': 'imageUpload.logo',
    'company-cover': 'imageUpload.companyCover',
    'campaign-cover': 'imageUpload.campaignCover',
    'creator-portfolio': 'imageUpload.portfolio',
  }[folder] || ''
}

export function coverPreviewFrames(folder, companyType = 'brand') {
  if (folder === 'campaign-cover') {
    return [
      { key: 'desktopPage', ratio: '1066 / 394' },
      { key: 'mobilePage', ratio: '3 / 2' },
      { key: 'desktopCard', ratio: '268 / 178' },
      { key: 'mobileCard', ratio: '3 / 2' },
    ]
  }
  if (folder === 'company-cover') {
    return [
      { key: 'desktopPage', ratio: companyType === 'agency' ? '1376 / 340' : '1440 / 340' },
      { key: 'mobilePage', ratio: companyType === 'agency' ? '343 / 130' : '375 / 490' },
      { key: 'desktopCard', ratio: '268 / 100', position: 'center 42%' },
      { key: 'mobileCard', ratio: '343 / 90', position: 'center 42%' },
    ]
  }
  return []
}
