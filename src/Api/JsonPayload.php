<?php

namespace App\Api;

use Symfony\Component\HttpFoundation\Request;

final class JsonPayload
{
    public static function fromRequest(Request $request): ?array
    {
        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }
}
