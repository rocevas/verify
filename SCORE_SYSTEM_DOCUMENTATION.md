# Email Verification Score System Documentation

## Overview
Šis dokumentas aprašo, kaip veikia email verification score sistema ir kokie check'ai yra įtraukti į score skaičiavimą.

## Score Calculation Logic

Score skaičiuojamas nuo 0 iki 100 balų, kur:
- **0-30**: Invalid/Risky (negalima naudoti)
- **31-60**: Risky (galima naudoti, bet rizikinga)
- **61-80**: Valid (galima naudoti)
- **81-100**: Excellent (labai patikimas)

## Check'ai įtraukti į Score Sistemą

### 1. High-Risk Check'ai (Score = 0)
Šie check'ai automatiškai nustato score į 0, jei jie failina:

| Check | Aprašymas | Kada failina |
|-------|-----------|--------------|
| `syntax` | Email sintaksės validacija | Jei email neteisingo formato |
| `no_reply` | No-reply keyword patikra | Jei email turi no-reply keywords (pvz., noreply@, donotreply@) |
| `typo_domain` | Typo domain patikra | Jei domain yra typo (pvz., gmail.co vietoj gmail.com) |
| `isp_esp` | ISP/ESP domain patikra | Jei domain yra ISP/ESP (pvz., comcast.net, verizon.net) |
| `blacklist` | Blacklist patikra | Jei email yra blacklist'e |
| `disposable` | Disposable email patikra | Jei email yra disposable (pvz., 10minutemail.com) |

### 2. Base Check'ai (Prideda taškų)
Šie check'ai prideda taškų į score:

| Check | Weight | Aprašymas | Kada prideda taškų |
|-------|--------|-----------|-------------------|
| `syntax` | 20 | Email sintaksės validacija | Jei email teisingo formato |
| `domain_validity` | 20 | Domain DNS resolution | Jei domain egzistuoja ir yra validus |
| `mx_record` | 25 | MX record patikra | Jei domain turi MX records |
| `smtp` | 25 | SMTP patikra | Jei SMTP check'as sėkmingas (dažnai unavailable public providers) |
| `disposable` | 10 | Disposable email patikra | Jei email NĖRA disposable |

**Maksimalus score be SMTP**: 65 taškai (syntax + domain_validity + mx_record + disposable)

**Maksimalus score su SMTP**: 100 taškai (visi check'ai)

### 3. Penalty Check'ai (Sumažina score)
Šie check'ai sumažina score, bet neį nulinio:

| Check | Penalty | Aprašymas | Kada taikomas |
|-------|---------|-----------|---------------|
| `role` | -10 | Role-based email penalty | Jei email yra role-based (pvz., info@, support@) |
| `mailbox_full` | -30 | Mailbox full penalty | Jei mailbox pilnas (email negali gauti laiškų) |
| `free` / `is_free` | 0 | Free email provider penalty | Disabled - free emails don't get penalty |
| `government_tld` | -10 | Government TLD penalty | Jei domain turi government TLD (pvz., .gov, .gov.uk) |

**Pastaba**: `free_email_penalty` yra nustatytas į 0 (disabled) - free emails negauna penalty.

## Score Calculation Flow

```
1. High-Risk Check'ai
   ├─ Jei syntax = false → return 0
   ├─ Jei no_reply = true → return 0
   ├─ Jei typo_domain = true → return 0
   ├─ Jei isp_esp = true → return 0
   ├─ Jei blacklist = true → return 0
   └─ Jei disposable = true → return 0

2. Base Score Calculation
   ├─ syntax = true → +20
   ├─ domain_validity = true → +20
   ├─ mx_record = true → +25
   ├─ smtp = true → +25 (optional)
   └─ disposable = false → +10

3. Penalty Application
   ├─ role = true → -10
   ├─ mailbox_full = true → -30
   ├─ free = true → 0 (disabled)
   └─ government_tld = true → -10

4. Final Score
   └─ Clamp between 0 and 100 (score negali būti virš 100)
```

## Configuration

Score weights gali būti konfigūruojami `config/email-verification.php`:

```php
'score_weights' => [
    'syntax' => 20,
    'domain_validity' => 20,
    'mx_record' => 25,
    'smtp' => 25,
    'disposable' => 10,
    'role_penalty' => 10,
    'mailbox_full_penalty' => 30,
    'free_email_penalty' => 5, // Set to 0 to disable
],
```

## Check'ai, kurie NĖRA įtraukti į Score

Šie check'ai yra atliekami, bet neįtakoja score:

| Check | Aprašymas | Kodėl neįtrauktas |
|-------|-----------|-------------------|
| `alias` / `alias_of` | Email alias detection | Informacinis tikslas (pvz., user+tag@gmail.com → user@gmail.com) |
| `did_you_mean` / `typo_suggestion` | Typo suggestion | Informacinis tikslas (sugeruoja teisingą domain) |

## Pavyzdžiai

### Pavyzdys 1: Perfect Email
- syntax: ✅ (+20)
- domain_validity: ✅ (+20)
- mx_record: ✅ (+25)
- smtp: ✅ (+25)
- disposable: ✅ (not disposable) (+10)
- role: ❌ (not role-based)
- mailbox_full: ❌ (not full)
- free: ❌ (not free)
- government_tld: ❌ (not gov)

**Score**: 100

### Pavyzdys 2: Free Email (Gmail)
- syntax: ✅ (+20)
- domain_validity: ✅ (+20)
- mx_record: ✅ (+25)
- smtp: ❌ (public provider, SMTP unavailable)
- disposable: ✅ (not disposable) (+10)
- role: ❌
- mailbox_full: ❌
- free: ✅ (0 penalty - disabled)
- government_tld: ❌

**Score**: 75 (20 + 20 + 25 + 10) + public provider bonus (if >= 70, then min(95, score + 15))

### Pavyzdys 3: Role-based Email
- syntax: ✅ (+20)
- domain_validity: ✅ (+20)
- mx_record: ✅ (+25)
- smtp: ✅ (+25)
- disposable: ✅ (+10)
- role: ✅ (-10)
- mailbox_full: ❌
- free: ❌
- government_tld: ❌

**Score**: 90 (100 - 10)

### Pavyzdys 4: Mailbox Full
- syntax: ✅ (+20)
- domain_validity: ✅ (+20)
- mx_record: ✅ (+25)
- smtp: ✅ (+25)
- disposable: ✅ (+10)
- role: ❌
- mailbox_full: ✅ (-30)
- free: ❌
- government_tld: ❌

**Score**: 70 (100 - 30)

### Pavyzdys 5: Disposable Email
- syntax: ✅
- disposable: ✅ (is disposable)

**Score**: 0 (automatically set to 0)

## Pastabos

1. **SMTP Check**: Dažnai unavailable public providers (Gmail, Yahoo, Outlook), todėl score gali būti aukštas net ir be SMTP check'o.

2. **Free Email Penalty**: Yra disabled (nustatytas į 0) - free emails negauna penalty.

3. **Mailbox Full**: Reikia SMTP check'o, kad būtų nustatytas. Jei SMTP nechecked, `mailbox_full` bus `false`.

4. **Score Clamping**: Final score visada yra tarp 0 ir 100, net jei penalties sumažina score žemiau 0. Score negali būti virš 100, nes `calculateScore()` metodas visada grąžina `max(0, min(100, $score))`.

