<?php

namespace Asynit\Parser;

use Asynit\Attribute\Test as TestAnnotation;
use Asynit\Attribute\TestCase;
use Asynit\Test;
use Asynit\TestSuite;
use Symfony\Component\Finder\Finder;

/**
 * @internal
 */
final class TestsFinder
{
    /** @return TestSuite<object>[] */
    public function findTests(string $path): array
    {
        if (\is_file($path)) {
            return $this->doFindTests([$path]);
        }

        $finder = Finder::create()
            ->files()
            ->name('*.php')
            ->in($path)
        ;

        return $this->doFindTests($finder);
    }

    /**
     * @param iterable<string|\SplFileInfo> $files
     *
     * @return TestSuite<object>[]
     */
    private function doFindTests(iterable $files): array
    {
        $suites = [];

        foreach ($files as $file) {
            $existingClasses = get_declared_classes();
            $path = $file;

            if ($path instanceof \SplFileInfo) {
                $path = $path->getRealPath();
            }

            require_once $path;

            $newClasses = array_diff(get_declared_classes(), $existingClasses);

            foreach ($newClasses as $class) {
                $reflectionClass = new \ReflectionClass($class);
                $testCases = $reflectionClass->getAttributes(TestCase::class);

                if (0 === count($testCases)) {
                    continue;
                }

                $testSuite = new TestSuite($reflectionClass);
                $suites[] = $testSuite;

                foreach ($reflectionClass->getMethods(\ReflectionMethod::IS_PUBLIC) as $reflectionMethod) {
                    $tests = $reflectionMethod->getAttributes(TestAnnotation::class);
                    $test = null;

                    if (count($tests) > 0) {
                        $test = new Test($testSuite, $reflectionMethod);
                    } elseif (preg_match('/^test(.+)$/', $reflectionMethod->getName())) {
                        $test = new Test($testSuite, $reflectionMethod);
                    }

                    if (null !== $test) {
                        $testSuite->tests[$test->getIdentifier()] = $test;
                    }
                }
            }
        }

        return $suites;
    }
}
