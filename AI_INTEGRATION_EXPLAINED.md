# Kaip naudojamas Ollama AI

## Proceso eiga

1. **Tradicinės patikros** (syntax, MX, SMTP, disposable, role-based)
2. **AI analizė** (Ollama analizuoja rezultatus ir pateikia insights)
3. **Rezultatų sujungimas**: Final Score = (Traditional × 70%) + (AI × 30%)

## Kur naudojamas

- ✅ `/dashboard` - ChatGPT stiliaus dashboard su AI
- ✅ `/api/ai/verify/stream` - Single email su AI
- ✅ `/api/ai/verify/batch/stream` - Batch su AI
- ❌ `/api/verify` - Tik tradicinės patikros (be AI)

## Ką AI pateikia

- **AI Insights** - Tekstinė analizė
- **AI Confidence** - Pasitikėjimo lygis (0-100)
- **Risk Factors** - Rizikos veiksniai
- **Final Score** - Sujungtas rezultatas

## Patikrinimas

```bash
# Test komanda
./vendor/bin/sail artisan ai:test test@example.com

# Patikrinti Ollama
./vendor/bin/sail exec ollama ollama list
```

## Konfigūracija

```env
AI_PROVIDER=ollama
AI_MODEL=llama3.2:1b
AI_ENABLED=true
```
