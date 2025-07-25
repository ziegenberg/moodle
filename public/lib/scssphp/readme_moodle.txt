# This is a description for including scssphp into Moodle core


## Dependencies

Please note that this library depends upon:

- `league/uri-interfaces`
- `league/uri`
- `symfony/filesystem`

They are included separately into Moodle core, so we need to replace them with our own versions.

Please note that this library depends upon:

- scssphp/source-span


## Installation

```sh
mv public/lib/scssphp/readme_moodle.txt ./
rm -rf public/lib/scssphp/*
installdir=`mktemp -d`
cd "${installdir}"
composer init --require scssphp/scssphp:* -n

cat composer.json | jq '.replace."symfony/filesystem"="*"' --indent 4 > composer.json.tmp; mv composer.json.tmp composer.json
cat composer.json | jq '.replace."league/uri-interfaces"="*"' --indent 4 > composer.json.tmp; mv composer.json.tmp composer.json
cat composer.json | jq '.replace."league/uri"="*"' --indent 4 > composer.json.tmp; mv composer.json.tmp composer.json

composer install
rm -rf vendor/composer
rm vendor/autoload.php
cd -
cp -rf "${installdir}/vendor/scssphp/"* public/lib/scssphp/
mv readme_moodle.txt public/lib/scssphp/
rm -rf $installdir
git add .
```
