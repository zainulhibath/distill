<?php

/*
 * This file is part of the Distill package.
 *
 * (c) Raul Fraile <raulfraile@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Distill\Extractor\Method;

use Distill\File;
use Distill\Format\FormatInterface;

/**
 * Extracts files from bzip2 archives.
 *
 * @author Raul Fraile <raulfraile@gmail.com>
 */
class ZipArchiveMethod extends AbstractMethod
{

    /**
     * {@inheritdoc}
     */
    public function extract($file, $target, FormatInterface $format)
    {
        if (!$this->isSupported()) {
            return false;
        }

        $archive = new \ZipArchive();

        if (true !== $archive->open($file)) {
            return false;
        }

        $archive->extractTo($target);
        $archive->close();

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function isSupported()
    {
        return class_exists('\\ZipArchive');
    }

}