#!/bin/bash

SCRIPT_DIR="$( cd -- "$( dirname -- "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"


mkdir -p $SCRIPT_DIR/build
cd $SCRIPT_DIR/build
git clone https://github.com/SDx3/sanderdorigo.nl.git .
cd $SCRIPT_DIR

php index.php

export $(egrep -v '^#' .env | xargs)
cd $SCRIPT_DIR/build

# assume things have changed:
git config --global user.email $GIT_EMAIL
git config --global user.name $GIT_NAME
cd $SCRIPT_DIR/build/content/stream
git add -u .
cd $SCRIPT_DIR/build
git commit -m "Auto-commit on `date +"%Y-%m-%d"`"

git push "https://$GIT_USER:$GIT_PASS@github.com/SDx3/sanderdorigo.nl.git" --all

# delete repos again
cd $SCRIPT_DIR
rm -rf build
