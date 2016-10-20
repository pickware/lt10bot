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
class MenuPublisher
{
    function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    function publishMenu(array $dishes)
    {
        $tomorrow = (new DateTime('tomorrow'))->format('Y-m-d');
        $telegram_token = getenv('TELEGRAM_BOT_TOKEN');
        $endpoint = "https://api.telegram.org/bot${telegram_token}/";
        $client = new Client(['base_uri' => $endpoint]);
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
        $buttons[] = ['text' => 'Nein danke', 'callback_data' => "false"];
        $message .= 'Wer möchte mitkommen?';
        $client->post('sendMessage',
            ['json' => [
                'chat_id' => getenv('TELEGRAM_CHAT_ID'),
                'text' => $message,
                'reply_markup' => ['inline_keyboard' => [$buttons]]
            ]]);
    }
}