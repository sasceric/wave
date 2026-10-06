<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class AdminLogReader
{
    private const MAX_FILES = 256;
    private const MAX_LINE_BYTES = 65536;
    private const CACHE_PREFIX = 'admin.logs.v1.';

    public function __construct(
        #[Autowire('%kernel.logs_dir%')] private readonly string $logsDirectory,
        private readonly CacheInterface $cache,
    ) {
    }

    public function files(): array
    {
        if (!is_dir($this->logsDirectory)) {
            return ['files' => [], 'limited' => false];
        }
        $latest = new \SplPriorityQueue();
        $latest->setExtractFlags(\SplPriorityQueue::EXTR_DATA);
        $count = 0;
        foreach (new \DirectoryIterator($this->logsDirectory) as $file) {
            $name = $file->getFilename();
            if ($this->safePath($name) === null) {
                continue;
            }
            ++$count;
            $latest->insert(['name' => $name, 'size' => $file->getSize(), 'modifiedAt' => gmdate(DATE_ATOM, $file->getMTime())], -$file->getMTime());
            if ($latest->count() > self::MAX_FILES) {
                $latest->extract();
            }
        }
        $files = iterator_to_array($latest, false);
        usort($files, static fn (array $a, array $b): int => strcmp($b['modifiedAt'], $a['modifiedAt']) ?: strcmp($a['name'], $b['name']));

        return ['files' => $files, 'limited' => $count > self::MAX_FILES];
    }

    public function page(string $file, string $cursor, int $pageSize): array
    {
        if (!in_array($pageSize, [25, 50, 100], true)) {
            throw new \InvalidArgumentException('Invalid page size.');
        }
        if ($cursor === '') {
            $listed = $this->files();
            $files = $file === '' ? $listed['files'] : array_values(array_filter($listed['files'], static fn (array $row): bool => $row['name'] === $file));
            if ($file !== '' && $files === []) {
                throw new \InvalidArgumentException('Unknown log file.');
            }
            $state = ['file' => $file, 'files' => [], 'limited' => $listed['limited']];
            foreach ($files as $row) {
                $path = $this->safePath($row['name']);
                $handle = $path === null ? false : fopen($path, 'rb');
                $stat = $handle === false ? false : fstat($handle);
                if ($stat !== false) {
                    $prefixBytes = min(512, $stat['size']);
                    $state['files'][$row['name']] = [
                        'position' => $stat['size'], 'inode' => $stat['ino'], 'modified' => $stat['mtime'],
                        'prefixBytes' => $prefixBytes, 'prefixHash' => $this->fingerprint($handle, $prefixBytes),
                    ];
                }
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
            $cursor = $this->save($state);
        } else {
            if (preg_match('/^[a-f0-9]{32}$/D', $cursor) !== 1) {
                throw new \InvalidArgumentException('Invalid log cursor.');
            }
            $state = $this->cache->get(self::CACHE_PREFIX . $cursor, static function (ItemInterface $item): mixed {
                $item->expiresAfter(1);

                return null;
            });
            if (!is_array($state) || $state['file'] !== $file) {
                throw new \InvalidArgumentException('Expired log cursor.');
            }
        }

        $handles = [];
        $candidates = [];
        $changed = false;
        try {
            foreach ($state['files'] as $name => $snapshot) {
                $path = $this->safePath($name);
                $handle = $path === null ? false : fopen($path, 'rb');
                $stat = $handle === false ? false : fstat($handle);
                if ($stat === false || $stat['ino'] !== $snapshot['inode'] || $stat['size'] < $snapshot['position']
                    || $this->fingerprint($handle, $snapshot['prefixBytes']) !== $snapshot['prefixHash']) {
                    if (is_resource($handle)) {
                        fclose($handle);
                    }
                    unset($state['files'][$name]);
                    $changed = true;
                    continue;
                }
                $handles[$name] = $handle;
                $candidates[$name] = $this->line($handle, $snapshot['position'], $name, $snapshot['modified']);
            }
            $rows = [];
            while (count($rows) < $pageSize) {
                $available = array_filter($candidates);
                if ($available === []) {
                    break;
                }
                uasort($available, static fn (array $a, array $b): int => $b['sortTime'] <=> $a['sortTime'] ?: strcmp($a['id'], $b['id']));
                $name = array_key_first($available);
                $entry = $available[$name];
                $state['files'][$name]['position'] = $entry['position'];
                unset($entry['sortTime'], $entry['position']);
                $rows[] = $entry;
                $snapshot = $state['files'][$name];
                $candidates[$name] = $this->line($handles[$name], $snapshot['position'], $name, $snapshot['modified']);
            }
            $hasMore = array_filter($candidates) !== [];

            return ['data' => $rows, 'meta' => [
                'pageSize' => $pageSize,
                'cursor' => $cursor,
                'nextCursor' => $hasMore ? $this->save($state) : null,
                'hasMore' => $hasMore,
                'changed' => $changed,
                'limited' => $state['limited'],
            ]];
        } finally {
            foreach ($handles as $handle) {
                fclose($handle);
            }
        }
    }

    private function safePath(string $name): ?string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.log(?:\.\d+)?$/D', $name) !== 1) {
            return null;
        }
        $path = $this->logsDirectory . '/' . $name;
        $root = realpath($this->logsDirectory);
        $resolved = realpath($path);

        return $root !== false && $resolved !== false && dirname($resolved) === $root
            && !is_link($path) && is_file($path) && is_readable($path) ? $resolved : null;
    }

    private function save(array $state): string
    {
        $cursor = bin2hex(random_bytes(16));
        $this->cache->get(self::CACHE_PREFIX . $cursor, static function (ItemInterface $item) use ($state): array {
            $item->expiresAfter(1800);

            return $state;
        });

        return $cursor;
    }

    private function fingerprint(mixed $handle, int $bytes): string
    {
        fseek($handle, 0);

        return hash('sha256', $bytes === 0 ? '' : (string) fread($handle, $bytes));
    }

    /** Read backwards in small blocks; never load a whole log into memory. */
    private function line(mixed $handle, int $position, string $name, int $modified): ?array
    {
        if ($position <= 0) {
            return null;
        }
        fseek($handle, $position - 1);
        if (fread($handle, 1) === "\n") {
            --$position;
        }
        $text = '';
        $start = $position;
        while ($start > 0 && strlen($text) < self::MAX_LINE_BYTES) {
            $size = min(8192, $start);
            $start -= $size;
            fseek($handle, $start);
            $block = fread($handle, $size);
            if ($block === false) {
                throw new \RuntimeException('Unable to read application log.');
            }
            $newline = strrpos($block, "\n");
            if ($newline !== false) {
                $text = substr($block, $newline + 1) . $text;
                $start += $newline + 1;
                break;
            }
            $text = $block . $text;
        }
        $text = mb_convert_encoding(rtrim($text, "\r"), 'UTF-8', 'UTF-8');
        $time = null;
        $level = null;
        $channel = null;
        if (preg_match('/^\[([^\]]{1,64})\]\s+([\w.-]+)\.([A-Z]+):/', $text, $match)) {
            try {
                $time = new \DateTimeImmutable($match[1]);
            } catch (\Exception) {
                // Nonstandard timestamps remain visible without inventing a date.
            }
            $channel = $match[2];
            $level = $match[3];
        }

        $message = $text;
        $json = json_decode($text, true);
        if (is_array($json) && is_string($json['message'] ?? null)) {
            try {
                $time = new \DateTimeImmutable($json['datetime'] ?? '');
            } catch (\Exception) {
            }
            $level = $json['level_name'] ?? null;
            $channel = $json['channel'] ?? null;
            $message = $json['message'];
        }

        return [
            'id' => $name . ':' . $start,
            'file' => $name,
            'time' => $time?->format(DATE_ATOM),
            'level' => $level,
            'channel' => $channel,
            'message' => mb_substr($this->redact($message), 0, 8000),
            'detail' => mb_substr($this->redact($text), 0, 8000),
            'truncated' => strlen($text) >= self::MAX_LINE_BYTES || mb_strlen($text) > 8000,
            'position' => $start,
            'sortTime' => $time === null ? (float) $modified : (float) $time->format('U.u'),
        ];
    }

    private function redact(string $text): string
    {
        $text = \App\Background\SafeLogProcessor::text($text);
        $text = preg_replace('~([a-z][a-z0-9+.-]*://[^\s/:@]+:)[^\s/@]+@~i', '$1[redacted]@', $text) ?? $text;
        $text = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/-]+=*/i', '$1 [redacted]', $text) ?? $text;
        $text = preg_replace(
            '~((?:password(?:_hash)?|passwordHash|passwd|authorization|cookie|set-cookie|(?:access_|refresh_)?token|secret|private_?key|api[_-]?key|authToken)\s*["\']?\s*[:=]\s*)(?:"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|[^\s,;}]+)~i',
            '$1[redacted]',
            $text,
        ) ?? $text;
        $text = preg_replace('/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/', '[redacted token]', $text) ?? $text;
        if (preg_match('/^(?:[A-Za-z0-9+\/]{48,}={0,2}|-----[A-Z ]*PRIVATE KEY-----)$/D', $text)) {
            return '[redacted private key]';
        }

        return preg_replace('/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s', '[redacted private key]', $text) ?? $text;
    }
}
