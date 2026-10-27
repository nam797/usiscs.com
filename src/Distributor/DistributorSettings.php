<?php

declare(strict_types=1);

namespace App\Distributor;

use App\Entity\Procurement\DistributorSetting;
use Doctrine\ORM\EntityManagerInterface;

/** The backoffice switches per distributor (pause, manual entry). */
final class DistributorSettings
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DistributorRegistry $distributors,
    ) {
    }

    /** The saved setting, or the defaults (not paused, not manual) when there's none yet. */
    public function for(string $code): DistributorSetting
    {
        return $this->entityManager->find(DistributorSetting::class, $code) ?? new DistributorSetting($code);
    }

    /** @return list<string> */
    public function pausedCodes(): array
    {
        $codes = [];
        foreach ($this->entityManager->getRepository(DistributorSetting::class)->findBy(['paused' => true]) as $setting) {
            $codes[] = $setting->getCode();
        }

        return $codes;
    }

    /** Saves a settings row for every distributor that has none, so the backoffice lists them all. */
    public function ensureAll(): void
    {
        $added = false;
        foreach (array_keys($this->distributors->all()) as $code) {
            if (null === $this->entityManager->find(DistributorSetting::class, $code)) {
                $this->entityManager->persist(new DistributorSetting($code));
                $added = true;
            }
        }
        if ($added) {
            $this->entityManager->flush();
        }
    }
}
