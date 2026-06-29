Description of XMLRPC for PHP library import into Moodle.

Source: https://github.com/gggeek/phpxmlrpc

This library provides XML-RPC client and server support from PHP code.
It is a modern replacement for the old (removed from core since PHP 8.0)
xmlrpc extension.

```sh
mv public/lib/phpxmlrpc/readme_moodle.txt ./
rm -rf public/lib/phpxmlrpc/*
tempdir=`mktemp -d`
cd "${tempdir}"
composer init --require phpxmlrpc/phpxmlrpc:^4 -n
composer install
cd -
cp -rf "${tempdir}/vendor/phpxmlrpc/phpxmlrpc/src" public/lib/phpxmlrpc/
rm public/lib/phpxmlrpc/src/Autoloader.php
cp "${tempdir}/vendor/phpxmlrpc/phpxmlrpc/composer.json" public/lib/phpxmlrpc/
cp "${tempdir}/vendor/phpxmlrpc/phpxmlrpc/license.txt" public/lib/phpxmlrpc/
cp "${tempdir}/vendor/phpxmlrpc/phpxmlrpc/README.md" public/lib/phpxmlrpc/
cp "${tempdir}/vendor/phpxmlrpc/phpxmlrpc/NEWS.md" public/lib/phpxmlrpc/
rm -rf $tempdir
mv readme_moodle.txt public/lib/phpxmlrpc/
git add .
```

Now update the public/lib/thirdpartylibs.xml with the upgrades, and commit the changes.
