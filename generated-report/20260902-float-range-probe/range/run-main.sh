#!/bin/zsh
P=/private/tmp/claude-501/-Users-megurine-repo-php-phpstan-src/57c31198-af9c-47de-b856-bda8e5738efb/scratchpad/probes-range
f="$1"; shift
cd /Users/megurine/repo/php/phpstan-src
bin/phpstan analyse --no-progress --error-format=raw -c $P/probe-main.neon "$@" "$P/$f" 2>&1 | grep -v '^\(Instructions\|-----\|Each error\|This page\|and instruction\|Before fixing\|The error usually\|Do not \|or `return\|$\)' | sed "s|^$P/||; s| \[identifier=phpstan.dumpType\]||" > "$P/$f.main.out"
cat "$P/$f.main.out"
