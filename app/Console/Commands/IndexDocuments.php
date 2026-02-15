<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Exception;

class IndexDocuments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rag:index
                            {--fresh : Clear the Qdrant collection before indexing}
                            {--path=knowledge : Directory relative to storage/app containing text files}
                            {--chunk-size=500 : Maximum characters per chunk}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Index business documents from storage into Qdrant vector database.';

    /**
     * Ollama API client.
     */
    protected Client $ollama;

    /**
     * Qdrant API client.
     */
    protected Client $qdrant;

    /**
     * Collection name for Qdrant.
     */
    protected string $collection;

    /**
     * Embedding model name.
     */
    protected string $embeddingModel;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Load configuration from .env with defaults
        $ollamaHost = env('OLLAMA_HOST', 'http://ollama:11434');
        $qdrantHost = env('QDRANT_HOST', 'http://qdrant:6333');
        $this->collection = env('QDRANT_COLLECTION', 'crypttalk_docs');
        $this->embeddingModel = env('EMBEDDING_MODEL', 'nomic-embed-text');

        // Initialize HTTP clients
        $this->ollama = new Client(['base_uri' => $ollamaHost]);
        $this->qdrant = new Client(['base_uri' => $qdrantHost]);

        // Check if Ollama is reachable
        try {
            $this->ollama->get('/');
        } catch (Exception $e) {
            $this->error('Cannot connect to Ollama. Is the container running?');
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        // Check if Qdrant is reachable
        try {
            $this->qdrant->get('/collections');
        } catch (Exception $e) {
            $this->error('Cannot connect to Qdrant. Is the container running?');
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        // Ensure the collection exists (or verify it does)
        if (!$this->ensureCollection()) {
            return self::FAILURE;
        }

        // Fresh start: delete all points if --fresh flag is used
        if ($this->option('fresh')) {
            $this->clearCollection();
        }

        // Read files from the specified directory
        $directory = $this->option('path');
        $disk = 'private'; // storage/app

        // DEBUG: ispiši stvarnu putanju i proveri postojanje
        $fullPath = Storage::disk($disk)->path($directory);
        $this->warn("Full path: " . $fullPath);
        $this->warn("File exists: " . (file_exists($fullPath) ? 'yes' : 'no'));
        $this->warn("Storage exists: " . (Storage::disk($disk)->exists($directory) ? 'yes' : 'no'));

        if (!Storage::disk($disk)->exists($directory)) {
            $this->error("Directory '{$directory}' does not exist in storage/app.");
            return self::FAILURE;
        }

        $files = Storage::disk($disk)->files($directory);
        $textFiles = array_filter($files, fn($file) => preg_match('/\.(txt|md|text)$/i', $file));

        if (empty($textFiles)) {
            $this->warn("No .txt or .md files found in '{$directory}'.");
            return self::SUCCESS;
        }

        $chunkSize = (int) $this->option('chunk-size');
        $totalChunks = 0;
        $successfulChunks = 0;

        foreach ($textFiles as $file) {
            $this->info("Processing: {$file}");

            $content = Storage::disk($disk)->get($file);
            if (empty(trim($content))) {
                $this->warn("  Skipping empty file.");
                continue;
            }

            // Split the document into chunks
            $chunks = $this->splitTextIntoChunks($content, $chunkSize);
            $this->line("  Split into " . count($chunks) . " chunks.");

            foreach ($chunks as $index => $chunkText) {
                $totalChunks++;

                try {
                    // 1. Generate embedding
                    $embedding = $this->generateEmbedding($chunkText);
                    if (!$embedding) {
                        $this->error("  Failed to generate embedding for chunk {$index}.");
                        continue;
                    }

                    // 2. Create a unique ID (MD5 of source + chunk index)
                    $pointId = md5($file . '_' . $index . '_' . time()); // time() to avoid collisions on re-index

                    // 3. Store in Qdrant
                    $payload = [
                        'text' => $chunkText,
                        'source' => basename($file),
                        'chunk_index' => $index,
                        'created_at' => now()->toIso8601String(),
                    ];

                    $this->storeVector($pointId, $embedding, $payload);
                    $successfulChunks++;

                    // Optional: show progress bar or dot
                    $this->output->write('.');

                } catch (Exception $e) {
                    $this->error("\n  Error indexing chunk {$index}: " . $e->getMessage());
                }
            }

            $this->line(''); // newline after dots
        }

        $this->newLine();
        $this->info("Indexing complete. Successful chunks: {$successfulChunks}/{$totalChunks}");

        return self::SUCCESS;
    }

    /**
     * Split text into chunks of approximately $maxLength characters,
     * trying not to break words.
     */
    protected function splitTextIntoChunks(string $text, int $maxLength): array
    {
        $text = preg_replace('/\s+/', ' ', $text); // normalize whitespace
        $chunks = [];
        $length = mb_strlen($text);

        $start = 0;
        while ($start < $length) {
            // If remaining text fits, take all
            if ($start + $maxLength >= $length) {
                $chunks[] = mb_substr($text, $start);
                break;
            }

            // Find a good break point (space) near the end of the chunk
            $end = $start + $maxLength;
            $breakPos = mb_strrpos(mb_substr($text, $start, $maxLength), ' ');
            if ($breakPos === false) {
                // No space found, cut exactly at maxLength
                $breakPos = $maxLength;
            } else {
                $breakPos += 1; // include the space in the first chunk? better to cut after space
            }

            $chunks[] = mb_substr($text, $start, $breakPos);
            $start += $breakPos;
        }

        // Filter out any empty chunks
        return array_filter($chunks, fn($chunk) => trim($chunk) !== '');
    }

    /**
     * Call Ollama to generate embedding for the given text.
     *
     * @return array|null Vector of 768 floats, or null on failure.
     */
    protected function generateEmbedding(string $text): ?array
    {
        try {
            $response = $this->ollama->post('/api/embeddings', [
                'json' => [
                    'model' => $this->embeddingModel,
                    'prompt' => $text,
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            $embedding = $data['embedding'] ?? null;

            if (!is_array($embedding) || count($embedding) !== 768) {
                $this->error('Unexpected embedding format from Ollama.');
                return null;
            }

            return $embedding;
        } catch (RequestException $e) {
            $this->error('Ollama request failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Store a single point (vector + payload) in Qdrant.
     */
    protected function storeVector(string $id, array $vector, array $payload): void
    {
        $this->qdrant->put("/collections/{$this->collection}/points", [
            'json' => [
                'points' => [
                    [
                        'id' => $id,
                        'vector' => $vector,
                        'payload' => $payload,
                    ],
                ],
            ],
        ]);
    }

    /**
     * Ensure the Qdrant collection exists with correct vector size and distance metric.
     * If not, attempt to create it.
     *
     * @return bool True if collection exists or was created successfully.
     */
    protected function ensureCollection(): bool
    {
        try {
            $response = $this->qdrant->get("/collections/{$this->collection}");
            $data = json_decode($response->getBody(), true);

            // Check if vector size is 768 and distance is Cosine
            $params = $data['result']['config']['params']['vectors'] ?? null;
            if ($params && ($params['size'] ?? 0) === 768 && ($params['distance'] ?? '') === 'Cosine') {
                $this->line("Collection '{$this->collection}' already exists with correct configuration.");
                return true;
            }

            // Wrong configuration – warn but continue (we won't auto-delete; user should recreate manually)
            $this->warn("Collection '{$this->collection}' exists but has size {$params['size']} / distance {$params['distance']}. Expected 768/Cosine.");
            $this->warn("Please delete and recreate the collection manually, or use a different collection name.");
            return false;

        } catch (RequestException $e) {
            if ($e->getResponse() && $e->getResponse()->getStatusCode() === 404) {
                // Collection not found, create it
                $this->line("Collection '{$this->collection}' not found. Creating...");
                try {
                    $this->qdrant->put("/collections/{$this->collection}", [
                        'json' => [
                            'vectors' => [
                                'size' => 768,
                                'distance' => 'Cosine',
                            ],
                        ],
                    ]);
                    $this->info("Collection '{$this->collection}' created successfully.");
                    return true;
                } catch (RequestException $e) {
                    $this->error("Failed to create collection: " . $e->getMessage());
                    return false;
                }
            }

            $this->error("Error checking collection: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete all points from the collection (fresh start).
     */
    protected function clearCollection(): void
    {
        $this->line("Clearing all points from collection '{$this->collection}'...");
        try {
            $this->qdrant->post("/collections/{$this->collection}/points/delete", [
                'json' => ['filter' => new \stdClass()], // empty filter = delete all
            ]);
            $this->info("Collection cleared.");
        } catch (RequestException $e) {
            $this->error("Failed to clear collection: " . $e->getMessage());
        }
    }
}