<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Setting
{
    #[ORM\Id, ORM\Column(length: 255)]
    private string $key = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $value = null;

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): void
    {
        $this->key = $key;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): void
    {
        $this->value = $value;
    }
}
