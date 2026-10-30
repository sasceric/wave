<?php

namespace App\Account;

final class RegistrationReminderEmailCopy
{
    /** @return array<string, string> */
    public static function forLocale(string $template, string $locale): array
    {
        if (in_array($template, ['profile_tip', 'pre_approval'], true)) return ProfilePreparationEmailCopy::forLocale($template, $locale);
        $copy = [
            'bs' => [
                'verification_reminder' => [
                    'subject' => 'Podsjetnik: potvrdi e-mail adresu za Wave',
                    'heading' => 'Potvrdi e-mail adresu.',
                    'message' => 'Tvoj Wave račun još čeka potvrdu e-mail adrese. Klikni na dugme ispod kako bismo mogli nastaviti s pregledom registracije.',
                    'preheader' => 'Potvrdi e-mail adresu.',
                ],
            ],
            'hr' => [
                'verification_reminder' => [
                    'subject' => 'Podsjetnik: potvrdi adresu e-pošte za Wave',
                    'heading' => 'Potvrdi adresu e-pošte.',
                    'message' => 'Tvoj Wave račun još čeka potvrdu adrese e-pošte. Klikni na gumb ispod kako bismo mogli nastaviti s pregledom registracije.',
                    'preheader' => 'Potvrdi adresu e-pošte.',
                ],
            ],
            'sr' => [
                'verification_reminder' => [
                    'subject' => 'Podsetnik: potvrdi e-mail adresu za Wave',
                    'heading' => 'Potvrdi e-mail adresu.',
                    'message' => 'Tvoj Wave nalog još čeka potvrdu e-mail adrese. Klikni na dugme ispod kako bismo mogli da nastavimo sa pregledom registracije.',
                    'preheader' => 'Potvrdi e-mail adresu.',
                ],
            ],
            'cnr' => [
                'verification_reminder' => [
                    'subject' => 'Podsjetnik: potvrdi e-mail adresu za Wave',
                    'heading' => 'Potvrdi e-mail adresu.',
                    'message' => 'Tvoj Wave nalog još čeka potvrdu e-mail adrese. Klikni na dugme ispod kako bismo mogli da nastavimo s pregledom registracije.',
                    'preheader' => 'Potvrdi e-mail adresu.',
                ],
            ],
            'sl' => [
                'verification_reminder' => [
                    'subject' => 'Opomnik: potrdi e-poštni naslov za Wave',
                    'heading' => 'Potrdi e-poštni naslov.',
                    'message' => 'Tvoj račun Wave še čaka na potrditev e-poštnega naslova. Klikni spodnji gumb, da lahko nadaljujemo pregled registracije.',
                    'preheader' => 'Potrdi e-poštni naslov.',
                ],
            ],
            'en' => [
                'verification_reminder' => [
                    'subject' => 'Reminder: verify your Wave email address',
                    'heading' => 'Confirm your email address.',
                    'message' => 'Your Wave account is still waiting for email verification. Click the button below so we can continue reviewing your registration.',
                    'preheader' => 'Confirm your email address.',
                ],
            ],
            'de' => [
                'verification_reminder' => [
                    'subject' => 'Erinnerung: Bestätige deine Wave-E-Mail-Adresse',
                    'heading' => 'Bestätige deine E-Mail-Adresse.',
                    'message' => 'Dein Wave-Konto wartet noch auf die Bestätigung deiner E-Mail-Adresse. Klicke unten, damit wir deine Registrierung weiter prüfen können.',
                    'preheader' => 'Bestätige deine E-Mail-Adresse.',
                ],
            ],
        ];

        return $copy[$locale][$template] ?? $copy['bs'][$template];
    }
}
