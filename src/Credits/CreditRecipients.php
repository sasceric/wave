<?php

declare(strict_types=1);

namespace App\Credits;

use App\Api\DirectoryCursor;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

/** Admin-only account search: stable batches of twenty, with actual user IDs. */
final class CreditRecipients
{
    public function __construct(private readonly Connection $db, private readonly DirectoryCursor $cursor)
    {
    }

    public function page(Request $request): array
    {
        $type = $request->query->getString('accountType');
        $query = trim($request->query->getString('q'));
        if (!in_array($type, ['creator', 'company'], true) || mb_strlen($query) > 200 || $request->query->getInt('limit', 20) !== 20) {
            throw new CreditException('invalid_request');
        }
        $kind = 'credit-recipient-'.$type;
        try {
            $position = $this->cursor->decode($request, $kind, ['name' => 'string', 'id' => 'int']);
        } catch (\InvalidArgumentException) {
            throw new CreditException('invalid_request');
        }
        $name = $type === 'creator' ? 'p.display_name' : 'p.name';
        $profile = $type === 'creator' ? 'creator' : 'company';
        $sql = "SELECT u.id, $name AS name, u.email, LOWER($name) AS sort_name FROM wave_user u JOIN $profile p ON p.owner_id = u.id WHERE u.role = :role";
        $params = ['role' => $type === 'creator' ? 'ROLE_CREATOR' : 'ROLE_COMPANY'];
        if ($query !== '') {
            $sql .= " AND (LOWER($name) LIKE :q ESCAPE '!' OR LOWER(u.email) LIKE :q ESCAPE '!')";
            $params['q'] = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($query)).'%';
        }
        if ($position !== null) {
            $sql .= " AND (LOWER($name) > :name OR (LOWER($name) = :name AND u.id > :id))";
            $params += $position;
        }
        $rows = $this->db->fetchAllAssociative($sql." ORDER BY LOWER($name) ASC, u.id ASC LIMIT 21", $params);
        $hasMore = count($rows) > 20;
        $rows = array_slice($rows, 0, 20);
        $last = $rows === [] ? null : $rows[array_key_last($rows)];
        return [
            'rows' => array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'name' => $row['name'], 'email' => $row['email']], $rows),
            'hasMore' => $hasMore,
            'nextCursor' => !$hasMore || $last === null ? null : $this->cursor->encode($request, $kind, ['name' => $last['sort_name'], 'id' => (int) $last['id']]),
        ];
    }
}
