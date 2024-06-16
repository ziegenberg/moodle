Description of phpCAS import into Moodle

Last release can be found at https://github.com/apereo/phpCAS/releases

NOTICE:
 * Before running composer command, make sure you have the composer version updated.
 * Composer version 2.7.7 2024-06-16 19:06:42

STEPS:
 * Make sure you're using the lowest supported PHP version for the given release (e.g. PHP 8.1 for Moodle 4.5)
 * Create a temporary folder outside your Moodle installation
 * Execute 'composer require apereo/phpcas:VERSION'
 * Check any new libraries that have been added and make sure they do not exist in Moodle already.
 * Remove the old 'vendor' directory in auth/cas/CAS/
 * Copy contents of 'vendor' directory
 * Create a commit with only the library changes.
   - Note: Make sure to check the list of unversioned files and add any new files to the staging area.
 * Update auth/cas/thirdpartylibs.xml
 * Create another commit with the previous change
