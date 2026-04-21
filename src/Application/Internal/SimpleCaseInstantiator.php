<?php

declare(strict_types=1);

namespace Testo\Application\Internal;

use Testo\Application\Exception\TestCaseInstantiationException;
use Testo\Core\Value\CaseInstance;

/**
 * @internal
 * @psalm-internal Testo\Application
 */
final class SimpleCaseInstantiator implements CaseInstance
{
    private ?object $instance = null;

    public function __construct(
        private \ReflectionClass $reflection,
    ) {}

    #[\Override]
    public function getInstance(): object
    {
        return $this->instance ??= $this->createInstance();
    }

    #[\Override]
    public function hasInstance(): bool
    {
        return $this->instance !== null;
    }

    private function createInstance(): object
    {
        try {
            return $this->reflection->newInstance();
        } catch (\Throwable $e) {
            throw new TestCaseInstantiationException(previous: $e);
        }
    }

    public function __serialize(): array
    {
        return[
            'class' => $this->reflection->getName(),
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->reflection = new \ReflectionClass($data['class']);
        $this->instance = null;
    }
}