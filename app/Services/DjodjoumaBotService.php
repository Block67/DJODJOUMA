<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\User;
use Telegram\Bot\Api;
use GuzzleHttp\Client;
use App\Models\Tontine;
use Illuminate\Support\Str;
use App\Models\Conversation;
use App\Models\TontineMember;
use App\Models\TontinePayment;
use App\Models\TontineWithdrawal;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class DjodjoumaBotService
{
    protected $telegram;
    protected $httpClient;

    public function __construct()
    {
        $token = config('tontine.telegram.bot_token');
        if (!$token) {
            throw new \Exception('Telegram bot token is not set.');
        }
        $this->telegram = new Api($token);
        $this->httpClient = new Client(['base_uri' => config('tontine.btcpay.server_url')]);
    }

    public function setWebhook(): bool
    {
        $webhookUrl = config('tontine.telegram.webhook_url');
        if (!is_string($webhookUrl) || empty($webhookUrl)) {
            Log::error('Invalid Telegram webhook URL.');
            return false;
        }

        try {
            $response = $this->telegram->setWebhook(['url' => $webhookUrl]);
            Log::info('Telegram webhook set successfully: ' . $webhookUrl);
            return $response;
        } catch (\Exception $e) {
            Log::error('Failed to set Telegram webhook: ' . $e->getMessage());
            return false;
        }
    }

    public function showMainMenu(int $chatId, int $userId, ?array $chatData = null): void
    {
        if ($chatData) {
            $this->registerUser($chatId, $chatData);
        }

        $user = User::where('telegram_id', $userId)->first();
        if (!$user) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Veuillez vous enregistrer en démarrant le bot avec /start.',
            ]);
            return;
        }

        $keyboard = [
            [
                ['text' => '➕ Créer une tontine', 'callback_data' => 'create_tontine'],
                ['text' => '🤝 Rejoindre une tontine', 'callback_data' => 'join_tontine'],
            ],
            [
                ['text' => '💸 Payer', 'callback_data' => 'pay'],
                ['text' => '💰 Vérifier le solde', 'callback_data' => 'balance'],
            ],
            [
                ['text' => '🏧 Retirer', 'callback_data' => 'withdraw'],
            ],
        ];

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "Bienvenue, @{$user->username} ! Que souhaitez-vous faire ?",
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);
    }

    public function handleCallback(int $chatId, int $userId, string $callbackData, ?int $messageId = null): void
    {
        $user = User::where('telegram_id', $userId)->first();
        if (!$user) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Utilisateur non enregistré. Veuillez utiliser /start.',
            ]);
            return;
        }

        switch ($callbackData) {
            case 'create_tontine':
                Conversation::updateOrCreate(
                    ['user_id' => $user->id],
                    ['state' => 'create_tontine_name', 'data' => []]
                );
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'Entrez le nom de la tontine :',
                ]);
                break;

            case 'join_tontine':
                $this->showJoinTontineOptions($chatId, $user);
                break;

            case 'pay':
                $this->selectTontineForPayment($chatId, $user);
                break;

            case 'balance':
                $this->selectTontineForBalance($chatId, $user);
                break;

            case 'withdraw':
                $this->selectTontineForWithdrawal($chatId, $user);
                break;

            default:
                if (str_starts_with($callbackData, 'frequency_')) {
                    $this->handleFrequencySelection($chatId, $user, $callbackData);
                } elseif (str_starts_with($callbackData, 'select_tontine_pay_')) {
                    $tontineId = str_replace('select_tontine_pay_', '', $callbackData);
                    $this->payTontine($chatId, $user, $tontineId);
                } elseif (str_starts_with($callbackData, 'select_tontine_balance_')) {
                    $tontineId = str_replace('select_tontine_balance_', '', $callbackData);
                    $this->checkBalance($chatId, $user, $tontineId);
                } elseif (str_starts_with($callbackData, 'select_tontine_withdraw_')) {
                    $tontineId = str_replace('select_tontine_withdraw_', '', $callbackData);
                    $this->withdrawTontine($chatId, $user, $tontineId);
                } elseif (str_starts_with($callbackData, 'join_tontine_code_')) {
                    $code = str_replace('join_tontine_code_', '', $callbackData);
                    $this->joinTontine($chatId, $user, $code);
                }
        }

        if ($messageId) {
            $this->telegram->editMessageReplyMarkup([
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'reply_markup' => json_encode(['inline_keyboard' => []]),
            ]);
        }
    }

    public function handleTextInput(int $chatId, int $userId, string $text): void
    {
        $user = User::where('telegram_id', $userId)->first();
        if (!$user) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Utilisateur non enregistré. Veuillez utiliser /start.',
            ]);
            return;
        }

        $conversation = Conversation::where('user_id', $user->id)->first();
        if (!$conversation) {
            $this->showMainMenu($chatId, $userId);
            return;
        }

        $data = $conversation->data ?? [];

        switch ($conversation->state) {
            case 'create_tontine_name':
                $data['name'] = $text;
                $conversation->update([
                    'state' => 'create_tontine_amount',
                    'data' => $data,
                ]);
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'Entrez le montant en FCFA :',
                ]);
                break;

            case 'create_tontine_amount':
                if (!is_numeric($text) || $text <= 0) {
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Veuillez entrer un montant valide (nombre positif).',
                    ]);
                    return;
                }
                $data['amount_fcfa'] = (int) $text;
                $conversation->update([
                    'state' => 'create_tontine_frequency',
                    'data' => $data,
                ]);
                $keyboard = [
                    [
                        ['text' => 'Quotidien', 'callback_data' => 'frequency_daily'],
                        ['text' => 'Hebdomadaire', 'callback_data' => 'frequency_weekly'],
                        ['text' => 'Mensuel', 'callback_data' => 'frequency_monthly'],
                    ],
                ];
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'Choisissez la fréquence :',
                    'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
                ]);
                break;

            case 'create_tontine_max_members':
                if (!is_numeric($text) || $text < 2) {
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Veuillez entrer un nombre maximum de membres (minimum 2).',
                    ]);
                    return;
                }
                $data['max_members'] = (int) $text;
                $this->createTontine($chatId, $user, $data);
                $conversation->delete();
                break;

            case 'join_tontine_code':
                $this->joinTontine($chatId, $user, $text);
                $conversation->delete();
                break;
        }
    }

    public function handleFrequencySelection(int $chatId, User $user, string $frequency): void
    {
        $conversation = Conversation::where('user_id', $user->id)->first();
        if (!$conversation || $conversation->state !== 'create_tontine_frequency') {
            return;
        }

        $data = $conversation->data;
        $data['frequency'] = str_replace('frequency_', '', $frequency);
        $conversation->update([
            'state' => 'create_tontine_max_members',
            'data' => $data,
        ]);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'Entrez le nombre maximum de membres :',
        ]);
    }

    protected function createTontine(int $chatId, User $user, array $data): void
    {
        $amountSats = $this->convertFcfaToSats($data['amount_fcfa']);
        $code = Str::random(8);

        $tontine = Tontine::create([
            'name' => $data['name'],
            'code' => $code,
            'creator_id' => $user->id,
            'amount_fcfa' => $data['amount_fcfa'],
            'amount_sats' => $amountSats,
            'frequency' => $data['frequency'],
            'max_members' => $data['max_members'],
            'current_members' => 1,
            'start_date' => Carbon::now(),
            'next_distribution' => $this->calculateNextDistribution(Carbon::now(), $data['frequency']),
        ]);

        TontineMember::create([
            'tontine_id' => $tontine->id,
            'user_id' => $user->id,
            'position' => 1,
            'joined_at' => Carbon::now(),
        ]);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "Tontine '{$data['name']}' créée avec succès ! Code d'invitation : $code",
        ]);
        $this->showMainMenu($chatId, $user->telegram_id);
    }

    protected function showJoinTontineOptions(int $chatId, User $user): void
    {
        $keyboard = [
            [['text' => 'Entrer le code', 'callback_data' => 'join_tontine_code']],
        ];

        Conversation::updateOrCreate(
            ['user_id' => $user->id],
            ['state' => 'join_tontine_code', 'data' => []]
        );

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'Entrez le code d\'invitation de la tontine :',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);
    }

    protected function joinTontine(int $chatId, User $user, string $code): void
    {
        $tontine = Tontine::where('code', $code)->first();

        if (!$tontine) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Code d\'invitation invalide.',
            ]);
            return;
        }

        if ($tontine->current_members >= $tontine->max_members) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'La tontine est complète.',
            ]);
            return;
        }

        if ($tontine->members()->where('user_id', $user->id)->exists()) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Vous êtes déjà membre de cette tontine.',
            ]);
            return;
        }

        $position = $tontine->current_members + 1;

        TontineMember::create([
            'tontine_id' => $tontine->id,
            'user_id' => $user->id,
            'position' => $position,
            'joined_at' => Carbon::now(),
        ]);

        $tontine->increment('current_members');

        // Notifier le créateur de la tontine
        $creator = User::find($tontine->creator_id);
        if ($creator && $creator->telegram_id !== $user->telegram_id) {
            $this->telegram->sendMessage([
                'chat_id' => $creator->telegram_id,
                'text' => "Un nouveau membre, @{$user->username}, a rejoint votre tontine '{$tontine->name}' ! Position : $position",
            ]);
        }

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "Vous avez rejoint la tontine '{$tontine->name}' ! Position : $position",
        ]);

        $this->showMainMenu($chatId, $user->telegram_id);
    }

    protected function selectTontineForPayment(int $chatId, User $user): void
    {
        $tontines = $user->tontines()->where('status', 'active')->get();

        if ($tontines->isEmpty()) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Vous n\'êtes membre d\'aucune tontine active.',
            ]);
            return;
        }

        $keyboard = $tontines->map(function ($tontine) {
            return [['text' => $tontine->name, 'callback_data' => 'select_tontine_pay_' . $tontine->id]];
        })->toArray();

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'Sélectionnez une tontine pour effectuer un paiement :',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);
    }

    protected function payTontine(int $chatId, User $user, string $tontineId): void
    {
        $tontine = Tontine::find($tontineId);
        if (!$tontine || $tontine->status !== 'active') {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Tontine invalide ou inactive.',
            ]);
            return;
        }

        $existingPayment = TontinePayment::where('tontine_id', $tontine->id)
            ->where('user_id', $user->id)
            ->where('round', $tontine->current_round)
            ->where('status', 'pending')
            ->first();

        if ($existingPayment) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Vous avez déjà une facture en attente pour ce tour.',
            ]);
            return;
        }

        $invoice = $this->createBtcpayInvoice($tontine->amount_sats, $tontine->id, $user->id);

        if (empty($invoice)) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Erreur lors de la création de la facture. Veuillez réessayer.',
            ]);
            return;
        }

        $bolt11 = $invoice['bolt11'] ?? 'ln_invoice';

        TontinePayment::create([
            'tontine_id' => $tontine->id,
            'user_id' => $user->id,
            'invoice_id' => $invoice['id'],
            'payment_hash' => $invoice['payment_hash'] ?? null,
            'amount_fcfa' => $tontine->amount_fcfa,
            'amount_sats' => $tontine->amount_sats,
            'bolt11_invoice' => $bolt11,
            'expires_at' => Carbon::now()->addMinutes(30),
            'round' => $tontine->current_round,
        ]);

        if ($bolt11 === 'ln_invoice') {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "Veuillez utiliser le lien de paiement : {$invoice['checkoutLink']}",
            ]);
        } else {
            $qrCode = QrCode::size(200)->generate($bolt11);
            $this->telegram->sendPhoto([
                'chat_id' => $chatId,
                'photo' => $qrCode,
                'caption' => "Payez {$tontine->amount_fcfa} FCFA ({$tontine->amount_sats} sats) pour la tontine '{$tontine->name}'.\nFacture : {$invoice['checkoutLink']}",
            ]);
        }

        $this->showMainMenu($chatId, $user->telegram_id);
    }

    protected function selectTontineForBalance(int $chatId, User $user): void
    {
        $tontines = $user->tontines()->where('status', 'active')->get();

        if ($tontines->isEmpty()) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Vous n\'êtes membre d\'aucune tontine active.',
            ]);
            return;
        }

        $keyboard = $tontines->map(function ($tontine) {
            return [['text' => $tontine->name, 'callback_data' => 'select_tontine_balance_' . $tontine->id]];
        })->toArray();

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'Sélectionnez une tontine pour vérifier le solde :',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);
    }

    protected function checkBalance(int $chatId, User $user, string $tontineId): void
    {
        $tontine = Tontine::find($tontineId);
        if (!$tontine) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Tontine invalide.',
            ]);
            return;
        }

        $totalSats = $tontine->payments()->where('status', 'paid')->sum('amount_sats');
        $totalFcfa = $this->convertSatsToFcfa($totalSats);

        $nextBeneficiary = $tontine->members()
            ->where('position', ($tontine->current_round % $tontine->max_members) + 1)
            ->first()?->user->username ?? 'Aucun';

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "Tontine : {$tontine->name}\nSolde : $totalFcfa FCFA ($totalSats sats)\nProchain bénéficiaire : @$nextBeneficiary\nTour actuel : {$tontine->current_round}",
        ]);

        $this->showMainMenu($chatId, $user->telegram_id);
    }

    protected function selectTontineForWithdrawal(int $chatId, User $user): void
    {
        $tontines = $user->tontines()->where('status', 'active')->get();

        if ($tontines->isEmpty()) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Vous n\'êtes membre d\'aucune tontine active.',
            ]);
            return;
        }

        $keyboard = $tontines->map(function ($tontine) {
            return [['text' => $tontine->name, 'callback_data' => 'select_tontine_withdraw_' . $tontine->id]];
        })->toArray();

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'Sélectionnez une tontine pour effectuer un retrait :',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);
    }

    protected function withdrawTontine(int $chatId, User $user, string $tontineId): void
    {
        $tontine = Tontine::find($tontineId);
        if (!$tontine || $tontine->status !== 'active') {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Tontine invalide ou inactive.',
            ]);
            return;
        }

        $member = $tontine->members()->where('user_id', $user->id)->first();
        if (!$member) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Vous n\'êtes pas membre de cette tontine.',
            ]);
            return;
        }

        $currentPosition = ($tontine->current_round % $tontine->max_members) + 1;
        if ($member->pivot->position !== $currentPosition) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Ce n\'est pas votre tour de retirer.',
            ]);
            return;
        }

        if ($member->pivot->has_received) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Vous avez déjà reçu votre part.',
            ]);
            return;
        }

        $withdrawalAmountSats = $tontine->amount_sats * $tontine->max_members;
        $withdrawalAmountFcfa = $this->convertSatsToFcfa($withdrawalAmountSats);

        $invoice = [
            'id' => Str::uuid(),
            'bolt11' => 'lightning:placeholder_invoice',
            'checkoutLink' => 'https://btcpay.placeholder/withdrawal',
        ];

        TontineWithdrawal::create([
            'tontine_id' => $tontine->id,
            'user_id' => $user->id,
            'amount_fcfa' => $withdrawalAmountFcfa,
            'amount_sats' => $withdrawalAmountSats,
            'bolt11_invoice' => $invoice['bolt11'],
            'payment_hash' => null,
            'round' => $tontine->current_round,
        ]);

        $member->pivot->has_received = true;
        $member->pivot->save();

        $tontine->increment('current_round');
        $tontine->next_distribution = $this->calculateNextDistribution(Carbon::now(), $tontine->frequency);
        $tontine->save();

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "Retrait initié : $withdrawalAmountFcfa FCFA ($withdrawalAmountSats sats).\nFacture : {$invoice['checkoutLink']}",
        ]);

        $this->showMainMenu($chatId, $user->telegram_id);
    }


    protected function createBtcpayInvoice(int $amountSats, int $tontineId, int $userId): array
    {
        try {
            $tontine = Tontine::find($tontineId);
            if (!$tontine || $tontine->amount_sats !== $amountSats) {
                Log::error('Échec de la création de la facture BTCPay : tontine invalide ou montant incorrect', [
                    'tontine_id' => $tontineId,
                    'provided_amount_sats' => $amountSats,
                    'tontine_amount_sats' => $tontine ? $tontine->amount_sats : null,
                ]);
                return [];
            }

            $storeId = config('tontine.btcpay.store_id');
            $apiKey = config('tontine.btcpay.api_key');
            $serverUrl = config('tontine.btcpay.server_url');

            $amountBtc = bcdiv($amountSats, 100_000_000, 8);

            $paymentRequestData = [
                'amount' => $amountBtc,
                'title' => 'Paiement Tontine',
                'currency' => 'BTC',
                'email' => "user_{$userId}@example.com",
                'description' => "Participation à la tontine '{$tontine->name}'",
                'checkout' => [
                    'paymentMethods' => ['BTC-LightningNetwork'],
                ],
            ];

            $createResponse = Http::withHeaders([
                'Authorization' => 'token ' . $apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->post("{$serverUrl}/stores/{$storeId}/payment-requests", $paymentRequestData);

            if (!$createResponse->successful()) {
                Log::error('Échec de la création de la demande de paiement BTCPay', ['response' => $createResponse->json()]);
                return [];
            }

            $paymentRequestId = $createResponse->json()['id'];

            $confirmResponse = Http::withHeaders([
                'Authorization' => 'token ' . $apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->post("{$serverUrl}/stores/{$storeId}/payment-requests/{$paymentRequestId}/pay", [
                'metadata' => [
                    'tontine_id' => $tontineId,
                    'user_id' => $userId,
                    'paymentRequestId' => $paymentRequestId,
                    'amount_sats' => $amountSats,
                ]
            ]);

            if ($confirmResponse->successful() && isset($confirmResponse['checkoutLink'])) {
                $data = $confirmResponse->json();
                $bolt11 = $data['bolt11'] ?? 'ln_invoice';

                if ($bolt11 === 'ln_invoice') {
                    Log::warning('bolt11 manquant dans la réponse BTCPay, utilisant la valeur par défaut', [
                        'paymentRequestId' => $paymentRequestId,
                        'response' => $data,
                    ]);
                }

                if (isset($data['amount'])) {
                    $returnedAmountBtc = (float) $data['amount'];
                    $returnedAmountSats = intval($returnedAmountBtc * 100_000_000);
                    if ($returnedAmountSats !== $amountSats) {
                        Log::error('Incohérence dans le montant de la facture BTCPay', [
                            'expected_sats' => $amountSats,
                            'returned_sats' => $returnedAmountSats,
                        ]);
                        return [];
                    }
                }

                return [
                    'id' => $paymentRequestId,
                    'checkoutLink' => $data['checkoutLink'],
                    'bolt11' => $bolt11,
                    'payment_hash' => $data['paymentHash'] ?? null,
                ];
            } else {
                Log::error('Échec de la confirmation de la demande de paiement BTCPay', ['response' => $confirmResponse->json()]);
                return [];
            }
        } catch (\Exception $e) {
            Log::error('Échec de la création de la facture BTCPay : ' . $e->getMessage());
            return [];
        }
    }



    protected function getCurrentBtcRate(): float
    {
        $response = Http::get('https://api.yadio.io/rate/XOF/BTC');

        if ($response->successful()) {
            return (float) $response->json()['rate'];
        }

        return (float) config('tontine.exchange.default_rate', 60000000);
    }

    protected function convertFcfaToSats(int $amountFcfa): int
    {
        $rate = $this->getCurrentBtcRate();

        $sats = ($amountFcfa / $rate) * 100_000_000;

        return intval(ceil($sats));
    }

    protected function convertSatsToFcfa(int $amountSats): int
    {
        $rate = $this->getCurrentBtcRate();

        $fcfa = ($amountSats * $rate) / 100_000_000;

        return intval(round($fcfa));
    }


    protected function calculateNextDistribution(Carbon $currentDate, string $frequency): Carbon
    {
        return match ($frequency) {
            'daily' => $currentDate->addDay(),
            'weekly' => $currentDate->addWeek(),
            'monthly' => $currentDate->addMonth(),
        };
    }

    public function answerCallbackQuery(string $callbackQueryId): void
    {
        try {
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $callbackQueryId,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to answer callback query: ' . $e->getMessage());
        }
    }

    public function handleBtcpayWebhook(string $invoiceId, string $status): void
    {
        $payment = TontinePayment::where('invoice_id', $invoiceId)->first();

        if (!$payment) {
            Log::warning('BTCPay webhook: Payment not found for invoice ' . $invoiceId);
            return;
        }

        if ($status === 'Settled') {
            $payment->update([
                'status' => 'paid',
                'paid_at' => Carbon::now(),
            ]);

            // Notifier le membre qui a payé
            $this->telegram->sendMessage([
                'chat_id' => $payment->user->telegram_id,
                'text' => "Paiement de {$payment->amount_fcfa} FCFA confirmé pour la tontine '{$payment->tontine->name}'.",
            ]);

            // Notifier le créateur de la tontine
            $tontine = $payment->tontine;
            $creator = User::find($tontine->creator_id);
            if ($creator && $creator->telegram_id !== $payment->user->telegram_id) {
                $this->telegram->sendMessage([
                    'chat_id' => $creator->telegram_id,
                    'text' => "Le membre @{$payment->user->username} a payé {$payment->amount_fcfa} FCFA pour la tontine '{$tontine->name}' (Tour {$payment->round}).",
                ]);
            }
        } elseif ($status === 'Expired') {
            $payment->update(['status' => 'expired']);
        }
    }

    protected function registerUser(int $chatId, array $chatData): void
    {
        User::updateOrCreate(
            ['telegram_id' => $chatId],
            [
                'first_name' => $chatData['first_name'] ?? null,
                'last_name' => $chatData['last_name'] ?? null,
                'username' => $chatData['username'] ?? 'User_' . $chatId,
                'language_code' => $chatData['language_code'] ?? 'fr',
            ]
        );
    }
}
