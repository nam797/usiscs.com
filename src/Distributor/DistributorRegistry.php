<?php

declare(strict_types=1);

namespace App\Distributor;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Every DistributorInterface adapter, by code. */
final class DistributorRegistry
{
    /** @var array<string, DistributorInterface> */
    private array $distributors = [];

    /** @param iterable<DistributorInterface> $distributors */
    public function __construct(#[AutowireIterator(DistributorInterface::TAG)] iterable $distributors)
    {
        foreach ($distributors as $distributor) {
            $this->distributors[$distributor->getCode()] = $distributor;
        }
        ksort($this->distributors);
    }

    public function get(string $code): ?DistributorInterface
    {
        return $this->distributors[$code] ?? null;
    }

    /** @return array<string, DistributorInterface> */
    public function all(): array
    {
        return $this->distributors;
    }

    public function nameOf(string $code): string
    {
        return $this->get($code)?->getName() ?? $code;
    }

    /** @return array<string, string> name => code, for form choices */
    public function choices(): array
    {
        $choices = [];
        foreach ($this->distributors as $code => $distributor) {
            $choices[$distributor->getName()] = $code;
        }

        return $choices;
    }
}
