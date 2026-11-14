# Authenticating requests

To authenticate requests, include an **`Authorization`** header with the value **`"Bearer {ACCESS_TOKEN}"`**.

All authenticated endpoints are marked with a `requires authentication` badge in the documentation below.

POST /api/auth_login with a customer account or POST /api/admin/auth_login with an administrator account. Copy data.access_token from the successful response and send it as Authorization: Bearer {ACCESS_TOKEN} on protected endpoints. Registration does not return a token; log in after registering.
