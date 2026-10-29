// Admin → Slanje e-mailova: the default message (ask creators to put their Wave profile link in their bio).
// {{name}} is the recipient's name, {{profileUrl}} their public Wave profile (or account overview).
export const BROADCAST_VARIABLES = ['name', 'profileUrl']

const DEFAULTS = {
  bs: {
    subject: 'Stavite svoj Wave profil u bio i dobijte više upita',
    heading: 'Neka vas brendovi lakše pronađu',
    message: 'Pozdrav {{name}},\n\nhvala što ste dio Wavea! Imamo jednu kratku molbu koja vama donosi više upita, a nama pomaže da rastemo.\n\nStavite link na svoj Wave profil u bio na Instagramu i TikToku. Brendovi koji vas otkriju na društvenim mrežama tako na jednom mjestu vide vaše pakete, portfolio i recenzije, i mogu vam odmah poslati upit.\n\nKako dodati link:\n• Instagram: Uredi profil → Linkovi → Dodaj vanjski link\n• TikTok: Uredi profil → Web-stranica\n\nVaš link je u dugmetu ispod. Ako imate svoj sajt ili Linktree, dodajte ga i tamo.\n\nHvala vam!\nWave tim',
    buttonLabel: 'Otvorite svoj profil',
  },
  hr: {
    subject: 'Stavite svoj Wave profil u bio i dobijte više upita',
    heading: 'Neka vas brendovi lakše pronađu',
    message: 'Pozdrav {{name}},\n\nhvala što ste dio Wavea! Imamo jednu kratku molbu koja vama donosi više upita, a nama pomaže da rastemo.\n\nStavite poveznicu na svoj Wave profil u bio na Instagramu i TikToku. Brendovi koji vas otkriju na društvenim mrežama tako na jednom mjestu vide vaše pakete, portfolio i recenzije te vam mogu odmah poslati upit.\n\nKako dodati poveznicu:\n• Instagram: Uredi profil → Poveznice → Dodaj vanjsku poveznicu\n• TikTok: Uredi profil → Web-stranica\n\nVaša poveznica je u gumbu ispod. Ako imate svoju web-stranicu ili Linktree, dodajte je i tamo.\n\nHvala vam!\nWave tim',
    buttonLabel: 'Otvorite svoj profil',
  },
  sr: {
    subject: 'Stavite svoj Wave profil u bio i dobijte više upita',
    heading: 'Neka vas brendovi lakše pronađu',
    message: 'Pozdrav {{name}},\n\nhvala što ste deo Wavea! Imamo jednu kratku molbu koja vama donosi više upita, a nama pomaže da rastemo.\n\nStavite link na svoj Wave profil u bio na Instagramu i TikToku. Brendovi koji vas otkriju na društvenim mrežama tako na jednom mestu vide vaše pakete, portfolio i recenzije, i mogu odmah da vam pošalju upit.\n\nKako da dodate link:\n• Instagram: Uredi profil → Linkovi → Dodaj spoljni link\n• TikTok: Uredi profil → Veb-sajt\n\nVaš link je u dugmetu ispod. Ako imate svoj sajt ili Linktree, dodajte ga i tamo.\n\nHvala vam!\nWave tim',
    buttonLabel: 'Otvorite svoj profil',
  },
  cnr: {
    subject: 'Stavite svoj Wave profil u bio i dobijte više upita',
    heading: 'Neka vas brendovi lakše pronađu',
    message: 'Pozdrav {{name}},\n\nhvala što ste dio Wavea! Imamo jednu kratku molbu koja vama donosi više upita, a nama pomaže da rastemo.\n\nStavite link na svoj Wave profil u bio na Instagramu i TikToku. Brendovi koji vas otkriju na društvenim mrežama tako na jednom mjestu vide vaše pakete, portfolio i recenzije, i mogu odmah da vam pošalju upit.\n\nKako da dodate link:\n• Instagram: Uredi profil → Linkovi → Dodaj spoljni link\n• TikTok: Uredi profil → Veb-sajt\n\nVaš link je u dugmetu ispod. Ako imate svoj sajt ili Linktree, dodajte ga i tamo.\n\nHvala vam!\nWave tim',
    buttonLabel: 'Otvorite svoj profil',
  },
  sl: {
    subject: 'Dodajte svoj profil Wave v bio in prejmite več povpraševanj',
    heading: 'Naj vas znamke najdejo lažje',
    message: 'Pozdravljeni, {{name}},\n\nhvala, ker ste del Wavea! Imamo kratko prošnjo, ki vam prinese več povpraševanj, nam pa pomaga rasti.\n\nDodajte povezavo do svojega profila Wave v bio na Instagramu in TikToku. Znamke, ki vas odkrijejo na družbenih omrežjih, tako na enem mestu vidijo vaše pakete, portfelj in ocene ter vam lahko takoj pošljejo povpraševanje.\n\nKako dodati povezavo:\n• Instagram: Uredi profil → Povezave → Dodaj zunanjo povezavo\n• TikTok: Uredi profil → Spletna stran\n\nVaša povezava je v gumbu spodaj. Če imate svojo spletno stran ali Linktree, jo dodajte tudi tja.\n\nHvala!\nEkipa Wave',
    buttonLabel: 'Odprite svoj profil',
  },
  en: {
    subject: 'Add your Wave profile to your bio and get more inquiries',
    heading: 'Help brands find you more easily',
    message: 'Hello {{name}},\n\nthank you for being part of Wave! We have one quick request that brings you more inquiries and helps us grow.\n\nAdd the link to your Wave profile to your Instagram and TikTok bio. Brands that discover you on social media can then see your packages, portfolio and reviews in one place and send you an inquiry right away.\n\nHow to add the link:\n• Instagram: Edit profile → Links → Add external link\n• TikTok: Edit profile → Website\n\nYour link is in the button below. If you have your own website or Linktree, add it there too.\n\nThank you!\nThe Wave team',
    buttonLabel: 'Open your profile',
  },
  de: {
    subject: 'Ihr Wave-Profil in die Bio: mehr Anfragen von Marken',
    heading: 'Damit Marken Sie leichter finden',
    message: 'Hallo {{name}},\n\ndanke, dass Sie Teil von Wave sind! Wir haben eine kurze Bitte, die Ihnen mehr Anfragen bringt und uns beim Wachsen hilft.\n\nFügen Sie den Link zu Ihrem Wave-Profil in Ihre Instagram- und TikTok-Bio ein. Marken, die Sie in sozialen Medien entdecken, sehen so Ihre Pakete, Ihr Portfolio und Ihre Bewertungen an einem Ort und können Ihnen sofort eine Anfrage senden.\n\nSo fügen Sie den Link hinzu:\n• Instagram: Profil bearbeiten → Links → Externen Link hinzufügen\n• TikTok: Profil bearbeiten → Website\n\nIhren Link finden Sie im Button unten. Wenn Sie eine eigene Website oder Linktree haben, fügen Sie ihn auch dort hinzu.\n\nVielen Dank!\nIhr Wave-Team',
    buttonLabel: 'Ihr Profil öffnen',
  },
}

export function defaultBroadcast(locale) {
  return { ...(DEFAULTS[locale] || DEFAULTS.bs), buttonUrl: '{{profileUrl}}' }
}

/** Same rules as AdminBroadcast::personalise(): without a name, "Pozdrav {{name}}," becomes "Pozdrav,". */
export function personaliseBroadcast(text, { name = '', profileUrl = '' } = {}) {
  let result = String(text || '').replace(/\{\{\s*(name|profileUrl)\s*\}\}/g, (match, key) => (key === 'name' ? name : profileUrl))
  if (!name) result = result.replace(/[ \t]+([,!.?])/g, '$1')
  return result.trim()
}

export function unknownBroadcastVariables(...texts) {
  return [...new Set([...texts.join('\n').matchAll(/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/g)].map((match) => match[1]).filter((name) => !BROADCAST_VARIABLES.includes(name)))]
}

/** Typed addresses: trimmed, valid-looking and without duplicates. */
export function isEmailAddress(value) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(String(value || '').trim())
}
