---
description: "Execute tests from an ADO test suite (pulls test case IDs from ADO, runs matching Excel tests)"
mode: agent
agent: Testcase Execution
---

Follow these steps in exact order. Do NOT skip ahead.

## Step 1: Ask Project
Call `list_projects` to see registered projects.
Ask: "Which project or Excel file?"

## Step 2: Ask ADO Suite Details
Ask: "Provide ADO Plan ID and Suite ID"

## Step 3: Ask Scope
Ask: "Which scope? (all / smoke / regression)" Default to "all".

## Step 4: Validate Credentials
Call `validate_credentials`. Check ADO PAT + project credentials. STOP until valid.

## Step 5: List ADO Suite Test Cases
Call `list_ado_suite_testcases`. If error (empty suite, 401), show and STOP.

## Step 6: Confirm Execution
Ask: "Run these {count} test cases? (yes/no)"

## Step 7: Run From ADO
Call `data_driven_tool(action="run_from_ado", ...)`. If error → STOP.

## Step 8–12: Post-Execution
Show summary → Ask dashboard → Ask ADO push → Ask email.