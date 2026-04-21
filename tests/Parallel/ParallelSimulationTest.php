<?php

declare(strict_types=1);

namespace Tests\Parallel;

use Testo\Assert;
use Testo\Test;

final class ParallelSimulationTest
{
    #[Test]
    public function slowOperationA(): void
    {
        \sleep(1);
        Assert::true(true, 'Completed Slow Task A');
    }

    #[Test]
    public function slowOperationB(): void
    {
        \sleep(1);
        Assert::true(true, 'Completed Slow Task B');
    }

    #[Test]
    public function slowOperationC(): void
    {
        \sleep(1);
        Assert::true(true, 'Completed Slow Task C');
    }

    #[Test]
    public function slowOperationD(): void
    {
        \sleep(1);
        Assert::true(true, 'Completed Slow Task D');
    }

    #[Test]
    public function workerHardCrashRecovery(): void
    {
        // Uncomment to test that a fatal crash doesn't kill the whole test suite runner!
        // exit(1); 
        
        Assert::true(true);
    }
}