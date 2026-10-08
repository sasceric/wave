<?php

namespace App\Api;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/** Signed, filter-bound keyset positions for public directory batches. */
final class DirectoryCursor
{
    public function __construct(#[Autowire('%kernel.secret%')] private readonly string $secret)
    {
    }

    public function enabled(Request $request): bool
    {
        $mode = $request->query->getString('pagination', 'offset');
        if (!in_array($mode, ['offset', 'cursor'], true)
            || ($request->query->has('cursor') && $mode !== 'cursor')
            || ($mode === 'cursor' && $request->query->getInt('offset') !== 0)) {
            throw new \InvalidArgumentException('Invalid directory pagination mode.');
        }

        return $mode === 'cursor';
    }

    /** @param array<string, 'string'|'int'|'bool'|'date'> $types */
    public function decode(Request $request, string $kind, array $types): ?array
    {
        if (!$request->query->has('cursor')) {
            return null;
        }
        $value = $request->query->getString('cursor');
        if (strlen($value) > 4096 || !preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D', $value, $parts)
            || !hash_equals(hash_hmac('sha256', $parts[1], $this->secret), $parts[2])) {
            throw new \InvalidArgumentException('Invalid directory cursor.');
        }
        try {
            $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/'), true) ?: '', true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Invalid directory cursor.');
        }
        if (!is_array($payload) || ($payload['scope'] ?? null) !== $this->scope($request, $kind)
            || !is_array($payload['position'] ?? null) || array_keys($payload['position']) !== array_keys($types)) {
            throw new \InvalidArgumentException('Directory cursor does not match this query.');
        }
        foreach ($types as $key => $type) {
            $value = $payload['position'][$key];
            $valid = match ($type) {
                'int' => is_int($value) && $value >= 0 && $value <= 2147483647,
                'bool' => is_bool($value),
                'string' => is_string($value) && mb_strlen($value) <= 500,
                'date' => is_string($value) && $this->validDate($value),
            };
            if (!$valid || ($key === 'id' && $value === 0)) {
                throw new \InvalidArgumentException('Invalid directory position.');
            }
        }

        return $payload['position'];
    }

    public function encode(Request $request, string $kind, array $position): string
    {
        $data = rtrim(strtr(base64_encode(json_encode([
            'scope' => $this->scope($request, $kind), 'position' => $position,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $data . '.' . hash_hmac('sha256', $data, $this->secret);
    }

    /** @param array<string, array{0: string, 1: 'ASC'|'DESC'}> $columns */
    public function seek(QueryBuilder $builder, array $columns, ?array $position): void
    {
        if ($position === null) {
            return;
        }
        $branches = [];
        $equals = [];
        foreach ($columns as $key => [$column, $direction]) {
            $parameter = 'directoryCursor_' . $key;
            $operator = $direction === 'DESC' ? '<' : '>';
            $branches[] = '(' . implode(' AND ', [...$equals, $column . ' ' . $operator . ' :' . $parameter]) . ')';
            $equals[] = $column . ' = :' . $parameter;
            if ($key === 'date') {
                $builder->setParameter($parameter, new \DateTimeImmutable($position[$key]), Types::DATETIME_IMMUTABLE);
            } else {
                $builder->setParameter($parameter, $position[$key]);
            }
        }
        // Give the index a leading range bound as well as the exclusive tie-break.
        $firstKey = array_key_first($columns);
        [$firstColumn, $firstDirection] = $columns[$firstKey];
        $builder->andWhere($firstColumn . ($firstDirection === 'DESC' ? ' <= ' : ' >= ') . ':directoryCursor_' . $firstKey);
        $builder->andWhere(implode(' OR ', $branches));
    }

    private function scope(Request $request, string $kind): string
    {
        $filters = [];
        foreach (['locale', 'q', 'platform', 'category', 'categories', 'creatorTypes', 'platforms', 'audience', 'company', 'featured', 'industry', 'industries', 'city', 'country', 'countries', 'verified', 'sort', 'channels', 'location', 'currency', 'budgetMin', 'budgetMax'] as $key) {
            $filters[$key] = trim($request->query->getString($key, $key === 'locale' ? 'bs' : ''));
        }

        return hash('sha256', json_encode([$kind, $filters], JSON_THROW_ON_ERROR));
    }

    private function validDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        $errors = \DateTimeImmutable::getLastErrors();

        return $date !== false && $date->format('Y-m-d H:i:s') === $value
            && ($errors === false || (!$errors['warning_count'] && !$errors['error_count']));
    }
}
