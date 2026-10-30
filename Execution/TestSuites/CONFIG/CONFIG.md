# API Test Configuration
# ============================================================================
# Environment: QA
# API Type: GraphQL / REST
# ============================================================================

## GraphQL Endpoint
API URL = ""

## Default Headers (sent with every request)
Practice = ""
SubPractice = ""
Version = ""

## APP to APP Token Generation (OAuth2 Client Credentials)
Token Endpoint URL = ""
Client ID = ""
Client Secret = ""
Grant Type = "client_credentials"
Scope = ""

---

## Notes
* For OAuth2: Token is generated automatically before API calls.
* For GraphQL: All API requests are POST with a GraphQL body.
* All requests require these headers:
  - Authorization: Bearer <access_token>
  - Content-Type: application/json
  - Practice, SubPractice, Version (as needed)

---

## Email Notification Settings
Email Enabled = "False"
SMTP Host = "smtp.office365.com"
SMTP Port = "587"
Sender Email = ""
Sender Password = ""
Recipients = ""
