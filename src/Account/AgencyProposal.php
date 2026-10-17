<?php

namespace App\Account;

final class AgencyProposal
{
    public static function parse(mixed $data): array
    {
        if (!is_array($data) || array_is_list($data) && $data !== []) throw new \InvalidArgumentException('proposal');
        $result = [];
        foreach (['scope' => 2000, 'timeline' => 240, 'team' => 500] as $key => $limit) {
            $value = $data[$key] ?? '';
            if (!is_string($value) || mb_strlen($value) > $limit) throw new \InvalidArgumentException($key);
            $result[$key] = trim($value);
        }
        $amount = $data['amount'] ?? null;
        if ($amount !== null && (!is_int($amount) || $amount < 1 || $amount > 10_000_000)) throw new \InvalidArgumentException('amount');
        $result['amount'] = $amount;
        return array_filter($result, static fn ($value): bool => $value !== '' && $value !== null);
    }
}
