#!/bin/bash

COMMANDS_DIR="$HOME/.ddev/commands"

# First remove all existing hard links
while read hard_link; do
    rm $hard_link;
done < <(find $COMMANDS_DIR -type f -links 2)
echo "old links deleted"

# Then add new links (ignoring files or directories we explicitly want to ignore)
IGNORE_PATHS=$(awk '{printf("%s%s", sep, $0); sep=" -o -path "} END {print ""}' .links-ignore)
while read file_to_link; do
    LINK_TO="${file_to_link/.\//$COMMANDS_DIR\/}"
    # delete file if it already exists (e.g. overriding ddeb-generated)
    if [ -f $LINK_TO ]; then
        echo "File '$LINK_TO' already exists. Deleting it first."
        rm $LINK_TO
    fi
    # make sure the directory exists so we can make a link in it
    LINK_DIR=$(dirname $LINK_TO)
    if [ ! -d $LINK_DIR ]; then
        mkdir -p $LINK_DIR
    fi
    # make link
    ln "${file_to_link/.\//$PWD\/}" $LINK_TO
done < <(find . \( -path $IGNORE_PATHS \) -prune -o -type f -print)
echo "new links created"
