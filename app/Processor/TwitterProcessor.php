<?php


/*
 * Copyright (c) 2022 Sander Dorigo
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of
 * this software and associated documentation files (the "Software"), to deal in
 * the Software without restriction, including without limitation the rights to
 * use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies
 * of the Software, and to permit persons to whom the Software is furnished to do
 * so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace App\Processor;

use Monolog\Logger;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Class TwitterProcessor
 */
class TwitterProcessor implements ProcessorInterface
{

    private Logger $logger;
    private string $destination;
    private int    $titleLength = 50;

    /**
     * @inheritDoc
     */
    public function process(array $items): void
    {
        $this->logger->debug('Parsing...');
        // build twig processor:
        $loader   = new FilesystemLoader(TEMPLATES);
        $twig     = new Environment($loader, [
            'cache' => CACHE,
            'debug' => true,
        ]);
        $template = $twig->load('twitter.twig');
        foreach ($items as $item) {
            $item['title_length'] = $this->titleLength;

            $search       = ['"'];
            $replace      = ['\\"'];
            $item['text'] = str_replace($search, $replace, $item['text']);
            $content      = $template->render($item);
            $date         = $item['created_at']->format('Y-m-d-H-i');
            $filename     = sprintf('%s-tweet.md', $date);
            $full         = sprintf('%s/%s', $this->destination, $filename);
            file_put_contents($full, $content);
        }
        $this->logger->debug('Done!');
    }

    /**
     * @inheritDoc
     */
    public function setLogger(Logger $logger): void
    {
        $this->logger = $logger;
        $this->logger->debug('TwitterProcessor has a logger!');
    }

    /**
     * @inheritDoc
     */
    public function setDestination(string $destination): void
    {
        $this->destination = $destination;
        $this->logger->debug(sprintf('TwitterProcessor has a destination: %s', $destination));
    }

    /**
     * @inheritDoc
     */
    public function setTitleLength(int $length): void
    {
        $this->titleLength = $length;
    }
}