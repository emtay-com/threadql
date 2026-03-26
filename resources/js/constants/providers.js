const Providers = {
    OPENAI: 'openai',
    ANTHROPIC: 'anthropic',
    OLLAMA: 'ollama',
    MISTRAL: 'mistral',
    GROQ: 'groq',
    XAI: 'xai',
    GEMINI: 'gemini',
    DEEPSEEK: 'deepseek',
    ELEVENLABS: 'elevenlabs',
    VOYAGEAI: 'voyageai',
    OPENROUTER: 'openrouter',
}

const ProviderSettings = {
    openai: {
        apiUrl: 'https://api.openai.com/v1',
        fullName: 'OpenAI',
    },
    anthropic:  {
        apiUrl: 'https://api.anthropic.com/v1',
        fullName: 'Anthropic',
    },
    ollama: {
        apiUrl: 'http://localhost:11434',
        fullName: 'Ollama',
    },
    mistral: {
        apiUrl: 'https://api.mistral.ai/v1',
        fullName: 'MistralAI',
    },
    groq: {
        apiUrl: 'https://api.groq.com/openai/v1',
        fullName: 'Groq',
    },
    xai: {
        apiUrl: 'https://api.x.ai/v1',
        fullName: 'X.AI (grok)',
    },
    gemini: {
        apiUrl: 'https://generativelanguage.googleapis.com/v1beta/models',
        fullName: 'Google Gemini',
    },
    deepseek: {
        apiUrl: 'https://api.deepseek.com/v1',
        fullName: 'Deepseek',
    },
    elevenlabs: {
        apiUrl: 'https://api.elevenlabs.io/v1/',
        fullName: 'ElevenLabs',
    },
    voyageai: {
        apiUrl: 'https://api.voyageai.com/v1',
        fullName: 'VoyageAI',
    },
    openrouter: {
        apiUrl: 'https://openrouter.ai/api/v1',
        fullName: 'OpenRouter',
    },
}

export {
    Providers,
    ProviderSettings
}
