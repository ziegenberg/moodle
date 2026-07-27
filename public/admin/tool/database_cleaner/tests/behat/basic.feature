@tool @tool_database_cleaner
Feature: Orphan course module cleaner
  As a site administrator
  I want to detect and remove orphan course modules
  So that the database does not contain corrupt course module references

  Background:
    Given the following "courses" exist:
      | fullname | shortname | format |
      | Course 1 | C1        | topics |
    And the following "activities" exist:
      | activity | course | section | name            |
      | assign    | C1     | 1       | Orphaned assign |
    And the following config values are set as admin:
      | coursebinenable | 0 | tool_recyclebin |

  @javascript
  Scenario: The report detects an orphan and web confirmation removes it via a background task
    Given the "Orphaned assign" activity in "C1" is an orphan
    And I log in as "admin"
    When I visit "/admin/tool/database_cleaner/index.php"
    Then I should see "Deletable orphans (1)"
    And I should see "Assignment"
    And I press "Review selected for deletion"
    And I set the field "ack" to "1"
    And I press "Delete the selected orphans"
    And I should see "background task"
    And I run all adhoc tasks
    When I visit "/admin/tool/database_cleaner/index.php"
    Then I should see "No orphan course modules were detected"
    And I should not see "Deletable orphans"
