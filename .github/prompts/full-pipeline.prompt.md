---
description: "Full pipeline: execute tests → dashboard → ADO sync → email (supports smoke/regression)"
mode: agent
agent: Testcase Execution
---

Follow these steps in exact order. Report status after each step.

## Step 1: Ask Which Project
Call `list_projects` to see registered projects.
Ask: "Which project or Excel file?" Wait for answer.

## Step 2: Ask Scope
Ask: "Which test scope? (all / smoke / regression)" Default to "all".

## Step 3: Validate Credentials
Call `validate_credentials`. Fix any issues before proceeding. STOP here until valid.

## Step 4: Execute Tests
Run `data_driven_tool` with the selected project and scope. If error → STOP.

## Step 5: Show Summary
Display: Scope, Total APIs, Passed, Failed, Errors, Duration.

## Step 6: Generate Dashboard
Call `data_driven_tool` with action="generate_dashboard". If error, log ✘ but continue.

## Step 7: Push to ADO
Call `update_testcase_outcome_in_testplan`. If ADO PAT missing, log ✘ and continue.

## Step 8: Send Email
Call `data_driven_tool` with action="send_email". If SMTP fails, log ✘.

## Step 9: Final Summary
Show: | Step | Status | with ✔/✘ for each.