<?php

namespace App\Account;

/**
 * Copy for the four profile reminder emails (days 1, 4, 10 and 21 after approval).
 * Creators are addressed informally, companies formally, as on the account pages.
 * Placeholders: {score} profile score, {step} title of the most valuable missing step.
 */
final class ProfileReminderEmailCopy
{
    private const COPY = [
        'bs' => [
            'common' => ['eyebrow' => 'SNAGA PROFILA', 'greeting' => 'Zdravo!', 'scoreLabel' => 'Snaga profila', 'stepsLabel' => 'Šta donosi najviše', 'buttonLabel' => 'Otvori Pregled', 'guideLabel' => 'Vodič: kako izgleda dobar profil', 'unsubscribeLabel' => 'Ne želim više savjete za profil', 'settingsLabel' => 'Upravljaj e-mailovima'],
            'creator' => [
                'footer' => 'Dobijaš ovaj e-mail jer imaš račun na Waveu. Poruke, ponude i sigurnosni e-mailovi stižu bez obzira na ovaj izbor.',
                1 => ['Tvoj Wave profil je {score}% gotov', 'Profil je {score}% gotov.', 'Brendovi na Waveu prvo otvore popunjene profile. Ovo su koraci koji donose najviše, a svaki traje par minuta.'],
                2 => ['Jedan korak do boljeg profila', 'Počni od najvažnijeg.', 'Najviše ti sada donosi korak „{step}“. Brendovi to gledaju prije nego što pošalju poziv.'],
                3 => ['Ovako te vide brendovi na Waveu', 'Kako te vide brendovi.', 'Na Pregledu vidiš svoju karticu onako kako je vide brendovi. Crtkano su mjesta koja još fale, a svako popunjeno čini profil uvjerljivijim.'],
                4 => ['Zadnji podsjetnik za tvoj profil', 'Zadnji podsjetnik.', 'Ovo je zadnji e-mail o profilu. Profil je {score}% gotov, a ostatak možeš popuniti kad god želiš na Pregledu.'],
            ],
            'company' => [
                'footer' => 'Dobijate ovaj e-mail jer imate račun na Waveu. Poruke, prijave i sigurnosni e-mailovi stižu bez obzira na ovaj izbor.',
                1 => ['Vaš Wave profil je {score}% gotov', 'Profil je {score}% gotov.', 'Kreatori se radije prijavljuju brendovima s popunjenim profilom. Ovo su koraci koji donose najviše, a svaki traje par minuta.'],
                2 => ['Jedan korak do boljeg profila', 'Počnite od najvažnijeg.', 'Najviše vam sada donosi korak „{step}“. Kreatori to gledaju prije nego što se prijave.'],
                3 => ['Ovako vas vide kreatori na Waveu', 'Kako vas vide kreatori.', 'Na Pregledu vidite svoju karticu onako kako je vide kreatori. Crtkano su mjesta koja još fale, a svako popunjeno čini profil uvjerljivijim.'],
                4 => ['Zadnji podsjetnik za vaš profil', 'Zadnji podsjetnik.', 'Ovo je zadnji e-mail o profilu. Profil je {score}% gotov, a ostatak možete popuniti kad god želite na Pregledu.'],
            ],
        ],
        'hr' => [
            'common' => ['eyebrow' => 'SNAGA PROFILA', 'greeting' => 'Bok!', 'scoreLabel' => 'Snaga profila', 'stepsLabel' => 'Što donosi najviše', 'buttonLabel' => 'Otvori Pregled', 'guideLabel' => 'Vodič: kako izgleda dobar profil', 'unsubscribeLabel' => 'Ne želim više savjete za profil', 'settingsLabel' => 'Upravljaj e-porukama'],
            'creator' => [
                'footer' => 'Primaš ovu e-poruku jer imaš račun na Waveu. Poruke, ponude i sigurnosne e-poruke stižu bez obzira na ovaj izbor.',
                1 => ['Tvoj Wave profil je {score}% gotov', 'Profil je {score}% gotov.', 'Brendovi na Waveu prvo otvore popunjene profile. Ovo su koraci koji donose najviše, a svaki traje nekoliko minuta.'],
                2 => ['Jedan korak do boljeg profila', 'Počni od najvažnijeg.', 'Najviše ti sada donosi korak „{step}“. Brendovi to gledaju prije nego što pošalju poziv.'],
                3 => ['Ovako te vide brendovi na Waveu', 'Kako te vide brendovi.', 'Na Pregledu vidiš svoju karticu onako kako je vide brendovi. Iscrtkano su mjesta koja još nedostaju, a svako popunjeno čini profil uvjerljivijim.'],
                4 => ['Zadnji podsjetnik za tvoj profil', 'Zadnji podsjetnik.', 'Ovo je zadnja e-poruka o profilu. Profil je {score}% gotov, a ostatak možeš popuniti kad god želiš na Pregledu.'],
            ],
            'company' => [
                'footer' => 'Primate ovu e-poruku jer imate račun na Waveu. Poruke, prijave i sigurnosne e-poruke stižu bez obzira na ovaj izbor.',
                1 => ['Vaš Wave profil je {score}% gotov', 'Profil je {score}% gotov.', 'Kreatori se radije prijavljuju brendovima s popunjenim profilom. Ovo su koraci koji donose najviše, a svaki traje nekoliko minuta.'],
                2 => ['Jedan korak do boljeg profila', 'Počnite od najvažnijeg.', 'Najviše vam sada donosi korak „{step}“. Kreatori to gledaju prije nego što se prijave.'],
                3 => ['Ovako vas vide kreatori na Waveu', 'Kako vas vide kreatori.', 'Na Pregledu vidite svoju karticu onako kako je vide kreatori. Iscrtkano su mjesta koja još nedostaju, a svako popunjeno čini profil uvjerljivijim.'],
                4 => ['Zadnji podsjetnik za vaš profil', 'Zadnji podsjetnik.', 'Ovo je zadnja e-poruka o profilu. Profil je {score}% gotov, a ostatak možete popuniti kad god želite na Pregledu.'],
            ],
        ],
        'sr' => [
            'common' => ['eyebrow' => 'SNAGA PROFILA', 'greeting' => 'Zdravo!', 'scoreLabel' => 'Snaga profila', 'stepsLabel' => 'Šta donosi najviše', 'buttonLabel' => 'Otvori Pregled', 'guideLabel' => 'Vodič: kako izgleda dobar profil', 'unsubscribeLabel' => 'Ne želim više savete za profil', 'settingsLabel' => 'Upravljaj imejlovima'],
            'creator' => [
                'footer' => 'Dobijaš ovaj imejl jer imaš nalog na Waveu. Poruke, ponude i bezbednosni imejlovi stižu bez obzira na ovaj izbor.',
                1 => ['Tvoj Wave profil je {score}% gotov', 'Profil je {score}% gotov.', 'Brendovi na Waveu prvo otvore popunjene profile. Ovo su koraci koji donose najviše, a svaki traje par minuta.'],
                2 => ['Jedan korak do boljeg profila', 'Počni od najvažnijeg.', 'Najviše ti sada donosi korak „{step}“. Brendovi to gledaju pre nego što pošalju poziv.'],
                3 => ['Ovako te vide brendovi na Waveu', 'Kako te vide brendovi.', 'Na Pregledu vidiš svoju karticu onako kako je vide brendovi. Isprekidano su mesta koja još fale, a svako popunjeno čini profil ubedljivijim.'],
                4 => ['Poslednji podsetnik za tvoj profil', 'Poslednji podsetnik.', 'Ovo je poslednji imejl o profilu. Profil je {score}% gotov, a ostatak možeš da popuniš kad god želiš na Pregledu.'],
            ],
            'company' => [
                'footer' => 'Dobijate ovaj imejl jer imate nalog na Waveu. Poruke, prijave i bezbednosni imejlovi stižu bez obzira na ovaj izbor.',
                1 => ['Vaš Wave profil je {score}% gotov', 'Profil je {score}% gotov.', 'Kreatori se radije prijavljuju brendovima sa popunjenim profilom. Ovo su koraci koji donose najviše, a svaki traje par minuta.'],
                2 => ['Jedan korak do boljeg profila', 'Počnite od najvažnijeg.', 'Najviše vam sada donosi korak „{step}“. Kreatori to gledaju pre nego što se prijave.'],
                3 => ['Ovako vas vide kreatori na Waveu', 'Kako vas vide kreatori.', 'Na Pregledu vidite svoju karticu onako kako je vide kreatori. Isprekidano su mesta koja još fale, a svako popunjeno čini profil ubedljivijim.'],
                4 => ['Poslednji podsetnik za vaš profil', 'Poslednji podsetnik.', 'Ovo je poslednji imejl o profilu. Profil je {score}% gotov, a ostatak možete da popunite kad god želite na Pregledu.'],
            ],
        ],
        'cnr' => [
            'common' => ['eyebrow' => 'SNAGA PROFILA', 'greeting' => 'Zdravo!', 'scoreLabel' => 'Snaga profila', 'stepsLabel' => 'Šta donosi najviše', 'buttonLabel' => 'Otvori Pregled', 'guideLabel' => 'Vodič: kako izgleda dobar profil', 'unsubscribeLabel' => 'Ne želim više savjete za profil', 'settingsLabel' => 'Upravljaj e-mailovima'],
            'creator' => [
                'footer' => 'Dobijaš ovaj e-mail jer imaš nalog na Waveu. Poruke, ponude i bezbjednosni e-mailovi stižu bez obzira na ovaj izbor.',
                1 => ['Tvoj Wave profil je {score}% gotov', 'Profil je {score}% gotov.', 'Brendovi na Waveu prvo otvore popunjene profile. Ovo su koraci koji donose najviše, a svaki traje par minuta.'],
                2 => ['Jedan korak do boljeg profila', 'Počni od najvažnijeg.', 'Najviše ti sada donosi korak „{step}“. Brendovi to gledaju prije nego što pošalju poziv.'],
                3 => ['Ovako te vide brendovi na Waveu', 'Kako te vide brendovi.', 'Na Pregledu vidiš svoju karticu onako kako je vide brendovi. Crtkano su mjesta koja još fale, a svako popunjeno čini profil uvjerljivijim.'],
                4 => ['Posljednji podsjetnik za tvoj profil', 'Posljednji podsjetnik.', 'Ovo je posljednji e-mail o profilu. Profil je {score}% gotov, a ostatak možeš popuniti kad god želiš na Pregledu.'],
            ],
            'company' => [
                'footer' => 'Dobijate ovaj e-mail jer imate nalog na Waveu. Poruke, prijave i bezbjednosni e-mailovi stižu bez obzira na ovaj izbor.',
                1 => ['Vaš Wave profil je {score}% gotov', 'Profil je {score}% gotov.', 'Kreatori se radije prijavljuju brendovima s popunjenim profilom. Ovo su koraci koji donose najviše, a svaki traje par minuta.'],
                2 => ['Jedan korak do boljeg profila', 'Počnite od najvažnijeg.', 'Najviše vam sada donosi korak „{step}“. Kreatori to gledaju prije nego što se prijave.'],
                3 => ['Ovako vas vide kreatori na Waveu', 'Kako vas vide kreatori.', 'Na Pregledu vidite svoju karticu onako kako je vide kreatori. Crtkano su mjesta koja još fale, a svako popunjeno čini profil uvjerljivijim.'],
                4 => ['Posljednji podsjetnik za vaš profil', 'Posljednji podsjetnik.', 'Ovo je posljednji e-mail o profilu. Profil je {score}% gotov, a ostatak možete popuniti kad god želite na Pregledu.'],
            ],
        ],
        'sl' => [
            'common' => ['eyebrow' => 'MOČ PROFILA', 'greeting' => 'Pozdravljeni!', 'scoreLabel' => 'Moč profila', 'stepsLabel' => 'Kaj prinese največ', 'buttonLabel' => 'Odpri Pregled', 'guideLabel' => 'Vodnik: kako je videti dober profil', 'unsubscribeLabel' => 'Ne želim več nasvetov za profil', 'settingsLabel' => 'Nastavitve e-pošte'],
            'creator' => [
                'footer' => 'To sporočilo prejemaš, ker imaš račun na Wavu. Sporočila, ponudbe in varnostna e-pošta prihajajo ne glede na to izbiro.',
                1 => ['Tvoj profil na Wavu je {score} % dokončan', 'Profil je {score} % dokončan.', 'Znamke na Wavu najprej odprejo izpolnjene profile. To so koraki, ki prinesejo največ, vsak pa vzame le nekaj minut.'],
                2 => ['En korak do boljšega profila', 'Začni z najpomembnejšim.', 'Največ ti zdaj prinese korak »{step}«. Znamke ga pogledajo, preden pošljejo povabilo.'],
                3 => ['Tako te vidijo znamke na Wavu', 'Kako te vidijo znamke.', 'Na Pregledu vidiš svojo kartico tako, kot jo vidijo znamke. Črtkano so mesta, ki še manjkajo, in vsako izpolnjeno naredi profil bolj prepričljiv.'],
                4 => ['Zadnji opomnik za tvoj profil', 'Zadnji opomnik.', 'To je zadnje sporočilo o profilu. Profil je {score} % dokončan, ostalo lahko izpolniš kadar koli na Pregledu.'],
            ],
            'company' => [
                'footer' => 'To sporočilo prejemate, ker imate račun na Wavu. Sporočila, prijave in varnostna e-pošta prihajajo ne glede na to izbiro.',
                1 => ['Vaš profil na Wavu je {score} % dokončan', 'Profil je {score} % dokončan.', 'Ustvarjalci se raje prijavijo znamkam z izpolnjenim profilom. To so koraki, ki prinesejo največ, vsak pa vzame le nekaj minut.'],
                2 => ['En korak do boljšega profila', 'Začnite z najpomembnejšim.', 'Največ vam zdaj prinese korak »{step}«. Ustvarjalci ga pogledajo, preden se prijavijo.'],
                3 => ['Tako vas vidijo ustvarjalci na Wavu', 'Kako vas vidijo ustvarjalci.', 'Na Pregledu vidite svojo kartico tako, kot jo vidijo ustvarjalci. Črtkano so mesta, ki še manjkajo, in vsako izpolnjeno naredi profil bolj prepričljiv.'],
                4 => ['Zadnji opomnik za vaš profil', 'Zadnji opomnik.', 'To je zadnje sporočilo o profilu. Profil je {score} % dokončan, ostalo lahko izpolnite kadar koli na Pregledu.'],
            ],
        ],
        'en' => [
            'common' => ['eyebrow' => 'PROFILE STRENGTH', 'greeting' => 'Hello!', 'scoreLabel' => 'Profile strength', 'stepsLabel' => 'What helps most', 'buttonLabel' => 'Open Overview', 'guideLabel' => 'Guide: what a strong profile looks like', 'unsubscribeLabel' => 'Stop profile tips', 'settingsLabel' => 'Manage emails'],
            'creator' => [
                'footer' => 'You are receiving this because you have a Wave account. Messages, offers and security emails arrive regardless of this choice.',
                1 => ['Your Wave profile is {score}% complete', 'Your profile is {score}% complete.', 'Brands on Wave open complete profiles first. These are the steps that help most, and each takes a few minutes.'],
                2 => ['One step to a better profile', 'Start with what matters most.', 'Right now, “{step}” helps you most. Brands check it before they send an invitation.'],
                3 => ['This is how brands see you on Wave', 'How brands see you.', 'Your Overview shows your card the way brands see it. Dashed spots are still missing, and each one you fill makes your profile more convincing.'],
                4 => ['A last reminder about your profile', 'A last reminder.', 'This is the last email about your profile. It is {score}% complete, and you can finish the rest from your Overview whenever you like.'],
            ],
            'company' => [
                'footer' => 'You are receiving this because you have a Wave account. Messages, applications and security emails arrive regardless of this choice.',
                1 => ['Your Wave profile is {score}% complete', 'Your profile is {score}% complete.', 'Creators prefer to apply to brands with a complete profile. These are the steps that help most, and each takes a few minutes.'],
                2 => ['One step to a better profile', 'Start with what matters most.', 'Right now, “{step}” helps you most. Creators check it before they apply.'],
                3 => ['This is how creators see you on Wave', 'How creators see you.', 'Your Overview shows your card the way creators see it. Dashed spots are still missing, and each one you fill makes your profile more convincing.'],
                4 => ['A last reminder about your profile', 'A last reminder.', 'This is the last email about your profile. It is {score}% complete, and you can finish the rest from your Overview whenever you like.'],
            ],
        ],
        'de' => [
            'common' => ['eyebrow' => 'PROFILSTÄRKE', 'greeting' => 'Hallo!', 'scoreLabel' => 'Profilstärke', 'stepsLabel' => 'Was am meisten bringt', 'buttonLabel' => 'Übersicht öffnen', 'guideLabel' => 'Leitfaden: So sieht ein starkes Profil aus', 'unsubscribeLabel' => 'Keine Profiltipps mehr', 'settingsLabel' => 'E-Mails verwalten'],
            'creator' => [
                'footer' => 'Du erhältst diese E-Mail, weil du ein Wave-Konto hast. Nachrichten, Angebote und Sicherheits-E-Mails kommen unabhängig von dieser Auswahl.',
                1 => ['Dein Wave-Profil ist zu {score} % fertig', 'Dein Profil ist zu {score} % fertig.', 'Marken auf Wave öffnen vollständige Profile zuerst. Diese Schritte bringen am meisten, und jeder dauert nur ein paar Minuten.'],
                2 => ['Ein Schritt zu einem besseren Profil', 'Fang mit dem Wichtigsten an.', 'Am meisten bringt dir jetzt „{step}“. Marken schauen sich das an, bevor sie eine Einladung senden.'],
                3 => ['So sehen dich Marken auf Wave', 'So sehen dich Marken.', 'In deiner Übersicht siehst du deine Karte so, wie Marken sie sehen. Gestrichelt sind die Stellen, die noch fehlen, und jede ausgefüllte macht dein Profil überzeugender.'],
                4 => ['Eine letzte Erinnerung zu deinem Profil', 'Eine letzte Erinnerung.', 'Das ist die letzte E-Mail zu deinem Profil. Es ist zu {score} % fertig, den Rest kannst du jederzeit in deiner Übersicht ergänzen.'],
            ],
            'company' => [
                'footer' => 'Sie erhalten diese E-Mail, weil Sie ein Wave-Konto haben. Nachrichten, Bewerbungen und Sicherheits-E-Mails kommen unabhängig von dieser Auswahl.',
                1 => ['Ihr Wave-Profil ist zu {score} % fertig', 'Ihr Profil ist zu {score} % fertig.', 'Creator bewerben sich lieber bei Marken mit vollständigem Profil. Diese Schritte bringen am meisten, und jeder dauert nur ein paar Minuten.'],
                2 => ['Ein Schritt zu einem besseren Profil', 'Beginnen Sie mit dem Wichtigsten.', 'Am meisten bringt Ihnen jetzt „{step}“. Creator schauen sich das an, bevor sie sich bewerben.'],
                3 => ['So sehen Creator Ihr Profil auf Wave', 'So sehen Creator Ihr Profil.', 'In Ihrer Übersicht sehen Sie Ihre Karte so, wie Creator sie sehen. Gestrichelt sind die Stellen, die noch fehlen, und jede ausgefüllte macht Ihr Profil überzeugender.'],
                4 => ['Eine letzte Erinnerung zu Ihrem Profil', 'Eine letzte Erinnerung.', 'Das ist die letzte E-Mail zu Ihrem Profil. Es ist zu {score} % fertig, den Rest können Sie jederzeit in Ihrer Übersicht ergänzen.'],
            ],
        ],
    ];

    /**
     * @return array<string, string>
     */
    public static function forStage(string $locale, string $audience, int $stage, int|string $score, string $firstStep): array
    {
        $copy = self::COPY[$locale] ?? self::COPY['bs'];
        $group = $copy[$audience === 'creator' ? 'creator' : 'company'];
        [$subject, $heading, $message] = $group[max(1, min(4, $stage))];
        $values = ['{score}' => (string) $score, '{step}' => $firstStep];
        $message = strtr($message, $values);

        return $copy['common'] + [
            'subject' => strtr($subject, $values),
            'heading' => strtr($heading, $values),
            'message' => $message,
            'preheader' => $message,
            'footer' => $group['footer'],
        ];
    }

    /** @return list<string> */
    public static function locales(): array
    {
        return array_keys(self::COPY);
    }
}
