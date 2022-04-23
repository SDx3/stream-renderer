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
    private array  $allowedTags;
    private array  $blockedTags;
    private Logger $logger;
    private string $token;
    private string $user;

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
        $params = [
            'url'        => $url,
            'format'     => 'json',
            'auth_token' => sprintf('%s:%s', $this->user, $this->token),
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
            $tags = $this->filterTags($tags);
            $this->logger->debug('Sleep for 1sec...');
            sleep(1);
        }

        return $this->filterTags($tags);
    }

    /**
     * @param array $tags
     * @return array
     */
    public function filterTags(array $tags): array
    {
        $tags   = array_unique($tags);
        $tags   = array_map('strtolower', $tags);
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
     * @param array $allowedTags
     */
    public function setAllowedTags(array $allowedTags): void
    {
        $this->allowedTags = $allowedTags;
    }

    /**
     * @param array $blockedTags
     */
    public function setBlockedTags(array $blockedTags): void
    {
        $this->blockedTags = $blockedTags;
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

    private function normalizeTag(string $tag): string
    {
        /**
         * @var string $key
         * @var array  $values
         */
        foreach ($this->allowedTags as $key => $values) {
            $values = array_map('strtolower', $values);
            if (strtolower($key) === $tag || in_array($tag, $values, true)) {
                return strtolower($key);
            }
        }
        return $tag;
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
            $values = array_map('strtolower', $values);
            if (strtolower($key) === $tag) {
                return true;
            }
            if (in_array($tag, $values, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $tag
     * @return bool
     */
    private function tagIsBlocked(string $tag): bool
    {
        $tags = array_map('strtolower', $this->blockedTags);
        return in_array($tag, $tags, true);
    }


}