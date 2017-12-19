<?php

namespace Asynit;

/**
 * A test.
 */
class Test
{
    const STATE_PENDING = 'pending';
    const STATE_RUNNING = 'running';
    const STATE_SUCCESS = 'success';
    const STATE_FAILURE = 'failure';
    const STATE_SKIPPED = 'skipped';

    /** @var Test[] */
    private $parents = [];

    /** @var Test[] */
    private $children = [];

    /** @var array */
    private $arguments = [];

    /** @var \ReflectionMethod */
    private $method;

    private $assertions = [];

    private $identifier;

    private $state;

    private $displayName;

    private $chromeSession;

    public function __construct(\ReflectionMethod $reflectionMethod, $identifier = null)
    {
        $this->method = $reflectionMethod;
        $this->identifier = $identifier ?: sprintf(
            '%s::%s',
            $this->method->getDeclaringClass()->getName(),
            $this->method->getName()
        );
        $this->displayName = $this->identifier;
        $this->state = self::STATE_PENDING;
    }

    /**
     * @return string|null
     */
    public function getChromeSession()
    {
        return $this->chromeSession;
    }

    /**
     * @param string $chromeSession
     */
    public function setChromeSession(string $chromeSession)
    {
        $this->chromeSession = $chromeSession;
    }

    public function hasChromeSession(): bool
    {
        return $this->chromeSession !== null;
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

    /**
     * @return string
     */
    public function getState(): string
    {
        return $this->state;
    }

    /**
     * @param string $state
     */
    public function setState(string $state)
    {
        $this->state = $state;
    }

    /**
     * Return an unique identifier for this test.
     *
     * @return string
     */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * @return \ReflectionMethod
     */
    public function getMethod(): \ReflectionMethod
    {
        return $this->method;
    }

    public function addChildren(Test $test)
    {
        $this->children[] = $test;
    }

    public function addParent(Test $test)
    {
        $this->parents[] = $test;
    }

    public function addArgument($argument, Test $test)
    {
        $this->arguments[$test->getIdentifier()] = $argument;
    }

    public function addAssertion($assertion)
    {
        $this->assertions[] = $assertion;
    }

    /**
     * @return array
     */
    public function getAssertions(): array
    {
        return $this->assertions;
    }

    /**
     * @return Test[]
     */
    public function getParents(): array
    {
        return $this->parents;
    }

    /**
     * @return Test[]
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    /**
     * @return array
     */
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

    /**
     * @return string
     */
    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    /**
     * @param string $displayName
     */
    public function setDisplayName(string $displayName)
    {
        $this->displayName = $displayName;
    }
}
