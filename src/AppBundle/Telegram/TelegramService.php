<?php

namespace AppBundle\Telegram;

use AppBundle\Entity\Reservation;
use AppBundle\Repository\ReservationRepository;
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

    public function __construct(Logger $logger, ReservationRepository $reservationRepository)
    {
        $this->logger = $logger;
        $this->reservationRepository = $reservationRepository;
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
    public function publishMenu(array $dishes, $date)
    {
        $dateFormatter = IntlDateFormatter::create(static::LOCALE, IntlDateFormatter::SHORT, IntlDateFormatter::NONE);
        $dateFormatter->setPattern('EEEE, d. MMMM');
        $localeFormattedDate = $dateFormatter->format($date);
        $isoFormattedDate = $date->format('Y-m-d');
        $this->logger->info("isoFormattedDate = ${isoFormattedDate}");
        $body = [
            'chat_id' => $this->chatId
        ];
        if (empty($dishes)) {
            $body['text'] = "Für den ${localeFormattedDate} habe ich leider kein Menü gefunden \u{1f625}";
        } else {
            $body['text'] = "Am ${localeFormattedDate} gibt es folgende Gerichte im LT10:\n";
            foreach ($dishes as $index => $dish) {
                $dishNumber = chr(ord('A') + $index);
                $description = $dish['description'];
                $cook = $dish['cook'];
                $price = number_format($dish['price'], 2);
                $body['text'] .= "${dishNumber}. $description (gekocht von ${cook}). Preis: ${price} €.\n";
            }
            $body['text'] .= 'Wer möchte mitkommen?';
            $body['reply_markup'] = $this->makeInlineKeyboard($dishes, $isoFormattedDate);
        }
        $this->client->post('sendMessage', ['json' => $body]);
    }

    /**
     * After reservations were changed, update the reservation counts on the last inline message.
     * @param array $callbackQuery the callback query that caused the reservation counts to update
     * @param string $date the date we're talking about
     * @param integer $numDishes the number of dishes, so we can generate the reply options again
     */
    public function updateReservationCounts($callbackQuery, $date, $numDishes)
    {
        $this->logger->info("updating reservation counts for ${date}");
        $dishes = [];
        for ($i=0; $i < $numDishes; $i++) {
            $dishes[] = ['dishNumber' => chr(ord('A') + $i)];
        }
        $body = [
            'chat_id' => $this->chatId,
            'message_id' => $callbackQuery->message->message_id,
            'reply_markup' => $this->makeInlineKeyboard($dishes, $date)
        ];
        $this->client->post('editMessageReplyMarkup', ['json' => $body]);
    }

    /**
     * Generate an inline_keyboard structure to send to the Telegram API.
     * @param array $dishes the dishes from which the user can select
     * @param string $date the date we're talking about
     * @return array an inline_keyboard structure to pass to the Telegram API
     */
    private function makeInlineKeyboard($dishes, $date) {
        $buttons = [];
        $numDishes = count($dishes);
        foreach ($dishes as $index => $dish) {
            $dishNumber = chr(ord('A') + $index);
            $dishCount = $this->reservationRepository->getNumberOfReservations($date, $dishNumber);
            $buttons[] = [
                'text' => "Gericht ${dishNumber}" . ($dishCount > 0 ? " (${dishCount} reserviert)" : ""),
                'callback_data' => "${dishNumber}_${date}_${numDishes}"
            ];
        }
        $buttons[] = ['text' => 'Nein danke', 'callback_data' => "none_${date}_${numDishes}"];
        return ['inline_keyboard' => [$buttons]];
    }

    /**
     * Handles when a user clicks on one of the menu options.
     * @param array $callbackQuery the original callback query
     * @param string $notificationText a notification text to show to the user
     */
    public function answerCallbackQuery($callbackQuery, $notificationText)
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
    public function sendLunchReminder($reservation)
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
            $this->logger->info("Cannot deliver lunch reminder to user ${userId} (${userName}), "
                . "probably because they block the bot.");
        }
    }
}