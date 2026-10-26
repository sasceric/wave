<?php

namespace App\Newsletter;

final class NewsletterSubscriberEmailCopy
{
    private const COPY = [
        'bs' => [
            'confirmLabel' => 'Potvrdi prijavu na Wave novosti',
            'unsubscribeLabel' => 'Odjavi Wave novosti',
            'subject' => 'Potvrdi prijavu na Wave novosti',
            'preheader' => 'Potvrdi prijavu na Wave novosti',
            'eyebrow' => 'WAVE NOVOSTI',
            'heading' => 'Potvrdi prijavu na Wave novosti',
            'greeting' => 'Zdravo!',
            'message' => 'Potvrdi da želiš primati povremene novosti o kampanjama, kreatorima i idejama iz Wave zajednice. Newsletter neće biti aktivan dok ne potvrdiš na stranici iz poveznice.',
            'footer' => 'Ova automatska poruka potvrđuje tvoju prijavu na Wave novosti. Ako se nisi prijavio/la, koristi poveznicu za odjavu ispod.',
        ],
        'hr' => [
            'confirmLabel' => 'Potvrdi prijavu na Wave novosti',
            'unsubscribeLabel' => 'Odjavi Wave novosti',
            'subject' => 'Potvrdi prijavu na Wave novosti',
            'preheader' => 'Potvrdi prijavu na Wave novosti',
            'eyebrow' => 'WAVE NOVOSTI',
            'heading' => 'Potvrdi prijavu na Wave novosti',
            'greeting' => 'Bok!',
            'message' => 'Potvrdi da želiš primati povremene novosti o kampanjama, kreatorima i idejama iz Wave zajednice. Newsletter neće biti aktivan dok ne potvrdiš na stranici iz poveznice.',
            'footer' => 'Ova automatska poruka potvrđuje tvoju prijavu na Wave novosti. Ako se nisi prijavio/la, koristi poveznicu za odjavu ispod.',
        ],
        'sr' => [
            'confirmLabel' => 'Potvrdi prijavu na Wave novosti',
            'unsubscribeLabel' => 'Odjavi Wave novosti',
            'subject' => 'Potvrdi prijavu na Wave novosti',
            'preheader' => 'Potvrdi prijavu na Wave novosti',
            'eyebrow' => 'WAVE NOVOSTI',
            'heading' => 'Potvrdi prijavu na Wave novosti',
            'greeting' => 'Zdravo!',
            'message' => 'Potvrdi da želiš da primaš povremene novosti o kampanjama, kreatorima i idejama iz Wave zajednice. Newsletter neće biti aktivan dok ne potvrdiš na stranici iz veze.',
            'footer' => 'Ova automatska poruka potvrđuje tvoju prijavu na Wave novosti. Ako se nisi prijavio/la, koristi vezu za odjavu ispod.',
        ],
        'cnr' => [
            'confirmLabel' => 'Potvrdi prijavu na Wave novosti',
            'unsubscribeLabel' => 'Odjavi Wave novosti',
            'subject' => 'Potvrdi prijavu na Wave novosti',
            'preheader' => 'Potvrdi prijavu na Wave novosti',
            'eyebrow' => 'WAVE NOVOSTI',
            'heading' => 'Potvrdi prijavu na Wave novosti',
            'greeting' => 'Zdravo!',
            'message' => 'Potvrdi da želiš primati povremene novosti o kampanjama, kreatorima i idejama iz Wave zajednice. Newsletter neće biti aktivan dok ne potvrdiš na stranici iz poveznice.',
            'footer' => 'Ova automatska poruka potvrđuje tvoju prijavu na Wave novosti. Ako se nijesi prijavio/la, koristi poveznicu za odjavu ispod.',
        ],
        'sl' => [
            'confirmLabel' => 'Potrdi prijavo na novice Wave',
            'unsubscribeLabel' => 'Odjava od novic Wave',
            'subject' => 'Potrdi prijavo na novice Wave',
            'preheader' => 'Potrdi prijavo na novice Wave',
            'eyebrow' => 'NOVICE WAVE',
            'heading' => 'Potrdi prijavo na novice Wave',
            'greeting' => 'Živjo!',
            'message' => 'Potrdi, da želiš prejemati občasne novice o kampanjah, ustvarjalcih in zamislih skupnosti Wave. Naročnina se aktivira šele po potrditvi na povezani strani.',
            'footer' => 'To samodejno sporočilo potrjuje tvojo prijavo na novice Wave. Če se nisi prijavil/a, uporabi spodnjo povezavo za odjavo.',
        ],
        'en' => [
            'confirmLabel' => 'Confirm Wave news signup',
            'unsubscribeLabel' => 'Unsubscribe from Wave news',
            'subject' => 'Confirm Wave news signup',
            'preheader' => 'Confirm Wave news signup',
            'eyebrow' => 'WAVE UPDATES',
            'heading' => 'Confirm Wave news signup',
            'greeting' => 'Hello!',
            'message' => 'Confirm that you want occasional news about campaigns, creators and ideas from Wave. Your newsletter subscription stays inactive until you confirm on the linked page.',
            'footer' => 'This automated message confirms your signup for Wave updates. If you did not sign up, use the unsubscribe link below.',
        ],
    ];

    /**
     * @return array<string, string>
     */
    public static function forLocale(string $locale): array
    {
        if ($locale === 'de') return \App\Localization\GermanTranslations::section('newsletter');
        return self::COPY[$locale] ?? self::COPY['bs'];
    }

    public static function languageTag(string $locale): string
    {
        return match ($locale) {
            'sr' => 'sr-Latn',
            'cnr' => 'cnr-Latn-ME',
            default => $locale,
        };
    }
}
