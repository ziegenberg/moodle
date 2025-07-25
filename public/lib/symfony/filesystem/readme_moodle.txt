# This is a description for including symfony/filesystem into Moodle core

## Dependencies

Please note that this library depends upon:

- `symfony/polyfill-mbstring`
- `symfony/polyfill-ctype`

As the ctype and the mbstring extension are required by Moodle, we can safely assume that they are available in the environment.


## Installation

```sh
mv public/lib/symfony/filesystem/readme_moodle.txt ./
rm -rf public/lib/symfony/filesystem
installdir=`mktemp -d`
cd "${installdir}"
composer init --require symfony/filesystem:* -n
cat composer.json | jq '.replace."symfony/polyfill-mbstring"="*"' --indent 4 > composer.json.tmp; mv composer.json.tmp composer.json
cat composer.json | jq '.replace."symfony/polyfill-ctype"="*"' --indent 4 > composer.json.tmp; mv composer.json.tmp composer.json
composer install
rm -rf vendor/composer
rm vendor/autoload.php
cd -
cp -rf "${installdir}/vendor/symfony/"* public/lib/symfony/
mv readme_moodle.txt public/lib/symfony/filesystem
rm -rf $installdir
git add .
```
