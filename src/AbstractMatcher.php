<?php

namespace HackRouting;

use HackRouting\Cache\CacheInterface;
use HackRouting\Cache\NullCache;
use HackRouting\HttpException\InternalServerErrorException;
use HackRouting\HttpException\MethodNotAllowedException;
use HackRouting\HttpException\NotFoundException;
use HackRouting\PrefixMatching\PrefixMap;
use Psl\Dict;
use Psl\Vec;
use Throwable;

use function rawurldecode;

/**
 * @template-covariant TResponder
 */
abstract class AbstractMatcher
{
    private CacheInterface $cache;

    /**
     * @var ?IResolver<TResponder>
     */
    private ?IResolver $resolver = null;

    public function __construct(?CacheInterface $cache = null, private bool $head_rewrite = true)
    {
        $this->cache = $cache ?? new NullCache();
    }

    /**
     * @return array<non-empty-string, array<string, TResponder>>
     */
    abstract protected function getRoutes(): array;

    /**
     * @param non-empty-string $method
     *
     * @return array{0: TResponder, 1: array<string, string>}
     *
     * @throws NotFoundException
     * @throws MethodNotAllowedException
     * @throws InternalServerErrorException
     */
    final public function match(string $method, string $path): array
    {
        $resolver = $this->getResolver();
        try {
            [$responder, $data] = $resolver->resolve($method, $path);
            $data = Dict\map($data, static fn(string $value): string => rawurldecode($value));
            return [$responder, $data];
        } catch (NotFoundException $e) {
            $allowed = $this->getAllowedMethods($path);
            if (!$allowed) {
                throw $e;
            }

            if ($this->head_rewrite && $method === HttpMethod::HEAD && $allowed === [HttpMethod::GET]) {
                [$responder, $data] = $resolver->resolve(HttpMethod::GET, $path);
                $data = Dict\map($data, static fn(string $value): string => rawurldecode($value));
                return [$responder, $data];
            }

            throw new MethodNotAllowedException($allowed);
        } catch (Throwable $throwable) {
            if ($throwable instanceof MethodNotAllowedException || $throwable instanceof NotFoundException) {
                throw $throwable;
            }

            throw new InternalServerErrorException('An Error accrued while resolving route.', (int)$throwable->getCode(), $throwable);
        }
    }

    /**
     * Return the list of HTTP Methods that are allowed for the given path.
     *
     * @return list<non-empty-string>
     */
    private function getAllowedMethods(string $path): array
    {
        $resolver = $this->getResolver();
        $allowed = [];
        foreach (Vec\keys($this->getRoutes()) as $method) {
            try {
                $resolver->resolve($method, $path);

                $allowed[] = $method;
            } catch (NotFoundException) {
                continue;
            }
        }

        return $allowed;
    }

    /**
     * @return IResolver<TResponder>
     */
    public function getResolver(): IResolver
    {
        if ($this->resolver !== null) {
            return $this->resolver;
        }

        $routes = $this->cache->fetch(__FILE__, function (): array {
            return Dict\map(
                $this->getRoutes(),
                /**
                 * @param array<string, TResponder> $method_routes
                 */
                static fn(array $method_routes): PrefixMap => PrefixMap::fromFlatMap($method_routes),
            );
        });

        $this->resolver = new PrefixMatchingResolver($routes);
        return $this->resolver;
    }
}