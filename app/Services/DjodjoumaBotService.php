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
use Illuminate\Http\Request;
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
            ['➕ Créer une tontine', '🤝 Rejoindre une tontine'],
            ['💸 Payer', '💰 Vérifier le solde'],
            ['🏧 Retirer'],
        ];

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "Bienvenue, @{$user->username} ! Que souhaitez-vous faire ?",
            'reply_markup' => json_encode([
                'keyboard' => $keyboard,
                'resize_keyboard' => true,
                'one_time_keyboard' => true,
            ]),
        ]);
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
            switch ($text) {
                case '➕ Créer une tontine':
                    Conversation::updateOrCreate(
                        ['user_id' => $user->id],
                        ['state' => 'create_tontine_name', 'data' => []]
                    );
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Entrez le nom de la tontine :',
                        'reply_markup' => json_encode(['remove_keyboard' => true]),
                    ]);
                    break;

                case '🤝 Rejoindre une tontine':
                    $this->showJoinTontineOptions($chatId, $user);
                    break;

                case '💸 Payer':
                    $this->selectTontineForPayment($chatId, $user);
                    break;

                case '💰 Vérifier le solde':
                    $this->selectTontineForBalance($chatId, $user);
                    break;

                case '🏧 Retirer':
                    $this->selectTontineForWithdrawal($chatId, $user);
                    break;

                default:
                    $this->showMainMenu($chatId, $userId);
                    break;
            }
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
                    ['Quotidien', 'Hebdomadaire', 'Mensuel'],
                ];
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'Choisissez la fréquence :',
                    'reply_markup' => json_encode([
                        'keyboard' => $keyboard,
                        'resize_keyboard' => true,
                        'one_time_keyboard' => true,
                    ]),
                ]);
                break;

            case 'create_tontine_frequency':
                if (!in_array($text, ['Quotidien', 'Hebdomadaire', 'Mensuel'])) {
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Veuillez sélectionner une fréquence valide.',
                    ]);
                    return;
                }
                $data['frequency'] = strtolower(str_replace(['Quotidien', 'Hebdomadaire', 'Mensuel'], ['daily', 'weekly', 'monthly'], $text));
                $conversation->update([
                    'state' => 'create_tontine_max_members',
                    'data' => $data,
                ]);
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'Entrez le nombre maximum de membres :',
                    'reply_markup' => json_encode(['remove_keyboard' => true]),
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

            case 'select_tontine_payment':
                $tontine = Tontine::where('name', $text)->where('status', 'active')->first();
                if (!$tontine || !$user->tontines()->where('tontine_id', $tontine->id)->exists()) {
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Tontine invalide ou vous n\'êtes pas membre.',
                        'reply_markup' => json_encode(['remove_keyboard' => true]),
                    ]);
                    return;
                }
                $conversation->delete();
                $this->payTontine($chatId, $user, $tontine->id);
                break;

            case 'select_tontine_balance':
                $tontine = Tontine::where('name', $text)->where('status', 'active')->first();
                if (!$tontine || !$user->tontines()->where('tontine_id', $tontine->id)->exists()) {
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Tontine invalide ou vous n\'êtes pas membre.',
                        'reply_markup' => json_encode(['remove_keyboard' => true]),
                    ]);
                    return;
                }
                $conversation->delete();
                $this->checkBalance($chatId, $user, $tontine->id);
                break;

            case 'select_tontine_withdraw':
                $tontine = Tontine::where('name', $text)->where('status', 'active')->first();
                if (!$tontine || !$user->tontines()->where('tontine_id', $tontine->id)->exists()) {
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Tontine invalide ou vous n\'êtes pas membre.',
                        'reply_markup' => json_encode(['remove_keyboard' => true]),
                    ]);
                    return;
                }
                $conversation->delete();
                $this->withdrawTontine($chatId, $user, $tontine->id);
                break;
        }
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
            ['Entrer le code'],
        ];

        Conversation::updateOrCreate(
            ['user_id' => $user->id],
            ['state' => 'join_tontine_code', 'data' => []]
        );

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'Entrez le code d\'invitation de la tontine :',
            'reply_markup' => json_encode([
                'keyboard' => $keyboard,
                'resize_id' => true,
                'one_time_keyboard' => true,
            ]),
        ]);
    }

    protected function joinTontine(int $chatId, User $user, string $code): void
    {
        $tontine = Tontine::where('code', $code)->first();

        if (!$tontine) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Code d\'invitation invalide.',
                'reply_markup' => json_encode(['remove_keyboard' => true]),
            ]);
            return;
        }

        if ($tontine->current_members >= $tontine->max_members) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'La tontine est complète.',
                'reply_markup' => json_encode(['remove_keyboard' => true]),
            ]);
            return;
        }

        if ($tontine->members()->where('user_id', $user->id)->exists()) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Vous êtes déjà membre de cette tontine.',
                'reply_markup' => json_encode(['remove_keyboard' => true]),
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
            'reply_markup' => json_encode(['remove_keyboard' => true]),
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
                'reply_markup' => json_encode(['remove_keyboard' => true]),
            ]);
            return;
        }

        $keyboard = $tontines->map(function ($tontine) {
            return [$tontine->name];
        })->toArray();

        Conversation::updateOrCreate(
            ['user_id' => $user->id],
            ['state' => 'select_tontine_payment', 'data' => []]
        );

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'Sélectionnez une tontine pour effectuer un paiement :',
            'reply_markup' => json_encode([
                'keyboard' => $keyboard,
                'resize_keyboard' => true,
                'one_time_keyboard' => true,
            ]),
        ]);
    }

    protected function payTontine(int $chatId, User $user, string $tontineId): void
    {
        $tontine = Tontine::find($tontineId);
        if (!$tontine || $tontine->status !== 'active') {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Tontine invalide ou inactive.',
                'reply_markup' => json_encode(['remove_keyboard' => true]),
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
                'reply_markup' => json_encode(['remove_keyboard' => true]),
            ]);
            return;
        }

        $invoice = $this->createBtcpayInvoice($tontine->amount_sats, $tontine->id, $user->id);

        if (empty($invoice)) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Erreur lors de la création de la facture. Veuillez réessayer.',
                'reply_markup' => json_encode(['remove_keyboard' => true]),
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
            'expires_at' => Carbon::now()->addMinutes(5),
            'round' => $tontine->current_round,
        ]);

        if ($bolt11 === 'ln_invoice') {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "Veuillez utiliser le lien de paiement : {$invoice['checkoutLink']}",
                'reply_markup' => json_encode(['remove_keyboard' => true]),
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
                'reply_markup' => json_encode(['remove_keyboard' => true]),
            ]);
            return;
        }

        $keyboard = $tontines->map(function ($tontine) {
            return [$tontine->name];
        })->toArray();

        Conversation::updateOrCreate(
            ['user_id' => $user->id],
            ['state' => 'select_tontine_balance', 'data' => []]
        );

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'Sélectionnez une tontine pour vérifier le solde :',
            'reply_markup' => json_encode([
                'keyboard' => $keyboard,
                'resize_keyboard' => true,
                'one_time_keyboard' => true,
            ]),
        ]);
    }

    protected function checkBalance(int $chatId, User $user, string $tontineId): void
    {
        $tontine = Tontine::find($tontineId);
        if (!$tontine) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Tontine invalide.',
                'reply_markup' => json_encode(['remove_keyboard' => true]),
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
            'reply_markup' => json_encode(['remove_keyboard' => true]),
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
                'reply_markup' => json_encode(['remove_keyboard' => true]),
            ]);
            return;
        }

        $keyboard = $tontines->map(function ($tontine) {
            return [$tontine->name];
        })->toArray();

        Conversation::updateOrCreate(
            ['user_id' => $user->id],
            ['state' => 'select_tontine_withdraw', 'data' => []]
        );

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'Sélectionnez une tontine pour effectuer un retrait :',
            'reply_markup' => json_encode([
                'keyboard' => $keyboard,
                'resize_keyboard' => true,
                'one_time_keyboard' => true,
            ]),
        ]);
    }

    protected function withdrawTontine(int $chatId, User $user, string $tontineId): void
    {
        $tontine = Tontine::find($tontineId);
        if (!$tontine || $tontine->status !== 'active') {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Tontine invalide ou inactive.',
                'reply_markup' => json_encode(['remove_keyboard' => true]),
            ]);
            return;
        }

        $member = $tontine->members()->where('user_id', $user->id)->first();
        if (!$member) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Vous n\'êtes pas membre de cette tontine.',
                'reply_markup' => json_encode(['remove_keyboard' => true]),
            ]);
            return;
        }

        $currentPosition = ($tontine->current_round % $tontine->max_members) + 1;
        if ($member->pivot->position !== $currentPosition) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Ce n\'est pas votre tour de retirer.',
                'reply_markup' => json_encode(['remove_keyboard' => true]),
            ]);
            return;
        }

        if ($member->pivot->has_received) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Vous avez déjà reçu votre part.',
                'reply_markup' => json_encode(['remove_keyboard' => true]),
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
            'reply_markup' => json_encode(['remove_keyboard' => true]),
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


    public function handleBtcpayWebhook(Request $request)
    {
        $rawBody = $request->getContent();
        Log::info('[BTCPAY] Webhook reçu', ['rawBody' => $rawBody]);

        $signatureHeader = $request->header('BTCPay-Sig');
        if (!$signatureHeader || !str_starts_with($signatureHeader, 'sha256=')) {
            Log::error('[BTCPAY] Signature manquante ou invalide');
            return response()->json(['error' => 'Signature invalide'], 400);
        }

        $providedSignature = substr($signatureHeader, 7);
        $computedSignature = hash_hmac('sha256', $rawBody, config('tontine.btcpay.webhook_secret'));

        if (!hash_equals($computedSignature, $providedSignature)) {
            Log::error('[BTCPAY] Signature incorrecte', [
                'attendue' => $computedSignature,
                'reçue' => $providedSignature,
            ]);
            return response()->json(['error' => 'Signature incorrecte'], 400);
        }

        $payload = json_decode($rawBody, true);
        Log::info('[BTCPAY] Payload décodé', $payload);

        // Vérification du type d’événement
        if (!isset($payload['type']) || $payload['type'] !== 'InvoiceSettled') {
            Log::warning('[BTCPAY] Type d\'événement non traité', ['type' => $payload['type'] ?? 'inconnu']);
            return response()->json(['ignored' => true], 200);
        }

        // Récupération du paymentRequestId depuis les metadata
        $paymentRequestId = $payload['metadata']['paymentRequestId'] ?? null;
        if (!$paymentRequestId) {
            Log::error('[BTCPAY] paymentRequestId manquant dans le payload');
            return response()->json(['error' => 'paymentRequestId manquant'], 400);
        }

        // Rechercher le paiement dans la base de données
        $payment = TontinePayment::where('invoice_id', $paymentRequestId)->first();
        if (!$payment) {
            Log::warning('BTCPay webhook: Paiement introuvable pour le paymentRequestId ' . $paymentRequestId);
            return response()->json(['error' => 'Paiement non trouvé'], 404);
        }

        // Mettre à jour le statut du paiement
        $payment->update([
            'status' => 'paid',
            'paid_at' => Carbon::now(),
        ]);

        // Notifier l’utilisateur via Telegram
        $this->telegram->sendMessage([
            'chat_id' => $payment->user->telegram_id,
            'text' => "✅ Paiement confirmé de {$payment->amount_fcfa} FCFA pour la tontine '{$payment->tontine->name}'.",
            'reply_markup' => json_encode(['remove_keyboard' => true]),
        ]);

        // Notifier le créateur si ce n’est pas la même personne
        $creator = User::find($payment->tontine->creator_id);
        if ($creator && $creator->telegram_id !== $payment->user->telegram_id) {
            $this->telegram->sendMessage([
                'chat_id' => $creator->telegram_id,
                'text' => "💰 Le membre @{$payment->user->username} a payé {$payment->amount_fcfa} FCFA pour la tontine '{$payment->tontine->name}' (Tour {$payment->round}).",
            ]);
        }

        return response()->json(['success' => true]);
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
