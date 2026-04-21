<?php

declare(strict_types=1);

namespace Testo\Application\Internal\Runner;

use Psr\EventDispatcher\EventDispatcherInterface;
use Testo\Application\Internal\SimpleCaseInstantiator;
use Testo\Core\Context\CaseInfo;
use Testo\Core\Context\CaseResult;
use Testo\Core\Context\TestInfo;
use Testo\Core\Context\TestResult;
use Testo\Core\Context\SuiteInfo;
use Testo\Core\Context\SuiteResult;
use Testo\Core\Definition\CaseDefinition;
use Testo\Core\Definition\TestDefinition;
use Testo\Core\Internal\DefaultTestHandler;
use Testo\Core\Value\Status;
use Testo\Event\TestSuite\TestSuiteFinished;
use Testo\Event\TestSuite\TestSuitePipelineFinished;
use Testo\Event\TestSuite\TestSuitePipelineStarting;
use Testo\Event\TestSuite\TestSuiteStarting;
use Testo\Filter;
use Testo\Pipeline\InterceptorProvider;
use Testo\Pipeline\Middleware\TestSuiteRunInterceptor;
use Testo\Pipeline\Pipeline;
use Testo\Parallel\Internal\ParallelInput;
use Hibla\Parallel\Parallel;
use Hibla\Parallel\Interfaces\ProcessPoolInterface;
use Hibla\Parallel\ValueObjects\WorkerMessage;
use Hibla\Promise\Promise;
use Hibla\Promise\Interfaces\PromiseInterface;
use Internal\Container\ObjectContainer;
use Testo\Application\Config\DefaultServicesConfig;

use function Hibla\await;

/**
 * A test suite runner that executes a suite of tests and returns the results.
 *
 * @internal
 * @psalm-internal Testo\Application
 */
final readonly class SuiteRunner
{
    public function __construct(
        private CaseRunner $caseRunner,
        private InterceptorProvider $interceptorProvider,
        private EventDispatcherInterface $eventDispatcher,
        private ParallelInput $parallelInput,
    ) {}

    public function runSuite(SuiteInfo $info, Filter $filter): SuiteResult
    {
        /**
         * Prepare interceptors pipeline
         *
         * @see TestSuiteRunInterceptor::runTestSuite()
         * @var list<TestSuiteRunInterceptor> $interceptors
         * @var callable(SuiteInfo): SuiteResult $pipeline
         */
        $interceptors = $this->interceptorProvider->fromConfig(TestSuiteRunInterceptor::class);
        $pipeline = Pipeline::prepare($filter->type, ...$interceptors)
            ->with(
                fn(SuiteInfo $info): SuiteResult => $this->run($info, $filter),
                'runTestSuite',
            );

        $this->eventDispatcher->dispatch(new TestSuitePipelineStarting($info));
        $result = $pipeline($info);
        $this->eventDispatcher->dispatch(new TestSuitePipelineFinished($info, $result));

        return $result;
    }

    public function run(SuiteInfo $suite, Filter $filter): SuiteResult
    {
        $this->eventDispatcher->dispatch(new TestSuiteStarting($suite));

        # Apply suite name filter if exists
        $suite->name === null or $filter = $filter->with(testSuites: [$suite->name]);

        // Route to parallel implementation if requested
        if ($this->parallelInput->isEnabled()) {
            return $this->runParallel($suite, $filter);
        }

        return $this->runSequentially($suite, $filter);
    }

    /**
     * Executes test cases sequentially (Default original behavior).
     */
    private function runSequentially(SuiteInfo $suite, Filter $filter): SuiteResult
    {
        $runner = $this->caseRunner;
        $results = [];
        $status = Status::Passed;

        // Todo: unhardcode
        $handler = (new DefaultTestHandler())(...);

        # Run tests for each case
        foreach ($suite->testCases->getCases() as $caseDefinition) {
            try {
                $caseInfo = new CaseInfo(
                    definition: $caseDefinition,
                    instance: $caseDefinition->reflection === null
                        ? null
                        : new SimpleCaseInstantiator($caseDefinition->reflection),
                    invoker: $caseDefinition->handler ?? $handler,
                );
                $result = $runner->runCase($caseInfo, $filter);
                $result->status->isFailure() and $status = Status::Failed;
                $results[] = $result;
            } catch (\Throwable) {
                // Skip for now
                $status = Status::Error;
            }
        }

        $result = new SuiteResult($results, status: $status);

        $this->eventDispatcher->dispatch(new TestSuiteFinished($suite, $result));
        return $result;
    }

    /**
     * Executes test cases in parallel utilizing Hibla Process Pools.
     */
    private function runParallel(SuiteInfo $suite, Filter $filter): SuiteResult
    {
        $results = [];
        $status = Status::Passed;
        $promises = [];
        $dispatchedCases = [];

        $pool = Parallel::pool(size: $this->parallelInput->getPoolSize())
            ->withoutTimeout()
            ->withUnlimitedMemory();;

        foreach ($suite->testCases->getCases() as $caseDefinition) {
            // Fallback to sequential for standalone (procedural) functions as they lack a ReflectionClass container
            if ($caseDefinition->reflection?->getName() === null) {
                $res = $this->runCaseSequentially($caseDefinition, $filter);
                $res->status->isFailure() and $status = Status::Failed;
                $results[] = $res;
                continue;
            }

            // Dispatch task to Hibla Process Pool
            $promises[] = $this->dispatchCaseToPool($pool, $caseDefinition, $filter);
            $dispatchedCases[] = $caseDefinition;
        }

        try {
            // Await all parallel execution promises to complete safely using allSettled()
            // This guarantees one crashing worker won't cancel the remaining workers.
            if ($promises !== []) {
                $settledResults = await(Promise::allSettled($promises));

                foreach ($settledResults as $index => $settled) {
                    if ($settled->isFulfilled()) {
                        /** @var CaseResult $caseResult */
                        $caseResult = $settled->value;
                        $results[] = $caseResult;

                        if ($caseResult->status->isFailure()) {
                            $status = Status::Failed;
                        }
                    } elseif ($settled->isRejected()) {
                        // Worker hard-crashed (OOM, Segfault, Timeout)
                        // map the failure to a synthetic CaseResult so Testo reports it gracefully
                        $status = Status::Error;
                        $results[] = $this->createCrashResult($dispatchedCases[$index], $settled->reason);
                    }
                }
            }
        } finally {
            $pool->drain();
        }

        $result = new SuiteResult($results, status: $status);
        $this->eventDispatcher->dispatch(new TestSuiteFinished($suite, $result));

        return $result;
    }

    private function runCaseSequentially(CaseDefinition $caseDefinition, Filter $filter): CaseResult
    {
        $caseInfo = new CaseInfo(
            definition: $caseDefinition,
            instance: null,
            invoker: $caseDefinition->handler ?? (new DefaultTestHandler())(...),
        );

        return $this->caseRunner->runCase($caseInfo, $filter);
    }

    private function dispatchCaseToPool(ProcessPoolInterface $pool, CaseDefinition $caseDefinition, Filter $filter): PromiseInterface
    {
        $className = $caseDefinition->reflection->getName();
        $caseType = $caseDefinition->type;
        $methodNames = \array_keys($caseDefinition->tests->getTests());

        return $pool->run(
            static fn() => self::workerExecuteCase($className, $caseType, $methodNames, $filter),

            // The Parent Process Message Handler (receives emit() calls)
            onMessage: function (WorkerMessage $message): void {
                // Dispatch the worker's events natively into the parent's event bus
                // allowing TerminalLogger to print test checkmarks in real-time!
                if (\is_object($message->data)) {
                    $this->eventDispatcher->dispatch($message->data);
                }
            }
        );
    }

    public static function workerExecuteCase(string $className, string $caseType, array $methodNames, Filter $filter): CaseResult
    {
        // Boot up the container in the isolated child process
        $container = new ObjectContainer();
        (new DefaultServicesConfig())->configure($container);

        // Hook into EventDispatcher to beam events back to the parent in real-time
        $realDispatcher = $container->get(EventDispatcherInterface::class);
        $container->set(new EmittingEventDispatcher($realDispatcher), EventDispatcherInterface::class);

        // Reconstruct Reflection and CaseDefinitions natively in the worker
        $reflection = new \ReflectionClass($className);
        $caseDef = new CaseDefinition(
            name: $reflection->getShortName(),
            type: $caseType,
            reflection: $reflection
        );

        foreach ($methodNames as $methodName) {
            $caseDef->tests->define($reflection->getMethod($methodName));
        }

        $caseInfo = new CaseInfo(
            definition: $caseDef,
            instance: new SimpleCaseInstantiator($reflection),
            invoker: new DefaultTestHandler()
        );

        $runner = $container->get(CaseRunner::class);
        return $runner->runCase($caseInfo, $filter);
    }

    private function createCrashResult(CaseDefinition $caseDefinition, \Throwable $e): CaseResult
    {
        $testInfo = new TestInfo(
            name: 'Parallel Worker Crash',
            caseInfo: new CaseInfo(definition: $caseDefinition),
            testDefinition: new TestDefinition(new \ReflectionFunction(static fn() => null))
        );

        $testResult = new TestResult(
            info: $testInfo,
            status: Status::Error,
            failure: $e
        );

        return new CaseResult([$testResult], Status::Error);
    }
}
