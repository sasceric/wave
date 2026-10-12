<?php

namespace App\Controller\Api;

use App\Service\UrlRedirects;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class RedirectController
{
    #[Route('/api/redirects/resolve', methods: ['GET'])]
    public function resolve(Request $request, UrlRedirects $redirects): JsonResponse
    {
        $path = $request->query->getString('path');
        if (strlen($path) > 2048) {
            return new JsonResponse(['data' => null], 400, ['Cache-Control' => 'no-store']);
        }
        [$path, $query] = array_pad(explode('?', $path, 2), 2, null);
        $target = $redirects->resolve($path, $query);

        return new JsonResponse(['data' => $target === null ? null : ['targetPath' => $target, 'statusCode' => 301]], headers: ['Cache-Control' => 'no-store']);
    }
}
