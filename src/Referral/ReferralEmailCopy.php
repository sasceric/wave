<?php

declare(strict_types=1);

namespace App\Referral;

/**
 * "Pozovi kreatore": the email a creator gets when their approved referrals reach a new ambassador tier.
 * {change} is the tier's name (for example "Srebreni ambasador").
 */
final class ReferralEmailCopy
{
    public const TEMPLATES = ['referral_tier_reached'];

    public const TIER_NAMES = [
        'bs' => [20 => 'Bronzani ambasador', 35 => 'Srebreni ambasador', 50 => 'Zlatni ambasador'],
        'hr' => [20 => 'Brončani ambasador', 35 => 'Srebrni ambasador', 50 => 'Zlatni ambasador'],
        'sr' => [20 => 'Bronzani ambasador', 35 => 'Srebrni ambasador', 50 => 'Zlatni ambasador'],
        'cnr' => [20 => 'Bronzani ambasador', 35 => 'Srebrni ambasador', 50 => 'Zlatni ambasador'],
        'sl' => [20 => 'Bronasti ambasador', 35 => 'Srebrni ambasador', 50 => 'Zlati ambasador'],
        'en' => [20 => 'Bronze ambassador', 35 => 'Silver ambassador', 50 => 'Gold ambassador'],
        'de' => [20 => 'Bronze-Botschafter', 35 => 'Silber-Botschafter', 50 => 'Gold-Botschafter'],
    ];

    private const COPY = [
        'bs' => [
            'subject' => 'Osvojili ste novi ambasador nivo na Waveu',
            'preheader' => 'Kreatori koje ste pozvali su odobreni. Vaše nagrade uskoro postaju aktivne.',
            'eyebrow' => 'AMBASADOR PROGRAM',
            'heading' => 'Sada ste {change}.',
            'greeting' => 'Zdravo!',
            'message' => 'Hvala što širite Wave. Kreatori koje ste pozvali su odobreni i osvojili ste novi nivo: {change}. Nagrade aktiviramo nakon kratkog pregleda preporuka i javit ćemo vam kad budu aktivne.',
            'buttonLabel' => 'Pogledajte napredak',
            'expiration' => 'Na stranici Pozovi kreatore vidite sve nagrade i koliko vam još treba do sljedećeg nivoa.',
            'security' => 'Pitanja? Pišite nam na info@wave.ba.',
            'fallback' => 'Ako se dugme ne otvori, kopirajte ovu adresu u preglednik:',
            'footer' => 'Ovu poruku šaljemo kad osvojite novi ambasador nivo.',
        ],
        'hr' => [
            'subject' => 'Osvojili ste novu ambasadorsku razinu na Waveu',
            'preheader' => 'Kreatori koje ste pozvali su odobreni. Vaše nagrade uskoro postaju aktivne.',
            'eyebrow' => 'AMBASADORSKI PROGRAM',
            'heading' => 'Sada ste {change}.',
            'greeting' => 'Bok!',
            'message' => 'Hvala što širite Wave. Kreatori koje ste pozvali su odobreni i osvojili ste novu razinu: {change}. Nagrade aktiviramo nakon kratkog pregleda preporuka i javit ćemo vam kad budu aktivne.',
            'buttonLabel' => 'Pogledajte napredak',
            'expiration' => 'Na stranici Pozovi kreatore vidite sve nagrade i koliko vam još treba do sljedeće razine.',
            'security' => 'Pitanja? Pišite nam na info@wave.ba.',
            'fallback' => 'Ako se gumb ne otvori, kopirajte ovu adresu u preglednik:',
            'footer' => 'Ovu poruku šaljemo kad osvojite novu ambasadorsku razinu.',
        ],
        'sr' => [
            'subject' => 'Osvojili ste novi ambasadorski nivo na Waveu',
            'preheader' => 'Kreatori koje ste pozvali su odobreni. Vaše nagrade uskoro postaju aktivne.',
            'eyebrow' => 'AMBASADORSKI PROGRAM',
            'heading' => 'Sada ste {change}.',
            'greeting' => 'Zdravo!',
            'message' => 'Hvala što širite Wave. Kreatori koje ste pozvali su odobreni i osvojili ste novi nivo: {change}. Nagrade aktiviramo nakon kratkog pregleda preporuka i javićemo vam kad budu aktivne.',
            'buttonLabel' => 'Pogledajte napredak',
            'expiration' => 'Na stranici Pozovi kreatore vidite sve nagrade i koliko vam još treba do sledećeg nivoa.',
            'security' => 'Pitanja? Pišite nam na info@wave.ba.',
            'fallback' => 'Ako se dugme ne otvori, kopirajte ovu adresu u pregledač:',
            'footer' => 'Ovu poruku šaljemo kad osvojite novi ambasadorski nivo.',
        ],
        'cnr' => [
            'subject' => 'Osvojili ste novi ambasadorski nivo na Waveu',
            'preheader' => 'Kreatori koje ste pozvali su odobreni. Vaše nagrade uskoro postaju aktivne.',
            'eyebrow' => 'AMBASADORSKI PROGRAM',
            'heading' => 'Sada ste {change}.',
            'greeting' => 'Zdravo!',
            'message' => 'Hvala što širite Wave. Kreatori koje ste pozvali su odobreni i osvojili ste novi nivo: {change}. Nagrade aktiviramo nakon kratkog pregleda preporuka i javićemo vam kad budu aktivne.',
            'buttonLabel' => 'Pogledajte napredak',
            'expiration' => 'Na stranici Pozovi kreatore vidite sve nagrade i koliko vam još treba do sljedećeg nivoa.',
            'security' => 'Pitanja? Pišite nam na info@wave.ba.',
            'fallback' => 'Ako se dugme ne otvori, kopirajte ovu adresu u pregledač:',
            'footer' => 'Ovu poruku šaljemo kad osvojite novi ambasadorski nivo.',
        ],
        'sl' => [
            'subject' => 'Na Wave ste dosegli novo ambasadorsko raven',
            'preheader' => 'Ustvarjalci, ki ste jih povabili, so odobreni. Vaše nagrade bodo kmalu aktivne.',
            'eyebrow' => 'AMBASADORSKI PROGRAM',
            'heading' => 'Zdaj ste {change}.',
            'greeting' => 'Pozdravljeni!',
            'message' => 'Hvala, ker širite Wave. Ustvarjalci, ki ste jih povabili, so odobreni in dosegli ste novo raven: {change}. Nagrade aktiviramo po kratkem pregledu povabil in sporočili vam bomo, ko bodo aktivne.',
            'buttonLabel' => 'Oglejte si napredek',
            'expiration' => 'Na strani Povabi ustvarjalce vidite vse nagrade in koliko vam manjka do naslednje ravni.',
            'security' => 'Vprašanja? Pišite nam na info@wave.ba.',
            'fallback' => 'Če se gumb ne odpre, kopirajte ta naslov v brskalnik:',
            'footer' => 'To sporočilo pošljemo, ko dosežete novo ambasadorsko raven.',
        ],
        'en' => [
            'subject' => 'You reached a new ambassador level on Wave',
            'preheader' => 'The creators you invited are approved. Your rewards become active soon.',
            'eyebrow' => 'AMBASSADOR PROGRAM',
            'heading' => 'You are now a {change}.',
            'greeting' => 'Hi!',
            'message' => 'Thank you for spreading Wave. The creators you invited are approved and you reached a new level: {change}. We activate the rewards after a short review of the referrals and will let you know when they are active.',
            'buttonLabel' => 'See your progress',
            'expiration' => 'On the Invite creators page you can see every reward and how many creators are left to the next level.',
            'security' => 'Questions? Write to us at info@wave.ba.',
            'fallback' => 'If the button does not open, copy this address into your browser:',
            'footer' => 'We send this message when you reach a new ambassador level.',
        ],
        'de' => [
            'subject' => 'Sie haben bei Wave eine neue Botschafter-Stufe erreicht',
            'preheader' => 'Die eingeladenen Creator sind freigegeben. Ihre Prämien werden bald aktiv.',
            'eyebrow' => 'BOTSCHAFTER-PROGRAMM',
            'heading' => 'Sie sind jetzt {change}.',
            'greeting' => 'Hallo!',
            'message' => 'Danke, dass Sie Wave weiterempfehlen. Die eingeladenen Creator sind freigegeben und Sie haben eine neue Stufe erreicht: {change}. Wir aktivieren die Prämien nach einer kurzen Prüfung der Empfehlungen und melden uns, sobald sie aktiv sind.',
            'buttonLabel' => 'Fortschritt ansehen',
            'expiration' => 'Auf der Seite „Creator einladen“ sehen Sie alle Prämien und wie viele Creator bis zur nächsten Stufe fehlen.',
            'security' => 'Fragen? Schreiben Sie uns an info@wave.ba.',
            'fallback' => 'Falls der Button nicht funktioniert, kopieren Sie diese Adresse in Ihren Browser:',
            'footer' => 'Diese Nachricht senden wir, wenn Sie eine neue Botschafter-Stufe erreichen.',
        ],
    ];

    /** @return array<string, string> */
    public static function forLocale(string $locale): array
    {
        return self::COPY[$locale] ?? self::COPY['bs'];
    }

    public static function tierName(int $tier, string $locale): string
    {
        return (self::TIER_NAMES[$locale] ?? self::TIER_NAMES['bs'])[$tier] ?? '';
    }
}
