<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Web;

/** Detect changed app source or dependency selection between review and promotion; this does not deploy code. */
final readonly class ScreenBuild
{
    public function __construct(private string $root)
    {
    }
    /** A digest of app source/configuration and Composer's selected dependency graph. */
    public function fingerprint(): string
    {
        $files = [];
        foreach (['src','config','public'] as $dir) {
            if (!is_dir($this->root . '/' . $dir)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root . '/' . $dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile()) {
                    $files[substr($file->getPathname(), strlen($this->root) + 1)] = hash_file('sha256', $file->getPathname());
                }
            }
        }
        $files['composer.lock'] = is_file($this->root . '/composer.lock') ? hash_file('sha256', $this->root . '/composer.lock') : null;
        return ScreenDrafts::hash($files);
    }
}
