<?php

namespace App\Localization;

final class DemoTranslations
{
    private const CREATORS = [
        'maya-chen' => [
            'bs' => ['category' => 'Putovanja', 'bio' => 'Tražim mirna mjesta i sporije načine da ih doživimo — od jutarnjih šetnji uz obalu do malih smještaja i ruta koje ostavljaju prostor za lutanje. Moji vodiči spajaju praktične savjete s toplim pričama i fotografijama, kako bi putovanje djelovalo dostižno, a ne nedostižno.', 'tagline' => 'Mala mjesta, sporije priče.', 'tags' => ['Sporija putovanja', 'Priroda', 'Fotografija']],
            'hr' => ['category' => 'Putovanja', 'bio' => 'Tražim mirna mjesta i sporije načine da ih doživimo — od jutarnjih šetnji uz obalu do malih smještaja i ruta koje ostavljaju prostor za istraživanje. Moji vodiči spajaju praktične savjete s toplim pričama i fotografijama kako bi putovanje djelovalo dostižno.', 'tagline' => 'Mala mjesta, sporije priče.', 'tags' => ['Sporija putovanja', 'Priroda', 'Fotografija']],
            'sr' => ['category' => 'Putovanja', 'bio' => 'Tražim mirna mesta i sporije načine da ih doživimo — od jutarnjih šetnji uz obalu do malih smeštaja i ruta koje ostavljaju prostor za istraživanje. Moji vodiči spajaju praktične savete s toplim pričama i fotografijama, kako bi putovanje delovalo dostižno.', 'tagline' => 'Mala mesta, sporije priče.', 'tags' => ['Sporija putovanja', 'Priroda', 'Fotografija']],
            'sl' => ['category' => 'Potovanja', 'bio' => 'Iščem mirne kraje in počasnejše načine, kako jih doživeti — od jutranjih sprehodov ob obali do majhnih nastanitev in poti, ki puščajo prostor za raziskovanje. Moji vodniki združujejo praktične nasvete s toplimi zgodbami in fotografijami, da so potovanja dosegljiva.', 'tagline' => 'Majhni kraji, počasnejše zgodbe.', 'tags' => ['Počasna potovanja', 'Narava', 'Fotografija']],
            'en' => ['category' => 'Travel', 'bio' => 'I look for quiet places and unhurried ways to experience them—from coastal mornings and thoughtful stays to routes that leave room to wander. My guides pair practical travel notes with warm, story-led photography, so a trip feels possible rather than out of reach.', 'tagline' => 'Small places, slower stories.', 'tags' => ['Slow travel', 'Outdoor', 'Photography']],
        ],
        'jordan-rivera' => [
            'bs' => ['category' => 'Hrana', 'bio' => 'Kuham za stvarni život: sezonske namirnice, jednostavni recepti i trpeza koja okuplja ljude. Dijelim korake koji zaista funkcionišu, male savjete za domaćinstvo i ideje zbog kojih obična večera postaje dobar povod da usporimo i budemo zajedno.', 'tagline' => 'Dobra hrana stvorena za dijeljenje.', 'tags' => ['Recepti', 'Druženja', 'Kuhanje kod kuće']],
            'hr' => ['category' => 'Hrana', 'bio' => 'Kuham za stvarni život: sezonske namirnice, jednostavni recepti i stol koji okuplja ljude. Dijelim korake koji doista funkcioniraju, male savjete za domaćinstvo i ideje zbog kojih obična večera postaje dobar razlog da usporimo i budemo zajedno.', 'tagline' => 'Dobra hrana stvorena za dijeljenje.', 'tags' => ['Recepti', 'Druženja', 'Kuhanje kod kuće']],
            'sr' => ['category' => 'Hrana', 'bio' => 'Kuvam za stvarni život: sezonske namirnice, jednostavni recepti i trpeza koja okuplja ljude. Delim korake koji zaista funkcionišu, male savete za domaćinstvo i ideje zbog kojih obična večera postaje dobar povod da usporimo i budemo zajedno.', 'tagline' => 'Dobra hrana stvorena za deljenje.', 'tags' => ['Recepti', 'Druženja', 'Kućno kuvanje']],
            'sl' => ['category' => 'Hrana', 'bio' => 'Kuham za vsakdanje življenje: sezonske sestavine, preprosti recepti in miza, ki združuje ljudi. Delim zanesljive korake, drobne nasvete za gostitelje in ideje, zaradi katerih je navadna večerja dober razlog, da se ustavimo in družimo.', 'tagline' => 'Dobra hrana, ustvarjena za deljenje.', 'tags' => ['Recepti', 'Druženje', 'Domača kuhinja']],
            'en' => ['category' => 'Food', 'bio' => 'I cook for real life: seasonal ingredients, simple recipes, and a table that brings people together. I share dependable steps, small hosting tips, and ideas that turn an ordinary weeknight dinner into a good reason to slow down and spend time together.', 'tagline' => 'Good food, made for sharing.', 'tags' => ['Recipes', 'Hosting', 'Home cooking']],
        ],
        'amara-okafor' => [
            'bs' => ['category' => 'Dobrobit', 'bio' => 'Vjerujem u pristupačan pokret i kratke trenutke svjesnosti koji podržavaju stvarni život, a ne savršenu sliku. Dijelim nježne treninge, ideje za odmor i proizvode koje mogu prirodno uklopiti u svoj dan. Cilj mi je da se svako osjeća dobrodošlo, bez obzira na iskustvo.', 'tagline' => 'Pokret u svakodnevici, s radošću.', 'tags' => ['Pokret', 'Svjesnost', 'Dobrobit']],
            'hr' => ['category' => 'Dobrobit', 'bio' => 'Vjerujem u pristupačno kretanje i kratke trenutke svjesnosti koji podržavaju stvarni život, a ne savršenu sliku. Dijelim nježne treninge, ideje za odmor i proizvode koje mogu prirodno uklopiti u dan. Želim da se svatko osjeća dobrodošlo, bez obzira na iskustvo.', 'tagline' => 'Svakodnevno kretanje, s radošću.', 'tags' => ['Pokret', 'Svjesnost', 'Dobrobit']],
            'sr' => ['category' => 'Dobrobit', 'bio' => 'Verujem u pristupačan pokret i kratke trenutke svesnosti koji podržavaju stvarni život, a ne savršenu sliku. Delim nežne treninge, ideje za odmor i proizvode koje mogu prirodno da uklopim u svoj dan. Želim da se svako oseća dobrodošlo, bez obzira na iskustvo.', 'tagline' => 'Pokret u svakodnevici, s radošću.', 'tags' => ['Pokret', 'Svesnost', 'Dobrobit']],
            'sl' => ['category' => 'Dobro počutje', 'bio' => 'Verjamem v dostopno gibanje in kratke trenutke čuječnosti, ki podpirajo resnično življenje, ne popolne podobe. Delim nežne vadbe, ideje za počitek in izdelke, ki jih lahko naravno vključim v svoj dan. Želim, da se vsak počuti dobrodošlo, ne glede na izkušnje.', 'tagline' => 'Vsakdanje gibanje z veseljem.', 'tags' => ['Gibanje', 'Čuječnost', 'Dobro počutje']],
            'en' => ['category' => 'Wellness', 'bio' => 'I believe in approachable movement and small moments of mindfulness that support real life, not a perfect picture. I share gentle workouts, ideas for rest, and products that fit naturally into an everyday routine. My goal is to make wellness feel welcoming, whatever your experience level.', 'tagline' => 'Everyday movement, with joy.', 'tags' => ['Movement', 'Mindfulness', 'Wellness']],
        ],
        'leo-martin' => [
            'bs' => ['category' => 'Životni stil', 'bio' => 'Stvaram dom koji je promišljen, udoban i pun stvari s pričom. Dijelim kako preuređujem polovne nalaze, popravljam ono što već imam i unosim male promjene bez velikog budžeta. Vjerujem da dom treba da raste s nama, a ne da prati prolazne trendove.', 'tagline' => 'Promišljeniji dom, korak po korak.', 'tags' => ['Enterijer', 'Uradi sam', 'Održivost']],
            'hr' => ['category' => 'Životni stil', 'bio' => 'Stvaram dom koji je promišljen, udoban i pun predmeta s pričom. Dijelim kako uređujem rabljene pronalaske, popravljam ono što već imam i unosim male promjene bez velikog budžeta. Vjerujem da dom treba rasti s nama, a ne pratiti prolazne trendove.', 'tagline' => 'Promišljeniji dom, korak po korak.', 'tags' => ['Interijeri', 'Uradi sam', 'Održivost']],
            'sr' => ['category' => 'Životni stil', 'bio' => 'Stvaram dom koji je promišljen, udoban i pun stvari s pričom. Delim kako preuređujem polovne komade, popravljam ono što već imam i unosim male promene bez velikog budžeta. Verujem da dom treba da raste s nama, a ne da prati prolazne trendove.', 'tagline' => 'Promišljeniji dom, korak po korak.', 'tags' => ['Enterijer', 'Uradi sam', 'Održivost']],
            'sl' => ['category' => 'Življenjski slog', 'bio' => 'Ustvarjam premišljen in udoben dom, poln predmetov z zgodbo. Pokažem, kako prenovim rabljene najdbe, popravim stvari, ki jih že imam, in uvedem majhne spremembe brez velikega proračuna. Verjamem, da mora dom rasti z nami, ne slediti minljivim trendom.', 'tagline' => 'Bolj premišljen dom, korak za korakom.', 'tags' => ['Notranja oprema', 'Naredi sam', 'Trajnost']],
            'en' => ['category' => 'Lifestyle', 'bio' => 'I make home feel thoughtful, comfortable, and full of things with a story. I share how I refresh second-hand finds, repair what I already own, and make small changes without a big budget. I believe a home should grow with us instead of chasing every passing trend.', 'tagline' => 'A more considered kind of home.', 'tags' => ['Interiors', 'DIY', 'Sustainability']],
        ],
        'sana-kim' => [
            'bs' => ['category' => 'Ljepota', 'bio' => 'Njegu kože pristupam bez pritiska da rutina mora biti savršena ili duga. Dijelim iskrene utiske, jednostavne korake i proizvode koje sam zaista isprobala, uz poseban fokus na osjetljivu kožu. Svaka preporuka treba da bude korisna, jasna i realna za svakodnevni život.', 'tagline' => 'Njega kože koja ostavlja mjesta za život.', 'tags' => ['Njega kože', 'Ljepota', 'Osjetljiva koža']],
            'hr' => ['category' => 'Ljepota', 'bio' => 'Njegu kože ne doživljavam kao pritisak da rutina mora biti savršena ili duga. Dijelim iskrene dojmove, jednostavne korake i proizvode koje sam zaista isprobala, uz poseban fokus na osjetljivu kožu. Svaka preporuka treba biti korisna, jasna i realna za svakodnevni život.', 'tagline' => 'Njega kože koja ostavlja mjesta za život.', 'tags' => ['Njega kože', 'Ljepota', 'Osjetljiva koža']],
            'sr' => ['category' => 'Lepota', 'bio' => 'Nega kože ne treba da stvara pritisak da rutina mora biti savršena ili duga. Delim iskrene utiske, jednostavne korake i proizvode koje sam zaista isprobala, uz poseban fokus na osetljivu kožu. Svaka preporuka treba da bude korisna, jasna i realna za svakodnevni život.', 'tagline' => 'Nega kože koja ostavlja prostor za život.', 'tags' => ['Nega kože', 'Lepota', 'Osetljiva koža']],
            'sl' => ['category' => 'Lepota', 'bio' => 'Nega kože ne sme ustvarjati pritiska, da mora biti rutina popolna ali dolga. Delim iskrene vtise, preproste korake in izdelke, ki sem jih res preizkusila, s posebnim poudarkom na občutljivi koži. Vsako priporočilo naj bo uporabno, jasno in primerno za vsakdan.', 'tagline' => 'Nega kože, ki pušča prostor za življenje.', 'tags' => ['Nega kože', 'Lepota', 'Občutljiva koža']],
            'en' => ['category' => 'Beauty', 'bio' => 'Skincare should not feel like pressure to build a perfect or complicated routine. I share honest notes, simple steps, and products I have actually tried, with a special focus on sensitive skin. Every recommendation should be useful, clear, and realistic enough for everyday life.', 'tagline' => 'Skincare that leaves room for life.', 'tags' => ['Skincare', 'Beauty', 'Sensitive skin']],
        ],
        'elena-garcia' => [
            'bs' => ['category' => 'Moda', 'bio' => 'Lični stil gradim oko komada koje već volim, ponovnog nošenja i vintage pronalazaka. Dijelim kombinacije za stvarne dane, savjete za pažljiviju kupovinu i načine da odjeća koju imamo ponovo djeluje svježe. Stil treba da bude ličan, udoban i održiv.', 'tagline' => 'Nosi ono što voliš, češće.', 'tags' => ['Lični stil', 'Vintage', 'Spora moda']],
            'hr' => ['category' => 'Moda', 'bio' => 'Osobni stil gradim oko komada koje već volim, ponovnog nošenja i vintage pronalazaka. Dijelim kombinacije za stvarne dane, savjete za pažljiviju kupnju i načine da odjeća koju imamo ponovno izgleda svježe. Stil treba biti osoban, udoban i održiv.', 'tagline' => 'Nosi ono što voliš, češće.', 'tags' => ['Osobni stil', 'Vintage', 'Spora moda']],
            'sr' => ['category' => 'Moda', 'bio' => 'Lični stil gradim oko komada koje već volim, ponovnog nošenja i vintage pronalazaka. Delim kombinacije za stvarne dane, savete za pažljiviju kupovinu i načine da odeća koju imamo ponovo izgleda sveže. Stil treba da bude ličan, udoban i održiv.', 'tagline' => 'Nosi ono što voliš, češće.', 'tags' => ['Lični stil', 'Vintage', 'Spora moda']],
            'sl' => ['category' => 'Moda', 'bio' => 'Osebni slog gradim s kosi, ki jih že imam rada, pogostim nošenjem in vintage najdbami. Delim kombinacije za vsakdan, nasvete za premišljene nakupe in načine, kako lahko oblačila znova delujejo sveže. Slog naj bo oseben, udoben in trajnosten.', 'tagline' => 'Pogosteje nosi, kar imaš rad/a.', 'tags' => ['Osebni slog', 'Vintage', 'Počasna moda']],
            'en' => ['category' => 'Fashion', 'bio' => 'I build personal style around pieces I already love, repeat wear, and vintage finds. I share outfits for real days, thoughtful shopping tips, and ways to make the clothes we own feel fresh again. Style should be personal, comfortable, and kind to the planet.', 'tagline' => 'Wear what you love, more often.', 'tags' => ['Personal style', 'Vintage', 'Slow fashion']],
        ],
    ];

    private const CREATOR_PACKAGE_COPY = [
        'instagram-story' => [
            'bs' => ['title' => 'Instagram story paket', 'description' => 'Tri pažljivo osmišljena story kadra predstavljaju proizvod u prirodnom trenutku, uz oznaku brenda i kratku poruku koja se uklapa u moj sadržaj.'],
            'hr' => ['title' => 'Instagram story paket', 'description' => 'Tri pažljivo osmišljena story kadra predstavljaju proizvod u prirodnom trenutku, uz oznaku brenda i kratku poruku koja se uklapa u moj sadržaj.'],
            'sr' => ['title' => 'Instagram story paket', 'description' => 'Tri pažljivo osmišljena story kadra predstavljaju proizvod u prirodnom trenutku, uz oznaku brenda i kratku poruku koja se uklapa u moj sadržaj.'],
            'sl' => ['title' => 'Paket Instagram story objav', 'description' => 'Trije premišljeno zasnovani story kadri predstavijo izdelek v naravnem trenutku, z oznako znamke in kratkim sporočilom, ki se ujema z mojo vsebino.'],
            'en' => ['title' => 'Instagram story set', 'description' => 'Three thoughtful story frames introduce the product in a natural moment, with a brand tag and a short message that fits my usual content.'],
        ],
        'instagram-reel' => [
            'bs' => ['title' => 'Instagram Reel', 'description' => 'Originalni kratki video povezuje proizvod s malom pričom iz svakodnevice, uz jasan kreativni fokus i oznaku brenda.'],
            'hr' => ['title' => 'Instagram Reel', 'description' => 'Izvorni kratki video povezuje proizvod s malom pričom iz svakodnevice, uz jasan kreativni fokus i oznaku brenda.'],
            'sr' => ['title' => 'Instagram Reel', 'description' => 'Originalni kratki video povezuje proizvod s malom pričom iz svakodnevice, uz jasan kreativni fokus i oznaku brenda.'],
            'sl' => ['title' => 'Instagram Reel', 'description' => 'Izviren kratek video poveže izdelek z vsakdanjo zgodbo, z jasnim ustvarjalnim poudarkom in oznako znamke.'],
            'en' => ['title' => 'Instagram Reel', 'description' => 'An original short video connects the product to a small everyday story, with a clear creative focus and brand tag.'],
        ],
        'instagram-carousel' => [
            'bs' => ['title' => 'Instagram foto-karusel', 'description' => 'Karusel originalnih fotografija i kratkih natpisa vodi publiku kroz proizvod, detalje i moj lični utisak.'],
            'hr' => ['title' => 'Instagram foto-karusel', 'description' => 'Karusel izvornih fotografija i kratkih opisa vodi publiku kroz proizvod, detalje i moj osobni dojam.'],
            'sr' => ['title' => 'Instagram foto-karusel', 'description' => 'Karusel originalnih fotografija i kratkih opisa vodi publiku kroz proizvod, detalje i moj lični utisak.'],
            'sl' => ['title' => 'Instagram foto vrtiljak', 'description' => 'Vrtiljak izvirnih fotografij in kratkih opisov občinstvu predstavi izdelek, podrobnosti in moj osebni vtis.'],
            'en' => ['title' => 'Instagram photo carousel', 'description' => 'A carousel of original photos and short captions walks my audience through the product, its details, and my personal take.'],
        ],
        'tiktok-video' => [
            'bs' => ['title' => 'TikTok kratki video', 'description' => 'Dinamičan video počinje prepoznatljivim kadrom i povezuje proizvod s pričom koja prirodno pripada mom sadržaju.'],
            'hr' => ['title' => 'TikTok kratki video', 'description' => 'Dinamičan video počinje prepoznatljivim kadrom i povezuje proizvod s pričom koja prirodno pripada mom sadržaju.'],
            'sr' => ['title' => 'TikTok kratki video', 'description' => 'Dinamičan video počinje prepoznatljivim kadrom i povezuje proizvod s pričom koja prirodno pripada mom sadržaju.'],
            'sl' => ['title' => 'TikTok kratki video', 'description' => 'Dinamičen video se začne z zanimivim kadrom in izdelek poveže z zgodbo, ki se naravno ujema z mojo vsebino.'],
            'en' => ['title' => 'TikTok short video', 'description' => 'A lively video opens with a clear hook and connects the product to a story that feels natural to my content.'],
        ],
        'youtube-video' => [
            'bs' => ['title' => 'YouTube video integracija', 'description' => 'Integracija u YouTube videu uključuje jasnu prezentaciju proizvoda, moje iskreno iskustvo i linkove u opisu videa.'],
            'hr' => ['title' => 'YouTube video integracija', 'description' => 'Integracija u YouTube videu uključuje jasno predstavljanje proizvoda, moje iskreno iskustvo i poveznice u opisu videa.'],
            'sr' => ['title' => 'YouTube video integracija', 'description' => 'Integracija u YouTube videu uključuje jasno predstavljanje proizvoda, moje iskreno iskustvo i linkove u opisu videa.'],
            'sl' => ['title' => 'YouTube video integracija', 'description' => 'Vključitev v YouTube video zajema jasno predstavitev izdelka, mojo iskreno izkušnjo in povezave v opisu videa.'],
            'en' => ['title' => 'YouTube video feature', 'description' => 'A YouTube integration includes a clear product introduction, my honest experience, and links in the video description.'],
        ],
    ];

    private const COMPANIES = [
        'good-earth' => ['bs' => 'Hrana i piće', 'hr' => 'Hrana i piće', 'sr' => 'Hrana i piće', 'sl' => 'Hrana in pijača', 'en' => 'Food & drink'],
        'field-notes' => ['bs' => 'Putovanja i priroda', 'hr' => 'Putovanja i priroda', 'sr' => 'Putovanja i priroda', 'sl' => 'Potovanja in narava', 'en' => 'Travel & outdoors'],
        'soft-form' => ['bs' => 'Dobrobit', 'hr' => 'Dobrobit', 'sr' => 'Dobrobit', 'sl' => 'Dobro počutje', 'en' => 'Wellness'],
    ];

    private const CAMPAIGNS = [
        'the-sunday-table' => [
            'bs' => ['title' => 'Nedjeljna trpeza', 'summary' => 'Pomozi nam da svakodnevni obrok pretvorimo u mali povod za slavlje.', 'description' => 'Tražimo kreatore koji vole dobru trpezu i jednostavne sezonske recepte. Podijeli svoj nedjeljni recept s jednom od naših osnovnih namirnica. Želimo prirodno svjetlo, iskren razgovor i jelo koje bi tvoja publika zaista pripremila.', 'category' => 'Hrana', 'deliverables' => ['1 kratki video', '3 kadra za story'], 'location' => 'Sjedinjene Američke Države'],
            'hr' => ['title' => 'Nedjeljni stol', 'summary' => 'Pomozi nam da svakodnevni obrok postane mala posebna prigoda.', 'description' => 'Tražimo kreatore koji vole dobar stol i jednostavne sezonske recepte. Podijeli svoj izvorni nedjeljni recept s jednom od naših osnovnih namirnica. Želimo prirodno svjetlo, iskren razgovor i jelo koje bi tvoja publika zaista pripremila.', 'category' => 'Hrana', 'deliverables' => ['1 kratki video', '3 story kadra'], 'location' => 'Sjedinjene Američke Države'],
            'sr' => ['title' => 'Nedeljna trpeza', 'summary' => 'Pomozi nam da običan obrok pretvorimo u mali povod za slavlje.', 'description' => 'Tražimo kreatore koji vole dobru trpezu i jednostavne sezonske recepte. Podeli svoj nedeljni recept uz jednu od naših osnovnih namirnica. Želimo prirodno svetlo, iskren razgovor i jelo koje bi tvoja publika zaista pripremila.', 'category' => 'Hrana', 'deliverables' => ['1 kratak video', '3 story kadra'], 'location' => 'Sjedinjene Američke Države'],
            'sl' => ['title' => 'Nedeljska miza', 'summary' => 'Pomagaj nam vsakdanji obrok spremeniti v majhen praznični trenutek.', 'description' => 'Iščemo ustvarjalce, ki imajo radi lepo pogrnjeno mizo in preproste sezonske recepte. Predstavi izviren nedeljski recept z eno od naših osnovnih sestavin. Želimo naravno svetlobo, iskren pogovor in jed, ki bi jo tvoje občinstvo zares pripravilo.', 'category' => 'Hrana', 'deliverables' => ['1 kratek video', '3 story kadri'], 'location' => 'Združene države Amerike'],
            'en' => ['title' => 'The Sunday Table', 'summary' => 'Help us make the everyday meal feel like a little occasion.', 'description' => 'We are looking for food creators who love a good table and a simple, seasonal recipe. Share an original Sunday recipe featuring one of our pantry staples. We want natural light, genuine conversation, and the kind of meal your audience would actually make.', 'category' => 'Food', 'deliverables' => ['1 short-form video', '3 story frames'], 'location' => 'United States'],
        ],
        'take-the-scenic-route' => [
            'bs' => ['title' => 'Kreni slikovitom rutom', 'summary' => 'Pokaži publici obližnje mjesto koje vrijedi istražiti polako.', 'description' => 'Field Notes gradi zbirku lokalnih vodiča za radoznale. Odvedi nas blizu kuće, pokaži šta to mjesto čini posebnim i povedi naš dnevnik na put. Prijaviti se mogu kreatori iz cijelog SAD-a.', 'category' => 'Putovanja', 'deliverables' => ['1 foto-karusel', '1 kratki video'], 'location' => 'Sjedinjene Američke Države'],
            'hr' => ['title' => 'Kreni slikovitom rutom', 'summary' => 'Pokaži publici obližnje mjesto koje vrijedi istražiti polako.', 'description' => 'Field Notes stvara zbirku lokalnih vodiča za znatiželjne. Odvedi nas negdje blizu doma, pokaži što to mjesto čini posebnim i povedi naš dnevnik na put. Mogu se prijaviti kreatori iz cijelog SAD-a.', 'category' => 'Putovanja', 'deliverables' => ['1 foto-karusel', '1 kratki video'], 'location' => 'Sjedinjene Američke Države'],
            'sr' => ['title' => 'Kreni slikovitom rutom', 'summary' => 'Pokaži publici obližnje mesto koje vredi istražiti polako.', 'description' => 'Field Notes pravi zbirku lokalnih vodiča za radoznale. Odvedi nas negde blizu kuće, pokaži šta to mesto čini posebnim i povedi naš dnevnik na put. Mogu da se prijave kreatori iz celog SAD-a.', 'category' => 'Putovanja', 'deliverables' => ['1 foto-karusel', '1 kratak video'], 'location' => 'Sjedinjene Američke Države'],
            'sl' => ['title' => 'Izberi slikovito pot', 'summary' => 'Občinstvu pokaži bližnji kraj, ki si zasluži počasno raziskovanje.', 'description' => 'Field Notes ustvarja zbirko lokalnih vodnikov za radovedne. Odpelji nas nekam blizu doma, pokaži, zakaj je kraj poseben, in na pot vzemi naš dnevnik. Prijavijo se lahko ustvarjalci iz vseh ZDA.', 'category' => 'Potovanja', 'deliverables' => ['1 foto vrtiljak', '1 kratek video'], 'location' => 'Združene države Amerike'],
            'en' => ['title' => 'Take the scenic route', 'summary' => 'Show your audience a nearby place worth slowing down for.', 'description' => 'Field Notes is building a collection of local guides for the curious. Take us somewhere close to home, share what makes it special, and bring our journal along for the journey. Open to creators across the US.', 'category' => 'Travel', 'deliverables' => ['1 photo carousel', '1 short-form video'], 'location' => 'United States'],
        ],
        'a-moment-to-reset' => [
            'bs' => ['title' => 'Trenutak za predah', 'summary' => 'Mali, iskren ritual usred užurbanog dana.', 'description' => 'Snimi kratak video o ritualu predaha koji je baš tvoj. Ne tražimo savršenu rutinu: samo stvaran trenutak, malo prostora za dah i upoznavanje s njegom tijela Soft Form.', 'category' => 'Dobrobit', 'deliverables' => ['1 kratki video', '2 kadra za story'], 'location' => 'Sjedinjene Američke Države i Ujedinjeno Kraljevstvo'],
            'hr' => ['title' => 'Trenutak za predah', 'summary' => 'Mali, iskren ritual usred užurbanog dana.', 'description' => 'Snimi kratak video o ritualu predaha koji je baš tvoj. Ne tražimo savršenu rutinu, već stvaran trenutak, malo prostora za disanje i upoznavanje s njegom tijela Soft Form.', 'category' => 'Dobrobit', 'deliverables' => ['1 kratki video', '2 story kadra'], 'location' => 'Sjedinjene Američke Države i Ujedinjeno Kraljevstvo'],
            'sr' => ['title' => 'Trenutak za predah', 'summary' => 'Mali, iskren ritual usred užurbanog dana.', 'description' => 'Snimi kratak video o ritualu predaha koji je baš tvoj. Ne tražimo savršenu rutinu, već stvaran trenutak, malo prostora za disanje i upoznavanje s negom tela Soft Form.', 'category' => 'Dobrobit', 'deliverables' => ['1 kratak video', '2 story kadra'], 'location' => 'Sjedinjene Američke Države i Ujedinjeno Kraljevstvo'],
            'sl' => ['title' => 'Trenutek za ponastavitev', 'summary' => 'Majhen, iskren ritual sredi napornega dne.', 'description' => 'Ustvari kratek video o ritualu za oddih, ki je resnično tvoj. Ne iščemo popolne rutine, le pristen trenutek, nekaj prostora za dih in predstavitev nege telesa Soft Form.', 'category' => 'Dobro počutje', 'deliverables' => ['1 kratek video', '2 story kadra'], 'location' => 'Združene države Amerike in Združeno kraljestvo'],
            'en' => ['title' => 'A moment to reset', 'summary' => 'A small, honest ritual for the middle of a busy day.', 'description' => 'Create a short video sharing a reset ritual that feels like you. We are not looking for a perfect routine: just a real moment, a little breathing room, and an introduction to Soft Form body care.', 'category' => 'Wellness', 'deliverables' => ['1 short-form video', '2 story frames'], 'location' => 'United States & UK'],
        ],
        'pantry-to-party' => [
            'bs' => ['title' => 'Od smočnice do gozbe', 'summary' => 'Pretvori pet osnovnih namirnica u večeru za pamćenje.', 'description' => 'Good Earth slavi radost kuhanja za ljude koje voliš. Snimi topao i pristupačan recept s našim namirnicama i reci nam koga bi pozvao/la za sto.', 'category' => 'Hrana', 'deliverables' => ['1 kratki video'], 'location' => 'Sjedinjene Američke Države'],
            'hr' => ['title' => 'Od smočnice do gozbe', 'summary' => 'Pretvori pet osnovnih namirnica u večeru za pamćenje.', 'description' => 'Good Earth slavi radost kuhanja za ljude koje voliš. Snimi topao i pristupačan recept s našim namirnicama i reci nam koga bi pozvao/la za stol.', 'category' => 'Hrana', 'deliverables' => ['1 kratki video'], 'location' => 'Sjedinjene Američke Države'],
            'sr' => ['title' => 'Od ostave do gozbe', 'summary' => 'Pretvori pet osnovnih namirnica u večeru za pamćenje.', 'description' => 'Good Earth slavi radost kuvanja za ljude koje voliš. Snimi topao i pristupačan recept s našim namirnicama i reci nam koga bi pozvao/la za trpezu.', 'category' => 'Hrana', 'deliverables' => ['1 kratak video'], 'location' => 'Sjedinjene Američke Države'],
            'sl' => ['title' => 'Od shrambe do pogostitve', 'summary' => 'Pet osnovnih sestavin spremeni v večerjo, ki si jo bodo zapomnili.', 'description' => 'Good Earth želi proslaviti veselje do kuhanja za ljudi, ki jih imaš rad/a. Ustvari prijeten receptni video z našimi sestavinami in povej, koga bi povabil/a k mizi.', 'category' => 'Hrana', 'deliverables' => ['1 kratek video'], 'location' => 'Združene države Amerike'],
            'en' => ['title' => 'Pantry to party', 'summary' => 'Turn five pantry staples into a dinner friends will remember.', 'description' => 'Good Earth wants to celebrate the joy of cooking for people you love. Make a short, welcoming recipe video with our pantry range and tell us who you would invite to the table.', 'category' => 'Food', 'deliverables' => ['1 short-form video'], 'location' => 'United States'],
        ],
        'leave-it-better' => [
            'bs' => ['title' => 'Ostavi prirodu ljepšom', 'summary' => 'Vikend u prirodi, pažljivo spakovan i ostavljen onakvim kakav smo ga zatekli.', 'description' => 'Field Notes traži pripovjedače koji vole boravak na otvorenom i žele podijeliti izlet s malim uticajem na prirodu. Ponesi svoju omiljenu opremu i bilježnicu za sve detalje.', 'category' => 'Putovanja', 'deliverables' => ['1 foto-karusel', '1 set story objava'], 'location' => 'Sjedinjene Američke Države i Kanada'],
            'hr' => ['title' => 'Ostavi prirodu ljepšom', 'summary' => 'Vikend u prirodi, pažljivo spakiran i ostavljen onakvim kakav smo ga zatekli.', 'description' => 'Field Notes traži pripovjedače koji vole boravak na otvorenom i žele podijeliti izlet s malim utjecajem na prirodu. Ponesi opremu koju već voliš i bilježnicu za sve detalje.', 'category' => 'Putovanja', 'deliverables' => ['1 foto-karusel', '1 set story objava'], 'location' => 'Sjedinjene Američke Države i Kanada'],
            'sr' => ['title' => 'Ostavi prirodu lepšom', 'summary' => 'Vikend u prirodi, pažljivo spakovan i ostavljen onakvim kakav smo ga zatekli.', 'description' => 'Field Notes traži ljubitelje prirode koji žele da podele jednodnevni izlet s malim uticajem na okolinu. Ponesi svoju omiljenu opremu i svesku za sve detalje.', 'category' => 'Putovanja', 'deliverables' => ['1 foto-karusel', '1 set story objava'], 'location' => 'Sjedinjene Američke Države i Kanada'],
            'sl' => ['title' => 'Naravi pusti lepši pečat', 'summary' => 'Vikend v naravi: premišljeno spakiran in po izletu takšen, kot smo ga našli.', 'description' => 'Field Notes išče pripovedovalce, ki radi raziskujejo na prostem in želijo predstaviti izlet z majhnim vplivom na okolje. Vzemi opremo, ki jo že imaš rad/a, ter zvezek za podrobnosti.', 'category' => 'Potovanja', 'deliverables' => ['1 foto vrtiljak', '1 komplet story objav'], 'location' => 'Združene države Amerike in Kanada'],
            'en' => ['title' => 'Leave it better', 'summary' => 'A weekend outdoors, packed thoughtfully and left as found.', 'description' => 'Field Notes is looking for outdoor storytellers to share their favorite low-impact day trip. Bring along the essentials you already love and a notebook to capture the details.', 'category' => 'Travel', 'deliverables' => ['1 photo carousel', '1 story set'], 'location' => 'United States & Canada'],
        ],
    ];

    public static function creator(string $slug): array
    {
        return self::withMontenegrin(self::CREATORS[$slug] ?? []);
    }

    public static function creatorPackage(string $copyKey, string $locale): array
    {
        $copy = self::withMontenegrin(self::CREATOR_PACKAGE_COPY[$copyKey] ?? []);

        return $copy[$locale] ?? [];
    }

    public static function company(string $slug): array
    {
        $industry = self::withMontenegrin(self::COMPANIES[$slug] ?? []);

        return array_map(static fn (string $value): array => ['industry' => $value], $industry);
    }

    public static function campaign(string $slug): array
    {
        return self::withMontenegrin(self::CAMPAIGNS[$slug] ?? []);
    }

    private static function withMontenegrin(array $translations): array
    {
        if (isset($translations['bs']) && !isset($translations['cnr'])) {
            $translations['cnr'] = $translations['bs'];

            return $translations;
        }

        foreach ($translations as $key => $value) {
            if (is_array($value)) {
                $translations[$key] = self::withMontenegrin($value);
            }
        }

        return $translations;
    }
}
