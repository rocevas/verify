# Ollama Docker Setup

Ollama automatiškai sukonfigūruotas Docker konteineriuose.

## Konfigūracija

`.env` failas:

```env
AI_PROVIDER=ollama
AI_MODEL=llama3.2:1b
AI_ENABLED=true
```

## Paleidimas

```bash
make up
```

Ollama automatiškai:
- ✅ Paleidžiamas serveris
- ✅ Įdiegiamas `llama3.2:1b` modelis (~700MB)
- ✅ Pasiekiamas per `http://ollama:11434`

## Patikrinimas

```bash
./vendor/bin/sail exec ollama ollama list
```

Turėtumėte matyti `llama3.2:1b` modelį.

## Troubleshooting

**Ollama neveikia:**
```bash
docker-compose logs ollama
docker-compose restart ollama
```

**Modelis neįdiegtas:**
```bash
./vendor/bin/sail exec ollama ollama pull llama3.2:1b
```

## Docker Volume

Modeliai saugomi `sail-ollama` volume - išlieka po restart.
