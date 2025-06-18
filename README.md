# DJODJOUMA

Un bot Telegram pour gérer des tontines (associations d'épargne et de crédit rotatives) avec des paiements via le réseau Lightning de Bitcoin à l'aide de BTCPay Server. Les utilisateurs peuvent créer, rejoindre, payer, vérifier les soldes et retirer des fonds des tontines via une interface basée sur des boutons. Le bot affiche les montants en FCFA (XOF), avec des valeurs stockées en satoshis, et utilise un taux de change statique.

## Fonctionnalités

-   **Créer une tontine** : Les utilisateurs peuvent créer une tontine en spécifiant le nom, le montant (FCFA), la fréquence (quotidienne, hebdomadaire, mensuelle) et le nombre maximum de membres.
-   **Rejoindre une tontine** : Les utilisateurs rejoignent une tontine à l'aide d'un code d'invitation unique de 8 caractères.
-   **Payer une contribution** : Les utilisateurs paient leurs contributions via des factures Lightning générées par BTCPay Server, avec des codes QR envoyés sur Telegram.
-   **Vérifier le solde** : Les utilisateurs consultent le solde de la tontine (en FCFA et satoshis), le tour actuel et le prochain bénéficiaire.
-   **Retirer des fonds** : Les utilisateurs retirent leur part lorsqu’arrive leur tour (facture placeholder ; nécessite une intégration en production).
-   **Interface avec boutons** : Toutes les interactions utilisent des boutons inline Telegram pour une utilisation simplifiée.
-   **Base de données SQLite** : Stocke les utilisateurs, tontines, paiements, retraits et états des conversations.
-   **Taux de change statique** : Convertit les FCFA en satoshis à l'aide d'un taux configurable (par défaut : 0.0000018).

## Prérequis

-   **PHP** : 8.1 ou supérieur
-   **Composer** : Dernière version
-   **Laravel** : 10.x
-   **SQLite** : Pour la base de données
-   **Bot Telegram** : Créez un bot via [BotFather](https://t.me/BotFather) pour obtenir un token
-   **BTCPay Server** : Instance avec support du réseau Lightning, clé API et ID de magasin
-   **Ngrok** : Pour tester les webhooks localement
-   **HTTPS** : Requis pour les webhooks Telegram en production

## Installation

-   **Installer les dépendances** : composer install
-   **Copier le fichier d'environnement** : cp .env.example .env

## Modifier .env avec vos paramètres

-   APP_ENV=production
-   APP_KEY=base64:votre-clé-générée
-   APP_URL=https://api.bantchi.mougni.com

-   DB_CONNECTION=sqlite
-   DB_DATABASE=/chemin/absolu/vers/database.sqlite

-   TONTINE_TELEGRAM_BOT_TOKEN=votre-token-telegram
-   TONTINE_TELEGRAM_WEBHOOK_URL=votre-webhook-url
-   TONTINE_BTCPAY_SERVER_URL=https://votre-serveur-btcpay
-   TONTINE_BTCPAY_API_KEY=votre-clé-api-btcpay
-   TONTINE_BTCPAY_STORE_ID=votre-id-magasin-btcpay
-   TONTINE_EXCHANGE_DEFAULT_RATE=0.0000018

## Utilisation

1. **Démarrer le bot** :

    - Ouvrez Telegram et envoyez `/start` à votre bot.
    - Le menu principal s’affiche avec des boutons en français et des emojis :
        - ➕ Créer une tontine
        - 🤝 Rejoindre une tontine
        - 💸 Payer
        - 💰 Vérifier le solde
        - 🏧 Retirer

2. **Créer une tontine** :

    - Cliquez sur ➕ Créer une tontine.
    - Entrez le nom, le montant (FCFA), la fréquence (Quotidien, Hebdomadaire, Mensuel) et le nombre maximum de membres.
    - Recevez un code d’invitation (e.g., `ABC12345`).

3. **Rejoindre une tontine** :

    - Cliquez sur 🤝 Rejoindre une tontine.
    - Entrez le code d’invitation.
    - Obtenez une position dans la tontine.

4. **Payer une contribution** :

    - Cliquez sur 💸 Payer.
    - Sélectionnez une tontine et recevez une facture Lightning avec un QR code.
    - Payez via un portefeuille compatible Lightning.

5. **Vérifier le solde** :

    - Cliquez sur 💰 Vérifier le solde.
    - Consultez les détails de la tontine (solde, prochain bénéficiaire, tour actuel).

6. **Retirer des fonds** :
    - Cliquez sur 🏧 Retirer.
    - Retirez lorsque c’est votre tour, avec une facture de retrait.
