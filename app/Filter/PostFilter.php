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

namespace App\Filter;

use Monolog\Logger;

/**
 * Class PostFilter
 */
class PostFilter
{
    private array  $bookmarks;
    private array  $ignoreHosts;
    private Logger $logger;
    private array  $rss;
    private array  $tweets;
    private array  $wallabag;

    /**
     * Bookmarks must not be bookmarked tweets or Wallabag items.
     *
     * @return array
     */
    public function getFilteredBookmarks(): array
    {
        // get URL's of Tweets in an array:
        $tweets   = $this->getTweetURLs();
        $wallabag = $this->getWallabagURLs();
        $result   = [];
        foreach ($this->bookmarks as $item) {
            $include = true;
            if (in_array($item['url'], $tweets, true)) {
                $this->logger->debug(sprintf('Bookmark collection will skip over "%s" because it\'s a tweet.', $item['url']));
                $include = false;
            }
            if (in_array($item['url'], $wallabag, true)) {
                $this->logger->debug(sprintf('Bookmark collection will skip over "%s" because it\'s in Wallabag.', $item['url']));
                $include = false;
            }
            if ($this->isIgnoredHost($item['host'])) {
                $this->logger->debug(sprintf('Bookmark collection will skip over "%s" because it\'s an excluded host.', $item['url']));
                $include = false;
            }
            if (true === $include) {
                $result[] = $item;
            }
        }
        return $result;
    }

    /**
     * Get array of Tweet URL's. Used by other filters.
     *
     * @return array
     */
    private function getTweetURLs(): array
    {
        $filtered = [];
        foreach ($this->tweets as $tweet) {
            $filtered[] = $tweet['url'];
        }
        return $filtered;
    }

    /**
     * Get array of Wallabag URL's. Used by other filters.
     *
     * @return array
     */
    private function getWallabagURLs(): array
    {
        $filtered = [];
        foreach ($this->wallabag as $v) {
            $filtered[] = $v['original_url'];
        }
        return $filtered;
    }

    /**
     * Will loop over RSS articles and make sure they're not in Wallabag or are Tweets (unlikely tho)
     * @return array
     */
    public function getFilteredRss(): array
    {
        // get URL's of Tweets in an array:
        $tweets   = $this->getTweetURLs();
        $wallabag = $this->getWallabagURLs();
        $result   = [];
        foreach ($this->rss as $item) {
            $include = true;
            if (in_array($item['url'], $tweets, true)) {
                $this->logger->debug(sprintf('RSS collection will skip over "%s" because it\'s a tweet.', $item['url']));
                $include = false;
            }
            if (in_array($item['url'], $wallabag, true)) {
                $this->logger->debug(sprintf('RSS collection will skip over "%s" because it\'s also in Wallabag.', $item['url']));
                $include = false;
            }
            if ($this->isIgnoredHost($item['host'])) {
                $this->logger->debug(sprintf('RSS collection will skip over "%s" because it\'s an excluded host.', $item['url']));
                $include = false;
            }
            if (true === $include) {
                $result[] = $item;
            }
        }
        return $result;
    }

    /**
     * @param string $host
     * @return bool
     */
    private function isIgnoredHost(string $host): bool
    {
        // if host should be ignored, do so:
        foreach ($this->ignoreHosts as $value) {
            if (str_contains($host, $value)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Collection of Tweets is currently unfiltered.
     *
     * @return array
     */
    public function getFilteredTweets(): array
    {
        return $this->tweets;
    }

    /**
     * Returns everything from Wallabag except Tweets that are also bookmarked.
     *
     * Tweets that are not bookmarked will generate a warning.
     *
     * @return array
     */
    public function getFilteredWallabag(): array
    {
        // get URL's of Tweets in an array:
        $tweets = $this->getTweetURLs();
        $result = [];
        foreach ($this->wallabag as $item) {
            $host    = parse_url($item['original_url'], PHP_URL_HOST);
            $include = true;
            if (in_array($item['original_url'], $tweets, true)) {
                $this->logger->debug(sprintf('Wallabag collection will skip over "%s" because it\'s a tweet.', $item['original_url']));
                $include = false;
            }
            if ('twitter.com' === $host || 'www.twitter.com' === $host && true === $include) {
                $this->logger->warning(sprintf('Found a tweet "%s" in Wallabag collection which is still included (not bookmarked).', $item['original_url']));
            }
            if (true === $include && $this->isIgnoredHost($item['host'])) {
                $this->logger->debug(sprintf('Wallabag collection will skip over "%s" because it\'s an excluded host.', $item['url']));
                $include = false;
            }
            if (true === $include) {
                $result[] = $item;
            }
        }
        return $result;
    }

    /**
     * @param array $bookmarks
     */
    public function setBookmarks(array $bookmarks): void
    {
        $this->bookmarks = $bookmarks;
    }

    /**
     * @param array $ignoreHosts
     */
    public function setIgnoreHosts(array $ignoreHosts): void
    {
        $this->ignoreHosts = $ignoreHosts;
    }

    /**
     * @param Logger $logger
     */
    public function setLogger(Logger $logger): void
    {
        $this->logger = $logger;
        $this->logger->debug('PostFilter now has a logger.');
    }

    /**
     * @param array $rss
     */
    public function setRss(array $rss): void
    {
        $this->rss = $rss;
    }

    /**
     * @param array $tweets
     */
    public function setTweets(array $tweets): void
    {
        $this->tweets = $tweets;
    }

    /**
     * @param array $wallabag
     */
    public function setWallabag(array $wallabag): void
    {
        $this->wallabag = $wallabag;
    }


}