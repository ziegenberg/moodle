@core @core_badges
Feature: Connect a user backpack to an external Open Badges v2.0 backpack

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Student   | 1        | student1@example.com |
    And the following "badge external backpacks" exist:
      | backpackapiurl                      | backpackweburl               | apiversion | sortorder |
      | https://backpack.example.org/api/v2 | https://backpack.example.org | 2          | 1         |
    And the following "setup backpack connected" exist:
      | user     | externalbackpack             |
      | student1 | https://backpack.example.org |

  @javascript
  Scenario: Fetch collections from a connected Open Badges v2.0 backpack
    Given I log in as "student1"
    # The user backpack authenticates against the backpack provider.
    And HTTP requests to "https://backpack.example.org/o/token" will respond with status code "200"
    And HTTP requests to "https://backpack.example.org/o/token" will respond with the header "Content-Type" set to "application/json"
    And HTTP requests to "https://backpack.example.org/o/token" will respond with the body in "badges/tests/fixtures/backpack_token.json"
    # The authenticated user backpack then fetches its collections.
    And HTTP requests to "https://backpack.example.org/api/v2/backpack/collections" will respond with status code "200"
    And HTTP requests to "https://backpack.example.org/api/v2/backpack/collections" will respond with the header "Content-Type" set to "application/json"
    And HTTP requests to "https://backpack.example.org/api/v2/backpack/collections" will respond with the body in "badges/tests/fixtures/backpack_collections.json"
    When I follow "Preferences" in the user menu
    And I follow "Backpack settings"
    Then I should see "Collection 1"

  @javascript
  Scenario: Show a warning when the collections cannot be fetched from the backpack
    Given I log in as "student1"
    # The backpack provider is unavailable, so authentication fails.
    And HTTP requests to "https://backpack.example.org/o/token" will respond with status code "500"
    When I follow "Preferences" in the user menu
    And I follow "Backpack settings"
    Then I should see "There are no public collections of badges available in your backpack"
