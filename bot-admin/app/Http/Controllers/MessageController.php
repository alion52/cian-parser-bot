<?php
namespace App\Http\Controllers;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MessageController extends Controller
{
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'user_id' => 'required|integer',
                'role' => 'required|in:user,assistant',
                'content' => 'required|string',
                'metadata' => 'nullable|array'
            ]);

            $message = Message::create([
                'user_id' => $validated['user_id'],
                'role' => $validated['role'],
                'content' => $validated['content'],
                'metadata' => $validated['metadata'] ?? ['source' => 'telegram']
            ]);

            Log::info('Message saved', ['id' => $message->id, 'user_id' => $message->user_id]);

            return response()->json([
                'success' => true,
                'message' => $message
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to save message: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getUserMessages($userId)
    {
        try {
            $messages = Message::where('user_id', $userId)
                ->orderBy('created_at', 'desc')
                ->limit(100)
                ->get();

            return response()->json([
                'success' => true,
                'messages' => $messages
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getStats()
    {
        $totalUsers = Message::distinct('user_id')->count('user_id');
        $totalMessages = Message::count();
        $userMessages = Message::where('role', 'user')->count();
        $botMessages = Message::where('role', 'assistant')->count();

        return response()->json([
            'success' => true,
            'stats' => [
                'total_users' => $totalUsers,
                'total_messages' => $totalMessages,
                'user_messages' => $userMessages,
                'bot_messages' => $botMessages
            ]
        ], 200);
    }
}