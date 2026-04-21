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
use Testo\Common\Info;

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
        private TestRunner $testRunner, 
        private InterceptorProvider $interceptorProvider,
        private EventDispatcherInterface $eventDispatcher,
        private ParallelInput $parallelInput,
    ) {}

    public function runSuite(SuiteInfo $info, Filter $filter): SuiteResult
    {
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
        $suite->name === null or $filter = $filter->with(testSuites: [$suite->name]);

        if ($this->parallelInput->isEnabled()) {
            return $this->runParallel($suite, $filter);
        }

        return $this->runSequentially($suite, $filter);
    }

    private function runSequentially(SuiteInfo $suite, Filter $filter): SuiteResult
    {
        $results = [];
        $status = Status::Passed;

        foreach ($suite->testCases->getCases() as $caseDefinition) {
            try {
                $caseInfo = $this->createCaseInfo($caseDefinition);
                $result = $this->caseRunner->runCase($caseInfo, $filter);
                $result->status->isFailure() and $status = Status::Failed;
                $results[] = $result;
            } catch (\Throwable) {
                $status = Status::Error;
            }
        }

        $result = new SuiteResult($results, status: $status);
        $this->eventDispatcher->dispatch(new TestSuiteFinished($suite, $result));
        return $result;
    }

    private function runParallel(SuiteInfo $suite, Filter $filter): SuiteResult
    {
        $caseResults = [];
        $status = Status::Passed;
        $pool = $this->createWorkerPool();

        foreach ($suite->testCases->getCases() as $caseDefinition) {
            $className = $caseDefinition->reflection?->getName();

            // Run procedural functions sequentially as they often share global state
            if ($className === null) {
                $caseResults[] = $this->runCaseSequentially($caseDefinition, $filter);
                continue;
            }

            // Parallelize at the TEST METHOD level
            $testPromises = [];
            $testDefinitions = [];
            
            foreach ($caseDefinition->tests->getTests() as $name => $testDefinition) {
                $testPromises[] = $this->dispatchTestToPool($pool, $className, $caseDefinition, $testDefinition, $filter);
                $testDefinitions[] = $testDefinition;
            }

            try {
                $settled = await(Promise::allSettled($testPromises));
                $methodResults = [];
                $caseStatus = Status::Passed;

                foreach ($settled as $index => $res) {
                    if ($res->isFulfilled()) {
                        $methodResults[] = $res->value;
                        if ($res->value->status->isFailure()) $caseStatus = Status::Failed;
                    } else {
                        $caseStatus = Status::Error;
                        $methodResults[] = $this->createCrashTestResult($caseDefinition, $testDefinitions[$index], $res->reason);
                    }
                }

                $caseResults[] = new CaseResult($methodResults, $caseStatus);
                if ($caseStatus->isFailure()) $status = Status::Failed;

            } catch (\Throwable) {
                $status = Status::Error;
            }
        }

        $pool->drain();
        $result = new SuiteResult($caseResults, status: $status);
        $this->eventDispatcher->dispatch(new TestSuiteFinished($suite, $result));
        
        return $result;
    }

    private function dispatchTestToPool(ProcessPoolInterface $pool, string $className, CaseDefinition $caseDef, TestDefinition $testDef, Filter $filter): PromiseInterface
    {
        $methodName = $testDef->reflection->getName();
        $caseType = $caseDef->type;

        return $pool->run(
            static fn() => self::workerExecuteTest($className, $methodName, $caseType, $filter),
            onMessage: function (WorkerMessage $message): void {
                if (is_object($message->data)) {
                    $this->eventDispatcher->dispatch($message->data);
                }
            }
        );
    }

    /**
     * WORKER ENTRY POINT: Runs a single test method.
     */
    public static function workerExecuteTest(string $className, string $methodName, string $caseType, Filter $filter): TestResult
    {
        $container = new \Internal\Container\ObjectContainer();
        (new \Testo\Application\Config\DefaultServicesConfig())->configure($container);

        $realDispatcher = $container->get(EventDispatcherInterface::class);
        $container->set(new EmittingEventDispatcher($realDispatcher), EventDispatcherInterface::class);

        $reflection = new \ReflectionClass($className);
        $caseDef = new CaseDefinition($reflection->getShortName(), $caseType, $reflection);
        $testDef = $caseDef->tests->define($reflection->getMethod($methodName));

        $caseInfo = new CaseInfo($caseDef, new SimpleCaseInstantiator($reflection), [], new DefaultTestHandler());
        $testInfo = new TestInfo($methodName, $caseInfo, $testDef);

        return $container->get(TestRunner::class)->runTest($testInfo);
    }

    private function createCaseInfo(CaseDefinition $def): CaseInfo
    {
        return new CaseInfo(
            definition: $def,
            instance: $def->reflection === null ? null : new SimpleCaseInstantiator($def->reflection),
            invoker: $def->handler ?? (new DefaultTestHandler())(...)
        );
    }

    private function createWorkerPool(): ProcessPoolInterface
    {
        return Parallel::pool(size: $this->parallelInput->getPoolSize())
            ->withoutTimeout()
            ->withBootstrap(Info::ROOT_DIR . '/vendor/autoload.php');
    }

    private function runCaseSequentially(CaseDefinition $def, Filter $filter): CaseResult
    {
        return $this->caseRunner->runCase($this->createCaseInfo($def), $filter);
    }

    private function createCrashTestResult(CaseDefinition $caseDef, TestDefinition $testDef, \Throwable $e): TestResult
    {
        $info = new TestInfo($testDef->reflection->getName(), new CaseInfo($caseDef), $testDef);
        return new TestResult($info, Status::Error, failure: $e);
    }
}