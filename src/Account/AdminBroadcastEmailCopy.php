<?php

namespace App\Account;

/**
 * Footer copy of "Admin → Slanje e-mailova" messages. The subject, heading, text and button
 * are written by the admin for each send; only the footer follows the recipient's language.
 */
final class AdminBroadcastEmailCopy
{
    /** @return array<string, string> */
    public static function forLocale(string $locale): array
    {
        return self::COPY[$locale] ?? self::COPY['bs'];
    }

    private const COPY = [
        'bs' => [
            'subject' => 'Novosti s Wavea',
            'preheader' => 'Kratka poruka Wave tima.',
            'heading' => 'Novosti s Wavea',
            'greeting' => 'Pozdrav',
            'message' => "Pozdrav Amina,\n\nOvo je primjer poruke koju pišete u Admin → Slanje e-mailova.\n\nWave tim",
            'buttonLabel' => 'Otvorite Wave',
            'footer' => 'Ovu poruku ste dobili kao korisnik Wavea.',
            'footerNewsletter' => 'Ovu poruku ste dobili jer ste prijavljeni na Wave novosti.',
            'footerExternal' => 'Ovu poruku vam šalje Wave (wave.ba), platforma za saradnju kreatora i brendova.',
            'settingsLabel' => 'Postavke e-mailova',
            'unsubscribeLabel' => 'Odjavite se od Wave novosti',
            'siteLabel' => 'wave.ba',
        ],
        'hr' => [
            'subject' => 'Novosti s Wavea',
            'preheader' => 'Kratka poruka Wave tima.',
            'heading' => 'Novosti s Wavea',
            'greeting' => 'Pozdrav',
            'message' => "Pozdrav Amina,\n\nOvo je primjer poruke koju pišete u Admin → Slanje e-mailova.\n\nWave tim",
            'buttonLabel' => 'Otvorite Wave',
            'footer' => 'Ovu poruku primili ste kao korisnik Wavea.',
            'footerNewsletter' => 'Ovu poruku primili ste jer ste prijavljeni na Wave novosti.',
            'footerExternal' => 'Ovu poruku šalje vam Wave (wave.ba), platforma za suradnju kreatora i brendova.',
            'settingsLabel' => 'Postavke e-pošte',
            'unsubscribeLabel' => 'Odjavite se od Wave novosti',
            'siteLabel' => 'wave.ba',
        ],
        'sr' => [
            'subject' => 'Novosti sa Wavea',
            'preheader' => 'Kratka poruka Wave tima.',
            'heading' => 'Novosti sa Wavea',
            'greeting' => 'Pozdrav',
            'message' => "Pozdrav Amina,\n\nOvo je primer poruke koju pišete u Admin → Slanje e-mailova.\n\nWave tim",
            'buttonLabel' => 'Otvorite Wave',
            'footer' => 'Ovu poruku ste dobili kao korisnik Wavea.',
            'footerNewsletter' => 'Ovu poruku ste dobili jer ste prijavljeni na Wave novosti.',
            'footerExternal' => 'Ovu poruku vam šalje Wave (wave.ba), platforma za saradnju kreatora i brendova.',
            'settingsLabel' => 'Podešavanja e-mailova',
            'unsubscribeLabel' => 'Odjavite se sa Wave novosti',
            'siteLabel' => 'wave.ba',
        ],
        'cnr' => [
            'subject' => 'Novosti sa Wavea',
            'preheader' => 'Kratka poruka Wave tima.',
            'heading' => 'Novosti sa Wavea',
            'greeting' => 'Pozdrav',
            'message' => "Pozdrav Amina,\n\nOvo je primjer poruke koju pišete u Admin → Slanje e-mailova.\n\nWave tim",
            'buttonLabel' => 'Otvorite Wave',
            'footer' => 'Ovu poruku ste dobili kao korisnik Wavea.',
            'footerNewsletter' => 'Ovu poruku ste dobili jer ste prijavljeni na Wave novosti.',
            'footerExternal' => 'Ovu poruku vam šalje Wave (wave.ba), platforma za saradnju kreatora i brendova.',
            'settingsLabel' => 'Podešavanja e-mailova',
            'unsubscribeLabel' => 'Odjavite se sa Wave novosti',
            'siteLabel' => 'wave.ba',
        ],
        'sl' => [
            'subject' => 'Novice z Wavea',
            'preheader' => 'Kratko sporočilo ekipe Wave.',
            'heading' => 'Novice z Wavea',
            'greeting' => 'Pozdravljeni',
            'message' => "Pozdravljeni, Amina,\n\nTo je primer sporočila, ki ga napišete v Admin → Pošiljanje e-pošte.\n\nEkipa Wave",
            'buttonLabel' => 'Odprite Wave',
            'footer' => 'To sporočilo ste prejeli kot uporabnik Wavea.',
            'footerNewsletter' => 'To sporočilo ste prejeli, ker ste prijavljeni na novice Wave.',
            'footerExternal' => 'To sporočilo vam pošilja Wave (wave.ba), platforma za sodelovanje ustvarjalcev in znamk.',
            'settingsLabel' => 'Nastavitve e-pošte',
            'unsubscribeLabel' => 'Odjava od novic Wave',
            'siteLabel' => 'wave.ba',
        ],
        'en' => [
            'subject' => 'News from Wave',
            'preheader' => 'A short message from the Wave team.',
            'heading' => 'News from Wave',
            'greeting' => 'Hello',
            'message' => "Hello Amina,\n\nThis is an example of a message you write in Admin → Send emails.\n\nThe Wave team",
            'buttonLabel' => 'Open Wave',
            'footer' => 'You received this message as a Wave user.',
            'footerNewsletter' => 'You received this message because you subscribed to Wave news.',
            'footerExternal' => 'This message is from Wave (wave.ba), a platform for creator and brand collaborations.',
            'settingsLabel' => 'Email settings',
            'unsubscribeLabel' => 'Unsubscribe from Wave news',
            'siteLabel' => 'wave.ba',
        ],
        'de' => [
            'subject' => 'Neuigkeiten von Wave',
            'preheader' => 'Eine kurze Nachricht des Wave-Teams.',
            'heading' => 'Neuigkeiten von Wave',
            'greeting' => 'Hallo',
            'message' => "Hallo Amina,\n\ndies ist ein Beispiel für eine Nachricht, die Sie unter Admin → E-Mails senden schreiben.\n\nIhr Wave-Team",
            'buttonLabel' => 'Wave öffnen',
            'footer' => 'Sie erhalten diese Nachricht als Wave-Nutzer.',
            'footerNewsletter' => 'Sie erhalten diese Nachricht, weil Sie die Wave-Neuigkeiten abonniert haben.',
            'footerExternal' => 'Diese Nachricht kommt von Wave (wave.ba), einer Plattform für Kooperationen zwischen Creatorn und Marken.',
            'settingsLabel' => 'E-Mail-Einstellungen',
            'unsubscribeLabel' => 'Wave-Neuigkeiten abbestellen',
            'siteLabel' => 'wave.ba',
        ],
    ];
}
