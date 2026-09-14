#!/bin/zsh
# usage: run.sh <file-relative-to-probes-range> [extra args] -- branch checkout; output saved to <file>.out (header + path prefix stripped)
P=/private/tmp/claude-501/-Users-megurine-repo-php-phpstan-src/57c31198-af9c-47de-b856-bda8e5738efb/scratchpad/probes-range
f="$1"; shift
cd /private/tmp/claude-501/-Users-megurine-repo-php-phpstan-src/57c31198-af9c-47de-b856-bda8e5738efb/scratchpad/review-float-range-type
bin/phpstan analyse --no-progress --error-format=raw -c $P/probe.neon "$@" "$P/$f" 2>&1 | grep -v '^\(Instructions\|-----\|Each error\|This page\|and instruction\|Before fixing\|The error usually\|Do not \|or `return\|$\)' | sed "s|^$P/||; s| \[identifier=phpstan.dumpType\]||" > "$P/$f.out"
cat "$P/$f.out"
