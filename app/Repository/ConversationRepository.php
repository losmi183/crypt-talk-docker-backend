<?php

namespace App\Repository;

use App\Models\Conversation;
use App\Models\User;
use App\Models\Connection;
use App\Services\ConversationServices;
use App\Services\JWTServices;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use stdClass;

class ConversationRepository
{
    private JWTServices $jwtServices;  

    public function __construct(JWTServices $jwtServices) {
        $this->jwtServices = $jwtServices;
    }

   public function userConversations(int $user_id, ?string $search = null)
   {
        $query = DB::table(table: 'conversations as c')        
        ->join('conversation_user as cu_user', function($join) use ($user_id) {
            $join->on('c.id', '=', 'cu_user.conversation_id')
                ->where('cu_user.user_id', $user_id);
        })
        ->leftjoin('conversation_user as cu', function($join) use ($user_id) {
            $join->on('c.id', '=', 'cu.conversation_id')
                ->where('cu.user_id', '!=', $user_id);
        })
        ->leftJoin('users as u', 'u.id', 'cu.user_id');


        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('u.name', 'like', "%{$search}%") // Pretraga po imenu sagovornika
                ->orWhere('c.title', 'like', "%{$search}%"); // Ili po nazivu grupe
            });
        }

        $conversations = $query->select([
            'c.id', 'c.title', 'c.type', 'c.encrypted', 'c.salt', 'c.iterations',
            DB::raw("GROUP_CONCAT(CONCAT_WS('|', u.id, u.name, u.role, u.avatar)) as participants"),

            DB::raw("
                (SELECT COUNT(*) 
                FROM messages as m
                WHERE m.conversation_id = c.id
                    AND (
                        cu_user.last_read_message_id IS NULL
                        OR m.id > cu_user.last_read_message_id
                    )
                ) as unread_count
            ")            
        ])        
        ->groupBy('c.id', 'c.title', 'c.type', 'c.encrypted', 'c.salt', 'c.iterations', 'cu_user.last_read_message_id')
        ->get();

        foreach ($conversations as $conversation) {
            $conversation->users = [];
            $participants = explode(',' ,$conversation->participants);
            foreach ($participants as $p) {
                $pArray = explode('|', $p);
                $pObj = new stdClass;
                $pObj->id = $pArray[0] ?? null;
                $pObj->name = $pArray[1] ?? null;
                $pObj->role = $pArray[2] ?? 'user';
                $pObj->avatar = $pArray[3] ?? null;
                $pObj->avatar_url = config('app.url') . '/images/avatar/' . ($pObj->avatar ? $pObj->avatar : 'default.png');
                $conversation->users[] = $pObj;
            }
        }

        return $conversations;
   }

   public function aiPerson($conversationId, $userId): stdClass
   {
        $aiPerson = \DB::table('conversation_user as cu')
            ->select('ai.user_id', 'ai.system_prompt', 'ai.description', 'ai.greeting_message', 'ai.temperature', 'ai.max_tokens')
            ->join('ai_persons as ai', 'ai.user_id', 'cu.user_id')
            ->where('cu.conversation_id', $conversationId)
            ->where('cu.user_id', '!=', $userId)
            // ->where('u.role', 'ai')
            ->first();

        return $aiPerson;
   }

   public function conversationParticipants(array $data): Collection
   {
        return DB::table('conversation_user')
            ->select('user_id')
            ->where('conversation_id', $data['conversationId'])
            ->where('user_id', '!=', $data['user_id'])
            ->get();
   }

   public function aiAssistentToNewUser(int $userId)
   {
        $ai_assistent_user_id = 101;
        try {
            $conversation_id = DB::table('conversations')->insertGetId([
                'type' => 'chatbot',
                'title' => 'Ai assistent',
            ]);
            DB::table('conversation_user')->insert([
                'conversation_id' => $conversation_id,
                'user_id' => $ai_assistent_user_id,
            ]);
            DB::table('conversation_user')->insert([
                'conversation_id' => $conversation_id,
                'user_id' => $userId,
            ]);

            $ai_person = DB::table('ai_persons')->where('user_id', $ai_assistent_user_id)->first();

            DB::table('messages')->insert([
                'conversation_id' => $conversation_id,
                'sender_id' => $ai_person->user_id,
                'message' => $ai_person->greeting_message,
            ]);

        } catch (\Throwable $th) {
            Log::error($th->getMessage());
        }
   }
}