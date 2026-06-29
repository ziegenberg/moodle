Description of XMLRPC for PHP library import into Moodle.

Source: https://github.com/gggeek/phpxmlrpc

This library provides XML-RPC client and server support from PHP code.
It is a modern replacement for the old (removed from core since PHP 8.0)
xmlrpc extension.

```sh
mv lib/phpxmlrpc/readme_moodle.txt ./
rm -rf lib/phpxmlrpc/*
tempdir=`mktemp -d`
cd "${tempdir}"
composer init --require phpxmlrpc/phpxmlrpc:^4 -n
composer install
cd -
cp -rf "${tempdir}/vendor/phpxmlrpc/phpxmlrpc/src" lib/phpxmlrpc/
rm lib/phpxmlrpc/src/Autoloader.php
cp "${tempdir}/vendor/phpxmlrpc/phpxmlrpc/composer.json" lib/phpxmlrpc/
cp "${tempdir}/vendor/phpxmlrpc/phpxmlrpc/license.txt" lib/phpxmlrpc/
cp "${tempdir}/vendor/phpxmlrpc/phpxmlrpc/README.md" lib/phpxmlrpc/
cp "${tempdir}/vendor/phpxmlrpc/phpxmlrpc/NEWS.md" lib/phpxmlrpc/
rm -rf $tempdir
mv readme_moodle.txt lib/phpxmlrpc/
git add .
```

Now update the lib/thirdpartylibs.xml with the upgrades, and commit the changes.
