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

use App\Data\PinBoard;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Monolog\Logger;

class FirefoxCollector implements CollectorInterface
{
    private string    $cacheFile;
    private array     $collection;
    private array     $configuration;
    private array     $excludeTags = ['bookmarks menu', 'bookmarks bar', 'mobile bookmarks'];
    private Logger    $logger;
    private ?PinBoard $pinBoard;

    /**
     * @throws GuzzleException
     */
    public function collect(bool $skipCache = false): void
    {
        $this->logger->debug('FirefoxCollector is going to collect.');
        $useCache = true;

        if (true === $skipCache) {
            $this->logger->debug('FirefoxCollector must skip cache.');
            $useCache = false;
        }
        if (false === $skipCache && $this->cacheOutOfDate()) {
            $this->logger->debug('FirefoxCollector will skip cache (which is out of date).');
            $useCache = false;
        }

        if (false === $useCache) {
            $this->logger->debug('FirefoxCollector will not use the cache.');
            $this->collectBookmarks();
            $this->saveToCache();
        }
        if (true === $useCache) {
            $this->logger->debug('FirefoxCollector will use the cache.');
            $this->collectCache();
        }
    }

    /**
     * @return bool
     */
    private function cacheOutOfDate(): bool
    {
        if (!file_exists($this->cacheFile)) {
            $this->logger->debug('FirefoxCollector found no cache file, so it\'s out of date.');
            return true;
        }
        $content = file_get_contents($this->cacheFile);
        $json    = json_decode($content, true, 128);
        if (false === $json) {
            $this->logger->debug('FirefoxCollector cache is outdated.');
            return true;
        }
        // diff is over 12hrs
        if (time() - $json['moment'] > (12 * 60 * 60)) {
            $this->logger->debug('FirefoxCollector cache is outdated.');
            return true;
        }
        $this->logger->debug('FirefoxCollector cache is fresh!');

        return false;
    }

    /**
     * @return void
     * @throws GuzzleException
     */
    private function collectBookmarks(): void
    {
        $this->logger->debug('Now collecting bookmarks.');
        $this->collection = [];
        $file             = sprintf('%s/%s', ROOT, 'bookmarks.json');
        $body             = file_get_contents($file);
        $json             = json_decode($body, true, 25);
        $sorted           = [];
        $sorted           = $this->processChildren('(root)', $json, $sorted);
        $total            = count($sorted);
        $index            = 0;

        $this->logger->debug('Now looping over bookmarks.');
        foreach ($sorted as $bookmark) {
            $index++;
            $this->logger->debug(sprintf('[%d/%d] Processing bookmark.', $index, $total));

            // if not a bookmark, continue:
            if ('text/x-moz-place' !== $bookmark['type']) {
                $this->logger->debug('Skip non-bookmark');
                continue;
            }
            if (str_starts_with($bookmark['uri'], 'place:')) {
                $this->logger->debug('Skip "place:"-bookmark');
                continue;
            }
            if (str_starts_with($bookmark['uri'], 'javascript:')) {
                $this->logger->debug('Skip "javascript:"-bookmark');
                continue;
            }            // if already in collection, continue (duplicate bookmark):
            $hash = sha1($bookmark['uri']);
            if (in_array($hash, $this->collection)) {
                $this->logger->debug(sprintf('Already collected "%s", duplicate bookmark.', $bookmark['uri']));
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
                $this->logger->debug('Bookmark is YouTube!');
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
                $this->logger->debug('Collected YouTube HTML');
            }

            // get parent(s) as tags:
            $tags = $bookmark['tags'];
            $tags = $this->getTags($sorted, $bookmark['parent'], $tags);
            $this->logger->debug(sprintf('Original tags are: %s', join(', ', $tags)));
            // add tags from pinboard:
            if (null !== $this->pinBoard) {
                $tags = array_unique(array_merge($tags, $this->pinBoard->getTagsForUrl($bookmark['uri'])));
                $tags = $this->pinBoard->filterTags($tags, $bookmark['uri']);
                $this->logger->debug(sprintf('Pinboard+original tags are: %s', join(', ', $tags)));
            }

            sort($tags);
            $this->logger->debug(sprintf('Final tags are: %s', join(', ', $tags)));
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

    /**
     * @param string $parentId
     * @param array  $set
     * @param array  $sorted
     * @return array
     */
    private function processChildren(string $parentId, array $set, array $sorted): array
    {
        $this->logger->debug(sprintf('Now in processChildren("%s")', $parentId));
        $guid   = $set['guid'] ?? '(empty)';
        $object = [
            'id'     => $guid,
            'parent' => $parentId,
            'title'  => $set['title'] ?? null,
            'uri'    => $set['uri'] ?? null,
            'type'   => $set['type'],
            'tags'   => [],
            'date'   => Carbon::createFromFormat('U', bcdiv($set['dateAdded'], '1000000'), $_ENV['TZ']),
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
        $this->logger->debug(sprintf('Now in processChildren("%s")', $id));
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
     *
     */
    private function saveToCache(): void
    {
        $content = [
            'moment' => time(),
            'data'   => $this->collection,
        ];
        $json    = json_encode($content, JSON_PRETTY_PRINT);
        file_put_contents($this->cacheFile, $json);
        $this->logger->debug('FirefoxCollector has saved the results to the cache.');
    }

    /**
     * @return void
     */
    private function collectCache(): void
    {
        $content = file_get_contents($this->cacheFile);
        $json    = json_decode($content, true, 128);
        if (false === $json) {
            return;
        }
        $this->collection = $json['data'];
        $this->logger->debug('FirefoxCollector has collected from the cache.');
        foreach ($this->collection as $index => $entry) {
            $entry['date'] = new Carbon($entry['date'], $_ENV['TZ']);
            if (null !== $this->pinBoard) {
                $entry['categories'] = $this->pinBoard->filterTags($entry['categories'], $entry['url']);
            }
            $this->collection[$index] = $entry;
        }

    }

    /**
     * @inheritDoc
     */
    public function getCollection(): array
    {
        return $this->collection;
    }

    /**
     * @return PinBoard
     */
    public function getPinBoard(): PinBoard
    {
        return $this->pinBoard;
    }

    /**
     * @param PinBoard|null $pinBoard
     * @return void
     */
    public function setPinBoard(?PinBoard $pinBoard): void
    {
        $this->pinBoard = $pinBoard;
    }

    /**
     * @inheritDoc
     */
    public function setConfiguration(array $configuration): void
    {
        $this->configuration = $configuration;
        $this->cacheFile     = sprintf('%s/bookmarks-cache.json', CACHE);
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
