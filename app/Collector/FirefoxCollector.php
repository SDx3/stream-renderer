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
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Monolog\Logger;

class FirefoxCollector implements CollectorInterface
{
    private array  $collection;
    private array  $configuration;
    private array  $excludeTags = ['bookmarks menu', 'bookmarks bar', 'mobile bookmarks'];
    private Logger $logger;

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
        foreach ($sorted as $bookmark) {
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

            // if already in collection, continue (duplicate bookmark):
            $hash = sha1($bookmark['uri']);
            if (in_array($hash, $this->collection)) {
                $this->logger->debug(sprintf('Skip "%s", already in collection.', $bookmark['title']));
                continue;
            }

            $host = parse_url($bookmark['uri'], PHP_URL_HOST);

            // special thing for youtube:
            $isYoutube = false;
            if (str_starts_with($host, 'www.')) {
                $host = substr($host, 4);
            }
            if (str_contains($host, 'youtube.com')) {
                $isYoutube = true;
            }

            $html = '';
            if (true === $isYoutube) {
                // get oEmbed for youtube movie:
                $params = [
                    'url'    => $bookmark['uri'],
                    'format' => 'json',
                ];
                $url    = sprintf('https://www.youtube.com/oembed?%s', http_build_query($params));
                $client = new Client;
                $res    = $client->get($url);
                $body   = (string) $res->getBody();
                $json   = json_decode($body, true);
                $html   = $json['html'];

                // this is a very cheap but effective way to resize the video:
                $search  = 'width="200" height="113"';
                $replace = 'width="600" height="339"';
                $html    = str_replace($search, $replace, $html);

            }

            // get parent(s) as tags:
            $tags = $bookmark['tags'];
            $tags = $this->getTags($sorted, $bookmark['parent'], $tags);

            // TODO filter on tags.

            $this->collection[$hash] = [
                'categories' => $tags,
                'title'      => $bookmark['title'],
                'url'        => $bookmark['uri'],
                'date'       => $bookmark['date'],
                'host'       => $host,
                'is_youtube' => $isYoutube,
                'html'       => $html,
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
     * @param array  $sorted
     * @param string $id
     * @param array  $tags
     * @return array
     */
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
    public function setConfiguration(array $configuration): void
    {
        $this->configuration = $configuration;
    }

    /**
     * @inheritDoc
     */
    public function setLogger(Logger $logger): void
    {
        $this->logger = $logger;
        $this->logger->debug('FirefoxCollector now has a logger!');
    }
}