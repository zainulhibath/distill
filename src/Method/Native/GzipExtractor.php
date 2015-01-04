<?php

/*
 * This file is part of the Distill package.
 *
 * (c) Raul Fraile <raulfraile@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Distill\Method\Native;

use Distill\Exception;
use Distill\Format\FormatInterface;
use Distill\Format\Simple\Gz;
use Distill\Method\AbstractMethod;
use Distill\Method\MethodInterface;
use Distill\Method\Native\GzipExtractor\BitReader;
use Distill\Method\Native\GzipExtractor\FileHeader;
use Distill\Method\Native\GzipExtractor\HuffmanTree;

/**
 * Extracts files from gzip archives natively from PHP.
 *
 * @author Raul Fraile <raulfraile@gmail.com>
 */
class GzipExtractor extends AbstractMethod
{
    const MAGIC_NUMBER = '1f8b';

    const COMPRESSION_TYPE_NON_COMPRESSED = 0x00;
    const COMPRESSION_TYPE_FIXED_HUFFMAN = 0x01;
    const COMPRESSION_TYPE_DYNAMIC_HUFFMAN = 0x02;


    const HLIT_BITS = 5;
    const HDIST_BITS = 5;
    const HCLEN_BITS = 4;

    const HLIT_INITIAL_VALUE = 257;
    const HDIST_INITIAL_VALUE = 1;
    const HCLEN_INITIAL_VALUE = 4;

    /**
     * @var BitReader
     */
    protected $bitReader;

    protected $currentBitPosition = 0;
    protected $currentByte = null;

    protected $codeLenghtsOrders = [16, 17, 18, 0, 8, 7, 9, 6, 10, 5, 11, 4, 12, 3, 13, 2, 14, 1, 15];

    protected $distanceBase = [1, 2, 3, 4, 5, 7, 9, 13, 17, 25, 33, 49, 65, 97, 129, 193, 257, 385, 513, 769, 1025, 1537, 2049, 3073, 4097, 6145, 8193, 12289, 16385, 24577];

    protected $lengthBase = [3, 4, 5, 6, 7, 8, 9, 10, 11, 13, 15, 17, 19, 23, 27, 31, 35, 43, 51, 59, 67, 83, 99, 115, 131, 163, 195, 227, 258];

    /**
     * {@inheritdoc}
     */
    public function extract($file, $target, FormatInterface $format)
    {
        $this->checkSupport($format);

        $this->getFilesystem()->mkdir($target);

        return $this->extractGzipFile($file, $target);
    }

    /**
     * {@inheritdoc}
     */
    public function isSupported()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public static function getClass()
    {
        return get_class();
    }

    protected function readFileHeader($fileHandler)
    {
    }

    /**
     * Extracts the contents from a GZIP file.
     * @param string $filename GZIP file name.
     * @param string $target   Target path.
     *
     * @throws Exception\IO\Input\FileCorruptedException
     *
     * @return bool
     */
    protected function extractGzipFile($filename, $target)
    {
        $fileHandler = fopen($filename, 'rb');

        $this->bitReader = new BitReader($fileHandler);

        // read file header
        try {
            $fileHeader = FileHeader::createFromResource($filename, $fileHandler);
        } catch (Exception\IO\Input\FileCorruptedException $e) {
            throw $e;
        }

        // read compressed blocks
        $result = '';
        $isBlockFinal = false;

        while (false === $isBlockFinal) {
            $isBlockFinal = 1 === $this->bitReader->read(1);
            $compressionType = $this->bitReader->read(2);

            if (self::COMPRESSION_TYPE_NON_COMPRESSED === $compressionType) {
                // no compression

                $lenght = fread($fileHandler, 2);
                $lenghtOneComplement = fread($fileHandler, 2);

                $result .= fread($fileHandler, $lenght);
            } else {
                // compression
                if (self::COMPRESSION_TYPE_FIXED_HUFFMAN === $compressionType) {
                    list($literalsTree, $distancesTree) = $this->getFixedHuffmanTrees();
                } elseif (self::COMPRESSION_TYPE_DYNAMIC_HUFFMAN === $compressionType) {
                    list($literalsTree, $distancesTree) = $this->getDynamicHuffmanTrees($fileHandler);
                } else {
                    throw new Exception\IO\Input\FileCorruptedException($filename);
                }

                $result .= $this->uncompressCompressedBlock($literalsTree, $distancesTree, $fileHandler);
            }
        }

        fclose($fileHandler);

        // write file
        $location = $target.DIRECTORY_SEPARATOR.$fileHeader->getOriginalFilename();
        file_put_contents($location, $result);

        return true;
    }


    protected function uncompressCompressedBlock(HuffmanTree $literalsTree, HuffmanTree $distancesTree, $fileHandler)
    {
        $endOfBlock = false;
        $bits = '';

        $result = '';
        while (false === $endOfBlock) {
            $bits .= decbin($this->bitReader->read(1));

            $decoded = $literalsTree->decode($bits);

            if (false !== $decoded) {
                if (256 === $decoded) {
                    $endOfBlock = true;
                } elseif ($decoded < 256) {
                    $result .= chr($decoded);
                } else {
                    $distance = false;
                    $distanceBits = '';
                    //while (false === $distance) {
                    //$distanceBits .= decbin($this->readBits2($fileHandler, 1));

                    //}

                    $lengthExtraBits = $this->getExtraLengthBits($decoded);
                    $lengthExtra = 0;
                    if ($lengthExtraBits > 0) {
                        $lengthExtra = $this->bitReader->read($lengthExtraBits);
                    }

                    $distance = false;
                    $distanceBits = '';
                    while (false === $distance) {
                        $distanceBits .= decbin($this->bitReader->read(1));
                        $distance = $distancesTree->decode($distanceBits);
                    }

                    $distanceExtra = $this->bitReader->read($this->getExtraDistanceBits($distance));

                    $d = $this->distanceBase[$distance] + $distanceExtra;
                    $l = $this->lengthBase[$decoded - 257] + $lengthExtra;
                    $concat = substr($result, -1 * $d, $l);
                    if (strlen($concat) < $l) {
                        $concat = substr(str_repeat($concat, $l / strlen($concat)), 0, $l);
                    }

                    $result .= $concat;
                }

                $bits = '';
            }
        }

        return $result;
    }

    protected function getFixedHuffmanTrees()
    {
        return [
            HuffmanTree::createFromLengths(array_merge(
                array_fill_keys(range(0, 143), 8),
                array_fill_keys(range(144, 255), 9),
                array_fill_keys(range(256, 279), 7),
                array_fill_keys(range(280, 287), 8)
            )),
            HuffmanTree::createFromLengths(array_fill_keys(range(0, 31), 5))
        ];
    }

    protected function getDynamicHuffmanTrees($fileHandler)
    {
        $literalsNumber = $this->bitReader->read(self::HLIT_BITS) + self::HLIT_INITIAL_VALUE;
        $distancesNumber = $this->bitReader->read(self::HDIST_BITS) + self::HDIST_INITIAL_VALUE;
        $codeLengthsNumber = $this->bitReader->read(self::HCLEN_BITS) + self::HCLEN_INITIAL_VALUE;

        // code lengths
        $codeLengths = [];
        for ($i = 0; $i < $codeLengthsNumber; $i++) {
            $codeLengths[$this->codeLenghtsOrders[$i]] = $this->bitReader->read(3);
        }

        // create code lengths huffman tree

        $codeLengthsTree = HuffmanTree::createFromLengths($codeLengths);

        $i = 0;
        $bits = '';

        $literalAndDistanceLengths = [];
        $previousCodeLength = 0;
        while ($i < ($literalsNumber + $distancesNumber)) {
            $bits .= decbin($this->bitReader->read(1));

            $decoded = $codeLengthsTree->decode($bits);

            if (false !== $decoded) {
                if ($decoded >= 0 && $decoded <= 15) {
                    // "normal" length
                    $literalAndDistanceLengths[] = $decoded;
                    $previousCodeLength = $decoded;

                    $i++;
                } elseif ($decoded >= 16 && $decoded <= 18) {
                    // repeat
                    switch ($decoded) {
                        case 16:
                            $times = $this->bitReader->read(2) + 3;
                            $repeatedValue = $previousCodeLength;
                            break;
                        case 17:
                            $times = $this->bitReader->read(3) + 3;
                            $repeatedValue = 0;
                            break;
                        default:
                            $times = $this->bitReader->read(7) + 11;
                            $repeatedValue = 0;
                            break;
                    }

                    for ($j = 0; $j < $times; $j++) {
                        $literalAndDistanceLengths[] = $repeatedValue;
                    }

                    $i += $times;
                }

                $bits = '';
            }
        }

        return [
            HuffmanTree::createFromLengths(array_slice($literalAndDistanceLengths, 0, $literalsNumber)),
            HuffmanTree::createFromLengths(array_slice($literalAndDistanceLengths, $literalsNumber))
        ];
    }

    /**
     * Gets the number of bits for the extra length.
     * @param $value
     *
     * @return int Number of bits.
     */
    protected function getExtraLengthBits($value)
    {
        if (($value >= 257 && $value <= 260) || $value === 285) {
            return 0;
        } elseif ($value >= 261 && $value <= 284) {
            return (($value - 257) >> 2) - 1;
        } else {
            throw new Exception\InvalidArgumentException('value', 'Invalid value');
        }
    }

    public function getExtraDistanceBits($value)
    {
        if ($value >= 0 && $value <= 1) {
            return 0;
        } elseif ($value >= 2 && $value <= 29) {
            return ($value >> 1) -1;
        } else {
            throw new Exception\InvalidArgumentException('value', 'Invalid value');
        }
    }

    /**
     * {@inheritdoc}
     */
    public static function getUncompressionSpeedLevel(FormatInterface $format = null)
    {
        return MethodInterface::SPEED_LEVEL_LOWEST;
    }

    /**
     * {@inheritdoc}
     */
    public function isFormatSupported(FormatInterface $format)
    {
        return $format instanceof Gz;
    }
}
