<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Daily aggregates only: no IP addresses, visitor identifiers or precise coordinates. */
#[ORM\Entity]
#[ORM\Table(name: 'wave_qr_scan_daily')]
#[ORM\UniqueConstraint(name: 'uniq_qr_scan_day_location', columns: ['qr_link_id', 'day', 'country', 'city'])]
class QrScanDaily
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: QrLink::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private QrLink $qrLink;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $day;

    #[ORM\Column(length: 2)]
    private string $country = '';

    #[ORM\Column(length: 120)]
    private string $city = '';

    #[ORM\Column]
    private int $scans = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $lastScanAt;

    public function __construct(QrLink $qrLink, \DateTimeImmutable $scannedAt, string $country = '', string $city = '')
    {
        $this->qrLink = $qrLink;
        $this->day = $scannedAt->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);
        $this->lastScanAt = $scannedAt;
        $this->country = $country;
        $this->city = $city;
        $this->scans = 1;
    }
}
