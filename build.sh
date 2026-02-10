#!/bin/bash

SCRIPT_DIR="$( cd -- "$( dirname -- "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"

#
# If you run this locally, the result ends up in the other repository.
#

# clone GitHub repository
rm -rf $SCRIPT_DIR/build
mkdir -p $SCRIPT_DIR/build
cd $SCRIPT_DIR/build
git clone https://github.com/SDx3/sanderdorigo.nl.git . > /dev/null 2>&1
cd $SCRIPT_DIR

git pull > /dev/null 2>&1

if [ "$1" == "--clean" ]; then
    rm -f $SCRIPT_DIR/cache/tags.json
    rm -f $SCRIPT_DIR/cache/urls.json
    rm -f $SCRIPT_DIR/cache/twitter-cache.json
    rm -f $SCRIPT_DIR/cache/wallabag.json
    rm -f $SCRIPT_DIR/cache/bookmarks-cache.json
fi

php index.php

if [ $? -ne 0 ]
then
  echo "Build script failed, will not continue."
  exit 1
fi

export $(egrep -v '^#' .env | xargs)
cd $SCRIPT_DIR/build

# assume things have changed:
git config --global user.email $GIT_EMAIL
git config --global user.name $GIT_NAME
cd $SCRIPT_DIR/build/content/stream
git add -A . > /dev/null
cd $SCRIPT_DIR/build

git commit -m "Auto-commit on `date +"%Y-%m-%d"`"
retVal=$?

if [ "$retVal" -eq 0 ] && [ "$retVal" -eq "0" ]; then
    echo "Could not do auto commit, please check, code $retVal"
fi

git push "https://$GIT_USER:$GIT_PASS@github.com/SDx3/sanderdorigo.nl.git" --all > /dev/null
retVal=$?

if [ "$retVal" -eq 0 ] && [ "$retVal" -eq "0" ]; then
    echo 'Could not do git push, please check, code %d.' $retVal
fi

# delete repos again
#cd $SCRIPT_DIR
#rm -rf build
