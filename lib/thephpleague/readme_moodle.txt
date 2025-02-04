# This is a description for including the URI toolkit into Moodle core

## Dependencies

Please note that this library depends upon:

- `psr/http-factory`
- `psr/http-message`

Both are already part of Moodle core, so we need to replace them with our own versions.


## Installation

```sh
mv lib/thephpleague/readme_moodle.txt ./
rm -rf lib/thephpleague/*
installdir=`mktemp -d`
cd "${installdir}"
composer init --require league/uri:* -n
cat composer.json | jq '.replace."psr/http-factory"="*"' --indent 4 > composer.json.tmp; mv composer.json.tmp composer.json
cat composer.json | jq '.replace."psr/http-message"="*"' --indent 4 > composer.json.tmp; mv composer.json.tmp composer.json
composer install
rm -rf vendor/composer
rm vendor/autoload.php
cd -
cp -rf "${installdir}/vendor/league/"* lib/thephpleague/
mv readme_moodle.txt lib/thephpleague/
rm -rf $installdir
git add .
```
