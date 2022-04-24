#!/bin/bash

SCRIPT_DIR="$( cd -- "$( dirname -- "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"

# clone GitHub repository
rm -rf $SCRIPT_DIR/build
mkdir -p $SCRIPT_DIR/build
cd $SCRIPT_DIR/build
git clone https://github.com/SDx3/sanderdorigo.nl.git . > /dev/null 2>&1
cd $SCRIPT_DIR

if [ "$1" == "--clean" ]; then
    echo "Will remove cache before continuing."
    rm -f $SCRIPT_DIR/cache/tags.json
    rm -f $SCRIPT_DIR/cache/urls.json
    rm -f $SCRIPT_DIR/cache/twitter-cache.json
    rm -f $SCRIPT_DIR/cache/wallabag.json
    rm -f $SCRIPT_DIR/cache/bookmarks-cache.json
    echo "Done!"
fi


exit

php index.php

export $(egrep -v '^#' .env | xargs)
cd $SCRIPT_DIR/build

# assume things have changed:
git config --global user.email $GIT_EMAIL
git config --global user.name $GIT_NAME
cd $SCRIPT_DIR/build/content/stream
git add -A . > /dev/null
cd $SCRIPT_DIR/build
git commit -m "Auto-commit on `date +"%Y-%m-%d"`" > /dev/null 2>&1

git push "https://$GIT_USER:$GIT_PASS@github.com/SDx3/sanderdorigo.nl.git" --all > /dev/null 2>&1

# delete repos again
cd $SCRIPT_DIR
rm -rf build
