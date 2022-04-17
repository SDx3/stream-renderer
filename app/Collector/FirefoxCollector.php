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

namespace App\Collector;

use Carbon\Carbon;
use GuzzleHttp\Exception\GuzzleException;
use Monolog\Logger;

class FirefoxCollector implements CollectorInterface
{
    private Logger $logger;
    private array  $excludeTags = ['bookmarks menu', 'bookmarks bar', 'mobile bookmarks'];
    private array  $collection;
    private array  $configuration;

    /**
     * @throws GuzzleException
     */
    public function collect(bool $skipCache = false): void
    {
        $this->collection = [];
        $file             = sprintf('%s/%s', ROOT, 'bookmarks.json');
        $body             = file_get_contents($file);
        $json             = json_decode($body, true, 25);
        $sorted           = [];
        $sorted           = $this->processChildren('(root)', $json, $sorted);

        $wallabag = $this->parseWallabag();


        foreach ($sorted as $id => $bookmark) {
            // if not a bookmark, continue:
            if ('text/x-moz-place' !== $bookmark['type']) {
                continue;
            }
            if (str_starts_with($bookmark['uri'], 'place:')) {
                continue;
            }
            if (str_starts_with($bookmark['uri'], 'javascript:')) {
                continue;
            }


            // if already in wallabag, continue
            $hash = sha1($bookmark['uri']);
            if (in_array($hash, $wallabag)) {
                continue;
            }

            // if already in collection, continue:
            if (in_array($hash, $this->collection)) {
                $this->logger->debug(sprintf('Skip "%s", already in collection.', $bookmark['title']));
                continue;
            }

            $host = parse_url($bookmark['uri'], PHP_URL_HOST);
            foreach ($this->configuration['exclude_hosts'] as $current) {
                if (str_contains($host, $current)) {
                    $this->logger->debug(sprintf('Exclude bookmark with host "%s"', $host));
                    continue 2;
                }
            }
            if (str_starts_with($host, 'www.')) {
                $host = substr($host, 4);
            }

            // get parent(s) as tags:
            $tags                    = $bookmark['tags'];
            $tags                    = $this->getTags($sorted, $bookmark['parent'], $tags);
            $this->collection[$hash] = [
                'categories' => $tags,
                'title'      => $bookmark['title'],
                'url'        => $bookmark['uri'],
                'date'       => $bookmark['date'],
                'host'       => $host,
            ];
        }
        $this->logger->debug(sprintf('FirefoxCollector collected %d bookmark(s)', count($this->collection)));
    }

    private function processChildren(string $parentId, array $set, array $sorted): array
    {
        $guid   = $set['guid'] ?? '(empty)';
        $object = [
            'id'     => $guid,
            'parent' => $parentId,
            'title'  => $set['title'] ?? null,
            'uri'    => $set['uri'] ?? null,
            'type'   => $set['type'],
            'tags'   => [],
            'date'   => Carbon::createFromFormat('U', bcdiv($set['dateAdded'], '1000000')),
        ];
        if (array_key_exists('tags', $set)) {
            $object['tags'] = explode(',', $set['tags']);
        }

        $sorted[$guid] = $object;

        // process all children in this array:
        if (array_key_exists('children', $set)) {
            foreach ($set['children'] as $entry) {
                $sorted = $this->processChildren($guid, $entry, $sorted);
            }
        }
        return $sorted;
    }

    /**
     * @inheritDoc
     */
    public function setConfiguration(array $configuration): void
    {
        $this->configuration = $configuration;
    }

    /**
     * @inheritDoc
     */
    public function getCollection(): array
    {
        return $this->collection;
    }

    /**
     * @inheritDoc
     */
    public function setLogger(Logger $logger): void
    {
        $this->logger = $logger;
        $this->logger->debug('FirefoxCollector now has a logger!');
    }

    private function parseWallabag(): array
    {
        $return = [];
        $file   = sprintf('%s/%s', CACHE, 'wallabag.json');
        if (file_exists($file)) {
            $content = file_get_contents($file);
            $json    = json_decode($content, true);
            if (array_key_exists('data', $json)) {
                foreach ($json['data'] as $entry) {
                    $key      = sha1($entry['original_url']);
                    $return[] = $key;
                }
            }
        }
        return $return;
    }

    private function getTags(array $sorted, string $id, array $tags): array
    {
        // first find the ID and add it to tags:
        foreach ($sorted as $key => $entry) {
            if ($key === $id) {
                // some we don't add to the array:
                $title = trim(strtolower($entry['title']));
                if ('' !== $title && !in_array($title, $this->excludeTags, true)) {
                    $tags[] = $title;
                }

                // then find the parent as well:
                $tags = $this->getTags($sorted, $entry['parent'], $tags);
            }

        }
        return $tags;
    }
}