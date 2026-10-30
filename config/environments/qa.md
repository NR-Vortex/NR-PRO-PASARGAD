# API Test Configuration
# ============================================================================
# Environment: QA
# ============================================================================
#
# INSTRUCTIONS:
#   1. Copy this file to qa.md and fill in the values for your app.
#   2. For multiple apps, create separate files: myapp.md, another_app.md, etc.
#   3. Switch apps at runtime: configure_credentials(app_name="myapp")
#
# AUTH OPTIONS (pick one):
#   A. OAuth2 Client Credentials — fill Client ID, Client Secret, etc.
#   B. Pre-acquired Token — paste a Bearer Token (skip OAuth2 entirely)
#   C. Token + Cookie — for EasyAuth/WAF apps, paste both
#   D. API Key — set Auth Type = "api_key" and provide API Key
#   E. No Auth — set Auth Type = "none"

## API Endpoint
API URL = ""

## Default Headers (optional — sent with every request)
Practice = ""
SubPractice = ""
Version = ""

---

## Authentication

### Option A: OAuth2 Client Credentials (app-to-app)
Token Endpoint URL = ""
Client ID = ""
Client Secret = ""
Grant Type = "client_credentials"
Scope = ""

### Option B: Pre-acquired Bearer Token (skip OAuth2)
# Paste a token from browser DevTools / Postman. Overrides OAuth2.
Bearer Token = ""

### Option C: Auth Cookie (for EasyAuth / WAF-protected apps)
# Paste the AppServiceAuthSession cookie value.
Auth Cookie = ""

---

## Execution Settings
Run Mode = "hybrid"
Parallel = "true"
Max Workers = "10"

---

## Azure DevOps (ADO) Integration
ADO Organization = ""
ADO Project = ""
ADO PAT = ""
ADO Plan ID = ""
ADO Suite ID = ""
Project Name = "APITest"

---

## Azure SQL Database
MSSQL Server = ""
MSSQL Port = "1433"
MSSQL Database = ""
MSSQL Auth Mode = "sql_auth"
MSSQL Username = ""
MSSQL Password = ""

---

## REST API Settings (for REST-mode Excel workbooks)
REST Base URL = ""
REST Auth Type = ""
# Options: "token" | "client_credentials" | "device_code" | "token_prompt" | ""
REST SetVersionId = ""
REST Bearer Token = ""
REST Auth Cookie = ""

---

## App-Specific Authentication (optional)
# For apps that need separate auth settings (e.g., different tenant, client):
# App Tenant ID = ""
# App Client ID = ""
# App Scope = ""

---

## Email Notification Settings
Email Enabled = "False"
SMTP Host = "smtp.office365.com"
SMTP Port = "587"
Sender Email = ""
Sender Password = ""
Recipients = ""
