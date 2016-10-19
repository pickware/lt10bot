<?php

namespace AppBundle\Telegram;


use GuzzleHttp\Client;
use Monolog\Logger;

class MenuPublisher
{
    function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    function publishMenu(array $plates)
    {
        $telegram_token = getenv('TELEGRAM_BOT_TOKEN');
        $endpoint = "https://api.telegram.org/bot${telegram_token}/";
        $client = new Client(['base_uri' => $endpoint]);
        $message = "Morgen gibt es folgende Gerichte im LT10:\n";
        foreach ($plates as $index => $plate) {
            $plateNumber = $index + 1;
            $description = $plate['description'];
            $cook = $plate['cook'];
            $price = number_format($plate['price'], 2);
            $message .= "${plateNumber}. $description (gekocht von ${cook}). Preis: ${price} €.\n";
        }
        $message .= 'Wer möchte mitkommen?';
        $response = $client->post('sendMessage',
            ['json' => [
                'chat_id' => getenv('TELEGRAM_CHAT_ID'),
                'text' => $message
            ]]);
    }
}