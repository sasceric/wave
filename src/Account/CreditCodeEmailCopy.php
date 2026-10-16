<?php

declare(strict_types=1);

namespace App\Account;

final class CreditCodeEmailCopy
{
    private const COPY = [
        'bs' => ['Tvoj Wave kod za kredite', 'Dodijeljen ti je kod za kredite.', 'TVOJI WAVE KREDITI', 'Tvoj kod je spreman.', 'Zdravo', 'Dobio/la si kod za %d kredita.', 'Kod', 'Broj kredita', 'Kako dodati kredite', 'Otvori stranicu Krediti i unesi ovaj kod. Krediti se dodaju tek nakon iskorištavanja koda. Kod je namijenjen samo tvom računu i može se iskoristiti jednom.', 'Iskoristi kod', 'Automatska poruka od Wavea. Molimo ne odgovaraj na ovu e-poruku.'],
        'hr' => ['Tvoj Wave kod za kredite', 'Dodijeljen ti je kod za kredite.', 'TVOJI WAVE KREDITI', 'Tvoj kod je spreman.', 'Bok', 'Dobio/la si kod za %d kredita.', 'Kod', 'Broj kredita', 'Kako dodati kredite', 'Otvori stranicu Krediti i unesi ovaj kod. Krediti se dodaju tek nakon iskorištavanja koda. Kod je namijenjen samo tvojem računu i može se iskoristiti jednom.', 'Iskoristi kod', 'Automatska poruka od Wavea. Molimo ne odgovaraj na ovu poruku.'],
        'sr' => ['Tvoj Wave kod za kredite', 'Dodeljen ti je kod za kredite.', 'TVOJI WAVE KREDITI', 'Tvoj kod je spreman.', 'Zdravo', 'Dobio/la si kod za %d kredita.', 'Kod', 'Broj kredita', 'Kako dodati kredite', 'Otvori stranicu Krediti i unesi ovaj kod. Krediti se dodaju tek nakon iskorišćavanja koda. Kod je namenjen samo tvom nalogu i može se iskoristiti jednom.', 'Iskoristi kod', 'Automatska poruka od Wavea. Molimo ne odgovaraj na ovu e-poruku.'],
        'cnr' => ['Tvoj Wave kod za kredite', 'Dodijeljen ti je kod za kredite.', 'TVOJI WAVE KREDITI', 'Tvoj kod je spreman.', 'Zdravo', 'Dobio/la si kod za %d kredita.', 'Kod', 'Broj kredita', 'Kako dodati kredite', 'Otvori stranicu Krediti i unesi ovaj kod. Krediti se dodaju tek nakon iskorišćavanja koda. Kod je namijenjen samo tvom nalogu i može se iskoristiti jednom.', 'Iskoristi kod', 'Automatska poruka od Wavea. Molimo ne odgovaraj na ovu e-poruku.'],
        'sl' => ['Tvoja koda za kredite Wave', 'Dodeljena ti je koda za kredite.', 'TVOJI KREDITI WAVE', 'Tvoja koda je pripravljena.', 'Živjo', 'Prejel/a si kodo za %d kreditov.', 'Koda', 'Število kreditov', 'Kako dodati kredite', 'Odpri stran Krediti in vnesi to kodo. Krediti se dodajo šele po uporabi kode. Koda je namenjena samo tvojemu računu in jo lahko uporabiš enkrat.', 'Uporabi kodo', 'Samodejno sporočilo Wave. Prosimo, ne odgovarjaj nanj.'],
        'en' => ['Your Wave credit code', 'A credit code has been assigned to you.', 'YOUR WAVE CREDITS', 'Your code is ready.', 'Hello', 'You received a code for %d credits.', 'Code', 'Credits', 'How to add credits', 'Open the Credits page and enter this code. Credits are added only when you redeem it. This code is reserved for your account and can be used once.', 'Redeem code', 'Automated message from Wave. Please do not reply to this email.'],
    ];

    public static function forLocale(string $locale): array
    {
        if ($locale === 'de') return \App\Localization\GermanTranslations::section('creditCode');
        return array_combine(['subject', 'preheader', 'eyebrow', 'heading', 'greeting', 'message', 'firstLabel', 'secondLabel', 'detailsLabel', 'details', 'buttonLabel', 'footer'], self::COPY[$locale] ?? self::COPY['bs']);
    }
}
