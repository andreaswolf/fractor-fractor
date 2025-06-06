<?php

declare(strict_types=1);

namespace a9f\Fractor\Caching\ValueObject\Storage;

use a9f\Fractor\Caching\Contract\ValueObject\Storage\CacheStorageInterface;
use a9f\Fractor\Caching\ValueObject\CacheFilePaths;
use a9f\Fractor\Caching\ValueObject\CacheItem;
use a9f\Fractor\Exception\Cache\CachingException;
use Nette\Utils\FileSystem as NetteFileSystem;
use Nette\Utils\Random;
use Symfony\Component\Filesystem\Filesystem;

final readonly class FileCacheStorage implements CacheStorageInterface
{
    public function __construct(
        private string $directory,
        private Filesystem $filesystem
    ) {
    }

    public function load(string $key, string $variableKey): mixed
    {
        return (function (string $key, string $variableKey) {
            $cacheFilePaths = $this->getCacheFilePaths($key);
            $filePath = $cacheFilePaths->getFilePath();
            if (! \is_file($filePath)) {
                return null;
            }
            $cacheItem = (require $filePath);
            if (! $cacheItem instanceof CacheItem) {
                return null;
            }
            if (! $cacheItem->isVariableKeyValid($variableKey)) {
                return null;
            }
            return $cacheItem->getData();
        })($key, $variableKey);
    }

    public function save(string $key, string $variableKey, mixed $data): void
    {
        $cacheFilePaths = $this->getCacheFilePaths($key);
        $this->filesystem->mkdir($cacheFilePaths->getFirstDirectory());
        $this->filesystem->mkdir($cacheFilePaths->getSecondDirectory());
        $filePath = $cacheFilePaths->getFilePath();
        $tmpPath = \sprintf('%s/%s.tmp', $this->directory, Random::generate());
        $errorBefore = \error_get_last();
        $exported = @\var_export(new CacheItem($variableKey, $data), \true);
        $errorAfter = \error_get_last();
        if ($errorAfter !== null && $errorBefore !== $errorAfter) {
            throw new CachingException(
                \sprintf(
                    'Error occurred while saving item %s (%s) to cache: %s',
                    $key,
                    $variableKey,
                    $errorAfter['message']
                )
            );
        }
        // for performance reasons, we don't use SmartFileSystem
        NetteFileSystem::write(
            $tmpPath,
            \sprintf("<?php declare(strict_types = 1);\n\nreturn %s;", $exported),
            null
        );
        $copySuccess = @\copy($tmpPath, $filePath);
        @\unlink($tmpPath);
        if ($copySuccess) {
            return;
        }
        if (\DIRECTORY_SEPARATOR === '/' || ! \file_exists($filePath)) {
            throw new CachingException(\sprintf('Could not write data to cache file %s.', $filePath));
        }
    }

    public function clean(string $key): void
    {
        $cacheFilePaths = $this->getCacheFilePaths($key);
        $this->processRemoveCacheFilePath($cacheFilePaths);
        $this->processRemoveEmptyDirectory($cacheFilePaths->getSecondDirectory());
        $this->processRemoveEmptyDirectory($cacheFilePaths->getFirstDirectory());
    }

    public function clear(): void
    {
        NetteFileSystem::delete($this->directory);
    }

    private function processRemoveCacheFilePath(CacheFilePaths $cacheFilePaths): void
    {
        $filePath = $cacheFilePaths->getFilePath();
        if (! $this->filesystem->exists($filePath)) {
            return;
        }
        NetteFileSystem::delete($filePath);
    }

    private function processRemoveEmptyDirectory(string $directory): void
    {
        if (! $this->filesystem->exists($directory)) {
            return;
        }
        if ($this->isNotEmptyDirectory($directory)) {
            return;
        }
        NetteFileSystem::delete($directory);
    }

    private function isNotEmptyDirectory(string $directory): bool
    {
        // FilesystemIterator will initially point to the first file in the folder - if there are no files in the folder, valid() will return false
        $filesystemIterator = new \FilesystemIterator($directory);
        return $filesystemIterator->valid();
    }

    private function getCacheFilePaths(string $key): CacheFilePaths
    {
        $keyHash = \sha1($key);
        $firstDirectory = \sprintf('%s/%s', $this->directory, \substr($keyHash, 0, 2));
        $secondDirectory = \sprintf('%s/%s', $firstDirectory, \substr($keyHash, 2, 2));
        $filePath = \sprintf('%s/%s.php', $secondDirectory, $keyHash);
        return new CacheFilePaths($firstDirectory, $secondDirectory, $filePath);
    }
}
