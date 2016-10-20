<?php

namespace AppBundle\Telegram;


use DateTime;
use GuzzleHttp\Client;
use Monolog\Logger;

/**
 * Class MenuPublisher
 *
 * Publishes a choice of dishes to a Telegram group.
 *
 * @package AppBundle\Telegram
 */
class TelegramService
{
    function __construct(Logger $logger)
    {
        $this->logger = $logger;
        $telegramToken = getenv('TELEGRAM_BOT_TOKEN');
        if (!$telegramToken) {
            throw new Exception('TELEGRAM_BOT_TOKEN must be set');
        }
        $endpoint = "https://api.telegram.org/bot${telegramToken}/";
        $this->client = new Client(['base_uri' => $endpoint]);

    }

    function publishMenu(array $dishes)
    {
        $tomorrow = (new DateTime('tomorrow'))->format('Y-m-d');
        $message = "Morgen gibt es folgende Gerichte im LT10:\n";
        $buttons = [];
        foreach ($dishes as $index => $dish) {
            $dishNumber = $index + 1;
            $description = $dish['description'];
            $cook = $dish['cook'];
            $price = number_format($dish['price'], 2);
            $message .= "${dishNumber}. $description (gekocht von ${cook}). Preis: ${price} €.\n";
            $buttons[] = ['text' => "Gericht ${dishNumber}", 'callback_data' => "${dishNumber}_${tomorrow}"];
        }
        $buttons[] = ['text' => 'Nein danke', 'callback_data' => "none_${tomorrow}"];
        $message .= 'Wer möchte mitkommen?';
        $this->client->post('sendMessage',
            ['json' => [
                'chat_id' => getenv('TELEGRAM_CHAT_ID'),
                'text' => $message,
                'reply_markup' => ['inline_keyboard' => [$buttons]]
            ]]);
    }

    function answerCallbackQuery($callbackQuery, $notificationText)
    {
        $body = [
            'callback_query_id' => (integer) $callbackQuery->id,
            'text' => $notificationText
        ];
        $this->client->post('answerCallbackQuery',
            ['json' => $body]);
    }
}