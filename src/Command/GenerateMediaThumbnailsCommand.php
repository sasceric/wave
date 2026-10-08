<?php

namespace App\Command;

use App\Entity\Media;
use App\Service\MediaThumbnails;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:media:generate-thumbnails', description: 'Index image dimensions and generate cached thumbnails in bounded, resumable batches.')]
final class GenerateMediaThumbnailsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MediaThumbnails $thumbnails,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Number of media records held in memory.', '100');
        $this->addOption('after-id', null, InputOption::VALUE_REQUIRED, 'Resume after this media ID.', '0');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum records to process; zero processes all.', '0');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Regenerate existing thumbnails atomically.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $batchSize = filter_var($input->getOption('batch-size'), FILTER_VALIDATE_INT);
        $afterId = filter_var($input->getOption('after-id'), FILTER_VALIDATE_INT);
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);
        if ($batchSize === false || $batchSize < 1 || $batchSize > 500
            || $afterId === false || $afterId < 0 || $limit === false || $limit < 0) {
            $output->writeln('<error>Use batch-size 1–500 and non-negative integer after-id/limit values.</error>');

            return Command::INVALID;
        }
        $processed = 0;
        $failed = 0;
        do {
            $size = $limit === 0 ? $batchSize : min($batchSize, $limit - $processed);
            $media = $this->entityManager->getRepository(Media::class)->createQueryBuilder('media')
                ->where('media.id > :afterId')
                ->setParameter('afterId', $afterId)
                ->orderBy('media.id', \SortDirection::Ascending)
                ->setMaxResults($size)
                ->getQuery()->getResult();
            foreach ($media as $image) {
                $afterId = $image->getId();
                ++$processed;
                try {
                    $dimensions = $this->thumbnails->warm($image->getStoragePath(), $input->getOption('force'));
                    $image->setDimensions($dimensions['width'], $dimensions['height']);
                } catch (\Throwable $exception) {
                    ++$failed;
                    $output->writeln(sprintf('<error>Media %d: %s</error>', $afterId, $exception->getMessage()));
                }
            }
            $this->entityManager->flush();
            $this->entityManager->clear();
            $output->writeln(sprintf('Processed %d; failed %d; resume with --after-id=%d.', $processed, $failed, $afterId));
        } while (count($media) === $size && ($limit === 0 || $processed < $limit));

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
