<?php

declare(strict_types=1);

namespace Testo\Parallel\Internal;

use Testo\Application\Config\Internal\Attribute\InflectableConfig;
use Testo\Application\Config\Internal\Attribute\InputOption;
use Hibla\Parallel\Utilities\SystemUtilities;

/**
 * CLI input for parallel execution configuration.
 *
 * @internal
 * @psalm-internal Testo\Parallel
 */
#[InflectableConfig]
final class ParallelInput
{
    #[InputOption('parallel')]
    public string|bool|null $parallel = false;

    /**
     * Determine if parallel execution is requested.
     */
    public function isEnabled(): bool
    {
        return $this->parallel !== false;
    }

    /**
     * Determine the desired worker pool size.
     */
    public function getPoolSize(): int
    {
        if (\is_numeric($this->parallel) && (int) $this->parallel > 0) {
            return (int) $this->parallel;
        }

        // Default to logical CPU count utilizing Hibla's utilities
        return SystemUtilities::getCpuCount();
    }
}
