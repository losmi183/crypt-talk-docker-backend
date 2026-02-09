<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use App\Repository\MessageRepository;

class GroqServices {

    private MessageRepository $messageRepository;
    private AIServices $aiServices;
    public function __construct(MessageRepository $messageRepository, AIServices $aiServices) {
        $this->messageRepository = $messageRepository;
        $this->aiServices = $aiServices;
    }

    public function send(array $data): string
    {
        $conversation_user = \DB::table('conversation_user as cu')
            ->leftJoin('users as u', 'u.id', 'cu.user_id')
            ->where('cu.conversation_id', $data['conversationId'])
            ->where('cu.user_id', '!=', $data['user_id'])
            ->where('u.role', 'ai')
            ->first();

        $aiPerson = \DB::table('ai_persons')->where('user_id', $conversation_user->user_id)->first();

        // Log::info(json_encode($aiPerson));
        
        $conversationHistory  = $this->messageRepository->getConversationMessages($data['conversationId'], 20);
        $limitedMessages = $this->aiServices->trimMessagesToTokenLimit($conversationHistory, $aiPerson->max_tokens);
        $messages = $this->aiServices->formatMessagesForAI($limitedMessages, $aiPerson);

        // Log::info(json_encode('$messages'));
        // Log::info(json_encode($messages));

        $client = new \GuzzleHttp\Client([
            'timeout' => 30.0,
        ]);
        
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . env('GROQ_API_KEY'),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'json' => [
                'model' => 'llama-3.3-70b-versatile',
                'messages' => $messages,
                'max_tokens' => 500,
                'temperature' => (int)$aiPerson->temperature,
            ],
            'http_errors' => false, // VAŽNO: ne baca exception za HTTP greške
        ];
        
        try {
            $response = $client->request('POST', 'https://api.groq.com/openai/v1/chat/completions', $options);
            
            $statusCode = $response->getStatusCode();
            $body = $response->getBody()->getContents();
            

           
            if ($statusCode !== 200) {
                $errorData = json_decode($body, true);
                $errorMessage = $errorData['error']['message'] ?? $body ?? 'Unknown error';
                return "HTTP Error {$statusCode}: {$errorMessage}";
            }
            
            $data = json_decode($body, true);

            Log::info('Full Groq Response Data:', ['data' => $data]);
            
            if (!isset($data['choices'][0]['message']['content'])) {
                return 'No content in response. Data: ' . json_encode($data);
            }
            
            $aiResponse =  $data['choices'][0]['message']['content'];
            // Log::info(json_encode('$aiResponse'));
            // Log::info(json_encode($aiResponse));
            return $aiResponse;
            
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            Log::error(json_encode($e->getMessage()));
            return "Connection error: " . $e->getMessage();
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            return "Request error: " . $e->getMessage();
            Log::error(json_encode($e->getMessage()));
        } catch (\Exception $e) {
            return "General error: " . $e->getMessage();
            Log::error(json_encode($e->getMessage()));
        }
    }
}