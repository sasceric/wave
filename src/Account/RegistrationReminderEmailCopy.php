<?php

namespace App\Account;

final class RegistrationReminderEmailCopy
{
    /** @return array<string, string> */
    public static function forLocale(string $template, string $locale): array
    {
        $copy = [
            'bs' => [
                'verification_reminder' => [
                    'subject' => 'Podsjetnik: potvrdi e-mail adresu za Wave',
                    'heading' => 'Potvrdi e-mail adresu.',
                    'message' => 'Tvoj Wave račun još čeka potvrdu e-mail adrese. Klikni na dugme ispod kako bismo mogli nastaviti s pregledom registracije.',
                    'preheader' => 'Potvrdi e-mail adresu.',
                ],
                'profile_tip' => [
                    'subject' => 'Predstavi se bolje na Waveu',
                    'heading' => 'Tvoj profil zaslužuje pažnju.',
                    'message' => 'Jasan opis, profilna slika ili logo i relevantni primjeri pomažu drugima da upoznaju tvoj rad ili kompaniju. Dodaj društvene mreže, usluge ili pakete ako ih nudiš i želiš podijeliti.',
                    'buttonLabel' => 'Uredi profil',
                    'expiration' => 'Dodatni sadržaj profila je opcionalan. Za odobrenje su potrebni potvrđena e-mail adresa i popunjeni obavezni podaci.',
                    'security' => 'Tvoj profil možeš uređivati dok račun čeka pregled.',
                    'preheader' => 'Tvoj profil zaslužuje pažnju.',
                    'eyebrow' => 'Uredi profil',
                ],
            ],
            'hr' => [
                'verification_reminder' => [
                    'subject' => 'Podsjetnik: potvrdi adresu e-pošte za Wave',
                    'heading' => 'Potvrdi adresu e-pošte.',
                    'message' => 'Tvoj Wave račun još čeka potvrdu adrese e-pošte. Klikni na gumb ispod kako bismo mogli nastaviti s pregledom registracije.',
                    'preheader' => 'Potvrdi adresu e-pošte.',
                ],
                'profile_tip' => [
                    'subject' => 'Predstavi se bolje na Waveu',
                    'heading' => 'Tvoj profil zaslužuje pažnju.',
                    'message' => 'Jasan opis, profilna slika ili logotip i relevantni primjeri pomažu drugima upoznati tvoj rad ili tvrtku. Dodaj društvene mreže, usluge ili pakete ako ih nudiš i želiš podijeliti.',
                    'buttonLabel' => 'Uredi profil',
                    'expiration' => 'Dodatni sadržaj profila nije obvezan. Za odobrenje su potrebni potvrđena adresa e-pošte i popunjeni obvezni podaci.',
                    'security' => 'Svoj profil možeš uređivati dok račun čeka pregled.',
                    'preheader' => 'Tvoj profil zaslužuje pažnju.',
                    'eyebrow' => 'Uredi profil',
                ],
            ],
            'sr' => [
                'verification_reminder' => [
                    'subject' => 'Podsetnik: potvrdi e-mail adresu za Wave',
                    'heading' => 'Potvrdi e-mail adresu.',
                    'message' => 'Tvoj Wave nalog još čeka potvrdu e-mail adrese. Klikni na dugme ispod kako bismo mogli da nastavimo sa pregledom registracije.',
                    'preheader' => 'Potvrdi e-mail adresu.',
                ],
                'profile_tip' => [
                    'subject' => 'Predstavi se bolje na Waveu',
                    'heading' => 'Tvoj profil zaslužuje pažnju.',
                    'message' => 'Jasan opis, profilna slika ili logo i relevantni primeri pomažu drugima da upoznaju tvoj rad ili kompaniju. Dodaj društvene mreže, usluge ili pakete ako ih nudiš i želiš da ih podeliš.',
                    'buttonLabel' => 'Uredi profil',
                    'expiration' => 'Dodatni sadržaj profila je opcionalan. Za odobrenje su potrebni potvrđena e-mail adresa i popunjeni obavezni podaci.',
                    'security' => 'Svoj profil možeš da uređuješ dok nalog čeka pregled.',
                    'preheader' => 'Tvoj profil zaslužuje pažnju.',
                    'eyebrow' => 'Uredi profil',
                ],
            ],
            'cnr' => [
                'verification_reminder' => [
                    'subject' => 'Podsjetnik: potvrdi e-mail adresu za Wave',
                    'heading' => 'Potvrdi e-mail adresu.',
                    'message' => 'Tvoj Wave nalog još čeka potvrdu e-mail adrese. Klikni na dugme ispod kako bismo mogli da nastavimo s pregledom registracije.',
                    'preheader' => 'Potvrdi e-mail adresu.',
                ],
                'profile_tip' => [
                    'subject' => 'Predstavi se bolje na Waveu',
                    'heading' => 'Tvoj profil zaslužuje pažnju.',
                    'message' => 'Jasan opis, profilna slika ili logo i relevantni primjeri pomažu drugima da upoznaju tvoj rad ili kompaniju. Dodaj društvene mreže, usluge ili pakete ako ih nudiš i želiš da ih podijeliš.',
                    'buttonLabel' => 'Uredi profil',
                    'expiration' => 'Dodatni sadržaj profila je opcionalan. Za odobrenje su potrebni potvrđena e-mail adresa i popunjeni obavezni podaci.',
                    'security' => 'Svoj profil možeš da uređuješ dok nalog čeka pregled.',
                    'preheader' => 'Tvoj profil zaslužuje pažnju.',
                    'eyebrow' => 'Uredi profil',
                ],
            ],
            'sl' => [
                'verification_reminder' => [
                    'subject' => 'Opomnik: potrdi e-poštni naslov za Wave',
                    'heading' => 'Potrdi e-poštni naslov.',
                    'message' => 'Tvoj račun Wave še čaka na potrditev e-poštnega naslova. Klikni spodnji gumb, da lahko nadaljujemo pregled registracije.',
                    'preheader' => 'Potrdi e-poštni naslov.',
                ],
                'profile_tip' => [
                    'subject' => 'Predstavi se bolje na Waveu',
                    'heading' => 'Tvoj profil si zasluži pozornost.',
                    'message' => 'Jasen opis, profilna slika ali logotip in ustrezni primeri pomagajo drugim spoznati tvoje delo ali podjetje. Dodaj družbena omrežja, storitve ali pakete, če jih ponujaš in želiš deliti.',
                    'buttonLabel' => 'Uredi profil',
                    'expiration' => 'Dodatna vsebina profila ni obvezna. Za odobritev sta potrebna potrjen e-poštni naslov in izpolnjeni obvezni podatki.',
                    'security' => 'Profil lahko urejaš, medtem ko račun čaka na pregled.',
                    'preheader' => 'Tvoj profil si zasluži pozornost.',
                    'eyebrow' => 'Uredi profil',
                ],
            ],
            'en' => [
                'verification_reminder' => [
                    'subject' => 'Reminder: verify your Wave email address',
                    'heading' => 'Confirm your email address.',
                    'message' => 'Your Wave account is still waiting for email verification. Click the button below so we can continue reviewing your registration.',
                    'preheader' => 'Confirm your email address.',
                ],
                'profile_tip' => [
                    'subject' => 'Make your Wave profile stand out',
                    'heading' => 'Give your profile a little attention.',
                    'message' => 'A clear description, profile photo or logo and relevant examples help others understand your work or company. Add social links, services or packages if you offer them and wish to share them.',
                    'buttonLabel' => 'Edit profile',
                    'expiration' => 'Additional profile content is optional. Approval requires a verified email address and completed mandatory fields.',
                    'security' => 'You can edit your profile while your account awaits review.',
                    'preheader' => 'Give your profile a little attention.',
                    'eyebrow' => 'Edit profile',
                ],
            ],
            'de' => [
                'verification_reminder' => [
                    'subject' => 'Erinnerung: Bestätige deine Wave-E-Mail-Adresse',
                    'heading' => 'Bestätige deine E-Mail-Adresse.',
                    'message' => 'Dein Wave-Konto wartet noch auf die Bestätigung deiner E-Mail-Adresse. Klicke unten, damit wir deine Registrierung weiter prüfen können.',
                    'preheader' => 'Bestätige deine E-Mail-Adresse.',
                ],
                'profile_tip' => [
                    'subject' => 'Präsentiere dein Wave-Profil besser',
                    'heading' => 'Dein Profil verdient Aufmerksamkeit.',
                    'message' => 'Eine klare Beschreibung, ein Profilbild oder Logo und passende Beispiele helfen anderen, deine Arbeit oder dein Unternehmen kennenzulernen. Ergänze soziale Netzwerke, Leistungen oder Pakete, wenn du sie anbietest und teilen möchtest.',
                    'buttonLabel' => 'Profil bearbeiten',
                    'expiration' => 'Zusätzliche Profilinhalte sind optional. Zur Freigabe brauchst du eine bestätigte E-Mail-Adresse und ausgefüllte Pflichtfelder.',
                    'security' => 'Du kannst dein Profil bearbeiten, während dein Konto auf die Prüfung wartet.',
                    'preheader' => 'Dein Profil verdient Aufmerksamkeit.',
                    'eyebrow' => 'Profil bearbeiten',
                ],
            ],
        ];

        return $copy[$locale][$template] ?? $copy['bs'][$template];
    }
}
