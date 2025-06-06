<?php

declare(strict_types=1);

namespace a9f\Fractor\Helper;

use Rector\Exception\ShouldNotHappenException;

final class FileHasher
{
    /**
     * cryptographic insecure hashing of a string
     */
    public function hash(string $string): string
    {
        return \hash($this->getAlgo(), $string);
    }

    /**
     * cryptographic insecure hashing of files
     *
     * @param string[] $files
     */
    public function hashFiles(array $files): string
    {
        $configHash = '';
        $algo = $this->getAlgo();
        foreach ($files as $file) {
            $hash = \hash_file($algo, $file);
            if ($hash === \false) {
                throw new ShouldNotHappenException(\sprintf('File %s is not readable', $file));
            }
            $configHash .= $hash;
        }
        return $configHash;
    }

    private function getAlgo(): string
    {
        return 'xxh128';
    }
}
