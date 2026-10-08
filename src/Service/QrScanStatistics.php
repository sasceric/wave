<?php

namespace App\Service;

use App\Entity\QrLink;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;

final class QrScanStatistics
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%env(csv:QR_LOCATION_TRUSTED_PROXIES)%')] private readonly array $trustedProxies,
    ) {
    }

    public function record(QrLink $link, Request $request): void
    {
        if (!$request->isMethod('GET')
            || preg_match('/bot|crawler|spider|facebookexternalhit|WhatsApp|Slackbot|Discordbot|TelegramBot/i', $request->headers->get('User-Agent', ''))
            || str_contains(strtolower($request->headers->get('Sec-Purpose', '').' '.$request->headers->get('Purpose', '')), 'prefetch')) {
            return;
        }
        [$country, $city] = $this->location($request);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        // A single atomic upsert also preserves counts under simultaneous scans.
        $this->connection->executeStatement(
            'INSERT INTO wave_qr_scan_daily (qr_link_id, day, country, city, scans, last_scan_at)
             VALUES (:id, :day, :country, :city, 1, :now)
             ON CONFLICT (qr_link_id, day, country, city) DO UPDATE
             SET scans = wave_qr_scan_daily.scans + 1, last_scan_at = excluded.last_scan_at',
            ['id' => $link->getId(), 'day' => $now->format('Y-m-d'), 'country' => $country, 'city' => $city, 'now' => $now->format('Y-m-d H:i:s')],
        );
    }

    public function summaries(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT qr_link_id, SUM(scans) AS scans, MAX(last_scan_at) AS last_scan_at FROM wave_qr_scan_daily WHERE qr_link_id IN (:ids) GROUP BY qr_link_id',
            ['ids' => $ids], ['ids' => ArrayParameterType::INTEGER],
        );
        $summaries = [];
        foreach ($rows as $row) {
            $summaries[(int) $row['qr_link_id']] = ['scans' => (int) $row['scans'], 'lastScanAt' => $this->timestamp($row['last_scan_at'])];
        }

        return $summaries;
    }

    public function details(QrLink $link): array
    {
        $id = $link->getId();
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $daily = $this->connection->fetchAllAssociative(
            'SELECT day, SUM(scans) AS scans FROM wave_qr_scan_daily WHERE qr_link_id = :id AND day >= :since GROUP BY day ORDER BY day DESC',
            ['id' => $id, 'since' => $today->modify('-29 days')->format('Y-m-d')],
        );
        $locations = $this->connection->fetchAllAssociative(
            'SELECT country, city, SUM(scans) AS scans FROM wave_qr_scan_daily WHERE qr_link_id = :id GROUP BY country, city ORDER BY scans DESC, country ASC, city ASC LIMIT 100', ['id' => $id],
        );

        return ($this->summaries([$id])[$id] ?? ['scans' => 0, 'lastScanAt' => null]) + [
            'daily' => array_map(static fn (array $row): array => ['day' => $row['day'], 'scans' => (int) $row['scans']], $daily),
            'locations' => array_map(static fn (array $row): array => ['country' => $row['country'] ?: null, 'city' => $row['city'] ?: null, 'scans' => (int) $row['scans']], $locations),
        ];
    }

    private function location(Request $request): array
    {
        $peer = $request->server->get('REMOTE_ADDR');
        if (!is_string($peer) || $this->trustedProxies === [] || !IpUtils::checkIp($peer, $this->trustedProxies)) {
            return ['', ''];
        }
        $country = strtoupper(trim($request->headers->get('CF-IPCountry', '')));
        if (!preg_match('/^[A-Z]{2}$/D', $country) || in_array($country, ['XX', 'T1'], true)) {
            return ['', ''];
        }
        $city = trim($request->headers->get('CF-IPCity', ''));
        $city = mb_check_encoding($city, 'UTF-8') ? mb_substr(preg_replace('/[\p{C}]/u', '', $city) ?? '', 0, 120) : '';

        return [$country, $city];
    }

    private function timestamp(string $value): string
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'))->format(DATE_ATOM);
    }
}
