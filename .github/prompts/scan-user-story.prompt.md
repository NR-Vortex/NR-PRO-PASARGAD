---
description: "Scan User Story from ADO and create test cases"
mode: agent
agent: Testcase Creation
---

Follow these steps in exact order. Do NOT skip ahead.

## Step 1: Get Work Item ID
Ask: "What is the ADO Work Item ID to scan?"

## Step 2: Validate Credentials
Call `validate_credentials`. Check ADO PAT. If missing, tell user to provide via configure_credentials. STOP until valid.

## Step 3: Scan User Story
Call `scan_user_story`. If error (404, credentials), show and STOP.

## Step 4: Show Proposals
Display test case proposals in a table.

## Step 5: Ask for Approval
Ask: "Approve these test cases? (yes/no/edit)" Do NOT auto-push.

## Step 6: Generate Excel (if approved)
Call `generate_test_steps_excel`.

## Step 7: Ask About ADO Push
Ask: "Push to ADO?" Only push after explicit "yes".