<?php

namespace App\Account;

use App\Entity\EmailTemplate;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class AccountEmailSender
{
    private const COPY = [
        'bs' => [
            'verify' => [
                'subject' => 'Registracija za Wave je zaprimljena',
                'preheader' => 'Potvrdi adresu i počni koristiti sve mogućnosti Wavea.',
                'eyebrow' => 'JOŠ SAMO JEDAN KORAK',
                'heading' => 'Potvrdi e-mail adresu.',
                'greeting' => 'Zdravo!',
                'message' => 'Tvoja registracija je zaprimljena. Potvrdi e-mail adresu. Nakon potvrde, račun će čekati odobrenje našeg tima, a obavijestit ćemo te čim bude odobren.',
                'buttonLabel' => 'Potvrdi e-mail adresu',
                'expiration' => 'Sigurna veza važi 24 sata.',
                'security' => 'Ako nisi otvorio/la Wave račun, zanemari ovu poruku.',
                'fallback' => 'Ako se dugme ne otvori, kopiraj ovu adresu u preglednik:',
                'footer' => 'Automatska poruka od Wavea. Molimo ne odgovaraj na ovu e-poruku.',
            ],
            'reset' => [
                'subject' => 'Promijeni lozinku za Wave',
                'preheader' => 'Postavi novu lozinku na siguran način.',
                'eyebrow' => 'SIGURNOST RAČUNA',
                'heading' => 'Zahtjev za promjenu lozinke.',
                'greeting' => 'Zdravo!',
                'message' => 'Primili smo zahtjev za promjenu lozinke povezane s tvojim Wave računom.',
                'buttonLabel' => 'Postavi novu lozinku',
                'expiration' => 'Sigurna veza važi jedan sat.',
                'security' => 'Ako nisi zatražio/la promjenu, zanemari ovu poruku. Lozinka se neće promijeniti.',
                'fallback' => 'Ako se dugme ne otvori, kopiraj ovu adresu u preglednik:',
                'footer' => 'Automatska poruka od Wavea. Molimo ne odgovaraj na ovu e-poruku.',
            ],
        ],
        'cnr' => [
            'verify' => [
                'subject' => 'Registracija za Wave je primljena',
                'preheader' => 'Potvrdi adresu i počni da koristiš sve mogućnosti Wavea.',
                'eyebrow' => 'JOŠ SAMO JEDAN KORAK',
                'heading' => 'Potvrdi e-mail adresu.',
                'greeting' => 'Zdravo!',
                'message' => 'Tvoja registracija je primljena. Potvrdi e-mail adresu. Nakon potvrde, nalog će čekati odobrenje našeg tima, a obavijestićemo te čim bude odobren.',
                'buttonLabel' => 'Potvrdi e-mail adresu',
                'expiration' => 'Sigurna veza važi 24 sata.',
                'security' => 'Ako nijesi otvorio/la Wave nalog, zanemari ovu poruku.',
                'fallback' => 'Ako se dugme ne otvori, kopiraj ovu adresu u pregledač:',
                'footer' => 'Automatska poruka od Wavea. Molimo te, ne odgovaraj na ovu e-poruku.',
            ],
            'reset' => [
                'subject' => 'Promijeni lozinku za Wave',
                'preheader' => 'Postavi novu lozinku na siguran način.',
                'eyebrow' => 'SIGURNOST NALOGA',
                'heading' => 'Zatražena je promjena lozinke.',
                'greeting' => 'Zdravo!',
                'message' => 'Primili smo zahtjev za promjenu lozinke povezane s tvojim Wave nalogom.',
                'buttonLabel' => 'Postavi novu lozinku',
                'expiration' => 'Sigurna veza važi jedan sat.',
                'security' => 'Ako nijesi zatražio/la promjenu, zanemari ovu poruku. Lozinka neće biti promijenjena.',
                'fallback' => 'Ako se dugme ne otvori, kopiraj ovu adresu u pregledač:',
                'footer' => 'Automatska poruka od Wavea. Molimo te, ne odgovaraj na ovu e-poruku.',
            ],
        ],
        'hr' => [
            'verify' => [
                'subject' => 'Registracija za Wave je zaprimljena',
                'preheader' => 'Potvrdi adresu i počni koristiti sve mogućnosti Wavea.',
                'eyebrow' => 'JOŠ SAMO JEDAN KORAK',
                'heading' => 'Potvrdi svoju adresu e-pošte.',
                'greeting' => 'Pozdrav!',
                'message' => 'Tvoja je registracija zaprimljena. Potvrdi adresu e-pošte. Nakon potvrde, račun će čekati odobrenje našeg tima, a obavijestit ćemo te čim bude odobren.',
                'buttonLabel' => 'Potvrdi adresu e-pošte',
                'expiration' => 'Sigurna poveznica vrijedi 24 sata.',
                'security' => 'Ako nisi otvorio/la Wave račun, zanemari ovu poruku.',
                'fallback' => 'Ako se gumb ne otvori, kopiraj ovu adresu u preglednik:',
                'footer' => 'Automatska poruka od Wavea. Molimo ne odgovaraj na ovu poruku.',
            ],
            'reset' => [
                'subject' => 'Promijeni lozinku za Wave',
                'preheader' => 'Postavi novu lozinku na siguran način.',
                'eyebrow' => 'SIGURNOST RAČUNA',
                'heading' => 'Zahtjev za promjenu lozinke.',
                'greeting' => 'Pozdrav!',
                'message' => 'Primili smo zahtjev za promjenu lozinke povezane s tvojim Wave računom.',
                'buttonLabel' => 'Postavi novu lozinku',
                'expiration' => 'Sigurna poveznica vrijedi jedan sat.',
                'security' => 'Ako nisi zatražio/la promjenu, zanemari ovu poruku. Lozinka se neće promijeniti.',
                'fallback' => 'Ako se gumb ne otvori, kopiraj ovu adresu u preglednik:',
                'footer' => 'Automatska poruka od Wavea. Molimo ne odgovaraj na ovu poruku.',
            ],
        ],
        'sr' => [
            'verify' => [
                'subject' => 'Registracija za Wave je primljena',
                'preheader' => 'Potvrdi adresu i počni da koristiš sve mogućnosti Wavea.',
                'eyebrow' => 'JOŠ SAMO JEDAN KORAK',
                'heading' => 'Potvrdi svoju e-mail adresu.',
                'greeting' => 'Zdravo!',
                'message' => 'Tvoja registracija je primljena. Potvrdi e-mail adresu. Nakon potvrde, nalog će čekati odobrenje našeg tima, a obavestićemo te čim bude odobren.',
                'buttonLabel' => 'Potvrdi e-mail adresu',
                'expiration' => 'Sigurna veza važi 24 sata.',
                'security' => 'Ako nisi otvorio/la Wave nalog, zanemari ovu poruku.',
                'fallback' => 'Ako se dugme ne otvori, kopiraj ovu adresu u pregledač:',
                'footer' => 'Automatska poruka od Wavea. Molimo te, ne odgovaraj na ovu e-poruku.',
            ],
            'reset' => [
                'subject' => 'Promeni lozinku za Wave',
                'preheader' => 'Postavi novu lozinku na siguran način.',
                'eyebrow' => 'BEZBEDNOST NALOGA',
                'heading' => 'Zahtev za promenu lozinke.',
                'greeting' => 'Zdravo!',
                'message' => 'Primili smo zahtev za promenu lozinke povezane sa tvojim Wave nalogom.',
                'buttonLabel' => 'Postavi novu lozinku',
                'expiration' => 'Sigurna veza važi jedan sat.',
                'security' => 'Ako nisi zatražio/la promenu, zanemari ovu poruku. Lozinka neće biti promenjena.',
                'fallback' => 'Ako se dugme ne otvori, kopiraj ovu adresu u pregledač:',
                'footer' => 'Automatska poruka od Wavea. Molimo te, ne odgovaraj na ovu e-poruku.',
            ],
        ],
        'sl' => [
            'verify' => [
                'subject' => 'Registracija v Wave je prejeta',
                'preheader' => 'Potrdi naslov in začni uporabljati vse možnosti Wave.',
                'eyebrow' => 'SAMO ŠE EN KORAK',
                'heading' => 'Potrdi svoj e-poštni naslov.',
                'greeting' => 'Pozdravljeni!',
                'message' => 'Tvoja registracija je prejeta. Potrdi svoj e-poštni naslov. Po potrditvi bo račun čakal na odobritev naše ekipe, o odobritvi pa te bomo obvestili po e-pošti.',
                'buttonLabel' => 'Potrdi e-poštni naslov',
                'expiration' => 'Varna povezava velja 24 ur.',
                'security' => 'Če računa Wave nisi ustvaril/a, lahko to sporočilo prezreš.',
                'fallback' => 'Če se gumb ne odpre, kopiraj ta naslov v brskalnik:',
                'footer' => 'To je samodejno sporočilo Wave. Prosimo, ne odgovarjaj nanj.',
            ],
            'reset' => [
                'subject' => 'Spremeni geslo za Wave',
                'preheader' => 'Varno nastavi novo geslo.',
                'eyebrow' => 'VARNOST RAČUNA',
                'heading' => 'Zahteva za spremembo gesla.',
                'greeting' => 'Pozdravljeni!',
                'message' => 'Prejeli smo zahtevo za spremembo gesla, povezanega s tvojim računom Wave.',
                'buttonLabel' => 'Nastavi novo geslo',
                'expiration' => 'Varna povezava velja eno uro.',
                'security' => 'Če zahteve nisi poslal/a ti, prezri to sporočilo. Geslo ne bo spremenjeno.',
                'fallback' => 'Če se gumb ne odpre, kopiraj ta naslov v brskalnik:',
                'footer' => 'To je samodejno sporočilo Wave. Prosimo, ne odgovarjaj nanj.',
            ],
        ],
        'en' => [
            'verify' => [
                'subject' => 'Your Wave registration has been received',
                'preheader' => 'Verify your address to unlock your Wave account.',
                'eyebrow' => 'ONE QUICK STEP',
                'heading' => 'Confirm your email address.',
                'greeting' => 'Hello!',
                'message' => 'Your registration has been received. Confirm your email address. Once confirmed, your account will wait for our team to approve it, and we will email you as soon as it is approved.',
                'buttonLabel' => 'Verify email address',
                'expiration' => 'This secure link expires in 24 hours.',
                'security' => 'If you did not create a Wave account, you can ignore this message.',
                'fallback' => 'If the button does not work, copy this address into your browser:',
                'footer' => 'This is an automated message from Wave. Please do not reply.',
            ],
            'reset' => [
                'subject' => 'Reset your Wave password',
                'preheader' => 'Set a new password for your account securely.',
                'eyebrow' => 'ACCOUNT SECURITY',
                'heading' => 'Password reset requested.',
                'greeting' => 'Hello!',
                'message' => 'We received a request to change the password for your Wave account.',
                'buttonLabel' => 'Set a new password',
                'expiration' => 'This secure link expires in one hour.',
                'security' => 'If you did not request this change, you can ignore this message. Your password will not change.',
                'fallback' => 'If the button does not work, copy this address into your browser:',
                'footer' => 'This is an automated message from Wave. Please do not reply.',
            ],
        ],
    ];

    private const NOTIFICATION_COPY = [
        'bs' => [
            'registered' => [
                'subject' => 'Tvoja Wave registracija čeka pregled',
                'preheader' => 'Registracija je primljena i čeka odobrenje.',
                'eyebrow' => 'REGISTRACIJA ZAPRIMLJENA',
                'heading' => 'Tvoj račun čeka odobrenje.',
                'greeting' => 'Zdravo!',
                'message' => 'Tvoja e-mail adresa je potvrđena, a registracija je zaprimljena. Naš tim će pregledati račun i poslati ti e-mail čim bude odobren.',
                'buttonLabel' => 'Otvori Wave',
                'expiration' => 'Nije potrebna dodatna radnja dok čekaš pregled.',
                'security' => 'Možeš se prijaviti i urediti svoj profil dok je račun na čekanju.',
                'fallback' => 'Ako se dugme ne otvori, kopiraj ovu adresu u preglednik:',
                'footer' => 'Automatska poruka od Wavea. Molimo ne odgovaraj na ovu e-poruku.',
            ],
            'approval' => [
                'subject' => 'Tvoj Wave račun je odobren',
                'preheader' => 'Možeš početi koristiti Wave.',
                'eyebrow' => 'RAČUN JE ODOBREN',
                'heading' => 'Dobro došao/la u Wave.',
                'greeting' => 'Zdravo!',
                'message' => 'Tvoj Wave račun je odobren. Prijavi se i nastavi uređivati profil, istraživati kampanje i povezivati se s brendovima.',
                'buttonLabel' => 'Otvori svoj račun',
                'expiration' => 'Odobrenje je aktivno i možeš odmah početi.',
                'security' => 'Ako nisi očekivao/la ovu poruku, kontaktiraj Wave podršku.',
                'fallback' => 'Ako se dugme ne otvori, kopiraj ovu adresu u preglednik:',
                'footer' => 'Automatska poruka od Wavea. Molimo ne odgovaraj na ovu e-poruku.',
            ],
        ],
        'hr' => [
            'registered' => [
                'subject' => 'Tvoja Wave registracija čeka pregled',
                'preheader' => 'Registracija je zaprimljena i čeka odobrenje.',
                'eyebrow' => 'REGISTRACIJA ZAPRIMLJENA',
                'heading' => 'Tvoj račun čeka odobrenje.',
                'greeting' => 'Pozdrav!',
                'message' => 'Tvoja je adresa e-pošte potvrđena, a registracija je zaprimljena. Naš će tim pregledati račun i poslati ti e-poštu čim bude odobren.',
                'buttonLabel' => 'Otvori Wave',
                'expiration' => 'Nije potrebna dodatna radnja dok čekaš pregled.',
                'security' => 'Možeš se prijaviti i urediti profil dok je račun na čekanju.',
                'fallback' => 'Ako se gumb ne otvori, kopiraj ovu adresu u preglednik:',
                'footer' => 'Automatska poruka od Wavea. Molimo ne odgovaraj na ovu poruku.',
            ],
            'approval' => [
                'subject' => 'Tvoj Wave račun je odobren',
                'preheader' => 'Možeš početi koristiti Wave.',
                'eyebrow' => 'RAČUN JE ODOBREN',
                'heading' => 'Dobro došao/la u Wave.',
                'greeting' => 'Pozdrav!',
                'message' => 'Tvoj Wave račun je odobren. Prijavi se i nastavi uređivati profil, istraživati kampanje i povezivati se s brendovima.',
                'buttonLabel' => 'Otvori svoj račun',
                'expiration' => 'Odobrenje je aktivno i možeš odmah početi.',
                'security' => 'Ako nisi očekivao/la ovu poruku, obrati se Wave podršci.',
                'fallback' => 'Ako se gumb ne otvori, kopiraj ovu adresu u preglednik:',
                'footer' => 'Automatska poruka od Wavea. Molimo ne odgovaraj na ovu poruku.',
            ],
        ],
        'sr' => [
            'registered' => [
                'subject' => 'Tvoja Wave registracija čeka pregled',
                'preheader' => 'Registracija je primljena i čeka odobrenje.',
                'eyebrow' => 'REGISTRACIJA PRIMLJENA',
                'heading' => 'Tvoj nalog čeka odobrenje.',
                'greeting' => 'Zdravo!',
                'message' => 'Tvoja e-mail adresa je potvrđena, a registracija je primljena. Naš tim će pregledati nalog i poslati ti e-mail čim bude odobren.',
                'buttonLabel' => 'Otvori Wave',
                'expiration' => 'Nije potrebna dodatna radnja dok čekaš pregled.',
                'security' => 'Možeš da se prijaviš i urediš profil dok je nalog na čekanju.',
                'fallback' => 'Ako se dugme ne otvori, kopiraj ovu adresu u pregledač:',
                'footer' => 'Automatska poruka od Wavea. Molimo te, ne odgovaraj na ovu e-poruku.',
            ],
            'approval' => [
                'subject' => 'Tvoj Wave nalog je odobren',
                'preheader' => 'Možeš da počneš da koristiš Wave.',
                'eyebrow' => 'NALOG JE ODOBREN',
                'heading' => 'Dobro došao/la u Wave.',
                'greeting' => 'Zdravo!',
                'message' => 'Tvoj Wave nalog je odobren. Prijavi se i nastavi da uređuješ profil, istražuješ kampanje i povezuješ se s brendovima.',
                'buttonLabel' => 'Otvori svoj nalog',
                'expiration' => 'Odobrenje je aktivno i možeš odmah da počneš.',
                'security' => 'Ako nijesi očekivao/la ovu poruku, kontaktiraj Wave podršku.',
                'fallback' => 'Ako se dugme ne otvori, kopiraj ovu adresu u pregledač:',
                'footer' => 'Automatska poruka od Wavea. Molimo te, ne odgovaraj na ovu e-poruku.',
            ],
        ],
        'cnr' => [
            'registered' => [
                'subject' => 'Tvoja Wave registracija čeka pregled',
                'preheader' => 'Registracija je primljena i čeka odobrenje.',
                'eyebrow' => 'REGISTRACIJA PRIMLJENA',
                'heading' => 'Tvoj nalog čeka odobrenje.',
                'greeting' => 'Zdravo!',
                'message' => 'Tvoja e-mail adresa je potvrđena, a registracija je primljena. Naš tim će pregledati nalog i poslati ti e-mail čim bude odobren.',
                'buttonLabel' => 'Otvori Wave',
                'expiration' => 'Nije potrebna dodatna radnja dok čekaš pregled.',
                'security' => 'Možeš da se prijaviš i urediš profil dok je nalog na čekanju.',
                'fallback' => 'Ako se dugme ne otvori, kopiraj ovu adresu u pregledač:',
                'footer' => 'Automatska poruka od Wavea. Molimo te, ne odgovaraj na ovu e-poruku.',
            ],
            'approval' => [
                'subject' => 'Tvoj Wave nalog je odobren',
                'preheader' => 'Možeš da počneš da koristiš Wave.',
                'eyebrow' => 'NALOG JE ODOBREN',
                'heading' => 'Dobro došao/la u Wave.',
                'greeting' => 'Zdravo!',
                'message' => 'Tvoj Wave nalog je odobren. Prijavi se i nastavi da uređuješ profil, istražuješ kampanje i povezuješ se s brendovima.',
                'buttonLabel' => 'Otvori svoj nalog',
                'expiration' => 'Odobrenje je aktivno i možeš odmah da počneš.',
                'security' => 'Ako nijesi očekivao/la ovu poruku, kontaktiraj Wave podršku.',
                'fallback' => 'Ako se dugme ne otvori, kopiraj ovu adresu u pregledač:',
                'footer' => 'Automatska poruka od Wavea. Molimo te, ne odgovaraj na ovu e-poruku.',
            ],
        ],
        'sl' => [
            'registered' => [
                'subject' => 'Tvoja registracija v Wave čaka na pregled',
                'preheader' => 'Registracija je prejeta in čaka na odobritev.',
                'eyebrow' => 'REGISTRACIJA PREJETA',
                'heading' => 'Tvoj račun čaka na odobritev.',
                'greeting' => 'Pozdravljeni!',
                'message' => 'Tvoj e-poštni naslov je potrjen, registracija pa je prejeta. Naša ekipa bo pregledala račun in ti poslala e-pošto, ko bo odobren.',
                'buttonLabel' => 'Odpri Wave',
                'expiration' => 'Med čakanjem ni potrebno dodatno dejanje.',
                'security' => 'Prijaviš se lahko in urediš profil, medtem ko je račun v pregledu.',
                'fallback' => 'Če se gumb ne odpre, kopiraj ta naslov v brskalnik:',
                'footer' => 'To je samodejno sporočilo Wave. Prosimo, ne odgovarjaj nanj.',
            ],
            'approval' => [
                'subject' => 'Tvoj račun Wave je odobren',
                'preheader' => 'Začneš lahko uporabljati Wave.',
                'eyebrow' => 'RAČUN JE ODOBREN',
                'heading' => 'Dobrodošel/la v Wave.',
                'greeting' => 'Pozdravljeni!',
                'message' => 'Tvoj račun Wave je odobren. Prijavi se in nadaljuj z urejanjem profila, raziskovanjem kampanj ter povezovanjem z blagovnimi znamkami.',
                'buttonLabel' => 'Odpri svoj račun',
                'expiration' => 'Odobritev je aktivna in lahko začneš takoj.',
                'security' => 'Če tega sporočila nisi pričakoval/a, se obrni na podporo Wave.',
                'fallback' => 'Če se gumb ne odpre, kopiraj ta naslov v brskalnik:',
                'footer' => 'To je samodejno sporočilo Wave. Prosimo, ne odgovarjaj nanj.',
            ],
        ],
        'en' => [
            'registered' => [
                'subject' => 'Your Wave registration is awaiting review',
                'preheader' => 'Your registration is received and awaiting approval.',
                'eyebrow' => 'REGISTRATION RECEIVED',
                'heading' => 'Your account is awaiting approval.',
                'greeting' => 'Hello!',
                'message' => 'Your email address is verified and your registration has been received. Our team will review your account and email you as soon as it is approved.',
                'buttonLabel' => 'Open Wave',
                'expiration' => 'No further action is needed while your account is under review.',
                'security' => 'You can sign in and update your profile while you wait.',
                'fallback' => 'If the button does not work, copy this address into your browser:',
                'footer' => 'This is an automated message from Wave. Please do not reply.',
            ],
            'approval' => [
                'subject' => 'Your Wave account has been approved',
                'preheader' => 'You can now start using Wave.',
                'eyebrow' => 'ACCOUNT APPROVED',
                'heading' => 'Welcome to Wave.',
                'greeting' => 'Hello!',
                'message' => 'Your Wave account has been approved. Sign in to finish your profile, explore campaigns, and connect with brands.',
                'buttonLabel' => 'Open your account',
                'expiration' => 'Your approval is active and you can get started now.',
                'security' => 'If you were not expecting this message, contact Wave support.',
                'fallback' => 'If the button does not work, copy this address into your browser:',
                'footer' => 'This is an automated message from Wave. Please do not reply.',
            ],
        ],
    ];

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly EmailTemplateRenderer $templates,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%/config/localized_routes.json')] private readonly string $localizedRoutesFile,
        #[Autowire('%env(MAIL_FROM_ADDRESS)%')] private readonly string $fromAddress,
        #[Autowire('%env(MAIL_FROM_NAME)%')] private readonly string $fromName,
        #[Autowire('%env(APP_BASE_URL)%')] private readonly string $appBaseUrl,
    ) {
    }

    public function sendVerification(User $user, string $token, string $locale): void
    {
        $this->send($user, $token, $locale, 'verify', 'verify-email');
    }

    public function sendPasswordReset(User $user, string $token, string $locale): void
    {
        $this->send($user, $token, $locale, 'reset', 'reset-password');
    }

    public function sendRegistrationReceived(User $user, string $locale): void
    {
        $this->send($user, null, $locale, 'registered', 'account');
    }

    public function sendApproval(User $user, string $locale): void
    {
        $this->send($user, null, $locale, 'approval', 'account');
    }

    public function defaultSubject(string $template, string $locale): string
    {
        return $this->copyFor($template, $locale)['subject'];
    }

    /**
     * @return array<string, string>
     */
    public function previewVariables(string $template, string $locale): array
    {
        $locale = isset(self::COPY[$locale]) ? $locale : 'bs';
        $variables = $this->copyFor($template, $locale);
        unset($variables['subject']);
        $variables['locale'] = match ($locale) {
            'sr' => 'sr-Latn',
            'cnr' => 'cnr-Latn-ME',
            default => $locale,
        };
        $variables['actionUrl'] = 'https://wave.ba/example-action';

        return $variables;
    }

    private function send(User $user, ?string $token, string $locale, string $type, string $routeName): void
    {
        $locale = isset(self::COPY[$locale]) ? $locale : 'bs';
        $copy = $this->copyFor($type, $locale);
        $url = rtrim($this->appBaseUrl, '/').$this->localizedRoutePath($routeName, $locale);
        if ($token !== null) {
            $url .= '#'.rawurlencode($token);
        }
        $variables = $copy;
        unset($variables['subject']);
        $variables += [
            'locale' => match ($locale) {
                'sr' => 'sr-Latn',
                'cnr' => 'cnr-Latn-ME',
                default => $locale,
            },
            'actionUrl' => $url,
        ];
        $customization = $this->entityManager->getRepository(EmailTemplate::class)->findOneBy([
            'templateKey' => $type,
            'locale' => $locale,
        ]);
        $message = new Email()
            ->from(new Address($this->fromAddress, $this->fromName))
            ->to($user->getEmail())
            ->subject($customization instanceof EmailTemplate ? $customization->getSubject() : $copy['subject'])
            ->text(implode("\n\n", [
                $copy['greeting'],
                $copy['message'],
                $copy['buttonLabel'].":\n".$url,
                $copy['expiration'],
                $copy['security'],
                $copy['footer'],
            ]))
            ->html($this->templates->render(
                $type,
                $variables,
                $customization instanceof EmailTemplate ? $customization->getHtmlBody() : null,
            ));

        $this->mailer->send($message);
    }

    /**
     * @return array<string, string>
     */
    private function copyFor(string $template, string $locale): array
    {
        $locale = isset(self::COPY[$locale]) ? $locale : 'bs';
        $copy = self::COPY[$locale][$template] ?? self::NOTIFICATION_COPY[$locale][$template] ?? null;
        if (!is_array($copy)) {
            throw new \InvalidArgumentException(sprintf('Unknown account email template "%s".', $template));
        }

        return $copy;
    }

    private function localizedRoutePath(string $routeName, string $locale): string
    {
        $routes = json_decode((string) file_get_contents($this->localizedRoutesFile), true, 32, JSON_THROW_ON_ERROR);
        $segment = is_array($routes) && is_array($routes[$locale] ?? null) && is_string($routes[$locale][$routeName] ?? null)
            ? $routes[$locale][$routeName]
            : $routeName;
        $prefix = $locale === 'bs' ? '' : '/'.$locale;

        return $prefix.'/'.$segment;
    }
}
