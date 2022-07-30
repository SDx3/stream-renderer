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

namespace App\Data;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Monolog\Logger;

/**
 * Class PinBoard
 */
class PinBoard
{
    private array  $allowedTags = [];
    private array  $blockedTags = [];
    private Logger $logger;
    private string $tagCacheFile;
    private string $token;
    private string $urlCacheFile;
    private array  $urls;
    private string $user;
    private array  $localIgnoreList;

    /**
     *
     */
    public function __construct()
    {
        $this->localIgnoreList = ['twitter', 'twitterlink', 'facebook'];
        $this->tagCacheFile    = sprintf('%s/tags.json', CACHE);
        $this->urlCacheFile    = sprintf('%s/urls.json', CACHE);
        $tags                  = include(ROOT . '/tags.php');
        $this->allowedTags     = $tags['allowed'];
        $this->getCache();
    }

    /**
     * @return void
     */
    private function getCache(): void
    {
        $this->blockedTags = [];
        $this->urls        = [];
        if (file_exists($this->tagCacheFile)) {
            $content           = file_get_contents($this->tagCacheFile);
            $this->blockedTags = json_decode($content, true);
        }
        if (file_exists($this->urlCacheFile)) {
            $content    = file_get_contents($this->urlCacheFile);
            $this->urls = json_decode($content, true);
        }
    }

    /**
     * @param array $articles
     * @return array
     */
    public function addTagsToList(array $articles): array
    {

        $all   = [];
        $index = 0;
        foreach ($articles as $article) {
            $index++;
            $url  = $article['url'];
            $tags = $this->getTagsForUrl($url);
            $all  = array_merge($all, $tags);
            if ($index > 10) {
                break;
            }
        }
        sort($all);
        return $all;
    }

    /**
     * @param string $url
     * @return array
     */
    public function getTagsForUrl(string $url): array
    {
        $this->logger->debug(sprintf('Checking tags for URL %s...', $url));
        $hash = sha1($url);
        if (array_key_exists($hash, $this->urls)) {
            $this->logger->debug(sprintf('Return tags from cache: %s', join(', ', $this->urls[$hash])));
            return $this->urls[$hash];
        }

        $params = [
            'url'        => $url,
            'format'     => 'json',
            'auth_token' => sprintf('%s:%s', $this->user, $this->token),
            'headers'    => [
                'User-Agent' => 'sanderdorigo.nl tag collector / 0.1 github.com/SDx3/stream-renderer',
            ],
        ];
        $api    = sprintf('https://api.pinboard.in/v1/posts/suggest?%s', http_build_query($params));
        $client = new Client();
        $opts   = [];
        try {
            $res = $client->request('GET', $api, $opts);
        } catch (GuzzleException $e) {
            $this->logger->error(sprintf('Could not get info! %s', $e->getMessage()));
            return [];
        }
        $tags = [];
        if (200 === $res->getStatusCode()) {
            $body = (string) $res->getBody();
            $json = json_decode($body, true);
            $tags = array_merge($tags, $json[0]['popular']);
            $tags = array_merge($tags, $json[1]['recommended']);
            $tags = $this->localFilter($tags);
            $tags = $this->filterTags($tags, $url);
            $this->logger->debug('Sleep for .25sec...');
            usleep(250000);
        }
        $result            = $this->filterTags($tags, $url);
        $this->urls[$hash] = $result;
        return $result;

    }

    /**
     * Will take the tags from the (cached) post and return only the tags that are allowed.
     * Tags that are in the cache file will be ignored (unless allowed). Newly found tags
     * will get a shout-out.
     *
     * @param array $tags
     * @return array
     */
    public function filterTags(array $tags, string $url): array
    {
        if (0 === count($tags)) {
            return [];
        }
        $tags = array_unique($tags);
        $tags = array_map('strtolower', $tags);
        $this->logger->debug(sprintf('Filtering for URL %s', $url));
        $this->logger->debug(sprintf('Will now filter set: %s', join(', ', $tags)));
        $return = [];


        /** @var string $tag */
        foreach ($tags as $tag) {
            $blocked = $this->tagIsBlocked($tag);
            if (!$blocked) {
                $return[] = $this->normalizeTag($tag);
            }
        }

        return array_unique($return);
    }

    /**
     * @param string $tag
     * @return bool
     */
    private function tagIsBlocked(string $tag): bool
    {
        $blocked = in_array($tag, $this->blockedTags, true);
        $allowed = $this->tagIsAllowed($tag);
        if ($blocked && !$allowed) {
            return true;
        }
        if (!$blocked && !$allowed) {
            $this->logger->info(sprintf(sprintf('Tag "%s" is NEW.', $tag)));
            // add it to the blocked tags:
            $this->blockedTags[] = trim(strtolower($tag));
            return true;
        }
        return false;
    }

    /**
     * @param string $tag
     * @return bool
     */
    private function tagIsAllowed(string $tag): bool
    {
        /**
         * @var string $key
         * @var array  $values
         */
        foreach ($this->allowedTags as $key => $values) {
            if (!is_array($values)) {
                die(sprintf('Tag %s does not contain array: %s' . "\n", $key, $values));
            }
            $values = array_map('strtolower', $values);
            if (strtolower($key) === $tag) {
                $this->logger->debug(sprintf('Tag "%s" is allowed (primary)', $tag));
                return true;
            }
            if (in_array($tag, $values, true)) {
                $this->logger->debug(sprintf('Tag "%s" is allowed (secondary) (%s)', $tag, join(', ', $values)));
                return true;
            }
        }
        $this->logger->debug(sprintf('Tag "%s" is blocked.', $tag));
        return false;
    }

    /**
     * @param string $tag
     * @return string
     */
    private function normalizeTag(string $tag): string
    {
        /**
         * @var string $key
         * @var array  $values
         */
        foreach ($this->allowedTags as $key => $values) {
            $values = array_map('strtolower', $values);
            if (strtolower($key) === $tag || in_array($tag, $values, true)) {
                $this->logger->debug(sprintf('Tag "%s" normalised to "%s".', $tag, $key));
                return strtolower($key);
            }
        }
        $this->logger->info(sprintf('Return original tag "%s".', $tag));
        return $tag;
    }

    /**
     * @param array $allowedTags
     */
    public function XsetAllowedTags(array $allowedTags): void
    {
        $this->allowedTags = $allowedTags;
    }

    /**
     * @param array $blockedTags
     */
    public function XsetBlockedTags(array $blockedTags): void
    {
        $this->blockedTags = $blockedTags;
    }

    /**
     * @return void
     */
    public function saveCache(): void
    {
        $list = array_unique($this->blockedTags);
        $list = array_map('strtolower', $list);
        sort($list);
        file_put_contents($this->tagCacheFile, json_encode($list, JSON_PRETTY_PRINT));

        // same for url list:
        file_put_contents($this->urlCacheFile, json_encode($this->urls, JSON_PRETTY_PRINT));
        $this->logger->debug('PinBoard updated the cache.');
    }

    /**
     * @param Logger $logger
     */
    public function setLogger(Logger $logger): void
    {
        $this->logger = $logger;
        $this->logger->debug('PinBoard now has a logger.');
    }

    /**
     * @param string $token
     */
    public function setToken(string $token): void
    {
        $this->token = $token;
    }

    /**
     * @param string $user
     */
    public function setUser(string $user): void
    {
        $this->user = $user;
    }

    /**
     * This method filters some tags that Pinboard people tend to give their posts
     * that I want to ignore.
     *
     * @param array $tags
     * @return array
     */
    private function localFilter(array $tags): array
    {
        $return = [];
        foreach ($tags as $tag) {
            $search = strtolower($tag);
            if (!in_array($search, $this->localIgnoreList, true)) {
                $return[] = $tag;
            }
        }
        return $return;
    }


}
