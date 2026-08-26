<?php

declare(strict_types=1);

namespace App\support;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;
use Webman\Container;
use Webman\Exception\NotFoundException;

final class AutowireContainer extends Container
{
    /** @param array<string, object|class-string|Closure> $bindings */
    public function __construct(private readonly array $bindings = [])
    {
    }

    public function get(string $name): mixed
    {
        if (!array_key_exists($name, $this->instances)) {
            $this->instances[$name] = array_key_exists($name, $this->bindings)
                ? $this->resolveBinding($this->bindings[$name])
                : $this->resolve($name);
        }

        return $this->instances[$name];
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->bindings)
            || class_exists($name)
            || parent::has($name);
    }

    /** @param array<array-key, mixed> $constructor */
    public function make(string $name, array $constructor = []): mixed
    {
        if ($constructor !== []) {
            if (!class_exists($name)) {
                throw new NotFoundException("Class '$name' not found");
            }

            return new $name(...array_values($constructor));
        }

        return $this->resolve($name);
    }

    private function resolve(string $name): object
    {
        if (!class_exists($name)) {
            throw new NotFoundException("Class '$name' not found");
        }

        $class = new ReflectionClass($name);
        if (!$class->isInstantiable()) {
            throw new RuntimeException("Class '$name' is not instantiable");
        }

        $constructor = $class->getConstructor();
        if ($constructor === null) {
            return $class->newInstance();
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $arguments[] = $this->get($type->getName());
                continue;
            }
            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }
            if ($parameter->allowsNull()) {
                $arguments[] = null;
                continue;
            }

            throw new RuntimeException(sprintf(
                'Cannot resolve parameter $%s of %s',
                $parameter->getName(),
                $name,
            ));
        }

        return $class->newInstanceArgs($arguments);
    }

    private function resolveBinding(object|string $binding): object
    {
        if ($binding instanceof Closure) {
            $resolved = $binding($this);
            if (!is_object($resolved)) {
                throw new RuntimeException('Container binding factory must return an object');
            }
            return $resolved;
        }
        if (is_string($binding)) {
            return $this->get($binding);
        }

        return $binding;
    }
}
