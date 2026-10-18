#!/bin/bash

set -euo pipefail

SCRIPT_DIR="$( cd -- "$( dirname -- "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"

#
# If you run this locally, the result ends up in the other repository.
#

# clone GitHub repository
rm -rf $SCRIPT_DIR/build
mkdir -p $SCRIPT_DIR/build
cd $SCRIPT_DIR/build
git clone https://github.com/SDx3/sanderdorigo.nl.git . -q
cd $SCRIPT_DIR
git pull -q

if [ "${1:-default}" == "--clean" ]; then
    rm -f $SCRIPT_DIR/cache/tags.json
    rm -f $SCRIPT_DIR/cache/urls.json
    rm -f $SCRIPT_DIR/cache/twitter-cache.json
    rm -f $SCRIPT_DIR/cache/mastodon-cache.json
    rm -f $SCRIPT_DIR/cache/raindrop-cache.json
    rm -f $SCRIPT_DIR/cache/wallabag.json
    rm -f $SCRIPT_DIR/cache/bookmarks-cache.json
fi

php index.php

export $(egrep -v '^#' .env | xargs)
cd $SCRIPT_DIR/build

# assume things have changed:
git config user.email $GIT_EMAIL
git config user.name $GIT_NAME
cd $SCRIPT_DIR/build/content/stream
git add -A .
cd $SCRIPT_DIR/build

git commit -m "Auto-commit on `date +"%Y-%m-%d"`"
#retVal=$?

#if [ "$retVal" -eq 0 ] && [ "$retVal" -eq "0" ]; then
#    echo "Could not do auto commit, please check, code $retVal"
#fi

git push "https://$GIT_USER:$GIT_PASS@github.com/SDx3/sanderdorigo.nl.git" --all
#retVal=$?

#if [ "$retVal" -eq 0 ] && [ "$retVal" -eq "0" ]; then
#    printf 'Could not do git push, please check, code "%d" "%s"\n' $retVal $retVal
#fi

# delete repos again
#cd $SCRIPT_DIR
#rm -rf build
