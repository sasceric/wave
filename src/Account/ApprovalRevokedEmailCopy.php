<?php

namespace App\Account;

final class ApprovalRevokedEmailCopy
{
    public const LAYOUT_KEYS = ['profileTypeTitle', 'profileTypeText'];

    public static function forLocale(string $locale, bool $company = false): array
    {
        $copy = [
            'bs' => [
                'subject' => 'Tvoj Wave profil ponovo čeka odobrenje',
                'preheader' => 'Dopuni fotografiju, opis, društvene mreže i vrstu profila za novi pregled.',
                'eyebrow' => 'DOPUNA PROFILA',
                'heading' => 'Dopunimo tvoju priču.',
                'message' => 'Tvoj račun smo vratili na čekanje odobrenja jer profil još nema sve podatke potrebne za kvalitetno predstavljanje. Profil je privremeno skriven iz javne liste, a sve što je sačuvano ostaje na tvom računu.',
                'expiration' => 'Za ponovno odobrenje dodaj jasnu fotografiju ili logo, biografiju/opis i povezane društvene mreže. Ako si kreator, odaberi i odgovarajuću vrstu kreatora.',
                'security' => 'Prijavi se i dopuni profil. Naš tim će ga ponovo pregledati i javiti ti e-mailom kada bude odobren.',
                'profileTypeTitle' => 'Kako se predstavljaš',
                'creatorTypeText' => 'Odaberi vrstu kreatora koja opisuje tvoj rad, npr. influenser, UGC kreator, fotograf ili videograf. Možeš odabrati više odgovarajućih vrsta.',
                'companyTypeText' => 'Provjeri da je odabrana prava vrsta kompanije: brend ili agencija, kako bi budući partneri razumjeli tvoju ulogu.',
            ],
            'hr' => [
                'subject' => 'Tvoj Wave profil ponovno čeka odobrenje',
                'preheader' => 'Dopuni fotografiju, opis, društvene mreže i vrstu profila za novi pregled.',
                'eyebrow' => 'DOPUNA PROFILA',
                'heading' => 'Dopunimo tvoju priču.',
                'message' => 'Tvoj račun smo vratili na čekanje odobrenja jer profil još nema sve podatke potrebne za kvalitetno predstavljanje. Profil je privremeno skriven iz javne liste, a sve što je spremljeno ostaje na tvom računu.',
                'expiration' => 'Za ponovno odobrenje dodaj jasnu fotografiju ili logotip, biografiju/opis i povezane društvene mreže. Ako si kreator, odaberi i odgovarajuću vrstu kreatora.',
                'security' => 'Prijavi se i dopuni profil. Naš tim će ga ponovno pregledati i javiti ti e-poštom kada bude odobren.',
                'profileTypeTitle' => 'Kako se predstavljaš',
                'creatorTypeText' => 'Odaberi vrstu kreatora koja opisuje tvoj rad, npr. influencer, UGC kreator, fotograf ili videograf. Možeš odabrati više odgovarajućih vrsta.',
                'companyTypeText' => 'Provjeri je li odabrana prava vrsta tvrtke: brend ili agencija, kako bi budući partneri razumjeli tvoju ulogu.',
            ],
            'sr' => [
                'subject' => 'Tvoj Wave profil ponovo čeka odobrenje',
                'preheader' => 'Dopuni fotografiju, opis, društvene mreže i vrstu profila za novi pregled.',
                'eyebrow' => 'DOPUNA PROFILA',
                'heading' => 'Dopunimo tvoju priču.',
                'message' => 'Tvoj nalog smo vratili na čekanje odobrenja jer profil još nema sve podatke potrebne za kvalitetno predstavljanje. Profil je privremeno skriven iz javne liste, a sve što je sačuvano ostaje na tvom nalogu.',
                'expiration' => 'Za ponovno odobrenje dodaj jasnu fotografiju ili logo, biografiju/opis i povezane društvene mreže. Ako si kreator, odaberi i odgovarajuću vrstu kreatora.',
                'security' => 'Prijavi se i dopuni profil. Naš tim će ga ponovo pregledati i javiti ti imejlom kada bude odobren.',
                'profileTypeTitle' => 'Kako se predstavljaš',
                'creatorTypeText' => 'Odaberi vrstu kreatora koja opisuje tvoj rad, npr. influenser, UGC kreator, fotograf ili videograf. Možeš odabrati više odgovarajućih vrsta.',
                'companyTypeText' => 'Proveri da je odabrana prava vrsta kompanije: brend ili agencija, kako bi budući partneri razumeli tvoju ulogu.',
            ],
            'cnr' => [
                'subject' => 'Tvoj Wave profil ponovo čeka odobrenje',
                'preheader' => 'Dopuni fotografiju, opis, društvene mreže i vrstu profila za novi pregled.',
                'eyebrow' => 'DOPUNA PROFILA',
                'heading' => 'Dopunimo tvoju priču.',
                'message' => 'Tvoj nalog smo vratili na čekanje odobrenja jer profil još nema sve podatke potrebne za kvalitetno predstavljanje. Profil je privremeno skriven iz javne liste, a sve što je sačuvano ostaje na tvom nalogu.',
                'expiration' => 'Za ponovno odobrenje dodaj jasnu fotografiju ili logo, biografiju/opis i povezane društvene mreže. Ako si kreator, odaberi i odgovarajuću vrstu kreatora.',
                'security' => 'Prijavi se i dopuni profil. Naš tim će ga ponovo pregledati i javiti ti e-mailom kada bude odobren.',
                'profileTypeTitle' => 'Kako se predstavljaš',
                'creatorTypeText' => 'Odaberi vrstu kreatora koja opisuje tvoj rad, npr. influenser, UGC kreator, fotograf ili videograf. Možeš odabrati više odgovarajućih vrsta.',
                'companyTypeText' => 'Provjeri da je odabrana prava vrsta kompanije: brend ili agencija, kako bi budući partneri razumjeli tvoju ulogu.',
            ],
            'sl' => [
                'subject' => 'Tvoj profil Wave ponovno čaka na odobritev',
                'preheader' => 'Dopolni fotografijo, opis, družbena omrežja in vrsto profila za nov pregled.',
                'eyebrow' => 'DOPOLNITEV PROFILA',
                'heading' => 'Dopolnimo tvojo zgodbo.',
                'message' => 'Tvoj račun smo vrnili v čakanje na odobritev, ker profil še nima vseh podatkov za kakovostno predstavitev. Profil je začasno skrit z javnega seznama, shranjena vsebina pa ostaja v tvojem računu.',
                'expiration' => 'Za ponovno odobritev dodaj jasno fotografijo ali logotip, biografijo/opis in povezana družbena omrežja. Če si ustvarjalec, izberi tudi ustrezno vrsto ustvarjalca.',
                'security' => 'Prijavi se in dopolni profil. Naša ekipa ga bo ponovno pregledala in te obvestila po e-pošti, ko bo odobren.',
                'profileTypeTitle' => 'Kako se predstavljaš',
                'creatorTypeText' => 'Izberi vrsto ustvarjalca, ki opisuje tvoje delo, npr. vplivnež, UGC ustvarjalec, fotograf ali videograf. Izbereš lahko več ustreznih vrst.',
                'companyTypeText' => 'Preveri izbrano vrsto podjetja: znamka ali agencija, da bodo bodoči partnerji razumeli tvojo vlogo.',
            ],
            'en' => [
                'subject' => 'Your Wave profile is awaiting approval again',
                'preheader' => 'Complete your photo, bio, social profiles and profile type for another review.',
                'eyebrow' => 'COMPLETE YOUR PROFILE',
                'heading' => 'Let’s complete your story.',
                'message' => 'We have returned your account to pending approval because your profile still needs the information required for a clear introduction. Your profile is temporarily hidden from public listings, and your saved content stays in your account.',
                'expiration' => 'For approval again, add a clear photo or logo, a bio/description and connected social profiles. Creators should also select the appropriate creator type.',
                'security' => 'Sign in and complete your profile. Our team will review it again and email you when it is approved.',
                'profileTypeTitle' => 'How you introduce yourself',
                'creatorTypeText' => 'Choose the creator types that describe your work, such as influencer, UGC creator, photographer or videographer. You can select more than one relevant type.',
                'companyTypeText' => 'Check your company type: brand or agency, so future partners understand your role.',
            ],
            'de' => [
                'subject' => 'Dein Wave-Profil wartet erneut auf Freigabe',
                'preheader' => 'Ergänze Foto, Beschreibung, soziale Profile und Profiltyp für eine neue Prüfung.',
                'eyebrow' => 'PROFIL VERVOLLSTÄNDIGEN',
                'heading' => 'Vervollständigen wir deine Geschichte.',
                'message' => 'Wir haben dein Konto zurück in die Freigabeprüfung versetzt, weil deinem Profil noch Angaben für eine klare Vorstellung fehlen. Dein Profil ist vorübergehend in öffentlichen Listen ausgeblendet. Gespeicherte Inhalte bleiben in deinem Konto erhalten.',
                'expiration' => 'Für eine erneute Freigabe ergänze Foto oder Logo, Biografie/Beschreibung und verknüpfte soziale Profile. Wähle als Creator auch passende Creator-Typen aus.',
                'security' => 'Melde dich an und ergänze dein Profil. Unser Team prüft es erneut und informiert dich per E-Mail über die Freigabe.',
                'profileTypeTitle' => 'Wie du dich vorstellst',
                'creatorTypeText' => 'Wähle Creator-Typen, die deine Arbeit beschreiben, etwa Influencer, UGC-Creator, Fotograf oder Videograf. Du kannst mehrere passende Typen auswählen.',
                'companyTypeText' => 'Prüfe deinen Unternehmenstyp: Marke oder Agentur, damit künftige Partner deine Rolle verstehen.',
            ],
        ];
        $row = $copy[$locale] ?? $copy['bs'];
        $row['profileTypeText'] = $row[$company ? 'companyTypeText' : 'creatorTypeText'];
        unset($row['creatorTypeText'], $row['companyTypeText']);
        $row['stepsTitle'] = $row['eyebrow'];
        return array_replace(ProfilePreparationEmailCopy::forLocale('pre_approval', $locale), $row);
    }
}
