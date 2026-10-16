<?php

namespace App\Account;

final class CampaignAlertEmailCopy
{
    public static function forLocale(string $locale): array
    {
        $copy = [
            'bs' => ['Nove kampanje za tebe', 'Zdravo,', 'Pogledaj nove kampanje koje odgovaraju tvojim odabranim oblastima i lokacijama.', 'Kampanje', 'Pogledaj kampanju', 'Ove poruke primaš jer su uključene obavijesti o kampanjama. Postavke možeš promijeniti ili isključiti na svom pregledu.', 'Uredi obavijesti'],
            'hr' => ['Nove kampanje za tebe', 'Pozdrav,', 'Pogledaj nove kampanje koje odgovaraju tvojim odabranim područjima i lokacijama.', 'Kampanje', 'Pogledaj kampanju', 'Ove poruke primaš jer su uključene obavijesti o kampanjama. Postavke možeš promijeniti ili isključiti na svom pregledu.', 'Uredi obavijesti'],
            'sr' => ['Nove kampanje za tebe', 'Zdravo,', 'Pogledaj nove kampanje koje odgovaraju tvojim odabranim oblastima i lokacijama.', 'Kampanje', 'Pogledaj kampanju', 'Ove poruke primaš jer su uključena obaveštenja o kampanjama. Podešavanja možeš promeniti ili isključiti na svom pregledu.', 'Uredi obaveštenja'],
            'cnr' => ['Nove kampanje za tebe', 'Zdravo,', 'Pogledaj nove kampanje koje odgovaraju tvojim odabranim oblastima i lokacijama.', 'Kampanje', 'Pogledaj kampanju', 'Ove poruke primaš jer su uključena obavještenja o kampanjama. Podešavanja možeš promijeniti ili isključiti na svom pregledu.', 'Uredi obavijesti'],
            'sl' => ['Nove kampanje zate', 'Živjo,', 'Oglej si nove kampanje, ki ustrezajo tvojim izbranim področjem in lokacijam.', 'Kampanje', 'Ogled kampanje', 'Ta sporočila prejemaš, ker imaš vključena obvestila o kampanjah. Nastavitve lahko spremeniš ali izključiš v pregledu.', 'Uredi obvestila'],
            'en' => ['New campaigns for you', 'Hello,', 'Explore new campaigns matching your selected categories and locations.', 'Campaigns', 'View campaign', 'You receive these messages because campaign alerts are enabled. Change or turn them off in your overview.', 'Manage campaign alerts'],
        ];
        return array_combine(['subject', 'greeting', 'message', 'campaigns', 'view', 'footer', 'settings'], $copy[$locale] ?? $copy['bs']);
    }
}
