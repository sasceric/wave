<?php

namespace App\Controller;

use App\Entity\QrLink;
use App\Service\QrScanStatistics;
use Doctrine\DBAL\Exception;
use Psr\Log\LoggerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class QrRedirectController
{
    #[Route('/q/{token}', requirements: ['token' => '[a-f0-9]{32}'], methods: ['GET', 'HEAD'], priority: 20)]
    public function redirect(string $token, Request $request, EntityManagerInterface $em, QrScanStatistics $statistics, LoggerInterface $logger): Response
    {
        $link = $em->getRepository(QrLink::class)->findOneBy(['token' => $token]);
        $headers = ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer'];
        if ($link instanceof QrLink) {
            try {
                $statistics->record($link, $request);
            } catch (Exception $exception) {
                // Analytics failure must not break a printed link's destination.
                $logger->warning('QR scan statistics could not be recorded.', ['qr_link_id' => $link->getId(), 'exception' => $exception]);
            }
        }

        return $link instanceof QrLink
            ? new RedirectResponse($link->getDestination(), 302, $headers)
            : new Response('QR link not found.', 404, $headers);
    }
}
