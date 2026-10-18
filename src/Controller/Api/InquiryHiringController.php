<?php

namespace App\Controller\Api;

use App\Account\AccountDeletion;
use App\Api\ApiAccess;
use App\Api\Currency;
use App\Api\InquiryOfferResource;
use App\Api\JsonPayload;
use App\Entity\CreatorInquiry;
use App\Entity\InquiryMessage;
use App\Entity\InquiryOffer;
use App\Entity\User;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use App\Safety\BlockPolicy;
use App\Service\RealtimeUpdatePublisher;
use App\Service\WebPushNotificationSender;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class InquiryHiringController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly BlockPolicy $blocks,
        private readonly RealtimeUpdatePublisher $publisher,
        private readonly WebPushNotificationSender $push,
    ) {}

    #[Route('/api/company/inquiries/{id}/hire', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function hire(int $id, Request $request): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return $this->error('unsupported_language', 'bs', 400);
        if ($error = ApiAccess::requireCsrf($request, $this->csrf, $locale)) return $error;
        $user = ApiAccess::requireRole($this->security, 'ROLE_COMPANY', $locale, true);
        if ($user instanceof JsonResponse) return $user;
        $inquiry = $this->em->find(CreatorInquiry::class, $id);
        if (!$inquiry || $inquiry->getCompany()->getOwner()?->getId() !== $user->getId()) return $this->error('invalid_request', $locale, 404);
        if ($error = $this->access($inquiry, $locale)) return $error;
        $data = JsonPayload::fromRequest($request);
        $amount = $data['amount'] ?? null;
        $currency = $data['currency'] ?? null;
        $message = is_string($data['message'] ?? null) ? trim($data['message']) : '';
        if (!is_int($amount) || $amount < 1 || $amount > 10000000 || !Currency::isSupported($currency) || mb_strlen($message) < 10 || mb_strlen($message) > 1500) return $this->error('invalid_offer', $locale, 400);
        $date = $data['dueDate'] ?? null;
        $dueDate = is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1 ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        if (!$dueDate || $dueDate->format('Y-m-d') !== $date || $dueDate < new DateTimeImmutable('today')) return $this->error('invalid_offer_due_date', $locale, 400);
        $result = $this->em->wrapInTransaction(function () use ($inquiry, $amount, $currency, $message, $dueDate, $user, $locale): array|JsonResponse {
            $this->lock($inquiry);
            if ($error = $this->access($inquiry, $locale)) return $error;
            if ($inquiry->getStatus() !== 'accepted') return $this->error('invalid_request', $locale, 409);
            if ($inquiry->getOffer() && $inquiry->getOffer()->getStatus() !== 'rejected') return $this->error('offer_exists', $locale, 409);
            $offer = new InquiryOffer($inquiry, $amount, $currency, $dueDate, $message);
            $this->em->persist($offer);
            $this->em->flush();
            return ['offer' => $offer, 'message' => $this->record($offer, $user, 'offer_sent', $locale)];
        });
        return $this->finish($result, $user, 201);
    }

    #[Route('/api/me/inquiry-offers/{id}/respond', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[Route('/api/me/inquiry-offers/{id}/cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function respond(int $id, Request $request): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return $this->error('unsupported_language', 'bs', 400);
        if ($error = ApiAccess::requireCsrf($request, $this->csrf, $locale)) return $error;
        $user = ApiAccess::requireParticipant($this->security, $locale, true);
        if ($user instanceof JsonResponse) return $user;
        $offer = $this->em->find(InquiryOffer::class, $id);
        if (!$offer) return $this->error('offer_not_found', $locale, 404);
        $inquiry = $offer->getInquiry();
        $cancelling = str_ends_with($request->getPathInfo(), '/cancel');
        $provider = $inquiry->getCreator()->getOwner()?->getId();
        $client = $inquiry->getCompany()->getOwner()?->getId();
        if (!in_array($user->getId(), $cancelling ? [$provider, $client] : [$provider], true)) return $this->error('offer_not_found', $locale, 404);
        if ($error = $this->access($inquiry, $locale)) return $error;
        $data = JsonPayload::fromRequest($request);
        $decision = $data['decision'] ?? null;
        $reason = is_string($data['reason'] ?? null) ? trim($data['reason']) : '';
        if (!$cancelling && !in_array($decision, ['accept', 'reject'], true)) return $this->error('invalid_offer_response', $locale, 400);
        $result = $this->em->wrapInTransaction(function () use ($inquiry, $offer, $cancelling, $decision, $reason, $user, $locale): array|JsonResponse {
            $this->lock($inquiry);
            $this->em->refresh($offer);
            if ($error = $this->access($inquiry, $locale)) return $error;
            if ($inquiry->getOffer()?->getId() !== $offer->getId() || $inquiry->getStatus() !== 'accepted') return $this->error('offer_not_pending', $locale, 409);
            if ($cancelling) {
                if ($offer->getStatus() === 'cancelled') return ['offer' => $offer, 'message' => null];
                if ($offer->getStatus() !== 'accepted') return $this->error('collaboration_not_active', $locale, 409);
                if (mb_strlen($reason) < 10 || mb_strlen($reason) > 1000) return $this->error('invalid_cancellation_reason', $locale, 400);
                $offer->cancel($reason);
                $type = 'cancelled';
            } else {
                if ($offer->getStatus() !== 'pending') return $this->error('offer_not_pending', $locale, 409);
                if ($decision === 'accept' && $offer->getDueDate() < new DateTimeImmutable('today')) return $this->error('invalid_offer_due_date', $locale, 409);
                $offer->respond($decision === 'accept' ? 'accepted' : 'rejected');
                $type = $decision === 'accept' ? 'hired' : 'offer_declined';
            }
            return ['offer' => $offer, 'message' => $this->record($offer, $user, $type, $locale)];
        });
        return $this->finish($result, $user);
    }

    private function access(CreatorInquiry $inquiry, string $locale): ?JsonResponse
    {
        if (AccountDeletion::sharedHistoryDeleted($inquiry->getCreator(), $inquiry->getCompany())) return $this->error('account_deleted_history', $locale, 409);
        if ($this->blocks->pair($inquiry->getCreator()->getOwner()?->getId(), $inquiry->getCompany()->getOwner()?->getId())) return $this->error('blocked_history', $locale, 409);
        return null;
    }

    private function lock(CreatorInquiry $inquiry): void
    {
        $this->em->lock($inquiry, LockMode::PESSIMISTIC_WRITE);
        $this->em->refresh($inquiry);
    }

    private function record(InquiryOffer $offer, User $actor, string $type, string $locale): InquiryMessage
    {
        $body = ApiMessages::get('hiring_'.$type, $locale).' '.$offer->getAmount().' '.$offer->getCurrency().' · '.$offer->getDueDate()->format('Y-m-d');
        $body .= $type === 'offer_sent' ? "\n".$offer->getMessage() : ($offer->getCancellationReason() ? "\n".$offer->getCancellationReason() : '');
        $message = new InquiryMessage($offer->getInquiry(), $actor, $body, [
            'context' => 'inquiry', 'type' => $type, 'offerId' => $offer->getId(),
            'amount' => $offer->getAmount(), 'currency' => $offer->getCurrency(),
            'dueDate' => $offer->getDueDate()->format('Y-m-d'),
            'message' => $type === 'offer_sent' ? $offer->getMessage() : null,
            'cancellationReason' => $offer->getCancellationReason(),
        ]);
        $this->em->persist($message);
        return $message;
    }

    /** @param array{offer: InquiryOffer, message: ?InquiryMessage}|JsonResponse $result */
    private function finish(array|JsonResponse $result, User $actor, int $status = 200): JsonResponse
    {
        if ($result instanceof JsonResponse) return $result;
        if ($message = $result['message']) {
            $inquiry = $result['offer']->getInquiry();
            $recipient = $inquiry->getCreator()->getOwner()?->getId() === $actor->getId() ? $inquiry->getCompany()->getOwner() : $inquiry->getCreator()->getOwner();
            if ($recipient instanceof User) {
                $resource = CreatorInquiryController::messageResource($message, $inquiry);
                $this->push->sendInquiry($recipient, $actor, (int) $inquiry->getId(), $message->getBody(), $message->getId());
                $this->publisher->publishEvent($recipient, ['type' => 'chat_message', 'notificationType' => 'chat_message', 'inquiryId' => $inquiry->getId(), 'message' => $resource]);
            }
        }
        return new JsonResponse(['data' => InquiryOfferResource::fromEntity($result['offer'])], $status, ['Cache-Control' => 'private, no-store']);
    }

    private function error(string $key, string $locale, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ApiMessages::get($key, $locale)], $status);
    }
}
