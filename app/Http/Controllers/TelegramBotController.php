<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\DjodjoumaBotService;
use Illuminate\Support\Facades\Log; // Added import

class TelegramBotController extends Controller
{
    protected $botService;

    public function __construct(DjodjoumaBotService $botService)
    {
        $this->botService = $botService;
    }

    public function handle(Request $request)
    {
        try {
            $data = $request->all();

            // Handle messages
            if (isset($data['message'])) {
                $chatId = $data['message']['chat']['id'];
                $userId = $data['message']['from']['id'];
                $text = $data['message']['text'] ?? '';
                $chatData = $data['message']['from'] ?? null;

                // Start command (no referral codes)
                if ($text === '/start') {
                    $this->botService->showMainMenu($chatId, $userId, $chatData);
                    return response()->json(['status' => 'success']);
                }

                // Other text inputs
                $this->botService->handleTextInput($chatId, $userId, $text);
            }

            // Handle callback queries
            if (isset($data['callback_query'])) {
                $callbackQuery = $data['callback_query'];
                $chatId = $callbackQuery['message']['chat']['id'];
                $userId = $callbackQuery['from']['id'];
                $callbackData = $callbackQuery['data'];
                $messageId = $callbackQuery['message']['message_id'];

                $this->botService->handleCallback($chatId, $userId, $callbackData, $messageId);
                $this->botService->answerCallbackQuery($callbackQuery['id']);
                return response()->json(['status' => 'success']);
            }

            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            Log::error('Telegram webhook error: ' . $e->getMessage()); // Changed to Log
            return response()->json(['status' => 'error'], 500);
        }
    }

    public function setWebhook()
    {
        $result = $this->botService->setWebhook();
        return response()->json(['status' => $result ? 'success' : 'error']);
    }

    public function handleBtcpay(Request $request)
    {
        try {
            $data = $request->json()->all();
            $invoiceId = $data['invoiceId'] ?? null;
            $status = $data['status'] ?? null;

            if (!$invoiceId || !$status) {
                return response()->json(['status' => 'error', 'message' => 'Invalid webhook data'], 400);
            }

            $this->botService->handleBtcpayWebhook($invoiceId, $status);
            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            Log::error('BTCPay webhook error: ' . $e->getMessage()); // Changed to Log
            return response()->json(['status' => 'error'], 500);
        }
    }
}
