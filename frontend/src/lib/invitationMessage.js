// Admin bulk invitations: the variables the server fills per creator, and a default text per language.
export const INVITATION_VARIABLES = ['name', 'companyName']

const profiles = (label) => `${label}
   • Instagram: @wave.ba.app
   • TikTok: @waveapp5
   • Facebook: @app.wave.ba`

const messages = {
  bs: `Pozdrav {{name}},

hvala ti što si dio Wave zajednice!

Wave gradimo kako bismo kreatorima i brendovima olakšali da se pronađu i dogovore saradnju. Najbrže rastemo kada nas predstave upravo kreatori poput tebe, zato te pozivamo u kampanju „Predstavi Wave – rastimo zajedno”.

Šta je potrebno:
1. Objavi post, reel, story ili video na Instagramu, Facebooku ili TikToku i predstavi Wave na svoj način.
2. ${profiles('U objavi označi naš profil:')}
3. Prihvati ovaj poziv i pošalji nam link na objavu ovdje u porukama.

Svaka tvoja objava pomaže da više brendova otkrije Wave i pronađe kreatore poput tebe, a to znači i više prilika za saradnju za tebe.

Ako imaš pitanje ili ideju, samo nam odgovori na ovu poruku.

S poštovanjem,
{{companyName}}`,
  hr: `Pozdrav {{name}},

hvala ti što si dio Wave zajednice!

Wave gradimo kako bismo kreatorima i brendovima olakšali da se pronađu i dogovore suradnju. Najbrže rastemo kada nas predstave upravo kreatori poput tebe, zato te pozivamo u kampanju „Predstavi Wave – rastimo zajedno”.

Što je potrebno:
1. Objavi post, reel, story ili video na Instagramu, Facebooku ili TikToku i predstavi Wave na svoj način.
2. ${profiles('U objavi označi naš profil:')}
3. Prihvati ovaj poziv i pošalji nam poveznicu na objavu ovdje u porukama.

Svaka tvoja objava pomaže da više brendova otkrije Wave i pronađe kreatore poput tebe, a to znači i više prilika za suradnju za tebe.

Ako imaš pitanje ili ideju, samo nam odgovori na ovu poruku.

S poštovanjem,
{{companyName}}`,
  sr: `Pozdrav {{name}},

hvala ti što si deo Wave zajednice!

Wave gradimo kako bismo kreatorima i brendovima olakšali da se pronađu i dogovore saradnju. Najbrže rastemo kada nas predstave upravo kreatori poput tebe, zato te pozivamo u kampanju „Predstavi Wave – rastimo zajedno”.

Šta je potrebno:
1. Objavi post, reel, story ili video na Instagramu, Facebooku ili TikToku i predstavi Wave na svoj način.
2. ${profiles('U objavi označi naš profil:')}
3. Prihvati ovaj poziv i pošalji nam link do objave ovde u porukama.

Svaka tvoja objava pomaže da više brendova otkrije Wave i pronađe kreatore poput tebe, a to znači i više prilika za saradnju za tebe.

Ako imaš pitanje ili ideju, samo nam odgovori na ovu poruku.

S poštovanjem,
{{companyName}}`,
  cnr: `Pozdrav {{name}},

hvala ti što si dio Wave zajednice!

Wave gradimo kako bismo kreatorima i brendovima olakšali da se pronađu i dogovore saradnju. Najbrže rastemo kada nas predstave upravo kreatori poput tebe, zato te pozivamo u kampanju „Predstavi Wave – rastimo zajedno”.

Šta je potrebno:
1. Objavi post, reel, story ili video na Instagramu, Facebooku ili TikToku i predstavi Wave na svoj način.
2. ${profiles('U objavi označi naš profil:')}
3. Prihvati ovaj poziv i pošalji nam link do objave ovdje u porukama.

Svaka tvoja objava pomaže da više brendova otkrije Wave i pronađe kreatore poput tebe, a to znači i više prilika za saradnju za tebe.

Ako imaš pitanje ili ideju, samo nam odgovori na ovu poruku.

S poštovanjem,
{{companyName}}`,
  sl: `Pozdravljeni {{name}},

hvala, ker si del skupnosti Wave!

Wave gradimo zato, da ustvarjalcem in blagovnim znamkam olajšamo iskanje in dogovarjanje sodelovanj. Najhitreje rastemo, ko nas predstavijo prav ustvarjalci, kot si ti, zato te vabimo v kampanjo »Predstavi Wave – rastimo skupaj«.

Kaj je treba narediti:
1. Objavi objavo, reel, zgodbo ali video na Instagramu, Facebooku ali TikToku in predstavi Wave na svoj način.
2. ${profiles('V objavi označi naš profil:')}
3. Sprejmi to povabilo in nam v sporočilih pošlji povezavo do objave.

Vsaka tvoja objava pomaga, da več blagovnih znamk odkrije Wave in najde ustvarjalce, kot si ti – to pomeni več priložnosti za sodelovanje zate.

Če imaš vprašanje ali idejo, preprosto odgovori na to sporočilo.

Lep pozdrav,
{{companyName}}`,
  en: `Hi {{name}},

thank you for being part of the Wave community!

We're building Wave to make it easier for creators and brands to find each other and agree on collaborations. We grow fastest when creators like you introduce us, so we'd love you to join our campaign “Introduce Wave – let's grow together”.

What to do:
1. Publish a post, reel, story or video on Instagram, Facebook or TikTok and introduce Wave in your own way.
2. ${profiles('Tag our profile in your post:')}
3. Accept this invitation and send us the link to your post here in messages.

Every post helps more brands discover Wave and find creators like you, which means more collaboration opportunities for you too.

If you have a question or an idea, just reply to this message.

Kind regards,
{{companyName}}`,
  de: `Hallo {{name}},

danke, dass du Teil der Wave-Community bist!

Wir bauen Wave, damit Creator und Marken einander leichter finden und Kooperationen vereinbaren können. Am schnellsten wachsen wir, wenn Creator wie du uns vorstellen – deshalb laden wir dich zu unserer Kampagne „Stell Wave vor – gemeinsam wachsen“ ein.

So geht's:
1. Veröffentliche einen Post, ein Reel, eine Story oder ein Video auf Instagram, Facebook oder TikTok und stell Wave auf deine Art vor.
2. ${profiles('Markiere unser Profil in deinem Beitrag:')}
3. Nimm diese Einladung an und schick uns hier in den Nachrichten den Link zu deinem Beitrag.

Jeder Beitrag hilft, dass mehr Marken Wave entdecken und Creator wie dich finden – und das bedeutet auch mehr Kooperationsmöglichkeiten für dich.

Wenn du eine Frage oder Idee hast, antworte einfach auf diese Nachricht.

Viele Grüße,
{{companyName}}`,
}

export function defaultInvitationMessage(locale) {
  return messages[locale] ?? messages.bs
}

/** Same replacement as the server: {{name}} and {{companyName}}, spaces inside the braces allowed. */
export function personaliseInvitation(message, values) {
  return String(message).replace(/\{\{\s*(name|companyName)\s*\}\}/g, (match, key) => values[key] ?? match).trim()
}
