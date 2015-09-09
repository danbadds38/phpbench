<?php

/*
 * This file is part of the PHP Bench package
 *
 * (c) Daniel Leech <daniel@dantleech.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpBench\Benchmark;

use PhpBench\PhpBench;
use PhpBench\ProgressLogger\NullProgressLogger;
use PhpBench\ProgressLoggerInterface;

/**
 * The benchmark runner.
 */
class Runner
{
    private $logger;
    private $collectionBuilder;
    private $iterationsOverride;
    private $revsOverride;
    private $configPath;
    private $parametersOverride;
    private $subjectsOverride = array();
    private $groups = array();
    private $executor;

    /**
     * @param CollectionBuilder $collectionBuilder
     * @param SubjectBuilder $subjectBuilder
     * @param string $configPath
     */
    public function __construct(
        CollectionBuilder $collectionBuilder,
        Executor $executor,
        $configPath
    ) {
        $this->logger = new NullProgressLogger();
        $this->collectionBuilder = $collectionBuilder;
        $this->executor = $executor;
        $this->configPath = $configPath;
    }

    /**
     * Whitelist of subject method names.
     *
     * @param string[] $subjects
     */
    public function overrideSubjects(array $subjects)
    {
        $this->subjectsOverride = $subjects;
    }

    /**
     * Set the progress logger to use.
     *
     * @param ProgressLoggerInterface
     */
    public function setProgressLogger(ProgressLoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Override the number of iterations to execute.
     *
     * @param int $iterations
     */
    public function overrideIterations($iterations)
    {
        $this->iterationsOverride = $iterations;
    }

    /**
     * Override the number of rev(olutions) to run.
     *
     * @param int
     */
    public function overrideRevs($revs)
    {
        $this->revsOverride = $revs;
    }

    public function overrideParameters($parameters)
    {
        $this->parametersOverride = $parameters;
    }

    /**
     * Whitelist of groups to execute.
     *
     * @param string[]
     */
    public function setGroups(array $groups)
    {
        $this->groups = $groups;
    }

    /**
     * Set the path to the configuration file.
     * This is required when launching a new process.
     *
     * @param string $configPath
     */
    public function setConfigPath($configPath)
    {
        $this->configPath = $configPath;
    }

    /**
     * Run all benchmarks (or all applicable benchmarks) in the given path.
     *
     * @param string
     */
    public function runAll($path)
    {
        $dom = new SuiteDocument();
        $suiteEl = $dom->createElement('phpbench');
        $suiteEl->setAttribute('version', PhpBench::VERSION);

        $collection = $this->collectionBuilder->buildCollection($path, $this->subjectsOverride, $this->groups);

        foreach ($collection->getBenchmarks() as $benchmark) {
            $benchmarkEl = $dom->createElement('benchmark');
            $benchmarkEl->setAttribute('class', $benchmark->getClassFqn());

            $this->logger->benchmarkStart($benchmark);
            $this->run($benchmark, $benchmarkEl);
            $this->logger->benchmarkEnd($benchmark);

            $suiteEl->appendChild($benchmarkEl);
        }

        $dom->appendChild($suiteEl);

        return $dom;
    }

    private function run(Benchmark $benchmark, \DOMElement $benchmarkEl)
    {
        foreach ($benchmark->getSubjects() as $subject) {
            $subjectEl = $benchmarkEl->ownerDocument->createElement('subject');
            $subjectEl->setAttribute('name', $subject->getMethodName());

            foreach ($subject->getGroups() as $group) {
                $groupEl = $benchmarkEl->ownerDocument->createElement('group');
                $groupEl->setAttribute('name', $group);
                $subjectEl->appendChild($groupEl);
            }

            $this->logger->subjectStart($subject);
            $this->runSubject($subject, $subjectEl);
            $this->logger->subjectEnd($subject);

            $benchmarkEl->appendChild($subjectEl);
        }
    }

    private function runSubject(Subject $subject, \DOMElement $subjectEl)
    {
        $iterationCount = null === $this->iterationsOverride ? $subject->getNbIterations() : $this->iterationsOverride;
        $revolutionCounts = $this->revsOverride ? array($this->revsOverride) : $subject->getRevs();
        $parameterSets = $this->parametersOverride ? array(array($this->parametersOverride)) : $subject->getParameterSets() ?: array(array(array()));

        $paramsIterator = new CartesianParameterIterator($parameterSets);

        foreach ($paramsIterator as $parameters) {
            $variantEl = $subjectEl->ownerDocument->createElement('variant');
            foreach ($parameters as $name => $value) {
                $parameterEl = $this->createParameter($subjectEl, $name, $value);
                $variantEl->appendChild($parameterEl);
            }

            $subjectEl->appendChild($variantEl);
            $this->runIterations($subject, $iterationCount, $revolutionCounts, $parameters, $variantEl);
        }
    }

    private function createParameter($parentEl, $name, $value)
    {
        $parameterEl = $parentEl->ownerDocument->createElement('parameter');
        $parameterEl->setAttribute('name', $name);

        if (is_array($value)) {
            $parameterEl->setAttribute('type', 'collection');
            foreach ($value as $key => $element) {
                $childEl = $this->createParameter($parameterEl, $key, $element);
                $parameterEl->appendChild($childEl);
            }

            return $parameterEl;
        }

        if (is_scalar($value)) {
            $parameterEl->setAttribute('value', $value);

            return $parameterEl;
        }

        throw new \InvalidArgumentException(sprintf(
            'Parameters must be either scalars or arrays, got: %s',
            is_object($value) ? get_class($value) : gettype($value)
        ));
    }

    private function runIterations(Subject $subject, $iterationCount, array $revolutionCounts, array $parameterSet, \DOMElement $variantEl)
    {
        for ($index = 0; $index < $iterationCount; $index++) {
            foreach ($revolutionCounts as $revolutionCount) {
                $iterationEl = $variantEl->ownerDocument->createElement('iteration');
                $variantEl->appendChild($iterationEl);
                $iterationEl->setAttribute('revs', $revolutionCount);
                $this->runIteration($subject, $revolutionCount, $parameterSet, $iterationEl);
            }
        }
    }

    private function runIteration(Subject $subject, $revolutionCount, $parameterSet, \DOMElement $iterationEl)
    {
        $result = $this->executor->execute(
            $subject,
            $revolutionCount,
            $parameterSet
        );

        $iterationEl->setAttribute('time', $result['time']);
        $iterationEl->setAttribute('memory', $result['memory']);
    }
}
