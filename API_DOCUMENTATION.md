# Email Verification API Documentation

## Base URL

```
https://your-domain.com/api
```

## Authentication

All endpoints require authentication using Laravel Sanctum. Include your API token in the request headers:

```
Authorization: Bearer {your-api-token}
```

## Endpoints

### 1. Verify Single Email

Verify a single email address.

**Endpoint:** `POST /api/verify`

**Request:**
```json
{
  "email": "user@example.com",
  "async": false
}
```

**Parameters:**
- `email` (required, string): Email address to verify
- `async` (optional, boolean): If true, verification is queued and processed asynchronously

**Response:**
```json
{
  "email": "user@example.com",
  "state": "deliverable",
  "result": "valid",
  "account": "user",
  "domain": "example.com",
  "score": 100,
  "email_score": 100,
  "duration": 0.45,
  "syntax": true,
  "domain_validity": true,
  "mx_record": true,
  "smtp": true,
  "disposable": false,
  "role": false,
  "no_reply": false,
  "typo_domain": false,
  "alias": null,
  "did_you_mean": null,
  "free": false,
  "mailbox_full": false
}
```

**Response with Error:**
```json
{
  "email": "invalid-email",
  "state": "undeliverable",
  "result": "syntax_error",
  "error": "Invalid email syntax",
  "score": 0,
  "syntax": false,
  ...
}
```

**State Values:**
- `deliverable` - Email can be delivered
- `undeliverable` - Email cannot be delivered
- `risky` - Email is risky but might be deliverable
- `unknown` - Status is unknown
- `error` - Error occurred

**Result Values:**
- `valid` - Valid email
- `syntax_error` - Invalid email syntax
- `typo` - Typo detected in domain
- `mailbox_not_found` - Mailbox not found
- `disposable` - Disposable email address
- `blocked` - Email is blacklisted/blocked
- `catch_all` - Catch-all server detected
- `mailbox_full` - Mailbox is full
- `role` - Role-based email address
- `error` - Error occurred

**Async Response (202):**
```json
{
  "message": "Verification queued",
  "email": "user@example.com"
}
```

---

### 2. Verify Batch Emails

Verify multiple email addresses in a single request (up to 100 emails).

**Endpoint:** `POST /api/verify/batch`

**Request:**
```json
{
  "emails": [
    "user1@example.com",
    "user2@example.com",
    "user3@example.com"
  ],
  "async": false
}
```

**Parameters:**
- `emails` (required, array): Array of email addresses (max 100)
- `async` (optional, boolean): If true, verifications are queued and processed asynchronously

**Response:**
```json
{
  "results": [
    {
      "email": "user1@example.com",
      "state": "deliverable",
      "result": "valid",
      "score": 100,
      "syntax": true,
      "mx_record": true,
      "smtp": true,
      "alias": null,
      "did_you_mean": null
    },
    {
      "email": "user2@example.com",
      "state": "undeliverable",
      "result": "mailbox_not_found",
      "score": 40,
      "syntax": true,
      "mx_record": false,
      "smtp": false,
      "alias": null,
      "did_you_mean": null
    }
  ],
  "count": 2,
  "bulk_job_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

**Async Response (202):**
```json
{
  "message": "Verifications queued",
  "count": 3,
  "bulk_job_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

---

### 3. Get Typo Suggestions

Get typo correction suggestions for an email address.

**Endpoint:** `POST /api/verify/typo-suggestions`

**Request:**
```json
{
  "email": "user@gmial.com"
}
```

**Parameters:**
- `email` (required, string): Email address to check for typos

**Response:**
```json
{
  "email": "user@gmial.com",
  "did_you_mean": "user@gmail.com",
  "hasSuggestion": true
}
```

**Response (no typo detected):**
```json
{
  "email": "user@gmail.com",
  "did_you_mean": null,
  "hasSuggestion": false
}
```

---

### 4. Get API Status

Get API health status and system information.

**Endpoint:** `GET /api/verify/status`

**Query Parameters:**
- `include_metrics` (optional, boolean): Include metrics summary in response

**Response:**
```json
{
  "status": "healthy",
  "uptime_seconds": 3600.5,
  "uptime_human": "1h 0m",
  "memory_usage_mb": 128.5,
  "memory_peak_mb": 256.0,
  "recent_verifications_1h": 150,
  "queue": {
    "pending": 5
  },
  "timestamp": "2026-01-03T20:00:00Z"
}
```

**Response with Metrics:**
```json
{
  "status": "healthy",
  "uptime_seconds": 3600.5,
  "uptime_human": "1h 0m",
  "memory_usage_mb": 128.5,
  "memory_peak_mb": 256.0,
  "recent_verifications_1h": 150,
  "queue": {
    "pending": 5
  },
  "timestamp": "2026-01-03T20:00:00Z",
  "metrics": {
    "verifications": {
      "total": 1000,
      "by_status": {
        "valid": 800,
        "invalid": 150,
        "risky": 50
      },
      "duration": {
        "count": 1000,
        "sum": 500.0,
        "avg": 0.5,
        "min": 0.1,
        "max": 2.0
      }
    },
    "smtp_checks": {
      "total": 800,
      "success": 750,
      "failed": 50,
      "duration": {...}
    },
    "dns_lookups": {
      "total": 2000,
      "duration": {...}
    },
    "cache": {
      "operations": {
        "total": 5000,
        "hits": 4500,
        "misses": 500
      }
    },
    "batches": {
      "total": 50,
      "size": {...},
      "duration": {...}
    }
  }
}
```

---

### 5. Get Metrics

Get detailed metrics summary.

**Endpoint:** `GET /api/verify/metrics`

**Response:**
```json
{
  "verifications": {
    "total": 1000,
    "by_status": {
      "valid": 800,
      "invalid": 150,
      "risky": 50
    },
    "duration": {
      "count": 1000,
      "sum": 500.0,
      "avg": 0.5,
      "min": 0.1,
      "max": 2.0
    }
  },
  "smtp_checks": {
    "total": 800,
    "success": 750,
    "failed": 50,
    "duration": {
      "count": 800,
      "avg": 1.2,
      "min": 0.3,
      "max": 5.0
    }
  },
  "dns_lookups": {
    "total": 2000,
    "duration": {
      "count": 2000,
      "avg": 0.1,
      "min": 0.05,
      "max": 0.5
    }
  },
  "cache": {
    "operations": {
      "total": 5000,
      "hits": 4500,
      "misses": 500
    }
  },
  "batches": {
    "total": 50,
    "size": {
      "count": 50,
      "avg": 25,
      "min": 1,
      "max": 100
    },
    "duration": {
      "count": 50,
      "avg": 5.0,
      "min": 1.0,
      "max": 30.0
    }
  }
}
```

---

## Response Fields

### Email Verification Response

| Field | Type | Description |
|-------|------|-------------|
| Field | Type | Description |
|-------|------|-------------|
| `email` | string | Email address that was verified |
| `state` | string | Delivery state (deliverable, undeliverable, risky, unknown, error) |
| `result` | string\|null | Detailed result code (valid, syntax_error, typo, etc.) |
| `account` | string\|null | Local part of email address |
| `domain` | string\|null | Domain part of email address |
| `score` | integer | Verification score (0-100) |
| `email_score` | integer | Email verification score (same as score) |
| `duration` | float\|null | Verification duration in seconds |
| `error` | string\|null | Error message (only present if error occurred) |
| `syntax` | boolean | Email syntax is valid |
| `domain_validity` | boolean | Domain exists and is valid |
| `mx_record` | boolean | Domain has MX records |
| `smtp` | boolean | SMTP check passed |
| `disposable` | boolean | Email is from disposable provider |
| `role` | boolean | Email is role-based |
| `no_reply` | boolean | Email contains no-reply keywords |
| `typo_domain` | boolean | Domain is a typo/spam trap |

**Note:** Some checks (like `blacklist`, `isp_esp`, `government_tld`) are performed internally and affect the verification result/score, but are not returned in the response as they are considered internal validation details.
| `alias` | string\|null | Canonical email address if this is an alias |
| `did_you_mean` | string\|null | Suggested correction if typo detected |
| `free` | boolean | Whether email is from a free provider |
| `mailbox_full` | boolean | Whether mailbox is full |

---

## Email Alias Detection

The API automatically detects email aliases for major providers:

### Gmail/GoogleMail
- **Dots are ignored**: `user.name@gmail.com` → `username@gmail.com`
- **Plus addressing**: `username+test@gmail.com` → `username@gmail.com`

### Yahoo
- **Hyphen addressing**: `username-test@yahoo.com` → `username@yahoo.com`

### Outlook/Hotmail/Live
- **Plus addressing**: `username+test@outlook.com` → `username@outlook.com`

If an alias is detected, the `alias` field will contain the canonical email address.

---

## Typo Detection

The API can detect common domain typos and suggest corrections:

- `gmial.com` → `gmail.com`
- `gmal.com` → `gmail.com`
- `yaho.com` → `yahoo.com`
- `hotmai.com` → `hotmail.com`
- `outlok.com` → `outlook.com`

If a typo is detected, the `did_you_mean` field will contain the corrected email address.

---

## Error Responses

### 401 Unauthorized
```json
{
  "error": "Unauthenticated"
}
```

### 403 Forbidden
```json
{
  "error": "No team selected. Please select a team first."
}
```

### 422 Validation Error
```json
{
  "error": "Validation failed",
  "message": "The given data was invalid.",
  "errors": {
    "email": ["The email field is required."]
  }
}
```

### 500 Server Error
```json
{
  "error": "Internal server error"
}
```

---

## Rate Limiting

Rate limiting is applied per domain to prevent SMTP server bans:
- Global limit: Configurable (default: disabled for queue workers)
- Per-domain limit: 20 checks per minute per domain
- Delay between checks: 0.5 seconds

---

## Best Practices

1. **Use async mode for batch processing**: Set `async: true` for large batches to avoid timeouts
2. **Check status endpoint**: Monitor API health before processing large batches
3. **Handle errors gracefully**: Always check for error fields in responses
4. **Use batch endpoint**: For multiple emails, use batch endpoint instead of multiple single requests
5. **Cache results**: Verification results are cached, but you should also cache on your side for frequently checked emails

---

## Examples

### cURL Examples

**Verify Single Email:**
```bash
curl -X POST https://your-domain.com/api/verify \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"email": "user@example.com"}'
```

**Verify Batch:**
```bash
curl -X POST https://your-domain.com/api/verify/batch \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "emails": ["user1@example.com", "user2@example.com"],
    "async": false
  }'
```

**Get Typo Suggestions:**
```bash
curl -X POST https://your-domain.com/api/verify/typo-suggestions \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"email": "user@gmial.com"}'
```

**Get Status:**
```bash
curl -X GET https://your-domain.com/api/verify/status \
  -H "Authorization: Bearer YOUR_API_TOKEN"
```

**Get Metrics:**
```bash
curl -X GET https://your-domain.com/api/verify/metrics \
  -H "Authorization: Bearer YOUR_API_TOKEN"
```

---

## Changelog

### Version 1.1.0 (2026-01-03)
- Added email alias detection (Gmail, Yahoo, Outlook)
- Added typo suggestions endpoint
- Added status endpoint
- Added metrics endpoint
- Optimized batch processing with domain grouping
- Improved response format consistency

### Version 1.0.0
- Initial release
- Basic email verification
- SMTP checking
- Blacklist checking
- Disposable email detection

