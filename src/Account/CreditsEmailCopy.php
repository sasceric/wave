<?php

declare(strict_types=1);

namespace App\Account;

final class CreditsEmailCopy
{
    private const COPY = [
        'bs' => [
            'subject' => 'Wave krediti su uključeni',
            'preheader' => 'Od danas su krediti potrebni za prijave i objavu kampanja.',
            'eyebrow' => 'KREDITI NA WAVEU',
            'heading' => 'Tvoj sljedeći korak počinje kreditima.',
            'greeting' => 'Zdravo!',
            'message' => 'Za novu prijavu ili objavu kampanje sada su potrebni krediti. Postojeće prijave i kampanje ostaju nepromijenjene.',
            'firstLabel' => 'Datum uključivanja',
            'secondLabel' => 'Dodani besplatni krediti',
            'detailsLabel' => 'Troškovi',
            'applicationLabel' => 'Prijava na kampanju',
            'campaignLabel' => 'Objava kampanje',
            'buttonLabel' => 'Pregledaj svoje kredite',
            'footer' => 'Automatska poruka od Wavea. Molimo ne odgovaraj na ovu e-poruku.',
        ],
        'hr' => [
            'subject' => 'Wave krediti su uključeni',
            'preheader' => 'Od danas su krediti potrebni za prijave i objavu kampanja.',
            'eyebrow' => 'KREDITI NA WAVEU',
            'heading' => 'Tvoj sljedeći korak počinje kreditima.',
            'greeting' => 'Bok!',
            'message' => 'Za novu prijavu ili objavu kampanje sada su potrebni krediti. Postojeće prijave i kampanje ostaju nepromijenjene.',
            'firstLabel' => 'Datum uključivanja',
            'secondLabel' => 'Dodani besplatni krediti',
            'detailsLabel' => 'Troškovi',
            'applicationLabel' => 'Prijava na kampanju',
            'campaignLabel' => 'Objava kampanje',
            'buttonLabel' => 'Pregledaj svoje kredite',
            'footer' => 'Automatska poruka od Wavea. Molimo ne odgovaraj na ovu e-poruku.',
        ],
        'sr' => [
            'subject' => 'Wave krediti su uključeni',
            'preheader' => 'Od danas su krediti potrebni za prijave i objavu kampanja.',
            'eyebrow' => 'KREDITI NA WAVEU',
            'heading' => 'Tvoj sledeći korak počinje kreditima.',
            'greeting' => 'Zdravo!',
            'message' => 'Za novu prijavu ili objavu kampanje sada su potrebni krediti. Postojeće prijave i kampanje ostaju nepromenjene.',
            'firstLabel' => 'Datum uključivanja',
            'secondLabel' => 'Dodati besplatni krediti',
            'detailsLabel' => 'Troškovi',
            'applicationLabel' => 'Prijava na kampanju',
            'campaignLabel' => 'Objava kampanje',
            'buttonLabel' => 'Pregledaj svoje kredite',
            'footer' => 'Automatska poruka od Wavea. Molimo ne odgovaraj na ovu e-poruku.',
        ],
        'cnr' => [
            'subject' => 'Wave krediti su uključeni',
            'preheader' => 'Od danas su krediti potrebni za prijave i objavu kampanja.',
            'eyebrow' => 'KREDITI NA WAVEU',
            'heading' => 'Tvoj sljedeći korak počinje kreditima.',
            'greeting' => 'Zdravo!',
            'message' => 'Za novu prijavu ili objavu kampanje sada su potrebni krediti. Postojeće prijave i kampanje ostaju nepromijenjene.',
            'firstLabel' => 'Datum uključivanja',
            'secondLabel' => 'Dodati besplatni krediti',
            'detailsLabel' => 'Troškovi',
            'applicationLabel' => 'Prijava na kampanju',
            'campaignLabel' => 'Objava kampanje',
            'buttonLabel' => 'Pregledaj svoje kredite',
            'footer' => 'Automatska poruka od Wavea. Molimo ne odgovaraj na ovu e-poruku.',
        ],
        'sl' => [
            'subject' => 'Krediti Wave so vključeni',
            'preheader' => 'Od danes za prijave in objavo kampanj potrebujete kredite.',
            'eyebrow' => 'KREDITI NA WAVEU',
            'heading' => 'Naslednji korak se začne s krediti.',
            'greeting' => 'Pozdravljeni!',
            'message' => 'Za novo prijavo ali objavo kampanje zdaj potrebujete kredite. Obstoječe prijave in kampanje ostajajo nespremenjene.',
            'firstLabel' => 'Datum vključitve',
            'secondLabel' => 'Dodani brezplačni krediti',
            'detailsLabel' => 'Stroški',
            'applicationLabel' => 'Prijava na kampanjo',
            'campaignLabel' => 'Objava kampanje',
            'buttonLabel' => 'Oglejte si svoje kredite',
            'footer' => 'Samodejno sporočilo platforme Wave. Prosimo, ne odgovarjajte nanj.',
        ],
        'en' => [
            'subject' => 'Wave credits are now enabled',
            'preheader' => 'From today, credits are needed to apply to and publish campaigns.',
            'eyebrow' => 'WAVE CREDITS',
            'heading' => 'Your next step starts with credits.',
            'greeting' => 'Hello!',
            'message' => 'You now need credits to submit a new application or publish a campaign. Existing applications and campaigns are unchanged.',
            'firstLabel' => 'Activation date',
            'secondLabel' => 'Free credits added',
            'detailsLabel' => 'Action costs',
            'applicationLabel' => 'Apply to a campaign',
            'campaignLabel' => 'Publish a campaign',
            'buttonLabel' => 'View your credits',
            'footer' => 'Automatic message from Wave. Please do not reply to this email.',
        ],
    ];

    public static function forLocale(string $locale): array
    {
        return self::COPY[$locale] ?? self::COPY['bs'];
    }
}
