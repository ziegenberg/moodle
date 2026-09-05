Description of SimplePie library import into Moodle

Obtained from https://github.com/simplepie/simplepie/releases/

To upgrade this library:

```sh
# Preserve the Moodle-owned readme file.
mv public/lib/simplepie/readme_moodle.txt ./
rm -rf public/lib/simplepie/*

tempdir=`mktemp -d`
cd "${tempdir}"
composer require simplepie/simplepie
cd - >/dev/null

# Copy only the files/dirs Moodle vendors.
cp -f "${tempdir}/vendor/simplepie/simplepie/CHANGELOG.md" public/lib/simplepie/
cp -f "${tempdir}/vendor/simplepie/simplepie/composer.json" public/lib/simplepie/
cp -f "${tempdir}/vendor/simplepie/simplepie/README.markdown" public/lib/simplepie/
cp -rf "${tempdir}/vendor/simplepie/simplepie/src" public/lib/simplepie/src

# Upstream ships the licence text under LICENSES/ (split by SPDX identifier) since 1.9.0;
# Moodle keeps a single BSD-3-Clause licence file named LICENSE.txt.
cp -f "${tempdir}/vendor/simplepie/simplepie/LICENSES/BSD-3-Clause.txt" public/lib/simplepie/LICENSE.txt

# Restore the Moodle-owned readme file.
mv readme_moodle.txt public/lib/simplepie/
rm -rf "${tempdir}"
git add public/lib/simplepie
```

Now update `public/lib/thirdpartylibs.xml` with the new version and commit the changes.

Verify the upgrade:
- Check whether the release gained any new files or dependencies that should be moved into Moodle,
  and update this doc if so.
