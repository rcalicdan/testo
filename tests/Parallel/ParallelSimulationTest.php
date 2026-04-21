<?php

declare(strict_types=1);

namespace Tests\Parallel;

use Testo\Assert;
use Testo\Test;

/**
 * A heavy-duty simulation test to prove the power of Hibla Parallel.
 * 
 * Total Work: 20 seconds (20 tasks * 1s sleep).
 */
final class ParallelSimulationTest
{
    #[Test] public function slowOperation01(): void
    {
        \sleep(1);
        Assert::true(true);
    }

    #[Test] public function slowOperation02(): void
    {
        \sleep(1);
        Assert::true(true);
    }

    #[Test] public function slowOperation03(): void
    {
        \sleep(1);
        Assert::true(true);
    }

    #[Test] public function slowOperation04(): void
    {
        \sleep(1);
        Assert::true(true);
    }
    #[Test] public function slowOperation05(): void
    {
        \sleep(1);
        Assert::true(true);
    }

    #[Test] public function slowOperation06(): void
    {
        \sleep(1);
        Assert::true(true);
    }

    #[Test] public function slowOperation07(): void
    {
        \sleep(1);
        Assert::true(true);
    }

    #[Test] public function slowOperation08(): void
    {
        \sleep(1);
        Assert::true(true);
    }
    
    #[Test] public function slowOperation09(): void
    {
        \sleep(1);
        Assert::true(true);
    }
    #[Test] public function slowOperation10(): void
    {
        \sleep(1);
        Assert::true(true);
    }

    #[Test] public function slowOperation11(): void
    {
        \sleep(1);
        Assert::true(true);
    }

    #[Test] public function slowOperation12(): void
    {
        \sleep(1);
        Assert::true(true);
    }

    #[Test] public function slowOperation13(): void
    {
        \sleep(1);
        Assert::true(true);
    }

    #[Test] public function slowOperation14(): void
    {
        \sleep(1);
        Assert::true(true);
    }

    #[Test] public function slowOperation15(): void
    {
        \sleep(1);
        Assert::true(true);
    }

    #[Test] public function slowOperation16(): void
    {
        \sleep(1);
        Assert::true(true);
    }

    #[Test] public function slowOperation17(): void
    {
        \sleep(1);
        Assert::true(true);
    }

    #[Test] public function slowOperation18(): void
    {
        \sleep(1);
        Assert::true(true);
    }

    #[Test] public function slowOperation19(): void
    {
        \sleep(1);
        Assert::true(true);
    }
    
    #[Test] public function slowOperation20(): void
    {
        \sleep(1);
        Assert::true(true);
    }

    /**
     * Verifies that the runner survives a hard crash in parallel mode.
     */
    #[Test]
    public function workerHardCrashRecovery(): void
    {
        // exit(1); // Uncomment to simulate a crash
        Assert::true(true);
    }
}
