#!/bin/sh
echo '=== comm counts ==='
for d in /proc/[0-9]*; do
  cat "$d/comm" 2>/dev/null
done | sort | uniq -c | sort -nr
echo '=== php-fpm wchan ==='
for d in /proc/[0-9]*; do
  c=$(cat "$d/comm" 2>/dev/null)
  [ "$c" = php-fpm ] || continue
  pid=$(echo "$d" | sed 's#/proc/##')
  wchan=$(cat "$d/wchan" 2>/dev/null)
  state=$(sed -n 's/.*).* //;s/ .*//;p' "$d/stat" 2>/dev/null | head -n 1)
  echo "pid=$pid wchan=$wchan state=$state"
done
