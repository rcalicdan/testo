<?php

declare(strict_types=1);

namespace Testo\Core\Context;

use Testo\Core\Internal\Attributed;
use Testo\Core\Value\Status;

/**
 * @api
 */
final class TestResult
{
    use Attributed;

    /**
     * @param array<non-empty-string, mixed> $attributes
     */
    public function __construct(
        public readonly TestInfo $info,
        public readonly Status $status,
        public readonly mixed $result = null,
        public readonly ?\Throwable $failure = null,
        public readonly array $attributes = [],
    ) {}

    public function with(
        ?Status $status = null,
    ): self {
        return new self(
            info: $this->info,
            status: $status ?? $this->status,
            result: $this->result,
            failure: $this->failure,
            attributes: $this->attributes,
        );
    }

    public function withResult(mixed $result): self
    {
        return $this->cloneWith('result', $result);
    }

    public function withFailure(?\Throwable $failure): self
    {
        return $this->cloneWith('failure', $failure);
    }

    public function __serialize(): array
    {
        return [
            'info' => $this->info,
            'status' => $this->status,
            // If a test literally returned a Closure, drop it
            'result' => $this->result instanceof \Closure ? null : $this->result,
            'failure' => $this->failure,
            'attributes' => $this->attributes,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->info = $data['info'];
        $this->status = $data['status'];
        $this->result = $data['result'];
        $this->failure = $data['failure'];
        $this->attributes = $data['attributes'];
    }
}
