@repository @repository_s3 @_file_upload @javascript
Feature: Pick files from the Amazon S3 repository
  In order to re-use files stored in Amazon S3
  As a user
  I need to be able to browse and select files using the Amazon S3 repository

  The HTTP requests made by the AWS SDK are mocked with the generic HTTP mocking
  steps (MDL-89728), so that no real S3 service is needed. The mocked URLs are
  the ones the AWS SDK resolves for a bucket in the us-east-1 region.

  Background:
    Given the S3 repository is enabled with accesskey "testaccess" secretkey "testsecret"
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "activities" exist:
      | activity | course | name        |
      | folder   | C1     | Test folder |
    And I am on the "Test folder" "folder activity" page logged in as admin
    And I press "Edit"

  Scenario: Browse the S3 repository and add a file as a copy
    Given HTTP requests to "https://s3.amazonaws.com/" will respond with status code "200"
    And HTTP requests to "https://s3.amazonaws.com/" will respond with the body in "repository/s3/tests/fixtures/listbuckets.xml"
    And HTTP requests to "https://testbucket.s3.amazonaws.com/" will respond with status code "200"
    And HTTP requests to "https://testbucket.s3.amazonaws.com/" will respond with the body in "repository/s3/tests/fixtures/listobjects.xml"
    And HTTP requests to "https://testbucket.s3.amazonaws.com/dir/file.txt" will respond with status code "200"
    And HTTP requests to "https://testbucket.s3.amazonaws.com/dir/file.txt" will respond with the header "Content-Type" set to "text/plain"
    And HTTP requests to "https://testbucket.s3.amazonaws.com/dir/file.txt" will respond with the body in "repository/s3/tests/fixtures/file.txt"
    When I click on "Add..." "button"
    And I select "Amazon S3" repository in file picker
    Then I should see "testbucket" "folder" in repository content area
    When I click on "testbucket" "folder" in repository content area
    Then I should see "dir" "folder" in repository content area
    And I should see "file.txt" "file" in repository content area
    When I click on "file.txt" "file" in repository content area
    And I click on "Select this file" "button"
    Then I should see "1" elements in "Files" filemanager

  Scenario: Browse the S3 repository and link to a file
    Given HTTP requests to "https://s3.amazonaws.com/" will respond with status code "200"
    And HTTP requests to "https://s3.amazonaws.com/" will respond with the body in "repository/s3/tests/fixtures/listbuckets.xml"
    And HTTP requests to "https://testbucket.s3.amazonaws.com/" will respond with status code "200"
    And HTTP requests to "https://testbucket.s3.amazonaws.com/" will respond with the body in "repository/s3/tests/fixtures/listobjects.xml"
    When I click on "Add..." "button"
    And I select "Amazon S3" repository in file picker
    And I click on "testbucket" "folder" in repository content area
    And I click on "file.txt" "file" in repository content area
    And I click on "Link to the file" "radio"
    And I click on "Select this file" "button"
    Then I should see "1" elements in "Files" filemanager

  Scenario: Browse an S3-compatible service and add a file as a copy
    Given the S3 repository is enabled with accesskey "testaccess" secretkey "testsecret" and endpoint "https://s3.example.com"
    And HTTP requests to "https://s3.example.com/" will respond with status code "200"
    And HTTP requests to "https://s3.example.com/" will respond with the body in "repository/s3/tests/fixtures/listbuckets.xml"
    And HTTP requests to "https://s3.example.com/testbucket/" will respond with status code "200"
    And HTTP requests to "https://s3.example.com/testbucket/" will respond with the body in "repository/s3/tests/fixtures/listobjects.xml"
    And HTTP requests to "https://s3.example.com/testbucket/dir/file.txt" will respond with status code "200"
    And HTTP requests to "https://s3.example.com/testbucket/dir/file.txt" will respond with the header "Content-Type" set to "text/plain"
    And HTTP requests to "https://s3.example.com/testbucket/dir/file.txt" will respond with the body in "repository/s3/tests/fixtures/file.txt"
    When I click on "Add..." "button"
    And I select "Amazon S3" repository in file picker
    And I click on "testbucket" "folder" in repository content area
    And I click on "file.txt" "file" in repository content area
    And I click on "Select this file" "button"
    Then I should see "1" elements in "Files" filemanager
