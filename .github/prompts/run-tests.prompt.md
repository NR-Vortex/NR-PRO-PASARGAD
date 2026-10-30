---
description: "Run API tests end-to-end (supports project or direct Excel path, smoke/regression/all)"
mode: agent
agent: Testcase Execution
---

Follow these steps in exact order. Complete each step before moving to the next. Do NOT skip ahead.

## Step 1: Identify Target
Call `list_projects` to see registered projects.
Ask: "Which project do you want to test, or provide the path to your Excel test data file?"
If user names a registered project, use `project` param. If user gives a file path, use `excel_path` param.

## Step 2: Ask Scope
Ask: "Which test scope? (all / smoke / regression)" Wait for answer. Default to "all" if not specified.

## Step 3: Validate Credentials
Call `validate_credentials`.
**If status is "expired" or "not_configured"**, tell the user to provide credentials. For bearer token auth: log into the app in browser, press F12 → Network, copy Authorization and Cookie headers.
**STOP here. Do NOT proceed until valid.**

## Step 4: Configure Credentials (if needed)
If the user provides new credentials, call `configure_credentials` then `validate_credentials` again to confirm.

## Step 5: Run Tests
Call `data_driven_tool` with the correct action and project/excel_path.
**If error:** credentials_expired → go back to Step 3. Any other error → show and STOP.

## Step 6: Show Summary
Display a table with: Scope, Total APIs, Passed, Failed, Error count, Duration.

## Step 7–10: Post-Execution
Ask each in order, wait for response: "Generate dashboard?" → "Push to ADO?" → "Send email?"