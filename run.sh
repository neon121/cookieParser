#!/usr/bin/env bash
rm _selenium.log
rm _php.log
pkill -f selenium-server-standalone-3.9.1.jar
pkill -f chromedriver
pkill -f chrome
nohup java -jar selenium-server-standalone-3.9.1.jar > _selenium.log 2>&1 &
sleep 2
nohup php start.php > _php.log 2>&1 &
sh -c 'tail -n +0 -f _php.log | { sed "/Finished at / q" && kill $$ ;}'
echo terminating
pkill -f selenium-server-standalone-3.9.1.jar
pkill -f chromedriver
pkill -f chrome
pkill -f _php.log
