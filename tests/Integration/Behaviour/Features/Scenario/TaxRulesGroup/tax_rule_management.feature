@restore-all-tables-before-feature
#./vendor/bin/behat -c tests/Integration/Behaviour/behat.yml -s tax_rules_group
Feature: Manage tax rules within a tax rules group
  As an employee
  I must be able to add, edit and delete tax rules within a tax rules group

  Background:
    Given I add a new tax rules group "test-group" with the following properties:
      | name       | Test Tax Rules Group |
      | is_enabled | true                 |

  Scenario: Adding a tax rule to a group for a specific country
    When I add a tax rule to group "test-group" with the following properties:
      | country     | 8   |
      | tax         | 1   |
      | behavior    | 0   |
      | zipcode     |     |
      | description | US tax rule |
    Then tax rule "test-tax-rule" should exist in group "test-group"
    And tax rule "test-tax-rule" country should be 8
    And tax rule "test-tax-rule" tax should be 1
    And tax rule "test-tax-rule" behavior should be 0
    And tax rule "test-tax-rule" description should be "US tax rule"

  Scenario: Adding a tax rule with a zipcode range
    When I add a tax rule to group "test-group" with the following properties:
      | country     | 6     |
      | tax         | 1     |
      | behavior    | 0     |
      | zipcode     | 75000-75015 |
      | description | Paris zip range |
    Then tax rule "test-tax-rule" should exist in group "test-group"
    And tax rule "test-tax-rule" zipcode from should be "75000"
    And tax rule "test-tax-rule" zipcode to should be "75015"

  Scenario: Adding a tax rule with empty zipcode stores 0/0
    When I add a tax rule to group "test-group" with the following properties:
      | country     | 6   |
      | tax         | 1   |
      | behavior    | 0   |
      | zipcode     |     |
      | description |     |
    Then tax rule "test-tax-rule" should exist in group "test-group"
    And tax rule "test-tax-rule" zipcode from should be "0"
    And tax rule "test-tax-rule" zipcode to should be "0"

  Scenario: Adding a tax rule with combine behavior
    When I add a tax rule to group "test-group" with the following properties:
      | country     | 6   |
      | tax         | 1   |
      | behavior    | 1   |
      | zipcode     |     |
      | description |     |
    Then tax rule "test-tax-rule" should exist in group "test-group"
    And tax rule "test-tax-rule" behavior should be 1

  Scenario: Editing a tax rule
    When I add a tax rule to group "test-group" with the following properties:
      | country     | 6   |
      | tax         | 1   |
      | behavior    | 0   |
      | zipcode     |     |
      | description | Original |
    When I edit tax rule "test-tax-rule" with the following properties:
      | description | Updated description |
      | behavior    | 2                   |
    Then tax rule "test-tax-rule" description should be "Updated description"
    And tax rule "test-tax-rule" behavior should be 2

  Scenario: Deleting a tax rule
    When I add a tax rule to group "test-group" with the following properties:
      | country     | 6   |
      | tax         | 1   |
      | behavior    | 0   |
      | zipcode     |     |
      | description |     |
    When I delete tax rule "test-tax-rule"
    Then tax rule "test-tax-rule" should not exist

  Scenario: Bulk deleting tax rules
    When I add a tax rule to group "test-group" with the following properties:
      | country     | 6   |
      | tax         | 1   |
      | behavior    | 0   |
      | zipcode     |     |
      | description | Rule 1 |
    And I add another tax rule to group "test-group" with the following properties:
      | country     | 8   |
      | tax         | 1   |
      | behavior    | 0   |
      | zipcode     |     |
      | description | Rule 2 |
    When I bulk delete tax rules "test-tax-rule, test-tax-rule-2" from group "test-group"
    Then tax rule "test-tax-rule" should not exist
    And tax rule "test-tax-rule-2" should not exist
