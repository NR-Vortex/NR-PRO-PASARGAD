---
description: "Register a new project in the knowledge library (guided setup)"
mode: agent
agent: Testcase Execution
---

Guide the user through registering a new project. Follow these steps in order.

## Step 1: Gather Project Info
Ask the user these questions one at a time:
1. "What is your project ID? (short name, no spaces, e.g. my_app)"
2. "What is the display name for this project?"
3. "What API type does it use? (rest / graphql / mixed)"
4. "What auth type? (bearer_token / oauth2_client_credentials / device_code / auth_code / token_prompt)"
5. "What is the name of your Excel test data file? (e.g. MyApp_TestData.xlsx)"

## Step 2: Create Project Folder
Create the folder `knowledge/<project_id>/` and a `project.yaml` file inside it with the values from Step 1.
Use the template from `knowledge/_example/project.yaml.template` as a base.

## Step 3: Register in registry.yaml
Open `knowledge/registry.yaml` and add the new project under the `projects:` section.

## Step 4: Verify
Call `list_projects` to confirm the new project appears.

## Step 5: Remind User
Tell the user: "Project registered. Make sure your Excel test data file is in data/testdata/ and your credentials are configured in config/environments/qa.md."