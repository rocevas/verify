# Score System Improvements

## Problemos su Senąja Sistema

### Senoji Score Sistema:
```php
'syntax' => 10,
'mx_record' => 30,
'smtp' => 50,  // Per daug priklauso nuo SMTP
'disposable' => 10,
'role_penalty' => 20
```

### Problemos:
1. **Per daug priklauso nuo SMTP (50 taškų)** - Public providers (Gmail, Yahoo, Outlook) dažnai blokuoja SMTP check, todėl jie gauna tik 40 taškų (syntax 10 + mx 30), nors jie yra 100% valid
2. **Domain validity nėra įtraukta** - Nors tikrinamas, bet neįtakoja score
3. **Nebalansuota** - Syntax tik 10 taškų, nors tai yra pagrindinis check

## Nauja Patobulinta Sistema

### Nauji Score Weights:
```php
'syntax' => 20,              // Padidinta (buvo 10)
'domain_validity' => 20,     // NAUJAS - Domain exists and is valid
'mx_record' => 25,           // Sumažinta (buvo 30)
'smtp' => 25,                // Sumažinta (buvo 50) - nebe per daug priklauso
'disposable' => 10,          // Nepakeista
'role_penalty' => 10,        // Sumažinta (buvo 20) - mažesnė bauda
```

### Score Calculation:

**Base Checks (Required):**
- Syntax: 20 taškų (jei false → score = 0)
- Domain Validity: 20 taškų (jei false → score = 0)

**Delivery Checks:**
- MX Records: 25 taškų
- SMTP: 25 taškų (jei pasiekiamas)

**Quality Checks:**
- Not Disposable: 10 taškų (jei disposable → score = 0)
- Role-based penalty: -10 taškų (jei role-based)

**Special Cases:**
- Government TLD: -10 taškų
- Public Provider Bonus: +15 taškų (jei known public provider ir score >= 70)

### Score Pavyzdžiai:

**Perfect Email (visi check'ai praėjo):**
- syntax: 20
- domain_validity: 20
- mx_record: 25
- smtp: 25
- disposable: 10
- **Total: 100**

**Public Provider (Gmail, Yahoo, Outlook - SMTP nepasiekiamas):**
- syntax: 20
- domain_validity: 20
- mx_record: 25
- smtp: 0 (nechecked)
- disposable: 10
- public_provider_bonus: +15
- **Total: 90**

**Email be SMTP check (bet visi kiti praėjo):**
- syntax: 20
- domain_validity: 20
- mx_record: 25
- smtp: 0
- disposable: 10
- **Total: 75**

**Role-based Email (visi check'ai praėjo):**
- syntax: 20
- domain_validity: 20
- mx_record: 25
- smtp: 25
- disposable: 10
- role_penalty: -10
- **Total: 90**

**Invalid Domain:**
- syntax: 20
- domain_validity: 0 (domain doesn't exist)
- **Total: 20** (arba 0, jei domain_validity yra required)

## Palyginimas su Go Versija

### Go Versija:
```go
weights := map[string]int{
    "syntax":         20,
    "domain_exists":  20,
    "mx_records":     20,
    "mailbox_exists": 20,  // Jie neturi SMTP, tai yra MX records
    "is_disposable":  10,
    "is_role_based":  10,
}
// Total: 100
```

### Mūsų Nauja Versija:
```php
'syntax' => 20,
'domain_validity' => 20,  // Atitinka domain_exists
'mx_record' => 25,
'smtp' => 25,             // Geresnė nei mailbox_exists (tikras SMTP check)
'disposable' => 10,
'role_penalty' => 10,     // Penalty, ne bonus
```

**Skirtumai:**
- ✅ Mūsų versija turi tikrą SMTP check (Go neturi)
- ✅ Mūsų versija balansuota - neper daug priklauso nuo SMTP
- ✅ Mūsų versija gerai veikia su public providers (bonus system)
- ✅ Mūsų versija turi domain_validity check

## Patobulinimai

1. **Balansuota sistema** - Neper daug priklauso nuo SMTP
2. **Domain validity įtraukta** - Dabar įtakoja score
3. **Public provider support** - Bonus system užtikrina gerą score net be SMTP
4. **Mažesnė role penalty** - Role-based emails gali gauti gerą score
5. **Geresnė sintaksė** - Syntax dabar suteikia 20 taškų (buvo 10)

## Score Thresholds

Pagal naują sistemą:
- **90-100**: Perfect email (valid, deliverable)
- **75-89**: Good email (valid, bet galbūt role-based arba be SMTP check)
- **50-74**: Risky email (galbūt catch-all arba kokių nors problemų)
- **1-49**: Low confidence (galbūt valid, bet daug problemų)
- **0**: Invalid email (syntax error, disposable, blacklist, typo domain, etc.)

