#!/bin/bash

PATH_PWD=$(pwd)
SCRIPT_PATH=$(cd $(dirname $0); pwd)
SCRIPT_NAME=$(basename $0)

for FILE in $SCRIPT_PATH/docs/*; do
    xcowsay -t 0 -f 24 --monitor=1 --at=2000,2000 < $FILE
done

xcowsay -t0 -f 60 --monitor=1 --at 400,400 'THANK YOU!'

cd $PATH_PWD
