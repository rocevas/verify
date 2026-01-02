#!/bin/sh
set -e

echo "Starting Ollama server in background..."
ollama serve &
OLLAMA_PID=$!

echo "Waiting for Ollama to be ready..."
sleep 10

# Wait for Ollama API to be available (using wget which is available in Ollama image)
for i in 1 2 3 4 5 6 7 8 9 10; do
    if wget -q --spider http://localhost:11434/api/tags 2>/dev/null || [ $? -eq 0 ]; then
        echo "Ollama is ready!"
        break
    fi
    echo "Waiting for Ollama... ($i/10)"
    sleep 3
done

# Pull the base model if it doesn't exist (using 1b variant for smaller resource usage)
echo "Checking for llama3.2:1b model..."
MODEL_EXISTS=$(ollama list 2>/dev/null | grep -q "llama3.2:1b" && echo "yes" || echo "no")

if [ "$MODEL_EXISTS" = "no" ]; then
    echo "Pulling llama3.2:1b model (1B parameters, Q4_0 quantized - optimized for low resources)..."
    echo "This may take a few minutes on first run, but the model is small (~700MB)..."
    ollama pull llama3.2:1b || {
        echo "Warning: Failed to pull llama3.2:1b, trying llama3.2 instead..."
        ollama pull llama3.2 || {
            echo "Error: Failed to pull model. Please check your internet connection."
            exit 1
        }
    }
    echo "Model llama3.2:1b installation completed!"
else
    echo "Model llama3.2:1b already exists."
fi

echo ""
echo "Ollama is ready to use!"
echo "Models available:"
ollama list || echo "Could not list models (server may still be starting)"

# Keep the server running
echo "Ollama server is running (PID: $OLLAMA_PID)"
wait $OLLAMA_PID

