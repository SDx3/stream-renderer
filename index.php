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

declare(strict_types=1);

use App\Collector\FirefoxCollector;
use App\Collector\RSSCollector;
use App\Collector\TwitterCollector;
use App\Collector\WallabagCollector;
use App\Processor\FirefoxProcessor;
use App\Processor\RSSProcessor;
use App\Processor\TwitterProcessor;
use App\Processor\WallabagProcessor;
use Monolog\Logger;

require 'init.php';

/** @var Logger $log */

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// delete all MD files (the great reset):
$path = scandir($_ENV['BLOG_PATH']);
foreach ($path as $file) {
    if ('.' !== $file && '..' !== $file && '_index.md' !== $file) {
        if (str_ends_with($file, '.md')) {
            $path = realpath(sprintf("%s/%s", $_ENV['BLOG_PATH'], $file));
            unlink($path);
        }
    }
}

// collect Twitter
$collector = new TwitterCollector;
$collector->setLogger($log);
$collector->setConfiguration(
    [
        'client_id'     => $_ENV['TWITTER_CLIENT_ID'],
        'client_secret' => $_ENV['TWITTER_CLIENT_SECRET'],
        'user_id'       => $_ENV['TWITTER_USER_ID'],
    ]
);
$collector->collect();
$bookmarkedTweets = $collector->getCollection();

// collect Wallabag (skips over Twitter entries)
$collector     = new WallabagCollector;
$configuration = [
    'client_id'     => $_ENV['WALLABAG_CLIENT_ID'],
    'client_secret' => $_ENV['WALLABAG_CLIENT_SECRET'],
    'username'      => $_ENV['WALLABAG_USERNAME'],
    'password'      => $_ENV['WALLABAG_PASSWORD'],
    'host'          => $_ENV['WALLABAG_HOST'],
];
$collector->setConfiguration($configuration);
$collector->setLogger($log);
$collector->collect();
$articles = $collector->getCollection();

// collect bookmarks (skips over Twitter + Wallabag entries)
$collector = new FirefoxCollector();
$collector->setLogger($log);
$collector->setConfiguration(
    [
        'exclude_hosts' => explode(',', $_ENV['EXCLUDE_HOSTS']),
    ]
);
$collector->collect();
$bookmarks = $collector->getCollection();


// collect RSS
$collector = new RSSCollector;
$collector->setLogger($log);
$collector->setConfiguration(['feed' => $_ENV['PUBLISHED_ARTICLES_FEED']]);
$collector->collect();
$feedArticles = $collector->getCollection();



// now process the result of the wallabag collection
$processor = new WallabagProcessor;
$processor->setLogger($log);
$processor->setDestination($_ENV['BLOG_PATH']);
$processor->setTitleLength((int) $_ENV['TITLE_LENGTH']);
$processor->process($articles);

// now process RSS
$processor = new RSSProcessor;
$processor->setLogger($log);
$processor->setDestination($_ENV['BLOG_PATH']);
$processor->setTitleLength((int) $_ENV['TITLE_LENGTH']);
$processor->process($feedArticles);

// now process tweets
$processor = new TwitterProcessor;
$processor->setLogger($log);
$processor->setDestination($_ENV['BLOG_PATH']);
$processor->setTitleLength((int) $_ENV['TITLE_LENGTH']);
$processor->process($bookmarkedTweets);

// now process bookmarks
$processor = new FirefoxProcessor;
$processor->setLogger($log);
$processor->setDestination($_ENV['BLOG_PATH']);
$processor->setTitleLength((int) $_ENV['TITLE_LENGTH']);
$processor->process($bookmarks);
