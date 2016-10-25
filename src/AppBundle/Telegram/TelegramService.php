<?php

namespace AppBundle\Telegram;

use AppBundle\Entity\Reservation;
use DateTime;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use IntlDateFormatter;
use Monolog\Logger;

/**
 * Class TelegramService
 *
 * Provides integration with the Telegram chat service.
 *
 * @package AppBundle\Telegram
 */
class TelegramService
{
    const LOCALE = 'de_DE';

    function __construct(Logger $logger)
    {
        $this->logger = $logger;
        $telegramToken = getenv('TELEGRAM_BOT_TOKEN');
        if (!$telegramToken) {
            throw new Exception('TELEGRAM_BOT_TOKEN must be set');
        }
        $this->chatId = getenv('TELEGRAM_CHAT_ID');
        if (!$this->chatId) {
            throw new Exception('TELEGRAM_CHAT_ID must be set');
        }
        $endpoint = "https://api.telegram.org/bot${telegramToken}/";
        $this->client = new Client(['base_uri' => $endpoint]);

    }

    /**
     * Publish a menu to the group chat.
     * @param array $dishes the dishes of the menu, as returned by LT10Service::getDishesForDate
     * @param DateTime $date the date the menu is valid for
     */
    function publishMenu(array $dishes, $date)
    {
        $dateFormatter = IntlDateFormatter::create(static::LOCALE, IntlDateFormatter::SHORT, IntlDateFormatter::NONE);
        $dateFormatter->setPattern('EEEE, d. MMMM');
        $localeFormattedDate = $dateFormatter->format($date);
        $message = null;
        $buttons = null;
        if (empty($dishes)) {
            $message = "Für den ${localeFormattedDate} habe ich leider kein Menü gefunden \u{1f625}";
        } else {
            $message = "Am ${localeFormattedDate} gibt es folgende Gerichte im LT10:\n";
            $buttons = [];
            $isoFormattedDate = $date->format('Y-m-d');
            foreach ($dishes as $index => $dish) {
                $dishNumber = chr(ord('A') + $index);
                $description = $dish['description'];
                $cook = $dish['cook'];
                $price = number_format($dish['price'], 2);
                $message .= "${dishNumber}. $description (gekocht von ${cook}). Preis: ${price} €.\n";
                $buttons[] = ['text' => "Gericht ${dishNumber}", 'callback_data' => "${dishNumber}_${isoFormattedDate}"];

            }
            $buttons[] = ['text' => 'Nein danke', 'callback_data' => "none_${isoFormattedDate}"];
            $message .= 'Wer möchte mitkommen?';
        }
        $body = [
            'chat_id' => $this->chatId,
            'text' => $message
        ];
        if ($buttons) {
            $body['reply_markup'] = ['inline_keyboard' => [$buttons]];
        }
        $this->client->post('sendMessage', ['json' => $body]);
    }

    /**
     * Handles when a user clicks on one of the menu options.
     * @param array $callbackQuery the original callback query
     * @param string $notificationText a notification text to show to the user
     */
    function answerCallbackQuery($callbackQuery, $notificationText)
    {
        $body = [
            'callback_query_id' => (integer)$callbackQuery->id,
            'text' => $notificationText
        ];
        $this->client->post('answerCallbackQuery', ['json' => $body]);
    }

    /**
     * Sends a lunch reminder to the person who made a reservation.
     * @param Reservation $reservation the reservation to send a reminder for
     */
    function sendLunchReminder($reservation)
    {
        try {
            $userName = $reservation->getUserName();
            $userId = $reservation->getUserId();
            $dish = $reservation->getDish();
            $message = "Hallo ${userName}! Du hast für heute Gericht ${dish} im LT10 bestellt. "
                . "Vergiss nicht, essen zu gehen! \u{1f37D} \u{1f642}";
            $body = [
                'chat_id' => (integer)$reservation->getUserId(),
                'text' => $message
            ];
            $this->client->post('sendMessage', ['json' => $body]);
        } catch (ClientException $e) {
            $this->logger->info("Cannot deliver exception to user ${userId} (${userName}), "
                . "probably because they block the bot.");
        }
    }
}