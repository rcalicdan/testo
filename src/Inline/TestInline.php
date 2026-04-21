<?php

declare(strict_types=1);

namespace Testo\Inline;

use Testo\Assert;
use Testo\Inline\Internal\InlineInterceptor;
use Testo\Pipeline\Attribute\FallbackInterceptor;
use Testo\Pipeline\Attribute\Interceptable;

/**
 * Test that a method or function returns a specified result when called with given arguments.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_FUNCTION | \Attribute::IS_REPEATABLE)]
#[FallbackInterceptor(InlineInterceptor::class)]
final readonly class TestInline implements Interceptable
{
    public function __construct(
        public array $arguments,
        public mixed $result = null,
    ) {}

    public function __serialize(): array
    {
        return[
            'arguments' => $this->arguments,
            'result' => $this->result instanceof \Closure ? null : $this->result,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->arguments = $data['arguments'];
        $this->result = $data['result'];
    }
}