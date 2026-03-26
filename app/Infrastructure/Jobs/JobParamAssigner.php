<?php

namespace App\Infrastructure\Jobs;

use App\Infrastructure\Attributes\Assignable;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

trait JobParamAssigner
{
    /**
     * @param array<int, mixed> $args
     */
    private function assignParams(array $args): void
    {
        $handleMethodParams = $this->loadHandleMethodParams($args);
        if (! empty($handleMethodParams)) {
            $this->attachParams($handleMethodParams);
        }

    }

    /**
     * @param array<int, mixed> $args
     * @return array<string, mixed>
     */
    private function loadHandleMethodParams(array $args): array
    {
        $handleMethodParams = [];

        try {
            $reflection = new ReflectionMethod(static::class, 'handle');
            $parameters = $reflection->getParameters();
        } catch (ReflectionException) {
            return [];
        }

        foreach ($parameters as $index => $parameter) {
            $paramName = $parameter->getName();
            if (property_exists($this, $paramName) && isset($args[$index])) {
                $handleMethodParams[$paramName] = $args[$index];
            }
        }

        return $handleMethodParams;
    }

    /**
     * @param array<string, mixed> $handleMethodParams
     */
    private function attachParams(array $handleMethodParams): void
    {
        $reflectionClass = new ReflectionClass(static::class);
        foreach ($handleMethodParams as $paramName => $value) {
            try {
                $property = $reflectionClass->getProperty($paramName);
                $attributes = $property->getAttributes(Assignable::class);

                if (! empty($attributes)) {
                    $this->$paramName = $value;
                }
            } catch (ReflectionException $e) {
                // Silently fail if property reflection issues (e.g., not accessible)
            }
        }
    }
}
