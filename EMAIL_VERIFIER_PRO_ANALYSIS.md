# Email Verifier Pro - Analizė ir Pasiūlymai

## Apžvalga

`email-verifier-pro` yra PHP pagrįstas email tikrinimo skriptas, kuris naudoja SMTP tikrinimą, disposable email sąrašus, blacklist tikrinimą ir specialius provider'ius (Gmail, Yahoo, Outlook, Mail.ru, Yandex, AOL).

## Pagrindinės Funkcijos

### 1. Email Tikrinimo Procesas

**Eilės tvarka:**
1. **Syntax Check** - `filter_var()` ir regex validacija
2. **Disposable Email Check** - `evp_domain_filter_list` lentelėje
3. **Unsupported Domain Check** - `evp_domain_filter_list` lentelėje
4. **DNS A Record Check** - `checkdnsrr($domain, "A")`
5. **MX Records Check** - `getmxrr()` su prioritetų rūšiavimu
6. **MX Skip List Check** - `evp_mx_skiplist` lentelėje (jei SMTP connection failed)
7. **Public Provider Check** - specialūs provider'iai (Gmail, Yahoo, Outlook, Mail.ru, Yandex, AOL)
8. **SMTP Check** - tiesioginis SMTP ryšys per port 25
9. **Catch-All Check** - tikrina ar domenas priima bet kokius email adresus

### 2. Naudojami Sąrašai ir Filtrai

#### A. Disposable Email Sąrašas
- **Lentelė:** `evp_domain_filter_list`
- **Tipas:** `e_type = 'Disposable Account'`
- **Tikrina:** ir email account, ir domain
- **Pavyzdys:** `SELECT * FROM evp_domain_filter_list WHERE e_type = 'Disposable Account' AND (name = '$email_acc' OR name = '$domain')`

#### B. Unsupported Domain Sąrašas
- **Lentelė:** `evp_domain_filter_list`
- **Tipas:** `e_type = 'Unsupported Domain'`
- **Pavyzdžiai:** `mimecast`, `yahoo.co`
- **Rezultatas:** Status = "Skipped", Safe to send = "Unknown"

#### C. MX Skip List
- **Lentelė:** `evp_mx_skiplist`
- **Paskirtis:** Praleisti MX serverius, kurie neleidžia SMTP connection
- **Automatinis pridėjimas:** Jei SMTP connection failed arba gavo 550 su "blocked by prs", "unsolicited mail"
- **Pavyzdys:** `INSERT INTO evp_mx_skiplist (mx_server, reason) VALUES ('$maxr', 'Smtp connection Failed')`

#### D. Public Provider Sąrašai

**Microsoft (Outlook/Hotmail):**
- MX: `outlook.com`, `hotmail.com`, `live.com`, `msn.com`
- Specialus provider: `pub_outlook.php` arba `outlook365.php` (jei private domain)

**Yahoo:**
- MX: `yahoodns.net`, `yahoo.co`, `yahoo.net`
- Specialus provider: `yahoo.php`

**Mail.ru:**
- MX: `mail.ru`
- Specialus provider: `mail.ru.php`

**Yandex:**
- MX: `yandex.com`, `yandex.ru`
- Specialus provider: `yandex.php`

**AOL:**
- MX: `mx-aol.mail`, `yahoodns.net` (kombinacija)
- Specialus provider: `aol.php`

#### E. Catch-All Skip Sąrašas
- **Hardcoded:** `.rediffmail.com`, `.internet.ru`, `.bk.ru`, `.inbox.ru`, `.list.ru`, `.mail.ru`, `.my.com`, `.sfr.fr`, `.free.fr`, `.neuf.fr`, `.mail.ua`, `.outlook.com`, `.ymail.com`, `.yahoo.com`, `.msn.com`, `.aol.com`, `.live.com`, `.hotmail.com`, `.google.com`, `.gmail.com`, `.gmail.com.br`, `.comcast.net`, `.icloud.com`, `.me.com`, `.mac.com`, `.yandex.com`, `.yandex.ru`
- **Paskirtis:** Praleisti catch-all tikrinimą šiems domenams

#### F. Blacklist (DNSBL) Sąrašai
- **Failas:** `lib/bl_monitor/dnsbl.json`
- **Kiekis:** 100+ DNSBL sąrašų
- **Tipai:** IP ir Domain blacklist'ai
- **Pavyzdžiai:**
  - Spamhaus (zen.spamhaus.org, bl.spamhaus.org, pbl.spamhaus.org, sbl.spamhaus.org, xbl.spamhaus.org, dbl.spamhaus.org)
  - SpamCop (bl.spamcop.net)
  - SORBS (dnsbl.sorbs.net, spam.dnsbl.sorbs.net, zombie.dnsbl.sorbs.net)
  - Barracuda (b.barracudacentral.org)
  - SURBL (multi.surbl.org)
  - Mailspike (bl.mailspike.net)
  - SpamRATS (dnsbl.spamrats.com, noptr.spamrats.com)
  - UCEPROTECT (dnsbl-1.uceprotect.net, dnsbl-2.uceprotect.net, dnsbl-3.uceprotect.net)
  - Backscatterer (ips.backscatterer.org)
  - DroneBL (dnsbl.dronebl.org)
  - Abuseat (cbl.abuseat.org)
  - ir daug daugiau...

### 3. SMTP Tikrinimo Detalės

**Procesas:**
1. Prisijungia prie MX serverio per port 25
2. Gauna 220 greeting
3. Siunčia EHLO (su konfigūruojamu hostname)
4. Siunčia MAIL FROM: <noreply@hostname>
5. Siunčia RCPT TO: <email>
6. Tikrina atsakymą:
   - **250** = Valid
   - **251/252** = Catch-all (priima)
   - **451/452/450** = Valid (temporary error)
   - **550** = Invalid (user not found)
   - **554** = Connection rejected

**Timeout:** Konfigūruojamas per `scan_time_out` opciją

**Rate Limiting:** Nėra automatinio rate limiting, bet yra MX skip list mechanizmas

### 4. Catch-All Tikrinimas

**Procesas:**
1. Sugeneruoja random email adresą (10 simbolių)
2. Tikrina ar domenas priima šį random email
3. Jei priima = Catch-All serveris
4. Jei nepriima = Normalus serveris

**Skip sąlygos:**
- Jei domenas yra `evp_domain_filter_list` su `catch_all_check = 0`
- Jei MX serveris yra public provider (Gmail, Yahoo, Outlook, etc.)

### 5. Inbox Checker (Mailbox Verification)

**Funkcija:** Patikrina ar email tikrai egzistuoja per IMAP

**Procesas:**
1. Siunčia tikrą email per SMTP
2. Laukia atsakymo IMAP inbox'e
3. Tikrina ar gavo bounce message
4. Jei gavo bounce = Invalid
5. Jei negavo bounce = Valid

**Naudojami SMTP error patterns:**
- Konfigūruojami per `smtp_errors` opciją
- Default: `Delivery Status Notification (Failure)|Delivery incomplete|Delay|BOUNCE-DELIVERY-FAILURE|Undeliverable|Email Delivery Failure|not found|delivery failure|No such user|Recipient address rejected|RecipientNotFound|550 Administrative prohibition|550 5.1.10|550 5.4.1|550 5.2.1|550 5.1.1|550 5.2.1|550 5.7.1|550 5.7.133|554 5.4.14`

## Pasiūlymai Jūsų Laravel Skriptui

### 1. Disposable Email Sąrašas ✅ (Jau turite)
- Jūsų sistema naudoja `Propaganistas\LaravelDisposableEmail`
- **Pasiūlymas:** Pridėti papildomą custom disposable sąrašą config faile

### 2. MX Skip List ❌ (Trūksta)
- **Pasiūlymas:** Pridėti `mx_skip_list` config opciją
- **Implementacija:** Patikrinti prieš SMTP check
- **Automatinis pridėjimas:** Jei SMTP connection failed arba 550 error

### 3. Unsupported Domain Sąrašas ❌ (Trūksta)
- **Pasiūlymas:** Pridėti `unsupported_domains` config opciją
- **Rezultatas:** Status = "skipped", score = 0

### 4. Public Provider Special Handling ❌ (Trūksta)
- **Pasiūlymas:** Pridėti specialius provider'ius:
  - Gmail/Google
  - Yahoo
  - Outlook/Hotmail/Microsoft
  - Mail.ru
  - Yandex
  - AOL
- **Implementacija:** Patikrinti MX records ir naudoti specialius provider'ius vietoj SMTP

### 5. Catch-All Skip Sąrašas ❌ (Trūksta)
- **Pasiūlymas:** Pridėti `catch_all_skip_domains` config opciją
- **Paskirtis:** Praleisti catch-all tikrinimą žinomoms public provider domenams

### 6. SMTP Error Pattern Matching ❌ (Trūksta)
- **Pasiūlymas:** Pridėti `smtp_error_patterns` config opciją
- **Paskirtis:** Identifikuoti specifinius SMTP error'us (blocked, unsolicited mail, etc.)

### 7. Blacklist Check ✅ (Jau turite)
- Jūsų sistema naudoja `BlocklistCheckService`
- **Pasiūlymas:** Pridėti daugiau DNSBL sąrašų iš `dnsbl.json`

### 8. Inbox Checker ❌ (Trūksta)
- **Pasiūlymas:** Pridėti optional inbox checker funkciją
- **Implementacija:** Siųsti tikrą email ir tikrinti IMAP inbox'ą

### 9. Rate Limiting ✅ (Jau turite)
- Jūsų sistema turi rate limiting
- **Pasiūlymas:** Optimizuoti delay tarp checks

### 10. Catch-All Detection ❌ (Trūksta)
- **Pasiūlymas:** Pridėti catch-all detection
- **Implementacija:** Sugeneruoti random email ir patikrinti ar priima

## Konfigūracijos Pavyzdžiai

### MX Skip List
```php
'mx_skip_list' => [
    'securence.com',
    'mailanyone.net',
    'mimecast.com',
    // Automatiškai pridės jei SMTP connection failed
],
```

### Unsupported Domains
```php
'unsupported_domains' => [
    'mimecast.com',
    'yahoo.co',
    // Kiti domenai, kurie neleidžia SMTP tikrinimo
],
```

### Catch-All Skip Domains
```php
'catch_all_skip_domains' => [
    'gmail.com',
    'google.com',
    'yahoo.com',
    'outlook.com',
    'hotmail.com',
    'live.com',
    'mail.ru',
    'yandex.com',
    'yandex.ru',
    'aol.com',
    // Kiti public provider domenai
],
```

### SMTP Error Patterns
```php
'smtp_error_patterns' => [
    'blocked by prs',
    'unsolicited mail',
    '550 5.7.1',
    '550 5.1.1',
    '550 5.2.1',
    '550 5.7.133',
    '554 5.4.14',
],
```

## Prioritetai

1. **Aukštas:** MX Skip List, Unsupported Domains, Catch-All Skip Domains
2. **Vidutinis:** Public Provider Special Handling, SMTP Error Pattern Matching
3. **Žemas:** Inbox Checker, Catch-All Detection

## Išvados

Jūsų Laravel sistema jau turi gerą pagrindą:
- ✅ Disposable email check
- ✅ Blacklist check
- ✅ Rate limiting
- ✅ SMTP check

Trūksta:
- ❌ MX skip list
- ❌ Unsupported domains
- ❌ Public provider special handling
- ❌ Catch-all skip domains
- ❌ SMTP error pattern matching

Šie patobulinimai padidins tikslumą ir sumažins false positive/negative rezultatus.

