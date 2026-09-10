@core @core_admin
Feature: Valid IP addresses are accepted and invalid ones are rejected in the IP blocker settings.
  In order to avoid misconfiguring the allowed and blocked IP lists
  As an admin
  I want only valid IPv4 and IPv6 addresses to be accepted

  Scenario Outline: Attempting to set the blocked IP list with a valid IP address
    Given I log in as "admin"
    And I navigate to "Security > IP blocker" in site administration
    When I set the following administration settings values:
      | Blocked IP List | <ip> |
    Then I should see "<ip>"
    And I should not see "These IP addresses are invalid"

    Examples:
      | ip                                     |
      | 10.0.0.5                               |
      | 2001:db8:3333:4444:5555:6666:7777:8888 |

  Scenario Outline: Attempting to set the blocked IP list with an invalid IP address
    Given I log in as "admin"
    And I navigate to "Security > IP blocker" in site administration
    When I set the following administration settings values:
      | Blocked IP List | <ip> |
    Then I should see "These IP addresses are invalid: <ip>"

    Examples:
      | ip              |
      | 2001:db8::g123  |
      | 999.999.999.999 |
