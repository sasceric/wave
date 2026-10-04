<?php

namespace App\Account;

final class ContactEmailCopy
{
    private const COPY = [
        'bs' => [
            'subject' => 'Nova poruka putem Wave kontakt forme',
            'heading' => 'Nova poruka putem kontakt forme',
            'preheader' => 'Primili smo novu poruku putem Wave kontakt forme.',
            'eyebrow' => 'PORUKA S KONTAKT FORME',
            'footer' => 'Poruka je poslana putem Wave kontakt forme. Odgovori direktno pošiljaocu pomoću opcije Odgovori.',
            'roleLabel' => 'Uloga',
            'nameLabel' => 'Ime',
            'emailLabel' => 'E-mail',
            'interestLabel' => 'Interes',
            'messageLabel' => 'Poruka',
            'roles' => ['creator' => 'Kreator', 'brand' => 'Brend', 'agency' => 'Agencija'],
            'interests' => [
                'collaboration' => 'Saradnja',
                'campaign' => 'Kampanja',
                'partnership' => 'Partnerstvo',
                'other' => 'Ostalo',
            ],
        ],
        'hr' => [
            'subject' => 'Nova poruka putem Wave kontakt obrasca',
            'heading' => 'Nova poruka putem kontakt obrasca',
            'preheader' => 'Primili smo novu poruku putem Wave kontakt obrasca.',
            'eyebrow' => 'PORUKA S KONTAKTNOG OBRASCA',
            'footer' => 'Poruka je poslana putem Wave kontakt obrasca. Odgovorite pošiljatelju izravno pomoću opcije Odgovori.',
            'roleLabel' => 'Uloga',
            'nameLabel' => 'Ime',
            'emailLabel' => 'E-pošta',
            'interestLabel' => 'Interes',
            'messageLabel' => 'Poruka',
            'roles' => ['creator' => 'Kreator', 'brand' => 'Brend', 'agency' => 'Agencija'],
            'interests' => [
                'collaboration' => 'Suradnja',
                'campaign' => 'Kampanja',
                'partnership' => 'Partnerstvo',
                'other' => 'Ostalo',
            ],
        ],
        'sr' => [
            'subject' => 'Nova poruka putem Wave kontakt forme',
            'heading' => 'Nova poruka putem kontakt forme',
            'preheader' => 'Primili smo novu poruku putem Wave kontakt forme.',
            'eyebrow' => 'PORUKA S KONTAKT FORME',
            'footer' => 'Poruka je poslata putem Wave kontakt forme. Odgovori direktno pošiljaocu pomoću opcije Odgovori.',
            'roleLabel' => 'Uloga',
            'nameLabel' => 'Ime',
            'emailLabel' => 'E-mail',
            'interestLabel' => 'Oblast interesovanja',
            'messageLabel' => 'Poruka',
            'roles' => ['creator' => 'Kreator', 'brand' => 'Brend', 'agency' => 'Agencija'],
            'interests' => [
                'collaboration' => 'Saradnja',
                'campaign' => 'Kampanja',
                'partnership' => 'Partnerstvo',
                'other' => 'Ostalo',
            ],
        ],
        'cnr' => [
            'subject' => 'Nova poruka putem Wave kontakt forme',
            'heading' => 'Nova poruka putem kontakt forme',
            'preheader' => 'Primili smo novu poruku putem Wave kontakt forme.',
            'eyebrow' => 'PORUKA SA KONTAKT FORME',
            'footer' => 'Poruka je poslata putem Wave kontakt forme. Odgovori direktno pošiljaocu pomoću opcije Odgovori.',
            'roleLabel' => 'Uloga',
            'nameLabel' => 'Ime',
            'emailLabel' => 'E-mail',
            'interestLabel' => 'Oblast interesovanja',
            'messageLabel' => 'Poruka',
            'roles' => ['creator' => 'Kreator', 'brand' => 'Brend', 'agency' => 'Agencija'],
            'interests' => [
                'collaboration' => 'Saradnja',
                'campaign' => 'Kampanja',
                'partnership' => 'Partnerstvo',
                'other' => 'Ostalo',
            ],
        ],
        'sl' => [
            'subject' => 'Novo sporočilo prek Wave kontaktnega obrazca',
            'heading' => 'Novo sporočilo prek kontaktnega obrazca',
            'preheader' => 'Prejeli smo novo sporočilo prek kontaktnega obrazca Wave.',
            'eyebrow' => 'SPOROČILO S KONTAKTNEGA OBRAZCA',
            'footer' => 'Sporočilo je bilo poslano prek obrazca Wave. Pošiljatelju odgovorite neposredno z možnostjo Odgovori.',
            'roleLabel' => 'Vloga',
            'nameLabel' => 'Ime',
            'emailLabel' => 'E-pošta',
            'interestLabel' => 'Zanimanje',
            'messageLabel' => 'Sporočilo',
            'roles' => [
                'creator' => 'Ustvarjalec',
                'brand' => 'Blagovna znamka',
                'agency' => 'Agencija',
            ],
            'interests' => [
                'collaboration' => 'Sodelovanje',
                'campaign' => 'Kampanja',
                'partnership' => 'Partnerstvo',
                'other' => 'Drugo',
            ],
        ],
        'en' => [
            'subject' => 'New Wave contact form message',
            'heading' => 'New Wave contact message',
            'preheader' => 'A new message was sent from the Wave contact form.',
            'eyebrow' => 'CONTACT FORM MESSAGE',
            'footer' => 'Sent from the Wave contact form. Reply directly to the sender using the Reply button.',
            'roleLabel' => 'Role',
            'nameLabel' => 'Name',
            'emailLabel' => 'Email',
            'interestLabel' => 'Interest',
            'messageLabel' => 'Message',
            'roles' => ['creator' => 'Creator', 'brand' => 'Brand', 'agency' => 'Agency'],
            'interests' => [
                'collaboration' => 'Collaboration',
                'campaign' => 'Campaign',
                'partnership' => 'Partnership',
                'other' => 'Other',
            ],
        ],
    ];

    /**
     * @return array{
     *     subject: string,
     *     heading: string,
     * preheader: string,
     * eyebrow: string,
     * footer: string,
     * roleLabel: string,
     *     nameLabel: string,
     *     emailLabel: string,
     *     interestLabel: string,
     *     messageLabel: string,
     *     roles: array<string, string>,
     *     interests: array<string, string>
     * }
     */
    public static function forLocale(string $locale): array
    {
        return self::COPY[$locale] ?? self::COPY['bs'];
    }

    /**
     * @return array<string, string>
     */
    public static function previewVariables(string $locale): array
    {
        $copy = self::forLocale($locale);
        $sampleMessages = [
            'bs' => 'Volio/voljela bih saznati više o saradnji s Waveom.',
            'hr' => 'Volio/voljela bih saznati više o suradnji s Waveom.',
            'sr' => 'Voleo/volela bih da saznam više o saradnji sa Waveom.',
            'cnr' => 'Volio/voljela bih da saznam više o saradnji sa Waveom.',
            'sl' => 'Želel/a bi izvedeti več o sodelovanju z Waveom.',
            'en' => 'I would love to learn more about working with Wave.',
        ];

        return [
            'locale' => self::languageTag($locale),
            'heading' => $copy['heading'],
            'preheader' => $copy['preheader'],
            'eyebrow' => $copy['eyebrow'],
            'footer' => $copy['footer'],
            'roleLabel' => $copy['roleLabel'],
            'nameLabel' => $copy['nameLabel'],
            'emailLabel' => $copy['emailLabel'],
            'interestLabel' => $copy['interestLabel'],
            'messageLabel' => $copy['messageLabel'],
            'role' => $copy['roles']['creator'],
            'name' => 'Alex Creator',
            'email' => 'alex@example.test',
            'interest' => $copy['interests']['collaboration'],
            'message' => $sampleMessages[$locale] ?? $sampleMessages['bs'],
        ];
    }

    public static function languageTag(string $locale): string
    {
        return match ($locale) {
            'sr' => 'sr-Latn',
            'cnr' => 'cnr-Latn-ME',
            default => isset(self::COPY[$locale]) ? $locale : 'bs',
        };
    }
}
