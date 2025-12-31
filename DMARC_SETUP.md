# DMARC Monitoring Setup

## Overview

The DMARC monitoring system allows you to monitor DMARC records for your domains and receive DMARC aggregate reports.

## Features

- Automatic DMARC record generation with report email
- DMARC record validation and monitoring
- Receiving and processing DMARC aggregate reports
- Real-time notifications when issues are detected

## Setup

### 1. Create a DMARC Monitor

1. Go to the DMARC Monitors section in the admin panel
2. Enter your domain name
3. The system will automatically generate a report email (e.g., `dmarc-abc12345@yourdomain.com`)
4. A DMARC record will be generated automatically

### 2. Configure DNS

Add the generated DMARC record to your DNS:

- **Type:** TXT
- **Name:** `_dmarc.yourdomain.com`
- **Value:** The generated DMARC record (e.g., `v=DMARC1; p=none; pct=100; rua=mailto:dmarc-abc12345@yourdomain.com`)

### 3. Configure Email Receiving

DMARC reports are sent as XML attachments via email. To receive them, you have several options:

#### Option A: Webhook Endpoint (Recommended for Production)

The system provides a webhook endpoint at:
```
POST /webhooks/dmarc-report
```

You can configure your mail server or email processing service to:
1. Receive emails sent to the report email addresses
2. Extract the XML attachment from DMARC report emails
3. POST the XML content to the webhook endpoint

**Example webhook payload:**
```json
{
  "xml": "<feedback>...</feedback>",
  "to": "dmarc-abc12345@yourdomain.com"
}
```

#### Option B: Mail Server Configuration

For production, you can set up a mail server (Postfix, etc.) to:
1. Receive emails at the report email addresses
2. Extract XML attachments
3. Forward to the webhook endpoint

#### Option C: Email Processing Service

Services like Mailgun, SendGrid, or AWS SES can be configured to:
1. Receive emails
2. Extract attachments
3. Forward to webhooks

### 4. Testing

You can test the webhook endpoint by sending a POST request with DMARC report XML:

```bash
curl -X POST http://your-domain.com/webhooks/dmarc-report \
  -H "Content-Type: application/json" \
  -d '{
    "xml": "<feedback>...</feedback>",
    "to": "dmarc-abc12345@yourdomain.com"
  }'
```

## How It Works

1. **Monitoring**: The system periodically checks your DMARC DNS records to ensure they're properly configured
2. **Report Receiving**: When ISPs send DMARC aggregate reports, they're received via the webhook endpoint
3. **Processing**: Reports are parsed and analyzed to detect issues
4. **Notifications**: You'll be notified if issues are detected (quarantined/rejected emails, etc.)

## DMARC Record Format

The generated DMARC record follows this format:
```
v=DMARC1; p=none; pct=100; rua=mailto:dmarc-{hash}@yourdomain.com
```

- `v=DMARC1`: DMARC version
- `p=none`: Policy (none/quarantine/reject)
- `pct=100`: Percentage of emails to apply policy to
- `rua=mailto:...`: Email address to receive aggregate reports

## Notes

- Reports are processed asynchronously via queue jobs
- The system automatically matches reports to monitors by email address or domain
- Check results are stored and can be viewed in the monitor details

