<?php

namespace App\Controller\Api;

use App\Api\ApiAccess;
use App\Background\AdminWorker;
use App\Background\FailedJobs;
use App\Background\TaskRegistry;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use App\Service\AdminLogReader;
use App\Service\QueueInspector;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route('/api/admin/tools')]
final class AdminToolsController
{
    #[Route('/tasks', methods: ['GET'])]
    public function tasks(Request $request, Security $security, TaskRegistry $monitor): JsonResponse
    {
        $locale = $this->authorize($request, $security);
        if ($locale instanceof JsonResponse) {
            return $locale;
        }

        return $this->collection($request, $monitor->tasks(), $locale);
    }

    #[Route('/queues', methods: ['GET'])]
    public function queues(Request $request, Security $security, QueueInspector $inspector, AdminWorker $worker): JsonResponse
    {
        $locale = $this->authorize($request, $security);
        if ($locale instanceof JsonResponse) {
            return $locale;
        }

        $response = $this->collection($request, $inspector->queues(), $locale);
        if ($response->getStatusCode() === 200) {
            $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
            $data['workers'] = $inspector->workers();
            $data['workerConfig'] = $worker->config();
            $response->setData($data);
        }

        return $response;
    }

    #[Route('/log-files', methods: ['GET'])]
    public function files(Request $request, Security $security, AdminLogReader $reader): JsonResponse
    {
        $locale = $this->authorize($request, $security);
        if ($locale instanceof JsonResponse) {
            return $locale;
        }

        return $this->response(['data' => $reader->files()]);
    }

    #[Route('/logs', methods: ['GET'])]
    public function logs(Request $request, Security $security, AdminLogReader $reader): JsonResponse
    {
        $locale = $this->authorize($request, $security);
        if ($locale instanceof JsonResponse) {
            return $locale;
        }
        try {
            return $this->response($reader->page($request->query->getString('file'), $request->query->getString('cursor'), $request->query->getInt('pageSize', 25)));
        } catch (\InvalidArgumentException) {
            return $this->response(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }
    }

    #[Route('/tasks/register', methods: ['POST'])]
    #[Route('/tasks/{name}/{action}', methods: ['POST'])]
    public function taskAction(Request $request, Security $security, CsrfTokenManagerInterface $csrf, TaskRegistry $tasks, ?string $name = null, ?string $action = null): JsonResponse
    {
        $locale = $this->authorize($request, $security);
        if ($locale instanceof JsonResponse) {
            return $locale;
        }
        if ($error = ApiAccess::requireCsrf($request, $csrf, $locale)) {
            return $error;
        }
        try {
            return $this->response(['data' => $name === null ? ['registered' => $tasks->register()] : ['jobId' => $tasks->action($name, $action ?? '')]], 202);
        } catch (\InvalidArgumentException|\DomainException $error) {
            return $this->response(['error' => ApiMessages::get('invalid_request', $locale)], $error instanceof \DomainException ? 409 : 400);
        }
    }

    #[Route('/failed', methods: ['GET'])]
    public function failed(Request $request, Security $security, QueueInspector $inspector): JsonResponse
    {
        $locale = $this->authorize($request, $security);
        if ($locale instanceof JsonResponse) {
            return $locale;
        }
        $page = $request->query->getInt('page', 1);
        $size = $request->query->getInt('pageSize', 25);
        if ($page < 1 || $page > 10000 || !in_array($size, [25, 50, 100], true)) {
            return $this->response(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }
        $rows = $inspector->failed($page, $size);

        return $this->response(['data' => array_slice($rows, 0, $size), 'meta' => ['page' => $page, 'pageSize' => $size, 'hasMore' => count($rows) > $size]]);
    }

    #[Route('/failed/{id}/{action}', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function failedAction(int $id, string $action, Request $request, Security $security, CsrfTokenManagerInterface $csrf, FailedJobs $jobs): JsonResponse
    {
        $locale = $this->authorize($request, $security);
        if ($locale instanceof JsonResponse) {
            return $locale;
        }
        if ($error = ApiAccess::requireCsrf($request, $csrf, $locale)) {
            return $error;
        }
        try {
            $jobs->action($id, $action);

            return $this->response(['data' => ['accepted' => true]], 202);
        } catch (\InvalidArgumentException|\DomainException $error) {
            return $this->response(['error' => ApiMessages::get('invalid_request', $locale)], $error instanceof \DomainException ? 409 : 400);
        }
    }

    #[Route('/worker/config', methods: ['GET'])]
    public function workerConfig(Request $request, Security $security, AdminWorker $worker): JsonResponse
    {
        $locale = $this->authorize($request, $security);

        return $locale instanceof JsonResponse ? $locale : $this->response(['data' => $worker->config()]);
    }

    #[Route('/worker/consume', methods: ['POST'])]
    public function workerConsume(Request $request, Security $security, CsrfTokenManagerInterface $csrf, AdminWorker $worker): JsonResponse
    {
        $locale = $this->authorize($request, $security);
        if ($locale instanceof JsonResponse) {
            return $locale;
        }
        if ($error = ApiAccess::requireCsrf($request, $csrf, $locale)) {
            return $error;
        }
        if (!$worker->config()['enabled']) {
            return $this->response(['error' => ApiMessages::get('forbidden', $locale)], 403);
        }
        // Release the PHP session before bounded external deliveries.
        if ($request->hasSession()) {
            $request->getSession()->save();
        }

        return $this->response(['data' => $worker->consume()]);
    }

    private function authorize(Request $request, Security $security): string|JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return $this->response(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        $admin = ApiAccess::requireRole($security, 'ROLE_ADMIN', $locale);
        if ($admin instanceof JsonResponse) {
            $admin->headers->set('Cache-Control', 'private, no-store');

            return $admin;
        }

        return $locale;
    }

    private function collection(Request $request, array $rows, string $locale): JsonResponse
    {
        $page = $request->query->getInt('page', 1);
        $size = $request->query->getInt('pageSize', 25);
        if ($page < 1 || $page > 10000 || !in_array($size, [25, 50, 100], true)) {
            return $this->response(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }

        return $this->response(['data' => array_slice($rows, ($page - 1) * $size, $size), 'meta' => [
            'page' => $page, 'pageSize' => $size, 'total' => count($rows), 'hasMore' => $page * $size < count($rows),
        ]]);
    }

    private function response(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex']);
    }
}
