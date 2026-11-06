<?php

declare(strict_types=1);

namespace App\Account;

/**
 * Sign-in and address changes: the confirmation link for an added address, and the notice sent to the
 * main address (and, after a main address change, to the previous one). `{change}` names what changed.
 */
final class AccountSecurityEmailCopy
{
    public const TEMPLATES = ['email_added_verify', 'account_security_notice'];

    private const COPY = [
        'bs' => [
            'email_added_verify' => [
                'subject' => 'Potvrdite novu e-mail adresu za Wave',
                'preheader' => 'Nakon potvrde možete se prijaviti i ovom adresom.',
                'eyebrow' => 'NOVA ADRESA',
                'heading' => 'Potvrdite da je ovo vaša adresa.',
                'greeting' => 'Zdravo!',
                'message' => 'Ova adresa je dodana vašem Wave računu. Nakon potvrde možete se njom prijaviti i postaviti je kao glavnu.',
                'buttonLabel' => 'Potvrdi adresu',
                'expiration' => 'Link vrijedi 24 sata.',
                'security' => 'Ako niste vi dodali ovu adresu, zanemarite ovu poruku.',
                'fallback' => 'Ako se dugme ne otvori, kopirajte ovu adresu u preglednik:',
                'footer' => 'Automatska poruka od Wavea.',
            ],
            'account_security_notice' => [
                'subject' => 'Promjena na vašem Wave računu',
                'preheader' => 'Provjerite da ste ovo bili vi.',
                'eyebrow' => 'SIGURNOST RAČUNA',
                'heading' => 'Na vašem računu je napravljena promjena.',
                'greeting' => 'Zdravo!',
                'message' => 'Na vašem Wave računu {change}.',
                'buttonLabel' => 'Otvori postavke',
                'expiration' => 'Ako ste ovo bili vi, ne morate ništa raditi.',
                'security' => 'Ako niste vi, odmah promijenite lozinku i pišite nam na info@wave.ba.',
                'fallback' => 'Ako se dugme ne otvori, kopirajte ovu adresu u preglednik:',
                'footer' => 'Ovu sigurnosnu poruku šaljemo uvijek, bez obzira na postavke obavještenja.',
            ],
            'changes' => ['email_added' => 'dodana je e-mail adresa %s. Adresa postaje aktivna kad je potvrdite preko linka koji smo poslali na nju', 'email_removed' => 'uklonjena je e-mail adresa %s', 'primary_changed' => 'glavna e-mail adresa je promijenjena u %s', 'linked' => 'povezan je %s račun', 'unlinked' => 'uklonjena je veza s %s računom'],
        ],
        'hr' => [
            'email_added_verify' => [
                'subject' => 'Potvrdite novu e-mail adresu za Wave',
                'preheader' => 'Nakon potvrde možete se prijaviti i ovom adresom.',
                'eyebrow' => 'NOVA ADRESA',
                'heading' => 'Potvrdite da je ovo vaša adresa.',
                'greeting' => 'Pozdrav!',
                'message' => 'Ova adresa dodana je vašem Wave računu. Nakon potvrde možete se njome prijaviti i postaviti je kao glavnu.',
                'buttonLabel' => 'Potvrdi adresu',
                'expiration' => 'Poveznica vrijedi 24 sata.',
                'security' => 'Ako niste vi dodali ovu adresu, zanemarite ovu poruku.',
                'fallback' => 'Ako se gumb ne otvori, kopirajte ovu adresu u preglednik:',
                'footer' => 'Automatska poruka od Wavea.',
            ],
            'account_security_notice' => [
                'subject' => 'Promjena na vašem Wave računu',
                'preheader' => 'Provjerite jeste li ovo bili vi.',
                'eyebrow' => 'SIGURNOST RAČUNA',
                'heading' => 'Na vašem računu napravljena je promjena.',
                'greeting' => 'Pozdrav!',
                'message' => 'Na vašem Wave računu {change}.',
                'buttonLabel' => 'Otvori postavke',
                'expiration' => 'Ako ste ovo bili vi, ne morate ništa raditi.',
                'security' => 'Ako niste vi, odmah promijenite lozinku i pišite nam na info@wave.ba.',
                'fallback' => 'Ako se gumb ne otvori, kopirajte ovu adresu u preglednik:',
                'footer' => 'Ovu sigurnosnu poruku šaljemo uvijek, bez obzira na postavke obavijesti.',
            ],
            'changes' => ['email_added' => 'dodana je e-mail adresa %s. Adresa postaje aktivna kad je potvrdite preko linka koji smo poslali na nju', 'email_removed' => 'uklonjena je e-mail adresa %s', 'primary_changed' => 'glavna e-mail adresa promijenjena je u %s', 'linked' => 'povezan je %s račun', 'unlinked' => 'uklonjena je veza s %s računom'],
        ],
        'sr' => [
            'email_added_verify' => [
                'subject' => 'Potvrdite novu e-mail adresu za Wave',
                'preheader' => 'Nakon potvrde možete se prijaviti i ovom adresom.',
                'eyebrow' => 'NOVA ADRESA',
                'heading' => 'Potvrdite da je ovo vaša adresa.',
                'greeting' => 'Zdravo!',
                'message' => 'Ova adresa je dodata vašem Wave nalogu. Nakon potvrde možete se njom prijaviti i postaviti je kao glavnu.',
                'buttonLabel' => 'Potvrdi adresu',
                'expiration' => 'Link važi 24 sata.',
                'security' => 'Ako niste vi dodali ovu adresu, zanemarite ovu poruku.',
                'fallback' => 'Ako se dugme ne otvori, kopirajte ovu adresu u pregledač:',
                'footer' => 'Automatska poruka od Wavea.',
            ],
            'account_security_notice' => [
                'subject' => 'Promena na vašem Wave nalogu',
                'preheader' => 'Proverite da li ste ovo bili vi.',
                'eyebrow' => 'BEZBEDNOST NALOGA',
                'heading' => 'Na vašem nalogu je napravljena promena.',
                'greeting' => 'Zdravo!',
                'message' => 'Na vašem Wave nalogu {change}.',
                'buttonLabel' => 'Otvori podešavanja',
                'expiration' => 'Ako ste ovo bili vi, ne morate ništa da radite.',
                'security' => 'Ako niste vi, odmah promenite lozinku i pišite nam na info@wave.ba.',
                'fallback' => 'Ako se dugme ne otvori, kopirajte ovu adresu u pregledač:',
                'footer' => 'Ovu bezbednosnu poruku šaljemo uvek, bez obzira na podešavanja obaveštenja.',
            ],
            'changes' => ['email_added' => 'dodata je e-mail adresa %s. Adresa postaje aktivna kad je potvrdite preko linka koji smo poslali na nju', 'email_removed' => 'uklonjena je e-mail adresa %s', 'primary_changed' => 'glavna e-mail adresa je promenjena u %s', 'linked' => 'povezan je %s nalog', 'unlinked' => 'uklonjena je veza sa %s nalogom'],
        ],
        'cnr' => [
            'email_added_verify' => [
                'subject' => 'Potvrdite novu e-mail adresu za Wave',
                'preheader' => 'Nakon potvrde možete se prijaviti i ovom adresom.',
                'eyebrow' => 'NOVA ADRESA',
                'heading' => 'Potvrdite da je ovo vaša adresa.',
                'greeting' => 'Zdravo!',
                'message' => 'Ova adresa je dodata vašem Wave nalogu. Nakon potvrde možete se njom prijaviti i postaviti je kao glavnu.',
                'buttonLabel' => 'Potvrdi adresu',
                'expiration' => 'Link važi 24 sata.',
                'security' => 'Ako niste vi dodali ovu adresu, zanemarite ovu poruku.',
                'fallback' => 'Ako se dugme ne otvori, kopirajte ovu adresu u preglednik:',
                'footer' => 'Automatska poruka od Wavea.',
            ],
            'account_security_notice' => [
                'subject' => 'Promjena na vašem Wave nalogu',
                'preheader' => 'Provjerite da li ste ovo bili vi.',
                'eyebrow' => 'BEZBJEDNOST NALOGA',
                'heading' => 'Na vašem nalogu je napravljena promjena.',
                'greeting' => 'Zdravo!',
                'message' => 'Na vašem Wave nalogu {change}.',
                'buttonLabel' => 'Otvori podešavanja',
                'expiration' => 'Ako ste ovo bili vi, ne morate ništa da radite.',
                'security' => 'Ako niste vi, odmah promijenite lozinku i pišite nam na info@wave.ba.',
                'fallback' => 'Ako se dugme ne otvori, kopirajte ovu adresu u preglednik:',
                'footer' => 'Ovu bezbjednosnu poruku šaljemo uvijek, bez obzira na podešavanja obavještenja.',
            ],
            'changes' => ['email_added' => 'dodata je e-mail adresa %s. Adresa postaje aktivna kad je potvrdite preko linka koji smo poslali na nju', 'email_removed' => 'uklonjena je e-mail adresa %s', 'primary_changed' => 'glavna e-mail adresa je promijenjena u %s', 'linked' => 'povezan je %s nalog', 'unlinked' => 'uklonjena je veza sa %s nalogom'],
        ],
        'sl' => [
            'email_added_verify' => [
                'subject' => 'Potrdite nov e-poštni naslov za Wave',
                'preheader' => 'Po potrditvi se lahko prijavite tudi s tem naslovom.',
                'eyebrow' => 'NOV NASLOV',
                'heading' => 'Potrdite, da je to vaš naslov.',
                'greeting' => 'Pozdravljeni!',
                'message' => 'Ta naslov je bil dodan vašemu računu Wave. Po potrditvi se lahko z njim prijavite in ga nastavite kot glavnega.',
                'buttonLabel' => 'Potrdi naslov',
                'expiration' => 'Povezava velja 24 ur.',
                'security' => 'Če tega naslova niste dodali vi, prezrite to sporočilo.',
                'fallback' => 'Če se gumb ne odpre, kopirajte ta naslov v brskalnik:',
                'footer' => 'Samodejno sporočilo Wave.',
            ],
            'account_security_notice' => [
                'subject' => 'Sprememba na vašem računu Wave',
                'preheader' => 'Preverite, ali ste bili to vi.',
                'eyebrow' => 'VARNOST RAČUNA',
                'heading' => 'Na vašem računu je bila narejena sprememba.',
                'greeting' => 'Pozdravljeni!',
                'message' => 'Na vašem računu Wave {change}.',
                'buttonLabel' => 'Odpri nastavitve',
                'expiration' => 'Če ste bili to vi, vam ni treba storiti ničesar.',
                'security' => 'Če to niste bili vi, takoj spremenite geslo in nam pišite na info@wave.ba.',
                'fallback' => 'Če se gumb ne odpre, kopirajte ta naslov v brskalnik:',
                'footer' => 'To varnostno sporočilo pošljemo vedno, ne glede na nastavitve obvestil.',
            ],
            'changes' => ['email_added' => 'je bil dodan e-poštni naslov %s. Naslov bo aktiven, ko ga potrdite prek povezave, ki smo jo poslali nanj', 'email_removed' => 'je bil odstranjen e-poštni naslov %s', 'primary_changed' => 'je bil glavni e-poštni naslov spremenjen v %s', 'linked' => 'je bil povezan račun %s', 'unlinked' => 'je bila odstranjena povezava z računom %s'],
        ],
        'en' => [
            'email_added_verify' => [
                'subject' => 'Confirm your new email address for Wave',
                'preheader' => 'Once confirmed, you can also sign in with this address.',
                'eyebrow' => 'NEW ADDRESS',
                'heading' => 'Confirm this is your address.',
                'greeting' => 'Hello!',
                'message' => 'This address was added to your Wave account. Once confirmed, you can sign in with it and make it your main address.',
                'buttonLabel' => 'Confirm address',
                'expiration' => 'The link is valid for 24 hours.',
                'security' => 'If you did not add this address, ignore this message.',
                'fallback' => 'If the button does not work, copy this address into your browser:',
                'footer' => 'Automated message from Wave.',
            ],
            'account_security_notice' => [
                'subject' => 'A change on your Wave account',
                'preheader' => 'Check that this was you.',
                'eyebrow' => 'ACCOUNT SECURITY',
                'heading' => 'Your account was changed.',
                'greeting' => 'Hello!',
                'message' => 'On your Wave account, {change}.',
                'buttonLabel' => 'Open settings',
                'expiration' => 'If this was you, there is nothing to do.',
                'security' => 'If this was not you, change your password right away and write to us at info@wave.ba.',
                'fallback' => 'If the button does not work, copy this address into your browser:',
                'footer' => 'We always send this security message, whatever your notification settings.',
            ],
            'changes' => ['email_added' => 'the email address %s was added. It becomes active once it is confirmed through the link we sent to it', 'email_removed' => 'the email address %s was removed', 'primary_changed' => 'the main email address was changed to %s', 'linked' => 'a %s account was linked', 'unlinked' => 'the link to a %s account was removed'],
        ],
        'de' => [
            'email_added_verify' => [
                'subject' => 'Bestätigen Sie Ihre neue E-Mail-Adresse für Wave',
                'preheader' => 'Nach der Bestätigung können Sie sich auch mit dieser Adresse anmelden.',
                'eyebrow' => 'NEUE ADRESSE',
                'heading' => 'Bestätigen Sie, dass dies Ihre Adresse ist.',
                'greeting' => 'Hallo!',
                'message' => 'Diese Adresse wurde Ihrem Wave-Konto hinzugefügt. Nach der Bestätigung können Sie sich damit anmelden und sie als Hauptadresse festlegen.',
                'buttonLabel' => 'Adresse bestätigen',
                'expiration' => 'Der Link ist 24 Stunden gültig.',
                'security' => 'Wenn Sie diese Adresse nicht hinzugefügt haben, ignorieren Sie diese Nachricht.',
                'fallback' => 'Falls der Button nicht funktioniert, kopieren Sie diese Adresse in Ihren Browser:',
                'footer' => 'Automatische Nachricht von Wave.',
            ],
            'account_security_notice' => [
                'subject' => 'Eine Änderung an Ihrem Wave-Konto',
                'preheader' => 'Prüfen Sie, ob Sie das waren.',
                'eyebrow' => 'KONTOSICHERHEIT',
                'heading' => 'An Ihrem Konto wurde etwas geändert.',
                'greeting' => 'Hallo!',
                'message' => 'An Ihrem Wave-Konto: {change}.',
                'buttonLabel' => 'Einstellungen öffnen',
                'expiration' => 'Wenn Sie das waren, müssen Sie nichts tun.',
                'security' => 'Wenn Sie das nicht waren, ändern Sie sofort Ihr Passwort und schreiben Sie uns an info@wave.ba.',
                'fallback' => 'Falls der Button nicht funktioniert, kopieren Sie diese Adresse in Ihren Browser:',
                'footer' => 'Diese Sicherheitsnachricht senden wir immer, unabhängig von Ihren Benachrichtigungseinstellungen.',
            ],
            'changes' => ['email_added' => 'die E-Mail-Adresse %s wurde hinzugefügt. Sie wird aktiv, sobald sie über den Link bestätigt wird, den wir an sie gesendet haben', 'email_removed' => 'die E-Mail-Adresse %s wurde entfernt', 'primary_changed' => 'die Hauptadresse wurde zu %s geändert', 'linked' => 'ein %s-Konto wurde verknüpft', 'unlinked' => 'die Verknüpfung mit einem %s-Konto wurde entfernt'],
        ],
    ];

    /** @return array<string, string>|null */
    public static function forLocale(string $template, string $locale): ?array
    {
        return self::COPY[$locale][$template] ?? self::COPY['bs'][$template] ?? null;
    }

    public static function change(string $kind, string $value, string $locale): string
    {
        $phrase = self::COPY[$locale]['changes'][$kind] ?? self::COPY['bs']['changes'][$kind] ?? '%s';

        return sprintf($phrase, $value);
    }
}
