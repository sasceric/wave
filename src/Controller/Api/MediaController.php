<?php

namespace App\Controller\Api;

use App\Api\ApiAccess;
use App\Api\MediaResource;
use App\Entity\Campaign;
use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\CreatorPortfolioMedia;
use App\Entity\Media;
use App\Entity\MediaFolder;
use App\Entity\User;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use App\Media\ImageVariants;
use App\Service\MediaStorage;
use App\Service\MediaThumbnails;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class MediaController
{
    private const MAX_FILE_SIZE = 10_485_760;

    private const FOLDERS = [
        'creator-avatar' => ['name' => 'Creator avatars', 'role' => 'ROLE_CREATOR'],
        'creator-portfolio' => ['name' => 'Creator portfolio', 'role' => 'ROLE_CREATOR'],
        'company-logo' => ['name' => 'Company logos', 'role' => 'ROLE_COMPANY'],
        'campaign-cover' => ['name' => 'Campaign covers', 'role' => 'ROLE_COMPANY'],
    ];

    #[Route('/api/media', name: 'api_media_index', methods: ['GET'])]
    public function index(
        Request $request,
        EntityManagerInterface $entityManager,
        Security $security,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        $user = $this->currentUser($security, $locale);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $criteria = ['owner' => $user];
        $folderSlug = $request->query->getString('folder');
        if ($folderSlug !== '') {
            if (!isset(self::FOLDERS[$folderSlug])) {
                return new JsonResponse(['error' => ApiMessages::get('invalid_media', $locale)], 400);
            }
            $folder = $entityManager->getRepository(MediaFolder::class)->findOneBy(['slug' => $folderSlug]);
            if (!$folder instanceof MediaFolder) {
                return new JsonResponse(['error' => ApiMessages::get('invalid_media', $locale)], 400);
            }
            $criteria['folder'] = $folder;
        }

        $media = $entityManager->getRepository(Media::class)->findBy($criteria, ['createdAt' => 'DESC'], 100);

        return new JsonResponse(['data' => array_map(MediaResource::fromEntity(...), $media)]);
    }

    #[Route('/api/media', name: 'api_media_upload', methods: ['POST'])]
    public function upload(
        Request $request,
        EntityManagerInterface $entityManager,
        Security $security,
        CsrfTokenManagerInterface $tokenManager,
        MediaStorage $storage,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if ($csrfError = ApiAccess::requireCsrf($request, $tokenManager, $locale)) {
            return $csrfError;
        }

        $folderSlug = $request->query->getString('folder');
        $folderSettings = self::FOLDERS[$folderSlug] ?? null;
        if ($folderSettings === null) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_media', $locale)], 400);
        }
        $user = ApiAccess::requireRole($security, $folderSettings['role'], $locale);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile
            || !$file->isValid()
            || $file->getSize() === null
            || $file->getSize() > self::MAX_FILE_SIZE
            || !$storage->supports($file)
        ) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_media', $locale)], 400);
        }

        $folder = $entityManager->getRepository(MediaFolder::class)->findOneBy(['slug' => $folderSlug]);
        if (!$folder instanceof MediaFolder) {
            $folder = new MediaFolder($folderSlug, $folderSettings['name']);
            $entityManager->persist($folder);
        }

        try {
            $stored = $storage->store($file, $folder, $user);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_media', $locale)], 400);
        }
        $originalName = preg_replace('~[\\\\/]+~', '_', basename($file->getClientOriginalName())) ?? 'image';
        $media = new Media(
            $folder,
            $user,
            mb_substr($originalName, 0, 255),
            $stored['path'],
            $stored['mimeType'],
            $stored['fileSize'],
        );
        $media->setDimensions($stored['width'], $stored['height']);
        $entityManager->persist($media);
        $entityManager->flush();

        return new JsonResponse(['data' => MediaResource::fromEntity($media)], 201);
    }

    #[Route('/api/media/{id}/file', name: 'api_media_file', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[Route('/api/media/{id}/thumbnail/{version}/{width}', name: 'api_media_thumbnail', requirements: ['id' => '\d+', 'version' => ImageVariants::VERSION, 'width' => '96|320|480'], methods: ['GET'])]
    public function file(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        Security $security,
        MediaStorage $storage,
        MediaThumbnails $thumbnails,
        ?int $width = null,
    ): Response
    {
        $media = $entityManager->getRepository(Media::class)->find($id);
        if (!$media instanceof Media) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_media', 'bs')], 404);
        }
        $isPublic = $media->getOwner()->isApproved() && $this->isPublicMedia($media, $entityManager);
        $user = $security->getUser();
        if (!$isPublic
            && (!$user instanceof User
                || ($user->getId() !== $media->getOwner()->getId()
                    && !$security->isGranted('ROLE_ADMIN')))
        ) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_media', 'bs')], 404);
        }
        try {
            $path = $width === null
                ? $storage->absolutePath($media->getStoragePath())
                : $thumbnails->path($media, $width);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_media', 'bs')], 404);
        }
        if (!is_file($path)) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_media', 'bs')], 404);
        }

        $response = new BinaryFileResponse($path, autoEtag: true);
        $response->headers->set('Content-Type', $width === null ? $media->getMimeType() : 'image/webp');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', $isPublic
            ? ($user instanceof User ? 'private' : 'public').', max-age=300, must-revalidate'
            : 'private, no-store');
        if ($isPublic) {
            // Public pixels contain no session data. Signed-in users retain a
            // private browser cache; Symfony must not reset its lifetime to zero.
            $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        }
        $response->setVary('Cookie');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            basename($path),
        );
        // Always authorize first, including conditional requests for cached images.
        if ($isPublic) {
            $response->isNotModified($request);
        }

        return $response;
    }

    #[Route('/api/media/{id}', name: 'api_media_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        Security $security,
        CsrfTokenManagerInterface $tokenManager,
        MediaStorage $storage,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if ($csrfError = ApiAccess::requireCsrf($request, $tokenManager, $locale)) {
            return $csrfError;
        }
        $user = $this->currentUser($security, $locale);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $media = $entityManager->getRepository(Media::class)->find($id);
        if (!$media instanceof Media || $media->getOwner() !== $user) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_media', $locale)], 404);
        }
        $isReferenced = $entityManager->getRepository(CreatorPortfolioMedia::class)->count(['media' => $media]) > 0
            || $entityManager->getRepository(Creator::class)->count(['avatarMedia' => $media]) > 0
            || $entityManager->getRepository(Company::class)->count(['logoMedia' => $media]) > 0
            || $entityManager->getRepository(Campaign::class)->count(['coverMedia' => $media]) > 0;
        if ($isReferenced) {
            return new JsonResponse(['error' => ApiMessages::get('media_in_use', $locale)], 409);
        }

        $entityManager->remove($media);
        $entityManager->flush();
        $storage->remove($media);

        return new JsonResponse(['data' => ['id' => $id]]);
    }

    private function currentUser(Security $security, string $locale): User|JsonResponse
    {
        $user = $security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => ApiMessages::get('authentication_required', $locale)], 401);
        }

        return $user;
    }

    private function isPublicMedia(Media $media, EntityManagerInterface $entityManager): bool
    {
        $creatorAvatarCount = $entityManager->getRepository(Creator::class)->createQueryBuilder('creator')
            ->select('COUNT(creator.id)')
            ->leftJoin('creator.owner', 'owner')
            ->andWhere('creator.avatarMedia = :media')
            ->andWhere('(owner.id IS NULL OR (owner.approved = :approved AND owner.hideMyAccount = :visible))')
            ->setParameter('media', $media)
            ->setParameter('approved', true)
            ->setParameter('visible', false)
            ->getQuery()
            ->getSingleScalarResult();
        if ((int) $creatorAvatarCount > 0) {
            return true;
        }

        $creatorPortfolioCount = $entityManager->getRepository(CreatorPortfolioMedia::class)->createQueryBuilder('portfolioMedia')
            ->select('COUNT(portfolioMedia.id)')
            ->join('portfolioMedia.creator', 'creator')
            ->leftJoin('creator.owner', 'owner')
            ->andWhere('portfolioMedia.media = :media')
            ->andWhere('(owner.id IS NULL OR (owner.approved = :approved AND owner.hideMyAccount = :visible))')
            ->setParameter('media', $media)
            ->setParameter('approved', true)
            ->setParameter('visible', false)
            ->getQuery()
            ->getSingleScalarResult();
        if ((int) $creatorPortfolioCount > 0) {
            return true;
        }

        $companyLogoCount = $entityManager->getRepository(Company::class)->createQueryBuilder('company')
            ->select('COUNT(company.id)')
            ->leftJoin('company.owner', 'owner')
            ->andWhere('company.logoMedia = :media')
            ->andWhere('(owner.id IS NULL OR (owner.approved = :approved AND owner.hideMyAccount = :visible))')
            ->setParameter('media', $media)
            ->setParameter('approved', true)
            ->setParameter('visible', false)
            ->getQuery()
            ->getSingleScalarResult();
        if ((int) $companyLogoCount > 0) {
            return true;
        }

        $campaignCoverCount = $entityManager->getRepository(Campaign::class)->createQueryBuilder('campaign')
            ->select('COUNT(campaign.id)')
            ->join('campaign.company', 'company')
            ->leftJoin('company.owner', 'owner')
            ->andWhere('campaign.coverMedia = :media')
            ->andWhere('(owner.id IS NULL OR (owner.approved = :approved AND owner.hideMyAccount = :visible))')
            ->setParameter('media', $media)
            ->setParameter('approved', true)
            ->setParameter('visible', false)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $campaignCoverCount > 0;
    }
}
