<?php

namespace App\Newsletter;

final class NewsletterSubscriberEmailCopy
{
    private const COPY = [
        'bs' => [
            'subject' => 'Prijava na Wave novosti',
            'preheader' => 'Hvala što pratiš novosti iz Wave zajednice.',
            'eyebrow' => 'WAVE NOVOSTI',
            'heading' => 'Drago nam je da si ovdje.',
            'greeting' => 'Zdravo!',
            'message' => 'Prijavljen/a si za povremene novosti o novim kampanjama, kreatorima i idejama iz Wave zajednice.',
            'footer' => 'Ova automatska poruka potvrđuje tvoju prijavu na Wave novosti. Ako se nisi prijavio/la, možeš zanemariti ovu poruku.',
        ],
        'hr' => [
            'subject' => 'Prijava na Wave novosti',
            'preheader' => 'Hvala što pratiš novosti iz Wave zajednice.',
            'eyebrow' => 'WAVE NOVOSTI',
            'heading' => 'Drago nam je što si ovdje.',
            'greeting' => 'Bok!',
            'message' => 'Prijavljen/a si za povremene novosti o novim kampanjama, kreatorima i idejama iz Wave zajednice.',
            'footer' => 'Ova automatska poruka potvrđuje tvoju prijavu na Wave novosti. Ako se nisi prijavio/la, možeš zanemariti ovu poruku.',
        ],
        'sr' => [
            'subject' => 'Prijava na Wave novosti',
            'preheader' => 'Hvala što pratiš novosti iz Wave zajednice.',
            'eyebrow' => 'WAVE NOVOSTI',
            'heading' => 'Drago nam je što si ovde.',
            'greeting' => 'Zdravo!',
            'message' => 'Prijavljen/a si za povremene novosti o novim kampanjama, kreatorima i idejama iz Wave zajednice.',
            'footer' => 'Ova automatska poruka potvrđuje tvoju prijavu na Wave novosti. Ako se nisi prijavio/la, možeš da zanemariš ovu poruku.',
        ],
        'cnr' => [
            'subject' => 'Prijava na Wave novosti',
            'preheader' => 'Hvala što pratiš novosti iz Wave zajednice.',
            'eyebrow' => 'WAVE NOVOSTI',
            'heading' => 'Drago nam je što si ovdje.',
            'greeting' => 'Zdravo!',
            'message' => 'Prijavljen/a si za povremene novosti o novim kampanjama, kreatorima i idejama iz Wave zajednice.',
            'footer' => 'Ova automatska poruka potvrđuje tvoju prijavu na Wave novosti. Ako se nijesi prijavio/la, možeš zanemariti ovu poruku.',
        ],
        'sl' => [
            'subject' => 'Prijava na novice Wave',
            'preheader' => 'Hvala, ker spremljaš novice skupnosti Wave.',
            'eyebrow' => 'NOVICE WAVE',
            'heading' => 'Veseli nas, da si z nami.',
            'greeting' => 'Živjo!',
            'message' => 'Prijavljen/a si na občasne novice o novih kampanjah, ustvarjalcih in zamislih skupnosti Wave.',
            'footer' => 'To samodejno sporočilo potrjuje tvojo prijavo na novice Wave. Če se nisi prijavil/a, lahko to sporočilo prezreš.',
        ],
        'en' => [
            'subject' => 'You’re on the Wave updates list',
            'preheader' => 'Thanks for joining the Wave community updates.',
            'eyebrow' => 'WAVE UPDATES',
            'heading' => 'Glad you’re here.',
            'greeting' => 'Hello!',
            'message' => 'You’re signed up for occasional updates about new campaigns, creators, and ideas from the Wave community.',
            'footer' => 'This automated message confirms your signup for Wave updates. If you did not sign up, you can ignore this email.',
        ],
    ];

    /**
     * @return array<string, string>
     */
    public static function forLocale(string $locale): array
    {
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
