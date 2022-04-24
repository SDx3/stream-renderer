# Stream of thoughts

This is a collection of scripts that renders into my [stream of thoughts](https://www.sanderdorigo.nl/stream), a Hugo-powered list of interesting things I found online.

## Sources

Right now, it has four sources that are sorted chronologically and displayed for your viewing pleasure.

* Interesting tweets are collected through the [Twitter API](https://developer.twitter.com/en/docs) from [my](https://twitter.com/SanderDorigo/) bookmarked Tweets.
* Articles I save, read and tag are collected using [Wallabag](https://github.com/wallabag/wallabag).
* Things I read through RSS feeds are collected and shared using [Tiny Tiny RSS](https://tt-rss.org/).
* Most of my bookmarks are saved using [Mozilla Firefox](https://www.mozilla.org/en-US/firefox/new/).

### Pinboard

As you can see on my site, most entries are tagged. This is partly my own handiwork, but for the most part supported by [Pinboard](https://pinboard.in/), a paid social bookmarking site. From their API I get the tags for the links I have collected.

## How it works

It's all built using PHP. There are four collectors in the `app/Collector` folder that use a variety of API's to collect information from their respective source. The one exception is Mozilla Firefox: I have to upload a file called `bookmarks.json` manually. Mozilla Firefox uses a notoriously terrible "[authentication zoo](https://wallabag.sanderdorigo.nl/share/625c3bbce80187.75912330)" which I refuse to use.

The collectors do some collecting and the code in `app/Processor` does the processing.

* First, they check with Pinboard which tags each link has. These tags are normalised a little bit according to `tags.php` so I don't end up with really weird tags. 
* Second, they generate little markdown files using the templates in `templates`.
* The end result is committed to the [GitHub repository](https://github.com/SDx3/sanderdorigo.nl/tree/main/content/stream) that holds my website and a GitHub action builds the website every night.

Items can be excluded by domain or by tag. This is useful to prevent the sharing of private websites or sensitive bookmarks.

---

So, essentially, when I bookmark a Tweet or share an article from an RSS feed, this script will pick it up and add it to my "[stream](https://www.sanderdorigo.nl/stream)". If you like what you see, you can also grab the [RSS-feed](https://www.sanderdorigo.nl/stream/index.xml) and add it to your favorite RSS reader.

Enjoy!

~ Sander
