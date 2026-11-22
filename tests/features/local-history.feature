Feature: Local history
  Every save keeps a snapshot of the file under .porta/history, independently
  of git. Ctrl+Alt+H lists the active file's snapshots and Enter restores one as
  a single undoable edit; Compare with Local History shows what a snapshot
  would change first; Recent Changes lists every file with history. Past the
  "Snapshots kept per file" setting the oldest snapshot is pruned.

  Background:
    Given a PHP project
    And a file "A.php" containing "version one"
    And the bundled plugin "local-history" is enabled

  Scenario: Restore an older snapshot, and undo the restore in one step
    Given I start the app with the arguments "A.php"
    And I save the file with the text "version two"
    And I save the file with the text "version three"
    When I use the action "ide_local_history"
    Then I should see "Local History"
    When I press "Down"
    And I press "Enter"
    Then I should see "Restored from local history"
    And the editor should contain "version two"
    When I focus "#editor"
    And I use the action "undo"
    Then the editor should contain "version three"

  Scenario: Compare with a snapshot shows what restoring it would change
    Given I start the app with the arguments "A.php"
    And I save the file with the text "alpha\nbeta\n"
    And I save the file with the text "alpha\nBETA\ndelta\n"
    When I use the action "local-history:compare_snapshot"
    Then I should see "Compare with Snapshot"
    When I press "Down"
    And I press "Enter"
    Then I should see "Local History Diff"
    And I should see "-beta"
    And I should see "+BETA"
    And I should see "+delta"

  Scenario: Recent changes lists the files with history, the newest on top
    Given a file "B.php" containing "b one"
    And I start the app with the arguments "A.php"
    And I save the file with the text "version two"
    And I open "B.php"
    And I save the file with the text "b two"
    When I use the action "local-history:recent_changes"
    Then I should see "Recent Changes"
    When I press "Enter"
    Then the editor should show "B.php"

  @setting:local-history.maxPerFile
  Scenario: Past the snapshots kept per file, the oldest is pruned
    Given the project configuration:
      """
      {"plugins": {"local-history": {"maxPerFile": 2}}}
      """
    And I start the app with the arguments "A.php"
    And I save the file with the text "version two"
    And I save the file with the text "version three"
    And I save the file with the text "version four"
    When I use the action "ide_local_history"
    And I press "Down"
    And I press "Down"
    And I press "Enter"
    Then the editor should contain "version three"
