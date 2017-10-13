<?php

namespace Asynit\Runner;

use Amp\Loop;
use Amp\Promise;
use Asynit\Assert\Assertion;
use Asynit\Output\OutputInterface;
use Asynit\Test;
use Asynit\TestCase;
use Asynit\Pool;
use Http\Message\RequestFactory;

class PoolRunner
{
    private $testObjects = [];

    /** @var OutputInterface */
    private $output;

    /** @var FutureHttpPool */
    private $futureHttpPool;

    /** @var int */
    private $concurrency;

    /** @var RequestFactory */
    private $requestFactory;

    public function __construct(RequestFactory $requestFactory, OutputInterface $output, $concurrency = 10)
    {
        $this->requestFactory = $requestFactory;
        $this->output = $output;
        $this->concurrency = $concurrency;
        $this->futureHttpPool = new FutureHttpPool();
    }

    public function loop(Pool $pool)
    {
        return \Amp\call(function () use($pool) {
            ob_start();
            $promises = [];

            while (!$pool->isEmpty()) {
                $test = $pool->getTestToRun();

                if ($test === null) {
                    // Wait for one the current test to finish @TODO Need to check when there is no more promise to resolve
                    yield \Amp\Promise\first($promises);

                    continue;
                }

                $promises[$test->getIdentifier()] = $this->run($test);
                $promises[$test->getIdentifier()]->onResolve(function () use (&$promises, $test) {
                    unset($promises[$test->getIdentifier()]);
                });
            }

            // No more test wait for all remaining run
            yield $promises;

            Loop::stop();
            ob_end_flush();
        });
    }

    /**
     * Run a test pool.
     *
     */
    protected function run(Test $test): Promise
    {
        return \Amp\call(function () use($test) {
            $debugOutput = ob_get_contents();
            ob_clean();

            $this->output->outputStep($test, $debugOutput);
            $test->setState(Test::STATE_RUNNING);

            $testCase = $this->getTestObject($test);
            $testCase->initialize();

            $method = $test->getMethod()->getName();
            $args = $test->getArguments();

            try {
                $result = yield \Amp\call(function () use($testCase, $method, $args) { return $testCase->$method(...$args); });

                foreach ($test->getChildren() as $childTest) {
                    $childTest->addArgument($result, $test);
                }

                $debugOutput = ob_get_contents();
                ob_clean();
                $this->output->outputSuccess($test, $debugOutput);
                $test->setState(Test::STATE_SUCCESS);
            } catch (\Throwable $error) {
                $debugOutput = ob_get_contents();
                ob_clean();

                $this->output->outputFailure($test, $debugOutput, $error);
                $test->setState(Test::STATE_FAILURE);
            }
        });
    }

    /**
     * Execute a test step.
     *
     * @param callable $callback
     * @param Test     $test
     * @param Pool     $pool
     *
     * @return bool
     */
    protected function executeTestStep($callback, Test $test, Pool $pool, $isTestMethod = false)
    {
        try {
            Assertion::$currentTest = $test;

            if ($isTestMethod && $test->getMethod()->returnsReference()) {
                $result = &$callback();
            } else {
                $result = $callback();
            }

            $futureHttpCollection = $this->futureHttpPool->flush();
            $test->mergeFutureHttp($futureHttpCollection, $test);
            $pool->queueFutureHttp($futureHttpCollection);
        } catch (\Throwable $exception) {
            $debugOutput = ob_get_contents();
            ob_clean();

            $this->futureHttpPool->flush();
            $pool->passFinishTest($test);

            return false;
        } catch (\Exception $exception) {
            $debugOutput = ob_get_contents();
            ob_clean();

            $this->futureHttpPool->flush();
            $pool->passFinishTest($test);
            $this->output->outputFailure($test, $debugOutput, $exception);

            return false;
        }

        $debugOutput = ob_get_contents();
        ob_clean();

        if ($isTestMethod) {
            foreach ($test->getChildren() as $childTest) {
                $childTest->addArgument($result, $test);
            }
        }

        if ($pool->hasTest($test) && $test->getFutureHttpPool()->isEmpty()) {
            $pool->passFinishTest($test);
            $this->output->outputSuccess($test, $debugOutput);

            foreach ($test->getChildren() as $childTest) {
                $complete = true;

                foreach ($childTest->getParents() as $parentTest) {
                    if (!$pool->hasCompletedTest($parentTest)) {
                        $complete = false;
                        break;
                    }
                }

                if ($complete) {
                    $pool->queueTest($childTest);
                }
            }

            return true;
        }

        if ($pool->hasTest($test)) {
            $this->output->outputStep($test, $debugOutput);
        }

        return true;
    }

    /**
     * Return a test case for a given test method.
     *
     * @param Test $test
     *
     * @return TestCase
     */
    private function getTestObject(Test $test): TestCase
    {
        $class = $test->getMethod()->getDeclaringClass()->getName();

        if (!array_key_exists($class, $this->testObjects)) {
            $this->testObjects[$class] = $test->getMethod()->getDeclaringClass()->newInstance($this->requestFactory, $this->futureHttpPool);
        }

        return $this->testObjects[$class];
    }
}
