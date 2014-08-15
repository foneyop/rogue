#!/bin/sh
exec ctags-exuberant -f .tags \
	-h \".php\" -R \
	--exclude=\"\.git\" \
	--totals=yes \
	--tag-relative=yes \
	--PHP-kinds=+cf \
	--regex-PHP='/abstract\s+class\s+([^ ]*)/\1/c/' \
	--regex-PHP='/interface\s+([^ ]*)/\1/c/' \
	--regex-PHP='/(public |static |abstract |protected |private )+function ([^ (]*)/\2/f/'
