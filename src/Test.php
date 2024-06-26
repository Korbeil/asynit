<?php

namespace Asynit;

/**
 * @internal
 */
final class Test
{
    public const STATE_PENDING = 'pending';
    public const STATE_RUNNING = 'running';
    public const STATE_SUCCESS = 'success';
    public const STATE_FAILURE = 'failure';
    public const STATE_SKIPPED = 'skipped';

    /** @var Test[] */
    private array $parents = [];

    /** @var array<array{ test: Test, skipIfFailed: bool }> */
    private array $children = [];

    /** @var mixed[] */
    private array $arguments = [];

    /** @var string[] */
    private array $assertions = [];

    private string $identifier;

    private string $displayName;

    public string $state;

    public float $startTime;

    public float $endTime;

    public string $output;

    public \Throwable $failure;

    /**
     * @param TestSuite<object>|null $suite
     */
    public function __construct(
        public readonly ?TestSuite $suite,
        public readonly \ReflectionMethod $method,
        ?string $identifier = null,
        public readonly bool $isRealTest = true,
    ) {
        $this->identifier = $identifier ?: sprintf(
            '%s::%s',
            $this->method->getDeclaringClass()->getName(),
            $this->method->getName()
        );
        $this->displayName = $this->identifier;
        $this->state = self::STATE_PENDING;
    }

    public function isCompleted(): bool
    {
        return in_array($this->state, [self::STATE_SUCCESS, self::STATE_FAILURE, self::STATE_SKIPPED], true);
    }

    public function isRunning(): bool
    {
        return self::STATE_RUNNING === $this->state;
    }

    public function isPending(): bool
    {
        return self::STATE_PENDING === $this->state;
    }

    public function canBeRun(): bool
    {
        if ($this->isCompleted() || $this->isRunning()) {
            return false;
        }

        foreach ($this->getParents() as $test) {
            if (!$test->isCompleted()) {
                return false;
            }
        }

        return true;
    }

    public function start(): void
    {
        $this->suite?->start();
        $this->startTime = microtime(true);
        $this->state = self::STATE_RUNNING;
    }

    public function success(string $output): void
    {
        $this->endTime = microtime(true);
        $this->output = $output;
        $this->state = self::STATE_SUCCESS;
        $this->suite?->tryEnd();
    }

    public function failure(string $output, \Throwable $error): void
    {
        $this->endTime = microtime(true);
        $this->output = $output;
        $this->state = self::STATE_FAILURE;
        $this->failure = $error;
        $this->suite?->tryEnd();
    }

    public function skipped(): void
    {
        $this->startTime = microtime(true);
        $this->endTime = microtime(true);
        $this->output = '';
        $this->state = self::STATE_SKIPPED;
        $this->suite?->tryEnd();
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getMethod(): \ReflectionMethod
    {
        return $this->method;
    }

    public function addChildren(Test $test, bool $skipIfFailed): void
    {
        $this->children[] = [
            'test' => $test,
            'skipIfFailed' => $skipIfFailed,
        ];
    }

    public function addParent(Test $test): void
    {
        $this->parents[] = $test;
    }

    public function addArgument(mixed $argument, Test $test): void
    {
        $this->arguments[$test->getIdentifier()] = $argument;
    }

    public function addAssertion(string $assertion): void
    {
        $this->assertions[] = $assertion;
    }

    /** @return string[] */
    public function getAssertions(): array
    {
        return $this->assertions;
    }

    public function getAssertionsCount(): int
    {
        return \count($this->assertions);
    }

    /**
     * @return Test[]
     */
    public function getParents(): array
    {
        return $this->parents;
    }

    /**
     * @return iterable<Test>
     */
    public function getChildren(bool $onlySkipIfFailed = false): iterable
    {
        foreach ($this->children as $child) {
            if ($onlySkipIfFailed && !$child['skipIfFailed']) {
                continue;
            }

            yield $child['test'];
        }
    }

    /** @return mixed[] */
    public function getArguments(): array
    {
        $args = [];
        $arguments = $this->arguments;

        foreach ($this->getParents() as $parent) {
            if (array_key_exists($parent->getIdentifier(), $arguments)) {
                $args[] = $arguments[$parent->getIdentifier()];
                unset($arguments[$parent->getIdentifier()]);
            }
        }

        return array_merge($args, array_values($arguments));
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): void
    {
        $this->displayName = $displayName;
    }

    public function getTime(): float
    {
        return $this->endTime - $this->startTime;
    }
}
