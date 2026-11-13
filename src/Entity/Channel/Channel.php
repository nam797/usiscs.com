<?php

declare(strict_types=1);

namespace App\Entity\Channel;

use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Core\Model\Channel as BaseChannel;

#[ORM\Entity]
#[ORM\Table(name: 'sylius_channel')]
class Channel extends BaseChannel
{
    /** Offer package protection at checkout (priced by PackageProtectionTier). */
    #[ORM\Column(name: 'package_protection_enabled', type: 'boolean', options: ['default' => false])]
    private bool $packageProtectionEnabled = false;

    public function isPackageProtectionEnabled(): bool
    {
        return $this->packageProtectionEnabled;
    }

    public function setPackageProtectionEnabled(bool $packageProtectionEnabled): void
    {
        $this->packageProtectionEnabled = $packageProtectionEnabled;
    }
}
