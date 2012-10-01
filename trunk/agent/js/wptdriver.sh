#!/bin/bash

server=http://localhost:8888
location=Test
export WPT_VERBOSE=false
export WPT_MAX_LOGLEVEL=5
export WPT_DEBUG=false

while getopts vds:l:m: o
do  case "$o" in
  s)  server="$OPTARG";;
  l)  location="$OPTARG";;
  m)  export WPT_MAX_LOGLEVEL="$OPTARG";;
  v)  export WPT_VERBOSE="true";;
  d)  export WPT_DEBUG=true;;
  [?])  echo "Usage: $0 [-s server] [-l location] [-v] [-d] [-m]"
    echo "        -s    server       WebPagetest server"
    echo "        -l    location     location name of the WebPagetest server"
    echo "        -v    verbose      mirrors all logs to stdout"
    echo "        -d    debug        sets all debug and custom loglevels to -1 so that"
    echo "                           they are guaranteed to display"
    echo "        -m    max loglevel sets the maximum loglevel that will be saved"
    echo "                           the value can either be a number (0-8) or the name"
    echo "                           of a loglevel such as critical, warning, or debug"
    exit 1;;
  esac
done
shift $OPTIND-1

# Determine parent directory of the webpagetest project
case "$0" in
  /*) wpt_root="$0" ;;
  *)  wpt_root="$PWD/$0" ;;
esac
while true; do
  if [[ -d "$wpt_root/agent/js/src" ]]; then
    break
  fi
  wpt_root="${wpt_root%/*}"
  if [[ -z "$wpt_root" ]]; then
    echo "Cannot determine project root from $0" 1>&2
    exit 2
  fi
done

agent="$wpt_root/agent/js"
devtools2har_jar="$wpt_root/lib/dt2har/target/dt2har-1.0-SNAPSHOT-jar-with-dependencies.jar"
selenium_jar="$wpt_root/lib/webdriver/java/selenium-standalone.jar"
chromedriver="$wpt_root/lib/webdriver/chromedriver/chromedriver"

export NODE_PATH="${agent}:${agent}/src:${wpt_root}/lib/webdriver/javascript/node"
echo "NODE_PATH=$NODE_PATH"

declare -a cmd=(node src/agent_main --wpt_server ${server} --location ${location} --chromedriver "$chromedriver" --selenium_jar "$selenium_jar" --devtools2har_jar="$devtools2har_jar")

echo "${cmd[@]}"
"${cmd[@]}"
