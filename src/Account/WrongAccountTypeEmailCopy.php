<?php

declare(strict_types=1);

namespace App\Account;

/**
 * Sent by an administrator when someone registered with the wrong account type:
 * a creator account that is really a brand or agency, or a brand/agency account that is really a creator.
 * The button opens Settings → Account type; the alternative is deleting the account and registering again.
 */
final class WrongAccountTypeEmailCopy
{
    public const TEMPLATES = ['wrong_account_type_creator', 'wrong_account_type_company'];

    private const COPY = [
        'bs' => [
            'wrong_account_type_creator' => [
                'subject' => 'Odabrali ste pogrešan tip računa na Waveu',
                'preheader' => 'Registrovali ste se kao kreator, a vaš profil pripada brendu ili agenciji.',
                'eyebrow' => 'TIP RAČUNA',
                'heading' => 'Odabrali ste pogrešan tip računa.',
                'greeting' => 'Zdravo!',
                'message' => 'Registrovali ste se kao kreator sadržaja, ali vaš profil pripada brendu ili agenciji. Kreatorski račun ne može biti brend ni agencija, pa ga ne možemo odobriti u ovom obliku. Najjednostavnije je da u Postavkama pošaljete zahtjev za promjenu tipa računa u brend ili agenciju. Vaši podaci ostaju sačuvani, a mi zahtjev pregledamo.',
                'buttonLabel' => 'Promijenite tip računa',
                'expiration' => 'Druga mogućnost: obrišite račun u Postavkama i registrujte se ponovo kao brend ili agencija.',
                'security' => 'Ako mislite da je ovo greška, pišite nam na info@wave.ba.',
                'fallback' => 'Ako se dugme ne otvori, kopirajte ovu adresu u preglednik:',
                'footer' => 'Ovu poruku vam je poslao tim Wavea nakon pregleda vašeg profila.',
            ],
            'wrong_account_type_company' => [
                'subject' => 'Odabrali ste pogrešan tip računa na Waveu',
                'preheader' => 'Registrovali ste se kao brend ili agencija, a vaš profil pripada kreatoru sadržaja.',
                'eyebrow' => 'TIP RAČUNA',
                'heading' => 'Odabrali ste pogrešan tip računa.',
                'greeting' => 'Zdravo!',
                'message' => 'Registrovali ste se kao brend ili agencija, ali vaš profil pripada kreatoru sadržaja. Račun brenda ili agencije ne može biti kreatorski, pa ga ne možemo odobriti u ovom obliku. Najjednostavnije je da u Postavkama pošaljete zahtjev za promjenu tipa računa u kreatora. Vaši podaci ostaju sačuvani, a mi zahtjev pregledamo.',
                'buttonLabel' => 'Promijenite tip računa',
                'expiration' => 'Druga mogućnost: obrišite račun u Postavkama i registrujte se ponovo kao kreator.',
                'security' => 'Ako mislite da je ovo greška, pišite nam na info@wave.ba.',
                'fallback' => 'Ako se dugme ne otvori, kopirajte ovu adresu u preglednik:',
                'footer' => 'Ovu poruku vam je poslao tim Wavea nakon pregleda vašeg profila.',
            ],
        ],
        'hr' => [
            'wrong_account_type_creator' => [
                'subject' => 'Odabrali ste pogrešnu vrstu računa na Waveu',
                'preheader' => 'Registrirali ste se kao kreator, a vaš profil pripada brendu ili agenciji.',
                'eyebrow' => 'VRSTA RAČUNA',
                'heading' => 'Odabrali ste pogrešnu vrstu računa.',
                'greeting' => 'Pozdrav!',
                'message' => 'Registrirali ste se kao kreator sadržaja, ali vaš profil pripada brendu ili agenciji. Kreatorski račun ne može biti brend ni agencija, pa ga ne možemo odobriti u ovom obliku. Najjednostavnije je da u Postavkama pošaljete zahtjev za promjenu vrste računa u brend ili agenciju. Vaši podaci ostaju sačuvani, a mi zahtjev pregledavamo.',
                'buttonLabel' => 'Promijenite vrstu računa',
                'expiration' => 'Druga mogućnost: izbrišite račun u Postavkama i ponovno se registrirajte kao brend ili agencija.',
                'security' => 'Ako mislite da je ovo pogreška, pišite nam na info@wave.ba.',
                'fallback' => 'Ako se gumb ne otvori, kopirajte ovu adresu u preglednik:',
                'footer' => 'Ovu poruku poslao vam je tim Wavea nakon pregleda vašeg profila.',
            ],
            'wrong_account_type_company' => [
                'subject' => 'Odabrali ste pogrešnu vrstu računa na Waveu',
                'preheader' => 'Registrirali ste se kao brend ili agencija, a vaš profil pripada kreatoru sadržaja.',
                'eyebrow' => 'VRSTA RAČUNA',
                'heading' => 'Odabrali ste pogrešnu vrstu računa.',
                'greeting' => 'Pozdrav!',
                'message' => 'Registrirali ste se kao brend ili agencija, ali vaš profil pripada kreatoru sadržaja. Račun brenda ili agencije ne može biti kreatorski, pa ga ne možemo odobriti u ovom obliku. Najjednostavnije je da u Postavkama pošaljete zahtjev za promjenu vrste računa u kreatora. Vaši podaci ostaju sačuvani, a mi zahtjev pregledavamo.',
                'buttonLabel' => 'Promijenite vrstu računa',
                'expiration' => 'Druga mogućnost: izbrišite račun u Postavkama i ponovno se registrirajte kao kreator.',
                'security' => 'Ako mislite da je ovo pogreška, pišite nam na info@wave.ba.',
                'fallback' => 'Ako se gumb ne otvori, kopirajte ovu adresu u preglednik:',
                'footer' => 'Ovu poruku poslao vam je tim Wavea nakon pregleda vašeg profila.',
            ],
        ],
        'sr' => [
            'wrong_account_type_creator' => [
                'subject' => 'Izabrali ste pogrešan tip naloga na Waveu',
                'preheader' => 'Registrovali ste se kao kreator, a vaš profil pripada brendu ili agenciji.',
                'eyebrow' => 'TIP NALOGA',
                'heading' => 'Izabrali ste pogrešan tip naloga.',
                'greeting' => 'Zdravo!',
                'message' => 'Registrovali ste se kao kreator sadržaja, ali vaš profil pripada brendu ili agenciji. Kreatorski nalog ne može biti brend ni agencija, pa ga ne možemo odobriti u ovom obliku. Najjednostavnije je da u Podešavanjima pošaljete zahtev za promenu tipa naloga u brend ili agenciju. Vaši podaci ostaju sačuvani, a mi zahtev pregledamo.',
                'buttonLabel' => 'Promenite tip naloga',
                'expiration' => 'Druga mogućnost: obrišite nalog u Podešavanjima i registrujte se ponovo kao brend ili agencija.',
                'security' => 'Ako mislite da je ovo greška, pišite nam na info@wave.ba.',
                'fallback' => 'Ako se dugme ne otvori, kopirajte ovu adresu u pregledač:',
                'footer' => 'Ovu poruku vam je poslao tim Wavea nakon pregleda vašeg profila.',
            ],
            'wrong_account_type_company' => [
                'subject' => 'Izabrali ste pogrešan tip naloga na Waveu',
                'preheader' => 'Registrovali ste se kao brend ili agencija, a vaš profil pripada kreatoru sadržaja.',
                'eyebrow' => 'TIP NALOGA',
                'heading' => 'Izabrali ste pogrešan tip naloga.',
                'greeting' => 'Zdravo!',
                'message' => 'Registrovali ste se kao brend ili agencija, ali vaš profil pripada kreatoru sadržaja. Nalog brenda ili agencije ne može biti kreatorski, pa ga ne možemo odobriti u ovom obliku. Najjednostavnije je da u Podešavanjima pošaljete zahtev za promenu tipa naloga u kreatora. Vaši podaci ostaju sačuvani, a mi zahtev pregledamo.',
                'buttonLabel' => 'Promenite tip naloga',
                'expiration' => 'Druga mogućnost: obrišite nalog u Podešavanjima i registrujte se ponovo kao kreator.',
                'security' => 'Ako mislite da je ovo greška, pišite nam na info@wave.ba.',
                'fallback' => 'Ako se dugme ne otvori, kopirajte ovu adresu u pregledač:',
                'footer' => 'Ovu poruku vam je poslao tim Wavea nakon pregleda vašeg profila.',
            ],
        ],
        'cnr' => [
            'wrong_account_type_creator' => [
                'subject' => 'Izabrali ste pogrešan tip naloga na Waveu',
                'preheader' => 'Registrovali ste se kao kreator, a vaš profil pripada brendu ili agenciji.',
                'eyebrow' => 'TIP NALOGA',
                'heading' => 'Izabrali ste pogrešan tip naloga.',
                'greeting' => 'Zdravo!',
                'message' => 'Registrovali ste se kao kreator sadržaja, ali vaš profil pripada brendu ili agenciji. Kreatorski nalog ne može biti brend ni agencija, pa ga ne možemo odobriti u ovom obliku. Najjednostavnije je da u Podešavanjima pošaljete zahtjev za promjenu tipa naloga u brend ili agenciju. Vaši podaci ostaju sačuvani, a mi zahtjev pregledamo.',
                'buttonLabel' => 'Promijenite tip naloga',
                'expiration' => 'Druga mogućnost: obrišite nalog u Podešavanjima i registrujte se ponovo kao brend ili agencija.',
                'security' => 'Ako mislite da je ovo greška, pišite nam na info@wave.ba.',
                'fallback' => 'Ako se dugme ne otvori, kopirajte ovu adresu u preglednik:',
                'footer' => 'Ovu poruku vam je poslao tim Wavea nakon pregleda vašeg profila.',
            ],
            'wrong_account_type_company' => [
                'subject' => 'Izabrali ste pogrešan tip naloga na Waveu',
                'preheader' => 'Registrovali ste se kao brend ili agencija, a vaš profil pripada kreatoru sadržaja.',
                'eyebrow' => 'TIP NALOGA',
                'heading' => 'Izabrali ste pogrešan tip naloga.',
                'greeting' => 'Zdravo!',
                'message' => 'Registrovali ste se kao brend ili agencija, ali vaš profil pripada kreatoru sadržaja. Nalog brenda ili agencije ne može biti kreatorski, pa ga ne možemo odobriti u ovom obliku. Najjednostavnije je da u Podešavanjima pošaljete zahtjev za promjenu tipa naloga u kreatora. Vaši podaci ostaju sačuvani, a mi zahtjev pregledamo.',
                'buttonLabel' => 'Promijenite tip naloga',
                'expiration' => 'Druga mogućnost: obrišite nalog u Podešavanjima i registrujte se ponovo kao kreator.',
                'security' => 'Ako mislite da je ovo greška, pišite nam na info@wave.ba.',
                'fallback' => 'Ako se dugme ne otvori, kopirajte ovu adresu u preglednik:',
                'footer' => 'Ovu poruku vam je poslao tim Wavea nakon pregleda vašeg profila.',
            ],
        ],
        'sl' => [
            'wrong_account_type_creator' => [
                'subject' => 'Izbrali ste napačno vrsto računa v Wave',
                'preheader' => 'Registrirali ste se kot ustvarjalec, vaš profil pa pripada znamki ali agenciji.',
                'eyebrow' => 'VRSTA RAČUNA',
                'heading' => 'Izbrali ste napačno vrsto računa.',
                'greeting' => 'Pozdravljeni!',
                'message' => 'Registrirali ste se kot ustvarjalec vsebin, vaš profil pa pripada znamki ali agenciji. Račun ustvarjalca ne more biti znamka ali agencija, zato ga v tej obliki ne moremo odobriti. Najlažje je, da v Nastavitvah pošljete zahtevo za spremembo vrste računa v znamko ali agencijo. Vaši podatki ostanejo shranjeni, zahtevo pa pregledamo mi.',
                'buttonLabel' => 'Spremenite vrsto računa',
                'expiration' => 'Druga možnost: izbrišite račun v Nastavitvah in se znova registrirajte kot znamka ali agencija.',
                'security' => 'Če menite, da gre za napako, nam pišite na info@wave.ba.',
                'fallback' => 'Če se gumb ne odpre, kopirajte ta naslov v brskalnik:',
                'footer' => 'To sporočilo vam je poslala ekipa Wave po pregledu vašega profila.',
            ],
            'wrong_account_type_company' => [
                'subject' => 'Izbrali ste napačno vrsto računa v Wave',
                'preheader' => 'Registrirali ste se kot znamka ali agencija, vaš profil pa pripada ustvarjalcu vsebin.',
                'eyebrow' => 'VRSTA RAČUNA',
                'heading' => 'Izbrali ste napačno vrsto računa.',
                'greeting' => 'Pozdravljeni!',
                'message' => 'Registrirali ste se kot znamka ali agencija, vaš profil pa pripada ustvarjalcu vsebin. Račun znamke ali agencije ne more biti račun ustvarjalca, zato ga v tej obliki ne moremo odobriti. Najlažje je, da v Nastavitvah pošljete zahtevo za spremembo vrste računa v ustvarjalca. Vaši podatki ostanejo shranjeni, zahtevo pa pregledamo mi.',
                'buttonLabel' => 'Spremenite vrsto računa',
                'expiration' => 'Druga možnost: izbrišite račun v Nastavitvah in se znova registrirajte kot ustvarjalec.',
                'security' => 'Če menite, da gre za napako, nam pišite na info@wave.ba.',
                'fallback' => 'Če se gumb ne odpre, kopirajte ta naslov v brskalnik:',
                'footer' => 'To sporočilo vam je poslala ekipa Wave po pregledu vašega profila.',
            ],
        ],
        'en' => [
            'wrong_account_type_creator' => [
                'subject' => 'You chose the wrong account type on Wave',
                'preheader' => 'You registered as a creator, but your profile belongs to a brand or agency.',
                'eyebrow' => 'ACCOUNT TYPE',
                'heading' => 'You chose the wrong account type.',
                'greeting' => 'Hello!',
                'message' => 'You registered as a content creator, but your profile belongs to a brand or agency. A creator account cannot be a brand or agency, so we cannot approve it as it is. The easiest fix is to send a request in Settings to change your account type to brand or agency. Your details are kept and we review the request.',
                'buttonLabel' => 'Change account type',
                'expiration' => 'Alternatively, delete the account in Settings and register again as a brand or agency.',
                'security' => 'If you think this is a mistake, write to us at info@wave.ba.',
                'fallback' => 'If the button does not work, copy this address into your browser:',
                'footer' => 'The Wave team sent you this message after reviewing your profile.',
            ],
            'wrong_account_type_company' => [
                'subject' => 'You chose the wrong account type on Wave',
                'preheader' => 'You registered as a brand or agency, but your profile belongs to a content creator.',
                'eyebrow' => 'ACCOUNT TYPE',
                'heading' => 'You chose the wrong account type.',
                'greeting' => 'Hello!',
                'message' => 'You registered as a brand or agency, but your profile belongs to a content creator. A brand or agency account cannot be a creator, so we cannot approve it as it is. The easiest fix is to send a request in Settings to change your account type to creator. Your details are kept and we review the request.',
                'buttonLabel' => 'Change account type',
                'expiration' => 'Alternatively, delete the account in Settings and register again as a creator.',
                'security' => 'If you think this is a mistake, write to us at info@wave.ba.',
                'fallback' => 'If the button does not work, copy this address into your browser:',
                'footer' => 'The Wave team sent you this message after reviewing your profile.',
            ],
        ],
        'de' => [
            'wrong_account_type_creator' => [
                'subject' => 'Sie haben bei Wave den falschen Kontotyp gewählt',
                'preheader' => 'Sie haben sich als Creator registriert, Ihr Profil gehört aber zu einer Marke oder Agentur.',
                'eyebrow' => 'KONTOTYP',
                'heading' => 'Sie haben den falschen Kontotyp gewählt.',
                'greeting' => 'Hallo!',
                'message' => 'Sie haben sich als Content Creator registriert, Ihr Profil gehört aber zu einer Marke oder Agentur. Ein Creator-Konto kann keine Marke oder Agentur sein, daher können wir es so nicht freigeben. Am einfachsten senden Sie in den Einstellungen eine Anfrage, den Kontotyp in Marke oder Agentur zu ändern. Ihre Daten bleiben erhalten und wir prüfen die Anfrage.',
                'buttonLabel' => 'Kontotyp ändern',
                'expiration' => 'Alternativ löschen Sie das Konto in den Einstellungen und registrieren sich erneut als Marke oder Agentur.',
                'security' => 'Wenn Sie glauben, dass es sich um einen Fehler handelt, schreiben Sie uns an info@wave.ba.',
                'fallback' => 'Falls der Button nicht funktioniert, kopieren Sie diese Adresse in Ihren Browser:',
                'footer' => 'Das Wave-Team hat Ihnen diese Nachricht nach der Prüfung Ihres Profils gesendet.',
            ],
            'wrong_account_type_company' => [
                'subject' => 'Sie haben bei Wave den falschen Kontotyp gewählt',
                'preheader' => 'Sie haben sich als Marke oder Agentur registriert, Ihr Profil gehört aber zu einem Content Creator.',
                'eyebrow' => 'KONTOTYP',
                'heading' => 'Sie haben den falschen Kontotyp gewählt.',
                'greeting' => 'Hallo!',
                'message' => 'Sie haben sich als Marke oder Agentur registriert, Ihr Profil gehört aber zu einem Content Creator. Ein Marken- oder Agenturkonto kann kein Creator-Konto sein, daher können wir es so nicht freigeben. Am einfachsten senden Sie in den Einstellungen eine Anfrage, den Kontotyp in Creator zu ändern. Ihre Daten bleiben erhalten und wir prüfen die Anfrage.',
                'buttonLabel' => 'Kontotyp ändern',
                'expiration' => 'Alternativ löschen Sie das Konto in den Einstellungen und registrieren sich erneut als Creator.',
                'security' => 'Wenn Sie glauben, dass es sich um einen Fehler handelt, schreiben Sie uns an info@wave.ba.',
                'fallback' => 'Falls der Button nicht funktioniert, kopieren Sie diese Adresse in Ihren Browser:',
                'footer' => 'Das Wave-Team hat Ihnen diese Nachricht nach der Prüfung Ihres Profils gesendet.',
            ],
        ],
    ];

    /** @return array<string, string>|null */
    public static function forLocale(string $template, string $locale): ?array
    {
        return self::COPY[$locale][$template] ?? self::COPY['bs'][$template] ?? null;
    }
}
