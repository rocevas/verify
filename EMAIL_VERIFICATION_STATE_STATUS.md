# Email Verification State and Status Documentation

## Overview

Email verification system now uses a two-level status system following the [Emailable API format](https://help.emailable.com/en-us/article/verification-results-all-possible-states-and-reasons-fjsjn2/):

- **`state`**: High-level state (deliverable, undeliverable, risky, unknown, error)
- **`status_detail`**: Specific status reason within that state

## Database Schema

### New Columns

The `email_verifications` table now includes:

1. **`state`** (string): High-level verification state
   - Values: `deliverable`, `undeliverable`, `risky`, `unknown`, `error`

2. **`status_detail`** (string, nullable): Specific status reason
   - See mapping below for possible values

3. **Boolean check columns** (moved from JSON to dedicated columns):
   - `check_syntax` - Syntax validation passed
   - `check_mx` - MX records found
   - `check_smtp` - SMTP verification passed
   - `check_disposable` - Disposable email detected
   - `check_role` - Role-based email detected
   - `check_no_reply` - No-reply keywords detected
   - `check_typo_domain` - Typo domain detected
   - `check_mailbox_full` - Mailbox is full
   - `check_free` - Free email provider

4. **`status`** (string): Legacy status field (kept for backward compatibility)
   - Changed from enum to string
   - Old values still supported: `valid`, `invalid`, `catch_all`, `unknown`, `spamtrap`, `abuse`, `do_not_mail`, `risky`

5. **`checks`** (JSON): Now only contains additional metadata:
   - `blacklist` - Blacklist status
   - `domain_validity` - Domain validity check
   - `isp_esp` - ISP/ESP domain check
   - `government_tld` - Government TLD check
   - `ai_analysis` - AI analysis performed
   - `ai_insights` - AI insights text
   - `ai_confidence` - AI confidence score
   - `ai_risk_factors` - AI risk factors array
   - `did_you_mean` - Typo correction suggestion

## State and Status Mapping

### Deliverable State

**`state: "deliverable"`** - Email address is valid and deliverable with high confidence.

| status_detail | Description | Conditions |
|--------------|------------|------------|
| `valid` | The email address exists and is deliverable | SMTP check passed OR (MX records exist + domain valid + status=valid for public providers) |

**Example:**
```json
{
  "state": "deliverable",
  "status_detail": "valid",
  "check_smtp": true,
  "check_mx": true,
  "score": 100
}
```

### Undeliverable State

**`state: "undeliverable"`** - Email address is not valid or should not be mailed to.

| status_detail | Description | Conditions |
|--------------|------------|------------|
| `syntax_error` | Email format is invalid | Syntax check failed |
| `typo` | Email address has a typo | Typo domain detected |
| `mailbox_not_found` | Recipient's inbox does not exist | No MX records OR invalid domain |
| `disposable` | Temporary/disposable email | Disposable email service detected |
| `blocked` | Mailbox blocked by provider | Blacklisted, spamtrap, abuse, or do_not_mail status |

**Examples:**
```json
{
  "state": "undeliverable",
  "status_detail": "syntax_error",
  "check_syntax": false
}

{
  "state": "undeliverable",
  "status_detail": "typo",
  "check_typo_domain": true,
  "did_you_mean": "user@gmail.com"
}

{
  "state": "undeliverable",
  "status_detail": "disposable",
  "check_disposable": true
}
```

### Risky State

**`state: "risky"`** - Email seems deliverable but should be used with caution.

| status_detail | Description | Conditions |
|--------------|------------|------------|
| `catch_all` | Server accepts all emails (catch-all) | Catch-all server detected |
| `mailbox_full` | Recipient's inbox is full | SMTP returned mailbox full error |
| `role` | Role-based email address | Role-based email detected (info@, support@, etc.) |

**Examples:**
```json
{
  "state": "risky",
  "status_detail": "catch_all",
  "check_mx": true,
  "score": 50
}

{
  "state": "risky",
  "status_detail": "mailbox_full",
  "check_mailbox_full": true,
  "check_mx": true
}

{
  "state": "risky",
  "status_detail": "role",
  "check_role": true,
  "check_mx": true
}
```

### Unknown State

**`state: "unknown"`** - Cannot determine validity due to connection/timeout issues.

| status_detail | Description | Conditions |
|--------------|------------|------------|
| `null` | Connection/timeout error | Timeout, connection failed, or server unavailable |

**Example:**
```json
{
  "state": "unknown",
  "status_detail": null,
  "error": "Connection timeout",
  "check_mx": false
}
```

### Error State

**`state: "error"`** - Unexpected error occurred during verification.

| status_detail | Description | Conditions |
|--------------|------------|------------|
| `error` | Unexpected error | Exception occurred during verification |

**Example:**
```json
{
  "state": "error",
  "status_detail": "error",
  "error": "Unexpected error occurred"
}
```

## Decision Logic Flow

The system determines `state` and `status_detail` in the following priority order:

1. **Syntax Error** (highest priority)
   - If `check_syntax = false` → `undeliverable` / `syntax_error`

2. **Typo Domain**
   - If `check_typo_domain = true` → `undeliverable` / `typo`

3. **Disposable Email**
   - If `check_disposable = true` → `undeliverable` / `disposable`

4. **Blocked/Blacklisted**
   - If `check_blacklist = true` OR status is `spamtrap`/`abuse`/`do_not_mail` → `undeliverable` / `blocked`

5. **Mailbox Full**
   - If `mailbox_full = true` → `risky` / `mailbox_full`

6. **Role-based Email**
   - If `check_role = true` → `risky` / `role`

7. **Catch-all**
   - If status = `catch_all` → `risky` / `catch_all`

8. **Valid Email**
   - If `check_smtp = true` → `deliverable` / `valid`
   - OR if `check_mx = true` + `check_domain_validity = true` + status = `valid` (public providers) → `deliverable` / `valid`

9. **Invalid Domain**
   - If `check_mx = false` OR `check_domain_validity = false` → `undeliverable` / `mailbox_not_found`

10. **Connection Errors**
    - If error contains "timeout", "connection", "unavailable" → `unknown` / `null`

11. **Unexpected Errors**
    - If error exists and status = `unknown` → `error` / `error`

12. **Default**
    - If status = `unknown` → `unknown` / `null`

## API Response Format

The API now returns both `state` and `status_detail`:

```json
{
  "email": "user@example.com",
  "state": "deliverable",
  "status_detail": "valid",
  "status": "valid",  // Legacy field (backward compatibility)
  "check_syntax": true,
  "check_mx": true,
  "check_smtp": true,
  "check_disposable": false,
  "check_role": false,
  "check_no_reply": false,
  "check_typo_domain": false,
  "check_mailbox_full": false,
  "check_free": false,
  "score": 100,
  "duration": 245.67,
  "free": false,
  "mailbox_full": false,
  "did_you_mean": null,
  "checks": {
    "blacklist": false,
    "domain_validity": true,
    "ai_analysis": true,
    "ai_insights": "This email address is valid...",
    "ai_confidence": 95
  }
}
```

## Migration Notes

- Existing data is automatically migrated:
  - Old `status` enum values are mapped to new `state` and `status_detail`
  - Boolean checks are extracted from JSON `checks` column to dedicated columns
  - Legacy `status` field is kept as string for backward compatibility

## Comparison with Emailable API

Our implementation follows the Emailable API format but includes additional checks:

| Emailable | Our Implementation | Notes |
|-----------|-------------------|-------|
| Deliverable | ✅ `deliverable` | Same |
| Undeliverable | ✅ `undeliverable` | Same |
| Risky | ✅ `risky` | Same |
| Unknown | ✅ `unknown` | Same |
| Duplicate | ❌ Not implemented | Can be handled at application level |
| Error | ✅ `error` | Added for unexpected errors |

Our system provides more granular status details and additional metadata (AI analysis, typo correction, etc.).

