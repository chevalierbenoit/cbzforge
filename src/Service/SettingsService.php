<?php

namespace App\Service;

use App\Entity\Setting;
use Doctrine\ORM\EntityManagerInterface;

class SettingsService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function get(string $key): ?string
    {
        $setting = $this->em->getRepository(Setting::class)->find($key);

        return $setting?->getValue();
    }

    public function set(string $key, string $value): void
    {
        $setting = $this->em->getRepository(Setting::class)->find($key)
            ?? new Setting();

        $setting->setKey($key);
        $setting->setValue($value);

        $this->em->persist($setting);
        $this->em->flush();
    }

    public function has(string $key): bool
    {
        return null !== $this->get($key);
    }
}
